<?php

namespace App\Services;


use App\Models\Payment;

class PaymentService
{
    public $method;
    protected $class;
    protected $config;
    protected $payment;

    public function __construct($method, $id = NULL, $uuid = NULL)
    {
        $this->method = $method;
        $this->class = '\\App\\Payments\\' . $this->method;
        if (!class_exists($this->class)) abort(500, 'gate is not found');
        if ($id) $payment = Payment::find($id)->toArray();
        if ($uuid) {
            // 支付回调路由 /guest/payment/notify/{method}/{uuid} 的两段来自不同来源、此前互不校验：
            // {method} 决定加载哪个驱动类，{uuid} 决定加载哪一行配置。攻击者可用 A 驱动去加载 B 的
            // 配置行 —— 若 A 驱动读密钥时用了 `?? ''`（Epusdt/Bepusdt），缺键即空串验签，就能对任意
            // 订单伪造「已支付」回调（凭空造币）。这里强制：uuid 指向的支付方式，其库里记录的驱动
            // 必须与 URL 里的 {method} 完全一致，否则一律拒绝。错误信息与「未找到」保持一致，不给
            // 攻击者「驱动不匹配 vs uuid 不存在」的区分探针。
            $row = Payment::where('uuid', $uuid)->first();
            if (!$row) abort(500, 'gate is not found');
            $payment = $row->toArray();
            if (($payment['payment'] ?? null) !== $this->method) {
                abort(500, 'gate is not found');
            }
        }
        $this->config = [];
        if (isset($payment)) {
            $this->config = $payment['config'];
            $this->config['enable'] = $payment['enable'];
            $this->config['id'] = $payment['id'];
            $this->config['uuid'] = $payment['uuid'];
            $this->config['notify_domain'] = $payment['notify_domain'];
        };
        $this->payment = new $this->class($this->config);
    }

    public function notify($params)
    {
        if (!$this->config['enable']) abort(500, 'gate is not enable');
        return $this->payment->notify($params);
    }

    public function pay($order)
    {
        // custom notify domain name
        $notifyUrl = url("/api/v1/guest/payment/notify/{$this->method}/{$this->config['uuid']}");
        if ($this->config['notify_domain']) {
            $parseUrl = parse_url($notifyUrl);
            $notifyUrl = $this->config['notify_domain'] . $parseUrl['path'];
        }

        return $this->payment->pay([
            'notify_url' => $notifyUrl,
            'return_url' => url('/#/order/' . $order['trade_no']),
            'trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'stripe_token' => $order['stripe_token']
        ]);
    }

    public function form()
    {
        $form = $this->payment->form();
        $keys = array_keys($form);
        foreach ($keys as $key) {
            if (isset($this->config[$key])) $form[$key]['value'] = $this->config[$key];
        }
        return $form;
    }
}
