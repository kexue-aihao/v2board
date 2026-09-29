<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;

/**
 * 机器人内的自助购买。
 *
 * 下单这一段与 OrderController::save 保持同样的校验（容量、周期价、续费规则、重置包前置条件）
 * 和同样的资金处理（VIP 折扣、余额抵扣），但按机器人场景砍掉了优惠券与多订阅选择 ——
 * 机器人只做「选套餐 → 选周期 → 选支付方式」。支付网关那一段与网页共用 OrderPaymentService。
 *
 * 之所以是复制而不是把 OrderController::save 抽出来共用：那条路径是站内的核心收款链路，
 * 抽错了影响面远大于机器人多一段重复校验。两处校验若将来出现分歧，以网页那条为准。
 */
class TelegramShopService
{
    private const PERIODS = [
        'month_price' => '月付',
        'quarter_price' => '季付',
        'half_year_price' => '半年付',
        'year_price' => '年付',
        'onetime_price' => '一次性',
        'reset_price' => '重置流量包',
    ];

    private $telegram;

    public function __construct(?TelegramService $telegram = null)
    {
        $this->telegram = $telegram ?: new TelegramService();
    }

    /**
     * 回调入口。返回 true 表示这条回调属于商店（调用方据此决定是否再交给娱乐模块）。
     */
    public function handleCallback(array $callback): bool
    {
        $data = (string)($callback['data'] ?? '');
        if (strpos($data, 'shop:') !== 0) {
            return false;
        }

        $chatId = (int)($callback['message']['chat']['id'] ?? 0);
        $queryId = (string)($callback['id'] ?? '');
        // 先应答，否则按钮会一直转圈
        try { $this->telegram->answerCallbackQuery($queryId); } catch (\Throwable $ignored) {}
        if ($chatId === 0) {
            return true;
        }

        try {
            if ($data === 'shop:plans') {
                $this->showPlans($chatId);
            } elseif (preg_match('/^shop:plan:(\d+)$/', $data, $match)) {
                $this->showPeriods($chatId, (int)$match[1]);
            } elseif (preg_match('/^shop:buy:(\d+):([a-z_]+)$/', $data, $match)) {
                $this->createOrder($chatId, (int)$match[1], $match[2]);
            } elseif (preg_match('/^shop:pay:([A-Za-z0-9]+):(\d+)$/', $data, $match)) {
                $this->pay($chatId, $match[1], (int)$match[2]);
            } else {
                $this->send($chatId, '这个按钮已经失效，请重新发送 /get_subscription');
            }
        } catch (\Throwable $e) {
            report($e);
            $this->send($chatId, '操作失败：' . $e->getMessage());
        }
        return true;
    }

    /** 入口：列出在售套餐 */
    public function showPlans(int $chatId): void
    {
        if (!$this->requireUser($chatId)) {
            return;
        }
        $plans = Plan::where('show', 1)->orderBy('sort', 'ASC')->get();
        if ($plans->isEmpty()) {
            $this->send($chatId, '当前没有在售套餐。');
            return;
        }
        $buttons = [];
        foreach ($plans as $plan) {
            $buttons[] = [$this->button($plan->name . $this->priceHint($plan), 'shop:plan:' . $plan->id)];
        }
        $this->send($chatId, '请选择套餐：', $buttons);
    }

    /** 列出该套餐可买的周期 */
    public function showPeriods(int $chatId, int $planId): void
    {
        if (!$this->requireUser($chatId)) {
            return;
        }
        $plan = Plan::find($planId);
        if (!$plan || !$plan->show) {
            $this->send($chatId, '该套餐已下架，请重新选择。');
            return;
        }
        $buttons = [];
        foreach (self::PERIODS as $field => $label) {
            $price = $plan->$field;
            if ($price === null || $price === '') {
                continue;
            }
            $buttons[] = [$this->button($label . '  ' . $this->formatAmount($price), 'shop:buy:' . $plan->id . ':' . $field)];
        }
        if (!$buttons) {
            $this->send($chatId, '该套餐暂无可购买的周期。');
            return;
        }
        $buttons[] = [$this->button('返回套餐列表', 'shop:plans')];
        $this->send($chatId, $plan->name . "\n请选择购买周期：", $buttons);
    }

    /** 建单并列出支付方式 */
    public function createOrder(int $chatId, int $planId, string $period): void
    {
        $user = $this->requireUser($chatId);
        if (!$user) {
            return;
        }
        if (!isset(self::PERIODS[$period])) {
            $this->send($chatId, '购买周期无效，请重新选择。');
            return;
        }

        $userService = new UserService();
        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            $this->send($chatId, '你有一笔未完成的订单，请先在网站完成或取消后再下单。');
            return;
        }

        $planService = new PlanService($planId);
        $plan = $planService->plan;
        if (!$plan) {
            $this->send($chatId, '套餐不存在。');
            return;
        }
        if ($user->plan_id !== $plan->id && !$planService->haveCapacity() && $period !== 'reset_price') {
            $this->send($chatId, '该套餐已售罄。');
            return;
        }
        if ($plan->$period === null || $plan->$period === '') {
            $this->send($chatId, '该周期不可购买，请重新选择。');
            return;
        }
        if ($period === 'reset_price') {
            if (!$userService->isAvailable($user) || $plan->id !== $user->plan_id) {
                $this->send($chatId, '当前没有可重置的订阅，无法购买重置流量包。');
                return;
            }
        }
        if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
            if ($period !== 'reset_price') {
                $this->send($chatId, '该套餐已下架，请重新选择。');
                return;
            }
        }
        if (!$plan->renew && $user->plan_id == $plan->id && $period !== 'reset_price') {
            $this->send($chatId, '该套餐不支持续费，请选择其它套餐。');
            return;
        }

        try {
            $order = DB::transaction(function () use ($user, $plan, $period, $userService) {
                $order = new Order();
                $orderService = new OrderService($order);
                $order->user_id = $user->id;
                $order->plan_id = $plan->id;
                $order->period = $period;
                $order->trade_no = Helper::generateOrderNo();
                $order->total_amount = $plan->$period;
                $orderService->setVipDiscount($user);
                $orderService->setOrderType($user);

                // 余额抵扣与网页一致：抵扣到 0 就是免支付订单，由 OrderPaymentService 直接开通
                if ($user->balance > 0 && $order->total_amount > 0) {
                    $remainingBalance = $user->balance - $order->total_amount;
                    if ($remainingBalance > 0) {
                        if (!$userService->addBalance($order->user_id, -$order->total_amount, 'order_balance_pay', ['remark' => $order->trade_no])) {
                            abort(500, '余额扣减失败');
                        }
                        $order->balance_amount = $order->total_amount;
                        $order->total_amount = 0;
                    } else {
                        if (!$userService->addBalance($order->user_id, -$user->balance, 'order_balance_pay', ['remark' => $order->trade_no])) {
                            abort(500, '余额扣减失败');
                        }
                        $order->balance_amount = $user->balance;
                        $order->total_amount -= $user->balance;
                    }
                }

                $orderService->setInvite($user);
                if (!$order->save()) {
                    abort(500, '下单失败');
                }
                return $order;
            });
        } catch (\Throwable $e) {
            $this->send($chatId, '下单失败：' . $e->getMessage());
            return;
        }

        $methods = $this->paymentMethods();
        if (!$methods) {
            // 余额已覆盖全额时不需要支付方式，直接走与网页相同的免支付开通路径。
            if ((int)$order->total_amount <= 0) {
                try {
                    $result = (new OrderPaymentService())->initiate($order, null);
                    if (($result['type'] ?? null) !== -1) {
                        throw new \RuntimeException('免费订单开通失败');
                    }
                    $this->send($chatId, '订单已开通，可发送 /get_traffic 查看订阅。');
                } catch (\Throwable $e) {
                    report($e);
                    // 免支付开通失败时订单仍是待支付，退回本次已抵扣的余额，避免用户余额
                    // 被一笔无法完成的机器人订单长期冻结。
                    try {
                        (new PaymentAttemptService())->cancelOrder($order, 'free order opening failed');
                    } catch (\Throwable $cancelError) {
                        report($cancelError);
                    }
                    $this->send($chatId, '订单开通失败：' . $e->getMessage());
                }
                return;
            }

            // 建单时可能刚好没有启用的支付驱动。取消订单会退回本次已抵扣的余额，
            // 避免机器人留下一个永久阻塞后续下单的待支付订单。
            (new PaymentAttemptService())->cancelOrder($order, 'no payment method available');
            $this->send($chatId, '当前没有可用的支付方式，请联系管理员。');
            return;
        }
        $buttons = [];
        foreach ($methods as $method) {
            $buttons[] = [$this->button($method->name, 'shop:pay:' . $order->trade_no . ':' . $method->id)];
        }
        $this->send(
            $chatId,
            "订单已创建\n"
            . '套餐：' . $plan->name . '（' . self::PERIODS[$period] . "）\n"
            . '应付：' . $this->formatAmount($order->total_amount) . "\n"
            . '订单号：' . $order->trade_no . "\n\n请选择支付方式：",
            $buttons
        );
    }

    /** 发起支付并把支付链接/二维码交给用户 */
    public function pay(int $chatId, string $tradeNo, int $paymentId): void
    {
        $user = $this->requireUser($chatId);
        if (!$user) {
            return;
        }
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $user->id)
            ->where('status', 0)
            ->first();
        if (!$order) {
            $this->send($chatId, '订单不存在或已支付。');
            return;
        }

        try {
            $result = (new OrderPaymentService())->initiate($order, $paymentId);
        } catch (\Throwable $e) {
            report($e);
            $this->send($chatId, '发起支付失败：' . $e->getMessage());
            return;
        }

        $type = $result['type'] ?? -1;
        $data = (string)($result['data'] ?? '');
        if ($type === -1) {
            $this->send($chatId, '订单已开通，可发送 /get_traffic 查看订阅。');
            return;
        }
        if ($type === 1) {
            if (!$this->isHttpUrl($data)) {
                $this->send($chatId, "请在浏览器打开以下链接完成支付：\n" . $data);
                return;
            }
            $this->send($chatId, '订单号：' . $order->trade_no . "\n点击下方按钮完成支付：", [
                [['text' => '去支付', 'url' => $data]]
            ]);
            return;
        }
        // type 0：二维码内容（通常是支付串或链接）
        $text = "订单号：" . $order->trade_no . "\n请扫码或复制以下内容完成支付：\n" . $data;
        if ($this->isHttpUrl($data)) {
            $this->send($chatId, $text, [['text' => '打开支付页', 'url' => $data]]);
            return;
        }
        $this->send($chatId, $text);
    }

    /* ---------------- 内部 ---------------- */

    private function requireUser(int $chatId): ?User
    {
        $user = User::where('telegram_id', $chatId)->first();
        if ($user) {
            return $user;
        }
        $this->send(
            $chatId,
            "本 Telegram 还没有绑定账号。\n"
            . "还没有账号：发送 /regedit 注册。\n"
            . "已有账号：发送 /login 生成免密登录链接。"
        );
        return null;
    }

    private function paymentMethods()
    {
        return Payment::where('enable', 1)
            ->orderBy('sort', 'ASC')
            ->get()
            ->filter(function ($payment) {
                return PaymentAttemptService::isDriverAvailable((string)$payment->payment);
            })
            ->values();
    }

    private function priceHint(Plan $plan): string
    {
        foreach (['month_price', 'quarter_price', 'half_year_price', 'year_price', 'onetime_price'] as $field) {
            if ($plan->$field !== null && $plan->$field !== '') {
                return '  ' . $this->formatAmount($plan->$field) . '起';
            }
        }
        return '';
    }

    private function isHttpUrl(string $value): bool
    {
        return (bool)preg_match('#^https?://#i', $value);
    }

    private function formatAmount($amount): string
    {
        return number_format(((int)$amount) / 100, 2, '.', '') . ' 元';
    }

    private function button(string $text, string $callbackData): array
    {
        return ['text' => $text, 'callback_data' => $callbackData];
    }

    private function send(int $chatId, string $text, array $buttons = []): void
    {
        $this->telegram->sendMessage($chatId, $text, '', $buttons ? ['inline_keyboard' => $buttons] : null);
    }
}
