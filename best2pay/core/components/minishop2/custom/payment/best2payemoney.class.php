<?php
if (!class_exists('msPaymentInterface')) {
    require_once dirname(dirname(dirname(__FILE__))) . '/model/minishop2/mspaymenthandler.class.php';
}

class Best2payemoney extends msPaymentHandler implements msPaymentInterface {
    var $resultUrl = '';

    function __construct(xPDOObject $object, $config = array()) {
        $this->modx = & $object->xpdo;

        //assets_url already include base_url
        $hostUrl = $this->modx->getOption('url_scheme') . $this->modx->getOption('http_host');
        $assetsUrl = $this->modx->getOption('minishop2.assets_url', $config, $this->modx->getOption('assets_url').'components/minishop2/');
        $resultScript = 'best2payemoney.php';
        $resultUrl = $hostUrl . $assetsUrl . 'payment/' . $resultScript;

        $this->config = array_merge(array(
            'result_url' => $resultUrl,
            'result_script' => $resultScript,
            'json_response' => false
        ), $config);

        $this->config['submit_fields'] = array_map('trim', explode(',', $this->config['submit_fields']));

        $this->modx->lexicon->load('minishop2:best2payemoney');
    }
    /* @inheritdoc} */
    public function send(msOrder $order) {
        $best2payLink = $this->getBest2PayLink($order);
        return $this->success('', array('redirect' =>  $best2payLink));
    }

    public function getBest2PayLink(msOrder $order) {

        $testmode = $this->modx->getOption('setting_ms2_payment_best2pay_test_mode');
        if ($testmode){
            $best2pay_url ='https://test.best2pay.net';
        } else {
            $best2pay_url = 'https://pay.best2pay.net';
        }
        $url = $best2pay_url.'/webapi/Register';
        $id = $order->get('id');
        $sector=$this->modx->getOption('setting_ms2_payment_best2pay_sector_id');
        $sum = $order->get('cost');
        $amount = round($sum * 100);
        $currencyName=$this->modx->getOption('setting_ms2_payment_best2pay_currency', null, 'руб');
        if ($currencyName==='руб'){
            $currency=643;
        } else if ($currencyName==='доллар'){
            $currency=840;
        } else if ($currencyName==='евро'){
            $currency=978;
        } else {
            throw new Exception('Wrong currency');
        }
        $password=$this->modx->getOption('setting_ms2_payment_best2pay_password');
        $desc=$this->modx->getOption('setting_ms2_payment_best2pay_desc', null, 'Оплата заказа').' '.$order->get('id');;
        $email = $order->getOne('UserProfile')->get('email');
        $signature  = base64_encode(md5($sector . $amount . $currency . $password));
        $data = array(
            'sector' => $sector,
            'reference' => $id,
            'amount' => $amount,
            'description' => $desc,
            'email' => $email,
            'currency' => $currency,
            'mode' => 1,
            'signature' => $signature,
            'url' => $this->config['result_url'],
            'failurl' => $this->config['result_url'],

        );
        $options = array(
            'http' => array(
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
            ),
        );

        $context  = stream_context_create($options);
        $best2pay_id = file_get_contents($url, false, $context);
        if (intval($best2pay_id) == 0) {
            throw new Exception('error register order');
        }
        $signature = base64_encode(md5($sector . $best2pay_id . $password));
        $link  = $best2pay_url
            . '/webapi/Epayment'
            . '?sector=' .$sector
            . '&id=' . $best2pay_id
            . '&signature=' . $signature;
        return $link;
    }
      /* @inheritdoc} */
    public function receive(msOrder $order, $params = array()) {

        /* @var miniShop2 $miniShop2 */
        $miniShop2 = $this->modx->getService('miniShop2');

        $operaionId =  $params['operation'];
        $orderId = $params['id'];
        $sectorId = $this->modx->getOption('setting_ms2_payment_best2pay_sector_id');
        $password = $this->modx->getOption('setting_ms2_payment_best2pay_password');

        $signature = base64_encode(md5($sectorId . $orderId . $operaionId  . $password));

        $testmode = $this->modx->getOption('setting_ms2_payment_best2pay_test_mode');
        if ($testmode){
            $best2pay_url ='https://test.best2pay.net';
        } else {
            $best2pay_url = 'https://pay.best2pay.net';
        }
        $url = $best2pay_url . '/webapi/Operation';

        $context = stream_context_create(array(
            'http' => array(
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query(array(
                    'sector' => $sectorId,
                    'id' => $orderId,
                    'operation' => $operaionId,
                    'signature' => $signature
                )),
            )
        ));

        $xml = file_get_contents($url, false, $context);
        if (!$xml)
            throw new Exception("Empty data");
        $xml = simplexml_load_string($xml);
        if (!$xml)
            throw new Exception("Non valid XML was received");
        $response = json_decode(json_encode($xml), true);
        if (!$response)
            throw new Exception("Non valid XML was received");

        $tmp_response = (array)$response;
        unset($tmp_response["signature"]);
        $signature = base64_encode(md5(implode('', $tmp_response) . $password));
        if ($signature !== $response['signature'])
            throw new Exception("Invalid signature");

        if ($response['type'] == 'EPAYMENT' && $response['state'] == 'APPROVED'){
            @$this->modx->context->key = 'mgr';
            $miniShop2->changeOrderStatus($order->get('id'), 2); // Setting status "paid"
            return true;
        } else {
            if ($this->modx->getOption('setting_ms2_payment_best2pay_cancel_order', null, false)){
                $miniShop2->changeOrderStatus($order->get('id'), 4); // Setting status "cancelled"
            }
            return false;
        }
    }
    /**
     * Process error
     *
     * @param string $text Text to log
     * @param array $params Request parameters
     * @return bool
     */
    public function paymentError($text, $params = array()) {
        $this->modx->log(xPDO::LOG_LEVEL_ERROR, '[miniShop2:Best2payemonye] ' . $text . ' Request: ' . print_r($params, true));
        return $this->buildResponse('error', $this->config['result_script'], $text);
    }
}