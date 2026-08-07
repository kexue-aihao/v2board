<?php

namespace App\Payments;

class CoinPayments {
    public function __construct($config) {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'coinpayments_merchant_id' => [
                'label' => 'Merchant ID',
                'description' => __('商户 ID，填写您在 Account Settings 中得到的 ID'),
                'type' => 'input',
            ],
            'coinpayments_ipn_secret' => [
                'label' => 'IPN Secret',
                'description' => __('通知密钥，填写您在 Merchant Settings 中自行设置的值'),
                'type' => 'input',
            ],
            'coinpayments_currency' => [
                'label' => __('货币代码'),
                'description' => __('填写您的货币代码（大写），建议与 Merchant Settings 中的值相同'),
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {

        // IPN notifications are slow, when the transaction is successful, we should return to the user center to avoid user confusion
        $parseUrl = parse_url($order['return_url']);
        $port = isset($parseUrl['port']) ? ":{$parseUrl['port']}" : '';
        $successUrl = "{$parseUrl['scheme']}://{$parseUrl['host']}{$port}";

        $params = [
            'cmd' => '_pay_simple',
            'reset' => 1,
            'merchant' => $this->config['coinpayments_merchant_id'],
            'item_name' => $order['display_trade_no'],
            'item_number' => $order['trade_no'],
            'want_shipping' => 0,
            'currency' => $order['gateway_currency'],
            'amountf' => sprintf('%.2f', $order['gateway_amount_minor'] / 100),
            'success_url' => $successUrl,
            'cancel_url' => $order['return_url'],
            'ipn_url' => $order['notify_url']
        ];

        $params_string = http_build_query($params);

        return [
            'type' => 1, // Redirect to url
            'data' =>  'https://www.coinpayments.net/index.php?' . $params_string
        ];
    }

    public function prepare($order)
    {
        $currency = strtoupper(trim((string)($this->config['coinpayments_currency'] ?? '')));
        if (!preg_match('/^[A-Z0-9]{3,12}$/', $currency)) {
            throw new \RuntimeException('CoinPayments currency is invalid');
        }
        return [
            'amount_minor' => (int)$order['total_amount'],
            'currency' => $currency
        ];
    }

    public function notify($params)
    {

        if (empty($this->config['coinpayments_merchant_id']) || empty($this->config['coinpayments_ipn_secret'])
            || !isset($params['merchant']) || !hash_equals(trim((string)$this->config['coinpayments_merchant_id']), (string)$params['merchant'])) {
            return false;
        }

        $headers = function_exists('getallheaders') ? getallheaders() : [];

        ksort($params);
        reset($params);
        $request = stripslashes(http_build_query($params));

        $headerName = 'Hmac';
        $signHeader = request()->header($headerName) ?: (isset($headers[$headerName]) ? $headers[$headerName] : '');

        $hmac = hash_hmac("sha512", $request, trim($this->config['coinpayments_ipn_secret']));

        // if ($hmac != $signHeader) { <-- Use this if you are running a version of PHP below 5.6.0 without the hash_equals function
        //     abort(400, 'HMAC signature does not match');
        // }

        if (!hash_equals($hmac, $signHeader)) {
            return false;
        }

        // HMAC Signature verified at this point, load some variables.
        if (!isset($params['status'], $params['item_number'], $params['txn_id'], $params['amount1'], $params['currency1'])
            || !is_numeric($params['amount1'])) {
            return false;
        }
        $status = (int)$params['status'];
        if ($status >= 100 || $status == 2) {
            // payment is complete or queued for nightly payout, success
            return [
                'trade_no' => $params['item_number'],
                'callback_no' => $params['txn_id'],
                'paid_amount_minor' => (int)round((float)$params['amount1'] * 100),
                'currency' => strtoupper((string)$params['currency1']),
                'custom_result' => 'IPN OK'
            ];
        } else if ($status < 0) {
            //payment error, this is usually final but payments will sometimes be reopened if there was no exchange rate conversion or with seller consent
            abort(500, 'Payment Timed Out or Error');
        } else {
            //payment is pending, you can optionally add a note to the order page
            return('IPN OK: pending');
        }
    }
}
