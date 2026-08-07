<?php

namespace App\Payments;

class EPay {
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'url' => [
                'label' => 'URL',
                'description' => '',
                'type' => 'input',
            ],
            'pid' => [
                'label' => 'PID',
                'description' => '',
                'type' => 'input',
            ],
            'key' => [
                'label' => 'KEY',
                'description' => '',
                'type' => 'input',
            ],
            'type' => [
                'label' => 'TYPE',
                'description' => __('支付类型，如: alipay, wxpay, qqpay'),
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        if (($order['gateway_currency'] ?? null) !== 'CNY') {
            abort(500, 'EPay only supports CNY payments');
        }
        $params = [
            'money' => $order['gateway_amount_minor'] / 100,
            'name' => $order['display_trade_no'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'out_trade_no' => $order['trade_no'],
            'pid' => $this->config['pid']
        ];
        if (!empty($this->config['type'])) {
            $params['type'] = $this->config['type'];
        }
        $params['sign'] = $this->sign($params);
        $params['sign_type'] = 'MD5';
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $this->config['url'] . '/submit.php?' . http_build_query($params)
        ];
    }

    public function notify($params)
    {
        if (!is_array($params) || empty($params['sign']) || empty($this->config['pid']) || empty($this->config['key'])) {
            return false;
        }
        $sign = strtolower(trim((string)$params['sign']));
        if (!hash_equals($this->sign($params), $sign)) return false;

        $tradeStatus = strtoupper(trim((string)($params['trade_status'] ?? '')));
        if (!in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return false;
        }
        if (empty($params['out_trade_no']) || empty($params['trade_no'])
            || !hash_equals((string)$this->config['pid'], (string)($params['pid'] ?? ''))) return false;

        // #25：回传实付金额（分）供 handle() 校验欠款。EPay 异步通知回显下单时提交的 money（元），
        // 且 money 已在验签范围内（sign 覆盖全部非空参数），不可被篡改。
        if (!isset($params['money']) || !is_numeric($params['money'])) return false;
        $paidAmount = (int) round(((float) $params['money']) * 100);

        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no'],
            'paid_amount_minor' => $paidAmount,
            'currency' => 'CNY'
        ];
    }

    private function sign(array $params): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $parts = [];
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null || is_array($value) || is_object($value)) continue;
            $parts[] = $key . '=' . (string)$value;
        }
        return md5(implode('&', $parts) . (string)$this->config['key']);
    }
}
