<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ResellerAccount;
use App\Models\ResellerCustomer;
use App\Models\ResellerOrder;
use App\Models\ResellerPayment;
use App\Models\ResellerPlan;
use App\Models\ResellerPlanTemplate;
use App\Models\ResellerSharedSubscription;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResellerOrderService
{
    public const PERIODS = [
        'month_price', 'quarter_price', 'half_year_price', 'year_price',
        'two_year_price', 'three_year_price', 'onetime_price',
    ];

    public function create(User $user, ResellerAccount $store, ResellerPlan $resellerPlan, string $period, ?int $subscriptionId = null): ResellerOrder
    {
        if (!$this->enabledStore($store)) {
            abort(403, 'Store is not available');
        }
        $plan = $this->validPlan($store, $resellerPlan, $period);
        $this->ensureCustomer($store, $user);

        $sharedService = new ResellerSharedSubscriptionService();
        $sharedGroup = null;
        $isSharedPlan = $sharedService->isSharedPlan($resellerPlan);
        if ($isSharedPlan) {
            if (!$sharedService->available()) abort(503, 'Shared subscription service is not installed');
            if ($subscriptionId) {
                $sharedGroup = ResellerSharedSubscription::where('subscription_id', $subscriptionId)
                    ->where('reseller_id', $store->id)
                    ->where('owner_user_id', $user->id)
                    ->where('reseller_plan_id', $resellerPlan->id)
                    ->whereIn('status', ['active', 'expired'])->first();
                if (!$sharedGroup) abort(403, 'Shared subscription does not belong to this store');
            } else {
                $sharedGroup = $sharedService->groupForRenewal($store, $user, (int)$resellerPlan->id);
                if ($sharedGroup) $subscriptionId = (int)$sharedGroup->subscription_id;
            }
            if (!$sharedGroup && $sharedService->groupForUser($user)) {
                abort(422, 'An account can only join one active shared group');
            }
            if (!$sharedGroup && !$this->canCreateSharedSubscription($user)) {
                abort(422, 'Enable multiple subscriptions before buying a separate shared package');
            }
        }

        $target = null;
        if ($subscriptionId) {
            $target = Subscription::where('id', $subscriptionId)
                ->where('user_id', $user->id)
                ->where('status', '!=', 'revoked')
                ->first();
            if (!$target || (int)$target->plan_id !== (int)$plan->id) {
                abort(422, 'Only same-plan renewal is supported');
            }
            if (!$this->subscriptionBelongsToStore($store, $user, $target)) {
                abort(403, 'Subscription does not belong to this store');
            }
        } elseif ((!$isSharedPlan || $sharedGroup) && $user->plan_id && (int)$user->plan_id === (int)$plan->id && $user->expired_at && $user->expired_at >= time()) {
            $target = (new SubscriptionService())->primary($user);
            if (!$target || !$this->subscriptionBelongsToStore($store, $user, $target)) {
                abort(403, 'Same-store renewal is required');
            }
            $subscriptionId = (int)$target->id;
        } elseif ((!$isSharedPlan || $sharedGroup) && $user->plan_id && (int)$user->plan_id !== (int)$plan->id && (!$user->expired_at || $user->expired_at >= time())) {
            abort(422, 'Plan change is not available in the reseller store');
        }
        if ((int)$plan->show === 0 && !$target) {
            abort(422, 'This plan is available for renewal only');
        }

        return DB::transaction(function () use ($user, $store, $resellerPlan, $plan, $period, $subscriptionId, $sharedGroup, $isSharedPlan) {
            $order = new Order();
            $orderService = new OrderService($order);
            $orderService->newSubscription = $isSharedPlan && !$sharedGroup;
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $order->subscription_id = $subscriptionId;
            $order->period = $period;
            $order->trade_no = \App\Utils\Helper::generateOrderNo();
            $order->total_amount = (int)$resellerPlan->{$period};
            $orderService->setOrderType($user);

            if (!$order->save()) {
                abort(500, 'Failed to create order');
            }

            $mapping = new ResellerOrder();
            $mapping->reseller_id = $store->id;
            $mapping->reseller_plan_id = $resellerPlan->id;
            $mapping->platform_order_id = $order->id;
            $mapping->user_id = $user->id;
            $mapping->period = $period;
            $mapping->amount_snapshot = $order->total_amount;
            $mapping->shared_subscription_id = $sharedGroup ? $sharedGroup->id : null;
            $mapping->save();

            return $mapping->load('platformOrder');
        });
    }

    public function checkout(ResellerAccount $store, User $user, string $tradeNo, int $paymentId, ?string $stripeToken = null): array
    {
        $mapping = ResellerOrder::with(['platformOrder', 'payment'])
            ->where('reseller_id', $store->id)
            ->where('user_id', $user->id)
            ->whereHas('platformOrder', function ($query) use ($tradeNo) {
                $query->where('trade_no', $tradeNo)->where('status', 0);
            })
            ->first();
        if (!$mapping || !$mapping->platformOrder) {
            abort(500, 'Order does not exist or has been paid');
        }

        $payment = ResellerPayment::where('id', $paymentId)
            ->where('reseller_id', $store->id)
            ->whereIn('driver', (array)config('v2board.reseller_allowed_payment_drivers', []))
            ->where('enabled', 1)
            ->first();
        if (!$payment) {
            abort(422, 'Payment method is not available');
        }

        $mapping->reseller_payment_id = $payment->id;
        $mapping->save();
        $mapping->setRelation('payment', $payment);

        $result = (new ResellerPaymentService($payment))->pay($mapping, $store->store_slug, $stripeToken);
        return ['type' => $result['type'], 'data' => $result['data']];
    }

    public function notify(ResellerAccount $store, string $uuid, array $params): string
    {
        $payment = ResellerPayment::where('uuid', $uuid)
            ->where('reseller_id', $store->id)
            ->where('enabled', 1)
            ->first();
        if (!$payment) {
            abort(404, 'Payment method does not exist');
        }

        $result = (new ResellerPaymentService($payment))->notify($params);
        if (!$result || !is_array($result) || empty($result['trade_no'])) {
            Log::warning('Reseller payment callback verification failed', [
                'reseller_id' => (int)$store->id,
                'payment_id' => (int)$payment->id,
                'driver' => $payment->driver,
                'parameter_keys' => array_keys($params),
                'trade_status' => $params['trade_status'] ?? null,
                'out_trade_no' => $params['out_trade_no'] ?? null,
            ]);
            abort(500, 'Payment verification failed');
        }

        $mapping = ResellerOrder::with('platformOrder')
            ->where('reseller_id', $store->id)
            ->whereHas('platformOrder', function ($query) use ($result) {
                $query->where('trade_no', $result['trade_no']);
            })->first();
        if (!$mapping || !$mapping->platformOrder) {
            abort(404, 'Order does not belong to this store');
        }
        if ($mapping->reseller_payment_id && (int)$mapping->reseller_payment_id !== (int)$payment->id) {
            abort(422, 'Payment method does not belong to this order');
        }

        $order = $mapping->platformOrder;
        if ((int)$order->total_amount !== (int)$mapping->amount_snapshot) {
            abort(422, 'Payment amount does not match order');
        }
        if (isset($result['payment_amount_cents'])
            && (int)$result['payment_amount_cents'] !== (int)$mapping->amount_snapshot) {
            abort(422, 'Payment amount does not match order');
        }
        if ((int)$order->status === 0) {
            if (!(new OrderService($order))->paid((string)($result['callback_no'] ?? 'reseller_callback'))) {
                abort(500, 'Order opening failed');
            }
        }
        return (string)($result['custom_result'] ?? 'success');
    }

    public function ensureCustomer(ResellerAccount $store, User $user): ResellerCustomer
    {
        return ResellerCustomer::firstOrCreate(
            ['reseller_id' => $store->id, 'user_id' => $user->id],
            ['created_at' => time(), 'updated_at' => time()]
        );
    }

    private function validPlan(ResellerAccount $store, ResellerPlan $resellerPlan, string $period)
    {
        if ((int)$resellerPlan->reseller_id !== (int)$store->id || !$resellerPlan->enabled) {
            abort(404, 'Reseller plan does not exist');
        }
        if (!in_array($period, self::PERIODS, true)) {
            abort(422, 'This payment period is not supported');
        }
        $template = ResellerPlanTemplate::where('base_plan_id', $resellerPlan->base_plan_id)
            ->where('enabled', 1)
            ->first();
        if (!$template) {
            abort(422, 'Base plan is not available for resale');
        }
        $plan = $resellerPlan->basePlan;
        if (!$plan || ($plan->show === 0 && $plan->renew === 0)) {
            abort(422, 'Base plan is not available');
        }
        $price = $resellerPlan->{$period};
        if ($price === null || (int)$price <= 0) {
            abort(422, 'Reseller price must be greater than zero');
        }
        return $plan;
    }

    private function subscriptionBelongsToStore(ResellerAccount $store, User $user, Subscription $subscription): bool
    {
        return ResellerOrder::where('reseller_id', $store->id)
            ->where('user_id', $user->id)
            ->whereHas('platformOrder', function ($query) use ($subscription) {
                $query->where('subscription_id', $subscription->id);
            })->exists();
    }

    private function enabledStore(ResellerAccount $store): bool
    {
        return (int)$store->id > 0 && $store->isFullyActive();
    }

    private function canCreateSharedSubscription(User $user): bool
    {
        if ((new SubscriptionService())->multiEnabled()) return true;
        return empty($user->plan_id) || (!empty($user->expired_at) && $user->expired_at < time());
    }
}
