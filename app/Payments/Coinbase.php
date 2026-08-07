<?php

namespace App\Payments;

class Coinbase {
    public function __construct($config) {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'coinbase_url' => [
                'label' => __('接口地址'),
                'description' => '',
                'type' => 'input',
            ],
            'coinbase_api_key' => [
                'label' => 'API KEY',
                'description' => '',
                'type' => 'input',
            ],
            'coinbase_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'description' => '',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order) {

        if (($order['gateway_currency'] ?? null) !== 'CNY') {
            abort(500, 'Coinbase only supports CNY payments');
        }

        $params = [
            'name' => '订阅套餐',
            'description' => '订单号 ' . $order['display_trade_no'],
            'pricing_type' => 'fixed_price',
            'local_price' => [
                'amount' => sprintf('%.2f', $order['gateway_amount_minor'] / 100),
                'currency' => $order['gateway_currency']
            ],
            'metadata' => [
                "outTradeNo" => $order['trade_no'],
            ],
        ];

        $params_string = http_build_query($params);
        
        $ret_raw = self::_curlPost($this->config['coinbase_url'], $params_string);

        $ret = @json_decode($ret_raw, true);
        
        if(empty($ret['data']['hosted_url'])) {
            abort(500, "error!");
        }
        return [
            'type' => 1,
            'data' => $ret['data']['hosted_url'],
        ];
    }

    public function notify($params) {
        
        $payload = trim(request()->getContent() ?: json_encode($_POST));
        $json_param = json_decode($payload, true); 
        if (!is_array($json_param)) {
            return false;
        }


        $computedSignature = \hash_hmac('sha256', $payload, $this->config['coinbase_webhook_key']);

        if (!self::hashEqual($this->header('X-Cc-Webhook-Signature'), $computedSignature)) {
            return false;
        }

        if (($json_param['event']['type'] ?? null) !== 'charge:confirmed') {
            return 'success';
        }
        $eventData = $json_param['event']['data'] ?? [];
        $code = (string)($eventData['code'] ?? '');
        if ($code === '') return false;
        $charge = $this->charge($code);
        $timeline = $charge['timeline'] ?? [];
        $last = is_array($timeline) && $timeline ? end($timeline) : null;
        $local = $charge['pricing']['local'] ?? [];
        if (!is_array($last) || ($last['status'] ?? null) !== 'COMPLETED'
            || !isset($local['amount']) || !is_numeric($local['amount'])
            || strtoupper((string)($local['currency'] ?? '')) !== 'CNY'
            || empty($charge['metadata']['outTradeNo'])) {
            return false;
        }

        return [
            'trade_no' => (string)$charge['metadata']['outTradeNo'],
            'callback_no' => (string)($charge['id'] ?? $json_param['event']['id'] ?? ''),
            'paid_amount_minor' => (int)round((float)$local['amount'] * 100),
            'currency' => 'CNY'
        ];
    }

    private function charge(string $code): ?array
    {
        $base = rtrim((string)$this->config['coinbase_url'], '/');
        $url = preg_match('#/charges$#', $base) ? $base . '/' . rawurlencode($code) : $base . '/charges/' . rawurlencode($code);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-CC-Api-Key: ' . $this->config['coinbase_api_key'], 'X-CC-Version: 2018-03-22']);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = is_string($body) ? json_decode($body, true) : null;
        return $status >= 200 && $status < 300 && is_array($data['data'] ?? null) ? $data['data'] : null;
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
            $ch, CURLOPT_HTTPHEADER, array('X-CC-Api-Key:' .$this->config['coinbase_api_key'], 'X-CC-Version: 2018-03-22')
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
    public function hashEqual($str1, $str2)
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

