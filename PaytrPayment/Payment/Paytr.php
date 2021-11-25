<?php

namespace PaytrPayment\Payment;

use XF;
use XF\Entity\PurchaseRequest;
use XF\Http\Request;
use XF\Mvc\Controller;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;

class Paytr extends AbstractProvider
{

    /**
     * @var string[]
     */
    protected $supportedCurrencies = [
        'TRY', 'USD', 'EUR', 'RUB', 'GBP',
    ];

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return 'PayTR Virtual Pos iFrame API';
    }

    /**
     * @param array $options
     * @param array $errors
     * @return bool
     */
    public function verifyConfig(array &$options, &$errors = []): bool
    {
        if (!$options['merchant_id'] || !$options['merchant_key'] || !$options['merchant_salt'])
        {
            $errors[] = XF::phrase('merchant_id_or_merchant_key_or_merchant_salt_required');
        }
        return !$errors;
    }

    /**
     * @param Controller $controller
     * @param PurchaseRequest $purchaseRequest
     * @param Purchase $purchase
     * @return XF\Mvc\Reply\View
     */
    public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase): XF\Mvc\Reply\View
    {
        $paymentProfile                         = $purchase->paymentProfile;
        $viewParams                             = $this->getPaymentParams($purchaseRequest, $purchase);
        $viewParams['paytr_error_message']      = false;
        $viewParams['paytr_display_title']      = $paymentProfile->display_title;
        $viewParams['paytr_request_key']        = $purchaseRequest->request_key;
        if(!in_array($purchase->currency, $this->supportedCurrencies)){
            $viewParams['paytr_error_message'] = XF::phrase('paytr_currency_error');
        }else{
            $paytr_init     = (new PaytrHelper())
                ->setMerchantId($paymentProfile->options['merchant_id'])
                ->setMerchantKey($paymentProfile->options['merchant_key'])
                ->setMerchantSalt($paymentProfile->options['merchant_salt'])
                ->setTestMode($paymentProfile->options['test_mode'] ?? 0)
                ->setInstallment($paymentProfile->options['installment'])
                ->setLang($paymentProfile->options['language'])
                ->setEmail($purchase->purchaser->email)
                ->setUserName($purchase->purchaser->username)
                ->setMerchantOkUrl($purchase->returnUrl)
                ->setMerchantFailUrl($purchase->cancelUrl)
                ->setPaymentAmount($purchase->cost)
                ->setUserBasket($purchase->title, $purchase->cost)
                ->setMerchantOid($purchaseRequest->purchase_request_id)
                ->setCurrency($purchase->currency);
            $viewParams['paytr']                      = $paytr_init->makePostVariables();
            $viewParams['paytr_eft']                  = $paytr_init->makePostVariablesWithEft();
            $viewParams['cost']                       = $purchase->cost;
            $viewParams['currency']                   = $purchase->currency;
            $viewParams['phone_number_description']   = $paymentProfile->options['phone_number_description'];
            $viewParams['address_description']        = $paymentProfile->options['address_description'];
            $viewParams['iframe_type']                = $paymentProfile->options['iframe_type'];
            $viewParams['typeHandlers']               = [
                1 => $paymentProfile->options['credit_card_title'],
                2 => $paymentProfile->options['paytr_money_order_eft_title'],
            ];
        }
        return $controller->view('PaytrPayment:Payment\Initiate', 'payment_initiate_paytr', $viewParams);
    }

    /**
     * @param Request $request
     * @return CallbackState
     */
    public function setupCallback(Request $request): CallbackState
    {
        $state = new CallbackState();
        $state->merchant_oid        = $request->filter('merchant_oid', 'str');
        $state->hash                = $request->filter('hash', 'str');
        $state->status              = $request->filter('status', 'str');
        $state->total_amount        = $request->filter('total_amount', 'str');
        $state->payment_type        = $request->filter('payment_type', 'str');
        $state->payment_amount      = $request->filter('payment_amount', 'str');
        $state->currency            = $request->filter('currency', 'str');
        $state->installment_count   = $request->filter('installment_count', 'str');
        $state->merchant_id         = $request->filter('merchant_id', 'str');
        $state->test_mode           = $request->filter('test_mode', 'str');
        $state->failed_reason_msg   = $request->filter('failed_reason_msg', 'str');
        $state->transactionId       = $state->merchant_oid;
        $state->requestKey          = $this->resolveRequestKey($state->merchant_oid);
        return $state;
    }

    /**
     * @param CallbackState $state
     * @return bool
     */
    public function validateCallback(CallbackState $state): bool
    {
        $paymentProfile  = $state->getPaymentProfile();
        $purchaseRequest = $state->getPurchaseRequest();
        if (!$state->requestKey || !$purchaseRequest)
        {
            $state->logType = 'error';
            $state->logMessage = 'OK';
            return false;
        }
        $options        = $paymentProfile->options;
        $hash           = base64_encode( hash_hmac('sha256', $state->merchant_oid.$options['merchant_salt'].$state->status.$state->total_amount, $options['merchant_key'], true) );
        if ($hash !== $state->hash)
        {
            $state->logType = 'error';
            $state->logMessage = 'Could not verify PayTR hash.';
            return false;
        }
        return true;
    }

    /**
     * @param CallbackState $state
     * @return bool
     */
    public function validateTransaction(CallbackState $state): bool
    {
        if (!$state->transactionId)
        {
            return false;
        }
        return true;
    }

    /**
     * @param CallbackState $state
     */
    public function getPaymentResult(CallbackState $state)
    {
        switch ($state->status)
        {
            case 'success':
                $state->paymentResult = CallbackState::PAYMENT_RECEIVED;
                break;

            case 'failed':
                $state->paymentResult = CallbackState::PAYMENT_REINSTATED;
                break;
        }
    }

    /**
     * @param CallbackState $state
     */
    public function completeTransaction(CallbackState $state)
    {
        if ($state->status == 'success')
        {
            parent::completeTransaction($state);
            $state->logType = 'info';
        }
        else
        {
            $state->logType = 'error';
        }
        $state->logMessage = 'OK';
    }

    /**
     * @param CallbackState $state
     */
    public function prepareLogData(CallbackState $state)
    {
        $state->logDetails = [
            '_GET' => $_GET,
            '_POST' => $_POST
        ];
    }

    /**
     * @param $merchant_oid
     * @return false|mixed|null
     */
    public function resolveRequestKey($merchant_oid){
        if(!$merchant_oid){
            return false;
        }
        $merchant_oid = str_replace('SP', '', explode('XF', $merchant_oid)[0]);
        $db = XF::db();
        return $db->fetchOne('SELECT request_key FROM xf_purchase_request WHERE purchase_request_id = ?', $merchant_oid);
    }

}