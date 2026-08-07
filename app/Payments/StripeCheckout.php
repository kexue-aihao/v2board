<?php

namespace App\Payments;

use Stripe\Stripe;
use Stripe\Checkout\Session;

class StripeCheckout {
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'currency' => [
                'label' => __('货币单位'),
                'description' => '',
                'type' => 'input',
            ],
            'stripe_sk_live' => [
                'label' => 'SK_LIVE',
                'description' => __('API 密钥'),
                'type' => 'input',
            ],
            'stripe_pk_live' => [
                'label' => 'PK_LIVE',
                'description' => __('API 公钥'),
                'type' => 'input',
            ],
            'stripe_webhook_key' => [
                'label' => __('WebHook 密钥签名'),
                'description' => '',
                'type' => 'input',
            ],
            'stripe_custom_field_name' => [
                'label' => __('自定义字段名称'),
                'description' => __('例如可设置为“联系方式”，以便及时与客户取得联系'),
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $currency = $order['gateway_currency'];
        $customFieldName = isset($this->config['stripe_custom_field_name']) ? $this->config['stripe_custom_field_name'] : 'Contact Infomation';

        $params = [
            'success_url' => $order['return_url'],
            'cancel_url' => $order['return_url'],
            'client_reference_id' => $order['trade_no'],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => $order['display_trade_no']
                        ],
                        'unit_amount' => $order['gateway_amount_minor']
                    ],
                    'quantity' => 1
                ]
            ],
            'mode' => 'payment',
            'invoice_creation' => ['enabled' => true],
            'phone_number_collection' => ['enabled' => true],
            'custom_fields' => [
                [
                    'key' => 'contactinfo',
                    'label' => ['type' => 'custom', 'custom' => $customFieldName],
                    'type' => 'text',
                ],
            ],
            // 'customer_email' => $user['email'] not support

        ];

        Stripe::setApiKey($this->config['stripe_sk_live']);
        try {
            $session = Session::create($params);
        } catch (\Exception $e) {
            abort(500, 'Failed to create payment session');
        }
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $session->url
        ];
    }

    public function prepare($order)
    {
        $currency = strtoupper(trim((string)$this->config['currency']));
        $exchange = $this->exchange('CNY', $currency);
        if (!$exchange) {
            throw new \RuntimeException('Currency conversion has timed out');
        }
        return [
            'amount_minor' => (int)floor($order['total_amount'] * $exchange),
            'currency' => $currency
        ];
    }

    public function notify($params)
    {
        \Stripe\Stripe::setApiKey($this->config['stripe_sk_live']);
        try {
            $event = \Stripe\Webhook::constructEvent(
                request()->getContent() ?: json_encode($_POST),
                request()->header('Stripe-Signature', ''),
                $this->config['stripe_webhook_key']
            );
        } catch (\Stripe\Error\SignatureVerification $e) {
            abort(400);
        }

        switch ($event->type) {
            case 'checkout.session.completed':
                $object = $event->data->object;
                if ($object->payment_status === 'paid') {
                    return [
                        'trade_no' => $object->client_reference_id,
                        'callback_no' => $object->payment_intent,
                        'paid_amount_minor' => (int)$object->amount_total,
                        'currency' => strtoupper((string)$object->currency)
                    ];
                }
                break;
            case 'checkout.session.async_payment_succeeded':
                $object = $event->data->object;
                if ($object->payment_status === 'paid') {
                    return [
                        'trade_no' => $object->client_reference_id,
                        'callback_no' => $object->payment_intent,
                        'paid_amount_minor' => (int)$object->amount_total,
                        'currency' => strtoupper((string)$object->currency)
                    ];
                }
                break;
            default:
                abort(500, 'event is not support');
        }
        return('success');
    }

    private function exchange($from, $to)
    {
        $result = file_get_contents("https://api.exchangerate-api.com/v4/latest/{$from}");
        $result = json_decode($result, true);
        return $result['rates'][$to];
    }
}
