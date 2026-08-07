<?php

namespace App\Payments;

use \Curl\Curl;

class Bepusdt
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'bepusdt_url' => [
                'label' => __('API 地址'),
                'description' => __('BEpusdt 服务地址(例如: https://pay.example.com)，只填到域名，不要带路径'),
                'type' => 'input',
            ],
            'bepusdt_token' => [
                'label' => 'API Token',
                'description' => __('BEpusdt 配置文件里的 auth_token'),
                'type' => 'input',
            ],
            'bepusdt_trade_type' => [
                'label' => __('交易类型'),
                'description' => __('留空时进入 BEpusdt 收银台由用户自选币种，填写时按该类型直接发起订单。可选: :options', ['options'
                    => 'usdt.trc20 / usdc.trc20 / tron.trx / usdt.erc20 / usdc.erc20 / ethereum.eth / '
                    . 'usdt.polygon / usdc.polygon / usdt.bep20 / usdc.bep20 / bsc.bnb / usdt.aptos / usdc.aptos / '
                    . 'usdt.solana / usdc.solana / usdt.xlayer / usdc.xlayer / usdt.arbitrum / usdc.arbitrum / '
                    . 'usdc.base / usdt.plasma / usdt.ton / ton.gram']),
                'type' => 'input',
            ],
            'bepusdt_fiat' => [
                'label' => __('法币'),
                'description' => __('默认 CNY，可选 CNY / USD / EUR / GBP / JPY'),
                'type' => 'input',
            ],
            'bepusdt_timeout' => [
                'label' => __('超时秒数'),
                'description' => __('留空默认 600。指定交易类型时最小 120，走收银台时最小 180'),
                'type' => 'input',
            ],
            'bepusdt_rate' => [
                'label' => __('汇率'),
                'description' => __('可选。7.4 固定汇率 / ~1.02 上浮百分比 / +0.3 固定加价'),
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {
        if (($order['gateway_currency'] ?? null) !== 'CNY') {
            abort(500, 'BEpusdt cannot securely quote a non-CNY payment');
        }
        $tradeType = strtolower(trim((string) ($this->config['bepusdt_trade_type'] ?? '')));

        $params = [
            'order_id' => (string) $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'redirect_url' => $order['return_url'],
            // v2board 的 total_amount 存的是分，BEpusdt 收的是法币元。
            'amount' => round($order['gateway_amount_minor'] / 100, 2),
            'name' => (string) $order['display_trade_no'],
        ];

        $fiat = strtoupper(trim((string) ($this->config['bepusdt_fiat'] ?? '')));
        if ($fiat !== '') {
            $params['fiat'] = $fiat;
        }
        $timeout = trim((string) ($this->config['bepusdt_timeout'] ?? ''));
        if ($timeout !== '') {
            $params['timeout'] = (int) $timeout;
        }
        $rate = trim((string) ($this->config['bepusdt_rate'] ?? ''));
        if ($rate !== '') {
            $params['rate'] = $rate;
        }

        // 交易类型留空走收银台让用户自选币种，填写则直接按该类型下单 —— 与 Epusdt 的 network 同一约定。
        $path = '/api/v1/order/create-order';
        if ($tradeType !== '') {
            $params['trade_type'] = $tradeType;
            $path = '/api/v1/order/create-transaction';
        }

        $params['signature'] = $this->makeSignature($params, trim((string) ($this->config['bepusdt_token'] ?? '')));

        $curl = new Curl();
        $curl->setUserAgent('bepusdt');
        $curl->setOpt(CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $curl->post(
            rtrim((string) ($this->config['bepusdt_url'] ?? ''), '/') . $path,
            json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $result = $curl->response;
        // 这里刻意不关掉证书校验：真正护着钱的是回调验签，但 payment_url 决定用户被送去哪个
        // 收款页，中间人改掉它就能把付款引走。
        // $curl->error 对 HTTP 4xx/5xx 也为真，所以不能拿它直接当「传输失败」——那样会把
        // BEpusdt 回的业务错误信息吞掉。判据改成「有没有回出可识别的应答体」：
        // 有就用它的 message，没有才把 curl/HTTP 的原文抛出来，免得证书、DNS、超时
        // 都退化成一句看不出原因的「下单失败」。errorMessage 用 ?? 兜底，属性不存在也不报警。
        $errorMessage = $curl->error ? trim((string) ($curl->errorMessage ?? '')) : '';
        $curl->close();

        if (!isset($result->status_code)) {
            abort(500, 'bepusdt request failed: ' . ($errorMessage !== '' ? $errorMessage : 'unrecognized response'));
        }

        if ((int) $result->status_code !== 200) {
            $message = isset($result->message) && $result->message !== ''
                ? (string) $result->message
                : 'bepusdt create order failed';
            abort(500, $message);
        }

        $paymentUrl = $result->data->payment_url ?? null;
        if (empty($paymentUrl)) {
            abort(500, 'bepusdt payment url missing');
        }

        return [
            'type' => 1,
            'data' => $paymentUrl,
        ];
    }

    public function notify($params)
    {
        if (!isset($params['signature'], $params['order_id'])) {
            return false;
        }

        // 空密钥无法安全验签：缺 token 时直接拒绝，而不是用空串继续算签名（纵深防御，见 PaymentService）。
        $token = trim((string) ($this->config['bepusdt_token'] ?? ''));
        if ($token === '') {
            return false;
        }

        $signature = strtolower((string) $params['signature']);
        unset($params['signature']);

        if (!hash_equals($this->makeSignature($params, $token), $signature)) {
            return false;
        }

        // status: 1=等待支付 2=支付成功 3=支付超时。
        // 只有 2 入账。v2board 的 notify 契约只有「入账」和 abort(500) 两条路 —— handle() 对
        // status===0 的订单必定调 paid()，没有「确认收到但不入账」这一档，所以非成功状态只能
        // 回 false（→ 500）。BEpusdt 对未支付订单每分钟推一次 status=1，但文档明确 status=1/3
        // 不重试，因此只是日志噪音，不会形成重试风暴。
        if (!isset($params['status'], $params['amount'], $params['order_id'])
            || !is_numeric($params['amount']) || (int) $params['status'] !== 2) {
            return false;
        }

        // 用链上交易哈希做 callback_no，对账时比内部 trade_id 有价值；拿不到再退回 trade_id。
        $blockTxId = isset($params['block_transaction_id']) ? trim((string) $params['block_transaction_id']) : '';

        return [
            'trade_no' => $params['order_id'],
            'callback_no' => $blockTxId !== '' ? $blockTxId : (string) ($params['trade_id'] ?? ''),
            'paid_amount_minor' => (int)round((float)$params['amount'] * 100),
            'currency' => strtoupper(trim((string)($this->config['bepusdt_fiat'] ?? '')) ?: 'CNY'),
        ];
    }

    private function makeSignature($params, $token)
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($key === 'signature' || $value === '' || $value === null) {
                continue;
            }

            // BEpusdt 的 EpusdtSign 用 fmt.Sprintf("%v", v) 把值转字符串，float64 的 %v 等价于
            // %g：10.0 → "10"、28.88 → "28.88"，下面这行与之等价。
            // 已知边界：amount >= 1000000 时 Go 会输出 "1e+06"（shortest 模式下 eprec=6，
            // exp >= eprec 就切科学计数法），而这里给的是 "1000000" —— 那种金额会验签失败。
            // v2board 的订单金额不会到这个量级，但改动金额上限前要先解决这一点。
            if (is_float($value) || is_int($value)) {
                $value = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
                $value = $value === '' ? '0' : $value;
            }

            $pairs[] = $key . '=' . (string) $value;
        }

        return strtolower(md5(implode('&', $pairs) . $token));
    }
}
