<?php

/**
 * 自己写别抄，抄NMB抄
 */
namespace App\Payments;

class AlipayF2F {
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'app_id' => [
                'label' => __('支付宝APPID'),
                'description' => '',
                'type' => 'input',
            ],
            'private_key' => [
                'label' => __('支付宝私钥'),
                'description' => '',
                'type' => 'input',
            ],
            'public_key' => [
                'label' => __('支付宝公钥'),
                'description' => '',
                'type' => 'input',
            ],
            'seller_id' => [
                'label' => __('Alipay seller ID'),
                'description' => __('Required to bind signed callbacks to this merchant account'),
                'type' => 'input',
            ],
            'product_name' => [
                'label' => __('自定义商品名称'),
                'description' => __('将会体现在支付宝账单中'),
                'type' => 'input'
            ]
        ];
    }

    public function pay($order)
    {
        try {
            if (($order['gateway_currency'] ?? null) !== 'CNY') {
                throw new \RuntimeException('Alipay only supports CNY payments');
            }
            $gateway = new \Library\AlipayF2F();
            $gateway->setMethod('alipay.trade.precreate');
            $gateway->setAppId($this->config['app_id']);
            $gateway->setPrivateKey($this->config['private_key']); // 可以是路径，也可以是密钥内容
            $gateway->setAlipayPublicKey($this->config['public_key']); // 可以是路径，也可以是密钥内容
            $gateway->setNotifyUrl($order['notify_url']);
            $gateway->setBizContent([
                'subject' => $this->config['product_name'] ?? (config('v2board.app_name', 'V2Board') . ' - 订阅'),
                'out_trade_no' => $order['trade_no'],
                'total_amount' => $order['gateway_amount_minor'] / 100
            ]);
            $gateway->send();
            return [
                'type' => 0, // 0:qrcode 1:url
                'data' => $gateway->getQrCodeUrl()
            ];
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }
    }

    public function notify($params)
    {
        if (!is_array($params) || ($params['trade_status'] ?? null) !== 'TRADE_SUCCESS') return false;
        if (($params['app_id'] ?? null) !== ($this->config['app_id'] ?? null)) return false;
        if (empty($this->config['seller_id']) || !hash_equals((string)$this->config['seller_id'], (string)($params['seller_id'] ?? ''))) return false;
        if (!isset($params['total_amount']) || !is_numeric($params['total_amount'])) return false;
        $gateway = new \Library\AlipayF2F();
        $gateway->setAppId($this->config['app_id']);
        $gateway->setPrivateKey($this->config['private_key']); // 可以是路径，也可以是密钥内容
        $gateway->setAlipayPublicKey($this->config['public_key']); // 可以是路径，也可以是密钥内容
        try {
            if ($gateway->verify($params)) {
                /**
                 * Payment is successful
                 */
                return [
                    'trade_no' => $params['out_trade_no'],
                    'callback_no' => $params['trade_no'],
                    'paid_amount_minor' => (int)round((float)$params['total_amount'] * 100),
                    'currency' => 'CNY'
                ];
            } else {
                /**
                 * Payment is not successful
                 */
                return false;
            }
        } catch (\Exception $e) {
            /**
             * Payment is not successful
             */
            return false;
        }
    }
}
