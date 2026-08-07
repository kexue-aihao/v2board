<?php

namespace App\Payments;


class BTCPay {
    public function __construct($config) {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'btcpay_url' => [
                'label' => __('API接口所在网址(包含最后的斜杠)'),
                'description' => '',
                'type' => 'input',
            ],
            'btcpay_storeId' => [
                'label' => 'storeId',
                'description' => '',
                'type' => 'input',
            ],
            'btcpay_api_key' => [
                'label' => 'API KEY',
                'description' => __('个人设置中的API KEY(非商店设置中的)'),
                'type' => 'input',
            ],
            'btcpay_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'description' => '',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order) {

        if (($order['gateway_currency'] ?? null) !== 'CNY') {
            abort(500, 'BTCPay only supports CNY payments');
        }

        $params = [
            'jsonResponse' => true,
            'amount' => sprintf('%.2f', $order['gateway_amount_minor'] / 100),
            'currency' => $order['gateway_currency'],
            'metadata' => [
                'orderId' => $order['trade_no']
            ]
        ];

        $params_string = @json_encode($params);

        $ret_raw = self::_curlPost($this->config['btcpay_url'] . 'api/v1/stores/' . $this->config['btcpay_storeId'] . '/invoices', $params_string);

        $ret = @json_decode($ret_raw, true);
        
        if(empty($ret['checkoutLink'])) {
            abort(500, "error!");
        }
        return [
            'type' => 1, // Redirect to url
            'data' => $ret['checkoutLink'],
        ];
    }

    public function notify($params) {
        $payload = trim(request()->getContent() ?: json_encode($_POST));
        $json_param = json_decode($payload, true);
        if (!is_array($json_param)) return false;
        $computedSignature = "sha256=" . \hash_hmac('sha256', $payload, $this->config['btcpay_webhook_key']);
        if (!self::hashEqual($this->header('Btcpay-Sig'), $computedSignature)) {
            return false;
        }

        // A valid signature only proves the event was sent by BTCPay. Fulfilment
        // is restricted to the final settlement event and a server-side invoice check.
        if (($json_param['type'] ?? null) !== 'InvoiceSettled' || empty($json_param['invoiceId'])) {
            return 'success';
        }
        $invoiceDetail = $this->invoice((string)$json_param['invoiceId']);
        if (!$invoiceDetail || ($invoiceDetail['status'] ?? null) !== 'Settled'
            || strtoupper((string)($invoiceDetail['currency'] ?? '')) !== 'CNY'
            || empty($invoiceDetail['metadata']['orderId'])
            || !isset($invoiceDetail['amount']) || !is_numeric($invoiceDetail['amount'])) {
            return false;
        }

        return [
            'trade_no' => (string)$invoiceDetail['metadata']['orderId'],
            'callback_no' => (string)$json_param['invoiceId'],
            'paid_amount_minor' => (int)round((float)$invoiceDetail['amount'] * 100),
            'currency' => 'CNY'
        ];
    }

    private function invoice(string $invoiceId): ?array
    {
        $url = rtrim((string)$this->config['btcpay_url'], '/') . '/api/v1/stores/'
            . rawurlencode((string)$this->config['btcpay_storeId']) . '/invoices/' . rawurlencode($invoiceId);
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Authorization: token " . $this->config['btcpay_api_key'] . "\r\nAccept: application/json\r\n",
            'timeout' => 15,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $data = is_string($body) ? json_decode($body, true) : null;
        return is_array($data) ? $data : null;
    }

    private function header(string $name): string
    {
        $value = request()->header($name);
        if ($value !== null) return (string)$value;
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $header => $value) {
            if (strcasecmp($header, $name) === 0) return (string)$value;
        }
        return '';
    }


    private function _curlPost($url,$params=false){
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch, CURLOPT_HTTPHEADER, array('Authorization:' .'token '.$this->config['btcpay_api_key'], 'Content-Type: application/json')
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }


    /**
     * @param string $str1
     * @param string $str2
     * @return bool
     */
    private function hashEqual($str1, $str2)
    {   

        if (function_exists('hash_equals')) {
            return \hash_equals($str1, $str2);
        }

        if (strlen($str1) != strlen($str2)) {
            return false;
        } else {
            $res = $str1 ^ $str2;
            $ret = 0;

            for ($i = strlen($res) - 1; $i >= 0; $i--) {
                $ret |= ord($res[$i]);
            }
            return !$ret;
        }
    }
    
}

