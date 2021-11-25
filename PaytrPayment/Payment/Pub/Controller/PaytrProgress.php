<?php

namespace PaytrPayment\Payment\Pub\Controller;

use XF;
use XF\Pub\Controller\AbstractController;

class PaytrProgress extends AbstractController
{

    /**
     * @return XF\Mvc\Reply\Error|XF\Mvc\Reply\View
     */
    public function actionProcess()
    {
        if($this->validateRequestKey()){
            $viewParams = [
                'display_title'  => $this->request->get('display_title'),
                'iframe_token'   => $this->getPaytrToken()
            ];
            return $this->view('PaytrPayment\Payment:View', 'paytr_payment_process', $viewParams);
        }else{
            return $this->error('Unfortunately the thing you are looking for could not be found.', 404);
        }
    }

    /**
     * @return false|mixed|null
     */
    private function validateRequestKey(){
        $db = XF::db();
        return $db->fetchOne('SELECT request_key FROM xf_purchase_request WHERE request_key = ?', $this->request->get('request_key'));
    }

    /**
     * @return array
     */
    private function getPostVariables(): array
    {
        return [
            'merchant_id'       => $this->request->get('merchant_id'),
            'user_ip'           => $this->request->get('user_ip'),
            'merchant_oid'      => $this->request->get('merchant_oid'),
            'email'             => $this->request->get('email'),
            'payment_amount'    => $this->request->get('payment_amount'),
            'paytr_token'       => $this->request->get('paytr_token'),
            'user_basket'       => $this->request->get('user_basket'),
            'debug_on'          => $this->request->get('debug_on'),
            'no_installment'    => $this->request->get('no_installment'),
            'max_installment'   => $this->request->get('max_installment'),
            'user_phone'        => $this->request->get('user_phone'),
            'user_name'         => $this->request->get('user_name'),
            'user_address'      => $this->request->get('user_address'),
            'merchant_ok_url'   => $this->request->get('merchant_ok_url'),
            'merchant_fail_url' => $this->request->get('merchant_fail_url'),
            'timeout_limit'     => $this->request->get('timeout_limit'),
            'currency'          => $this->request->get('currency'),
            'test_mode'         => $this->request->get('test_mode'),
            'lang'              => $this->request->get('lang'),
        ];
    }

    /**
     * @return array
     */
    private function getPostVariablesWithEft(): array
    {
        return [
            'merchant_id'       => $this->request->get('merchant_id'),
            'user_ip'           => $this->request->get('user_ip'),
            'merchant_oid'      => $this->request->get('merchant_oid'),
            'email'             => $this->request->get('email'),
            'payment_type'      => 'eft',
            'payment_amount'    => $this->request->get('payment_amount'),
            'paytr_token'       => $this->request->get('paytr_token'),
            'debug_on'          => $this->request->get('debug_on'),
            'timeout_limit'     => $this->request->get('timeout_limit'),
            'test_mode'         => $this->request->get('test_mode'),
        ];
    }

    /**
     * @return string|void
     */
    private function getPaytrToken(){
        $ch=curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://www.paytr.com/odeme/api/get-token");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1) ;
        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->request->get('payment_type') === 'eft' ? $this->getPostVariablesWithEft() : $this->getPostVariables());
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $result = @curl_exec($ch);
        if(curl_errno($ch)){
            die("PAYTR IFRAME connection error. err:".curl_error($ch));
        }
        curl_close($ch);
        $result=json_decode($result,1);
        if($result['status']=='success'){
            return ($this->request->get('payment_type') === 'eft' ? 'https://www.paytr.com/odeme/api/' : 'https://www.paytr.com/odeme/guvenli/') . $result['token'];
        }else{
            die("PAYTR IFRAME failed. reason:".$result['reason']);
        }
    }

}