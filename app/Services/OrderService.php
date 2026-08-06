<?php

namespace App\Services;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OrderService
{
    CONST STR_TO_TIME = [
        'month_price' => 1,
        'quarter_price' => 3,
        'half_year_price' => 6,
        'year_price' => 12,
        'two_year_price' => 24,
        'three_year_price' => 36
    ];
    public $order;
    public $user;
    public $newSubscription = false;
    // 零元单开通失败时的原因，供管理端提示使用；CLI 与支付回调仍以返回值判断成败。
    public $lastError = null;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function open()
    {
        if (!$this->order->id) return false;

        return DB::transaction(function () {
            $lockedOrder = Order::where('id', $this->order->id)
                ->lockForUpdate()
                ->first();
            if (!$lockedOrder) return false;
            $this->order = $lockedOrder;
            if ((int)$lockedOrder->status === 3) return true;
            if ((int)$lockedOrder->status !== 1) return false;
            return $this->openUnlocked();
        });
    }

    private function openUnlocked()
    {
        $order = $this->order;
        if ((int)$order->status === 3) return true;
        $this->user = User::find($order->user_id);
        if ((int)$order->type === 9) {
            // 充值入账走加锁的 addBalance（内部 User::lockForUpdate + 基于新鲜值加减）。
            // 原实现对 $this->user（第 55 行 User::find，未加锁）做 balance += 后整行 save()，
            // 是无锁的读-改-写：与并发的下单扣款交错时会整列覆盖，把已花掉的余额写回来。
            // 本方法已在 open() 的 DB::transaction 内（订单行已 lockForUpdate），去掉这里多余的
            // 嵌套 begin/commit —— 出错时 abort 会让外层事务整体回滚。
            $delta = (int)($order->total_amount + $this->getbounus($order->total_amount));
            if (!(new UserService())->addBalance($order->user_id, $delta, 'deposit', [
                'source_type' => 'order',
                'source_id' => $order->id,
                'unique_key' => 'deposit:' . $order->id,
                'remark' => $order->trade_no
            ])) {
                abort(500, __('充值失败'));
            }
            $order->status = 3;
            if (!$order->save()) {
                abort(500, __('充值失败'));
            }
            return true;
        }

        $plan = Plan::find($order->plan_id);

        if ($this->useSubscriptions()) {
            DB::beginTransaction();
            try {
                $subscription = $this->openSubscription($order, $plan);
                // Keep the platform order linked to the subscription it opened.
                // Reseller renewal validation and existing order detail both rely on this relation.
                $order->subscription_id = $subscription->id;
                $eventId = (int)$order->type === 1
                    ? config('v2board.new_order_event_id', 0)
                    : config('v2board.renew_order_event_id', 0);
                if ((int)$eventId === 1) {
                    (new SubscriptionService())->reset($subscription);
                }
                $order->status = 3;
                $order->save();
                DB::commit();
                return $subscription;
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        }

        if ($order->refund_amount) {
            $this->user->balance = $this->user->balance + $order->refund_amount;
        }
        DB::beginTransaction();
        if ($order->surplus_order_ids) {
            try {
                Order::whereIn('id', $order->surplus_order_ids)->update([
                    'status' => 4
                ]);
            } catch (\Exception $e) {
                DB::rollback();
                abort(500, __('开通失败'));
            }
        }
        switch ((string)$order->period) {
            case 'onetime_price':
                $this->buyByOneTime($order, $plan);
                break;
            case 'reset_price':
                $this->buyByResetTraffic();
                break;
            default:
                $this->buyByPeriod($order, $plan);
        }

        switch ((int)$order->type) {
            case 1:
                $this->openEvent(config('v2board.new_order_event_id', 0));
                break;
            case 2:
                $this->openEvent(config('v2board.renew_order_event_id', 0));
                break;
            case 3:
                $this->openEvent(config('v2board.change_order_event_id', 0));
                break;
        }

        $this->setSpeedLimit($plan->speed_limit);

        if (!$this->user->save()) {
            DB::rollBack();
            abort(500, __('开通失败'));
        }
        $order->status = 3;
        if (!$order->save()) {
            DB::rollBack();
            abort(500, __('开通失败'));
        }

        DB::commit();
        return true;
    }


    public function completeFree(): bool
    {
        if ((int)$this->order->total_amount > 0 || !$this->order->id) return false;

        try {
            return (bool)DB::transaction(function () {
                $order = Order::where('id', $this->order->id)
                    ->lockForUpdate()
                    ->first();
                if (!$order || (int)$order->total_amount > 0) return false;
                if ((int)$order->status === 3) return true;
                if (!in_array((int)$order->status, [0, 1], true)) return false;

                $order->paid_at = $order->paid_at ?: time();
                $order->callback_no = 'free_order';
                $order->status = 1;
                if (!$order->save()) {
                    throw new \RuntimeException('Unable to mark free order as paid');
                }

                $this->order = $order;
                return (bool)$this->open();
            });
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::error('Free order opening failed', [
                'trade_no' => $this->order->trade_no,
                'order_id' => $this->order->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function setOrderType(User $user)
    {
        $order = $this->order;
        if ($order->period === 'deposit'){
            $order->type = 9;
        } else if ($order->period === 'reset_price') {
            $order->type = 4;
        } else if ($this->newSubscription && (new SubscriptionService())->multiEnabled()) {
            $order->type = 1;
        } else if ($order->subscription_id) {
            $order->type = 2;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            if (!(int)config('v2board.plan_change_enable', 1)) abort(500, __('目前不允许更改订阅，请联系客服或提交工单操作'));
            $order->type = 3;
            if ((int)config('v2board.surplus_enable', 1)) $this->getSurplusValue($user, $order);
            if ($order->surplus_amount >= $order->total_amount) {
                $order->refund_amount = $order->surplus_amount - $order->total_amount;
                $order->total_amount = 0;
            } else {
                $order->total_amount = $order->total_amount - $order->surplus_amount;
            }
        } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) { // 用户订阅未过期且购买订阅与当前订阅相同 === 续费
            $order->type = 2;
        } else { // 新购
            $order->type = 1;
        }
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        if ($user->discount) {
            $order->discount_amount = $order->discount_amount + ($order->total_amount * ($user->discount / 100));
        }
        // 夹紧折扣与应付金额（修复：券折扣在 CouponService 里只按券自身范围 clamp 过一次，叠加 VIP
        // 折扣后既不夹上限也不夹下限，可使 total_amount 变负 —— 负数被免支付通道当成「免费」放行，
        // 还会在 setOrderType 里凭空造出 refund_amount）。折扣额取整并限定在 [0, 原价]，应付随之非负。
        $order->discount_amount = (int)round($order->discount_amount);
        if ($order->discount_amount < 0) $order->discount_amount = 0;
        if ($order->discount_amount > $order->total_amount) $order->discount_amount = $order->total_amount;
        $order->total_amount = $order->total_amount - $order->discount_amount;
        if ($order->total_amount < 0) $order->total_amount = 0;
    }

    public function setInvite(User $user):void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0)) return;
        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter) return;
        $isCommission = false;
        switch ((int)$inviter->commission_type) {
            case 0:
                $commissionFirstTime = (int)config('v2board.commission_first_time_enable', 1);
                $isCommission = (!$commissionFirstTime || ($commissionFirstTime && !$this->haveValidOrder($user)));
                break;
            case 1:
                $isCommission = true;
                break;
            case 2:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission) return;
        if ($inviter && $inviter->commission_rate) {
            $order->commission_balance = $order->total_amount * ($inviter->commission_rate / 100);
        } else {
            $order->commission_balance = $order->total_amount * (config('v2board.invite_commission', 10) / 100);
        }
    }

    private function haveValidOrder(User $user)
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [0, 2])
            ->first();
    }

    private function getSurplusValue(User $user, Order $order)
    {
        if ($user->expired_at === NULL) {
            $this->getSurplusValueByOneTime($user, $order);
        } else {
            $this->getSurplusValueByPeriod($user, $order);
        }
    }


    private function getSurplusValueByOneTime(User $user, Order $order)
    {
        $lastOneTimeOrder = Order::where('user_id', $user->id)
            ->where('period', 'onetime_price')
            ->where('status', 3)
            ->orderBy('id', 'DESC')
            ->first();
        if (!$lastOneTimeOrder) return;
        $nowUserTraffic = $user->transfer_enable / 1073741824;
        if ($nowUserTraffic == 0) return;
        $paidTotalAmount = ($lastOneTimeOrder->total_amount + $lastOneTimeOrder->balance_amount);
        if ($paidTotalAmount == 0) return;
        $notUsedTraffic = $nowUserTraffic - (($user->u + $user->d) / 1073741824);
        $remainingTrafficRatio = $notUsedTraffic / $nowUserTraffic;
        $result = $remainingTrafficRatio * $paidTotalAmount;
        $order->surplus_amount = max($result, 0);
        $orderModel = Order::where('user_id', $user->id)->where('period', '!=', 'reset_price')->where('status', 3);
        $order->surplus_order_ids = array_column($orderModel->get()->toArray(), 'id');
    }

    private function getSurplusValueByPeriod(User $user, Order $order)
    {
        $orders = Order::where('user_id', $user->id)
            ->where('period', '!=', 'reset_price')
            ->where('period', '!=', 'onetime_price')
            ->where('period', '!=', 'deposit')
            ->where('status', 3)
            ->get()
            ->toArray();
        if (!$orders) return;
        $orderAmountSum = 0;
        $orderMonthSum = 0;
        $lastValidateAt = null;
        foreach ($orders as $item) {
            $period = self::STR_TO_TIME[$item['period']];
            $orderEndTime = strtotime("+{$period} month", $item['created_at']);
            if ($orderEndTime < time()) continue;
            $lastValidateAt = $item['created_at'] > $lastValidateAt ? $item['created_at'] : $lastValidateAt;
            $orderMonthSum += $period;
            $orderAmountSum += $item['total_amount'] + $item['balance_amount'] + $item['surplus_amount'] - $item['refund_amount'];
        }
        if ($lastValidateAt === null) return;
    
        $expiredAtByOrder = strtotime("+{$orderMonthSum} month", $lastValidateAt);
        $expiredAtByUser = $user->expired_at;
        if ($expiredAtByOrder < time() || $expiredAtByUser < time()) return;
        $orderSurplusSecond = $expiredAtByUser - time();
        $orderRangeSecond = $expiredAtByOrder - $lastValidateAt;
    
        $totalTraffic = $user->transfer_enable;
        $usedTraffic = ($user->u + $user->d);
        if ($totalTraffic == 0) return;
    
        $remainingTrafficRatio = ($totalTraffic - $usedTraffic) / $totalTraffic;
    
        $avgPricePerSecond = $orderAmountSum / $orderRangeSecond;
        if ($orderRangeSecond <= 31 * 86400) {
            $remainingExpiredTimeRatio = $orderSurplusSecond / $orderRangeSecond;
            $surplusRatio = min($remainingExpiredTimeRatio, $remainingTrafficRatio);
            $orderSurplusAmount = $avgPricePerSecond * $orderSurplusSecond * $surplusRatio;
        } else {
            $monthSeconds = 30 * 86400;
            $firstMonthRemainSeconds = $orderSurplusSecond % $monthSeconds;
            $surplusRatio = min($firstMonthRemainSeconds / $monthSeconds, $remainingTrafficRatio);
            $laterMonthsSeconds = $orderSurplusSecond - $firstMonthRemainSeconds;
            $orderSurplusAmount = $avgPricePerSecond * $monthSeconds * $surplusRatio +
                                  $avgPricePerSecond * $laterMonthsSeconds;
        }
    
        $order->surplus_amount = max($orderSurplusAmount, 0);
        $order->surplus_order_ids = array_column($orders, 'id');
    }

    public function paid(string $callbackNo): bool
    {
        if (!$this->order->id) return false;
        if ((int)$this->order->total_amount <= 0) {
            return $this->completeFree();
        }

        // Payment callbacks and cancellations race across workers. Reload under the same
        // row lock used by cancel(), so only one terminal transition can win.
        $tradeNo = DB::transaction(function () use ($callbackNo) {
            $order = Order::where('id', $this->order->id)
                ->lockForUpdate()
                ->first();
            if (!$order) return null;

            $this->order = $order;
            if ((int)$order->status !== 0) return false;

            $order->status = 1;
            $order->paid_at = time();
            $order->callback_no = $callbackNo;
            if (!$order->save()) {
                throw new \RuntimeException('Unable to mark order as paid');
            }

            return $order->trade_no;
        });

        if ($tradeNo === null) return false;
        // A prior payment or cancellation won the lock. Preserve the callback's
        // idempotent success response without reactivating the order.
        if ($tradeNo === false) return true;

        try {
            // Do not run a synchronous queue job before the paid state commits.
            OrderHandleJob::dispatch($tradeNo);
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }

    public function cancel(): bool
    {
        // 旧实现无锁、不复核状态、不幂等：status=2 是按主键的无条件 UPDATE（不带 AND status=0），
        // 且 updated_at 恒 dirty 使重复 save 照样成功，每次都 addBalance(+balance_amount) 退一次款。
        // 四个入口（用户端/管理端/店面/OrderHandleJob）共用本方法 + Webman 多进程真并发 →
        // 并发/重试取消同一订单可把同一笔 balance_amount 无限次退回余额（本次线上 1 元变 0 元购的成因）。
        // 改为与同文件 completeFree()/open() 逐字同构的加锁范式，一处修复覆盖全部入口。
        if (!$this->order->id) return false;
        try {
            return (bool)DB::transaction(function () {
                $order = Order::where('id', $this->order->id)
                    ->lockForUpdate()
                    ->first();
                if (!$order) return false;
                // 幂等：已取消视为成功，绝不重复退款（并发、重试、跨入口重复调用都在这里归零）。
                if ((int)$order->status === 2) {
                    $this->order = $order;
                    return true;
                }
                // 白名单：只允许「待支付(0) → 已取消(2)」。禁止把已支付(1)/已开通(3)盲写回取消再退款
                // —— 这与四个调用方控制器里既有的 `status !== 0` 前置检查一致，此处在行锁内强制执行。
                if ((int)$order->status !== 0) return false;
                // 原子状态跃迁：并发下只有一个请求能命中 status=0 并把它改成 2，其余影响行数为 0。
                $affected = Order::where('id', $order->id)
                    ->where('status', 0)
                    ->update(['status' => 2]);
                if ($affected !== 1) return false;
                $order->status = 2;
                $this->order = $order;
                if ($order->balance_amount) {
                    // 退款与状态跃迁同事务：退款失败则整体回滚，订单不会停在「已取消但没退钱」。
                    // unique_key 让「一张订单只退一次」成为数据库层不变式（与上面的状态白名单双保险）。
                    if (!(new UserService())->addBalance($order->user_id, (int)$order->balance_amount, 'order_cancel_refund', [
                        'source_type' => 'order',
                        'source_id' => $order->id,
                        'unique_key' => 'order_cancel_refund:' . $order->id,
                        'remark' => $order->trade_no
                    ])) {
                        throw new \RuntimeException('cancel refund failed');
                    }
                }
                return true;
            });
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function buyByResetTraffic()
    {
        $this->user->u = 0;
        $this->user->d = 0;
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        // change plan process
        if ((int)$order->type === 3) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->device_limit = $plan->device_limit;
        // 从一次性转换到循环
        if ($this->user->expired_at === NULL) $this->buyByResetTraffic();
        // 新购
        if ($order->type === 1) $this->buyByResetTraffic();

        // 到期当天续费刷新流量
        $expireDay = date('d', $this->user->expired_at);
        $expireMonth = date('m', $this->user->expired_at);
        $today = date('d');
        $currentMonth = date('m');
        if ($order->type === 2 && $expireMonth == $currentMonth && $expireDay === $today ) {
            $this->buyByResetTraffic();
        }

        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
    }

    private function buyByOneTime(Order $order, Plan $plan)
    {
        $transfer_enable = $plan->transfer_enable;
        if (!$order->surplus_order_ids) {
            $notUsedTraffic = ($this->user->transfer_enable - ($this->user->u + $this->user->d)) / 1073741824;
            if ($notUsedTraffic > 0 && $this->user->expired_at == NULL) {
                $transfer_enable += $notUsedTraffic;
            }
        }
        $this->buyByResetTraffic();
        $this->user->transfer_enable = $transfer_enable * 1073741824;
        $this->user->device_limit = $plan->device_limit;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
    }

    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        switch ($str) {
            case 'month_price':
                return strtotime('+1 month', $timestamp);
            case 'quarter_price':
                return strtotime('+3 month', $timestamp);
            case 'half_year_price':
                return strtotime('+6 month', $timestamp);
            case 'year_price':
                return strtotime('+12 month', $timestamp);
            case 'two_year_price':
                return strtotime('+24 month', $timestamp);
            case 'three_year_price':
                return strtotime('+36 month', $timestamp);
        }
    }

    private function openEvent($eventId)
    {
        switch ((int) $eventId) {
            case 0:
                break;
            case 1:
                $this->buyByResetTraffic();
                break;
        }
    }

    private function useSubscriptions(): bool
    {
        return Schema::hasTable('v2_subscription') && $this->order->plan_id > 0;
    }

    private function openSubscription(Order $order, Plan $plan): Subscription
    {
        if (!$plan) {
            abort(500, __('Subscription plan does not exist'));
        }
        $subscriptionService = new SubscriptionService();
        $multiEnabled = $subscriptionService->multiEnabled();
        $target = null;
        if ($order->subscription_id) {
            $target = Subscription::where('id', $order->subscription_id)
                ->where('user_id', $order->user_id)
                ->where('status', '!=', 'revoked')
                ->lockForUpdate()
                ->first();
            if (!$target) {
                abort(403, __('Subscription does not belong to the user'));
            }
        }
        if (!$target && (!$multiEnabled || in_array((int)$order->type, [2, 3], true))) {
            $target = $subscriptionService->primary($this->user);
        }
        if ($order->period === 'reset_price') {
            if (!$target) $target = $subscriptionService->primary($this->user);
            if (!$target) abort(422, __('No active subscription'));
            return $subscriptionService->reset($target);
        }
        if ($target && (!$multiEnabled || in_array((int)$order->type, [2, 3], true))) {
            return $subscriptionService->renew($target, $plan, $order->period);
        }
        return $subscriptionService->create($this->user, $plan, $order->period);
    }

    private function getbounus($total_amount) {
        $deposit_bounus = config('v2board.deposit_bounus', []);
        if (empty($deposit_bounus) || $deposit_bounus[0] === null) {
            return 0;
        }
        $add = 0;
        foreach ($deposit_bounus as $tier) {
            list($amount, $bounus) = explode(':', $tier);
            $amount = (float)$amount * 100;
            $bounus = (float)$bounus * 100;
            $amount = (int)$amount;
            $bounus = (int)$bounus;
            if ($total_amount >= $amount) {
                $add = max($add, $bounus);
            }
        }
        return $add;
    }
}
