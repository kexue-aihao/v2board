<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\OrderSave;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Models\Subscription;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PaymentAttemptService;
use App\Services\PaymentService;
use App\Services\PlanService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderController extends Controller
{
    public function fetch(Request $request)
    {
        $model = Order::where('user_id', $request->user['id'])
            ->orderBy('created_at', 'DESC');
        if ($request->input('status') !== null) {
            $model->where('status', $request->input('status'));
        }
        $order = $model->get();
        $plan = Plan::get();
        for ($i = 0; $i < count($order); $i++) {
            for ($x = 0; $x < count($plan); $x++) {
                if ($order[$i]['plan_id'] === $plan[$x]['id']) {
                    $order[$i]['plan'] = $plan[$x];
                }
            }
        }
        return response([
            'data' => $order->makeHidden(['id', 'user_id'])
        ]);
    }

    public function detail(Request $request)
    {
        $order = Order::where('user_id', $request->user['id'])
            ->where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist or has been paid'));
        }
        if ($order->plan_id == 0) {
            $order['plan'] = [
                'id' => 0,
                'name' => 'deposit'
            ];
            $order->bounus = $this->getbounus($order->total_amount);
            $order->get_amount = $order->total_amount + $order->bounus;

            return response([
                'data' => $order
            ]);
        }
        $order['plan'] = Plan::find($order->plan_id);
        $order['try_out_plan_id'] = (int)config('v2board.try_out_plan_id');
        if (!$order['plan']) {
            abort(500, __('Subscription plan does not exist'));
        }
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        return response([
            'data' => $order
        ]);
    }

    public function save(OrderSave $request)
    {
        $userService = new UserService();
        if ($userService->isNotCompleteOrderByUserId($request->user['id'])) {
            abort(500, __('You have an unpaid or pending order, please try again later or cancel it'));
        }
        if ($request->input('plan_id') == 0) {
            // OrderSave 已校验 deposit_amount 为 1..9999998 的整数；这里再强制 (int) 兜底，
            // 杜绝 '0.5'/'1e-3' 等被松散比较放行、再落进 int 列时截断或触发严格模式异常。
            $amount = (int)$request->input('deposit_amount');
            if ($amount <= 0) {
                abort(500, __('Failed to create order, deposit amount must be greater than 0'));
            }
            if ($amount >= 9999999 ) {
                abort(500, __('Deposit amount too large, please contact the administrator'));
            }
            $user = User::find($request->user['id']);
            DB::beginTransaction();
            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $request->user['id'];
            $order->plan_id = $request->input('plan_id');
            $order->period = 'deposit';
            $order->trade_no = Helper::generateOrderNo();
            $order->total_amount = $amount;
            
            $orderService->setOrderType($user);
            $orderService->setInvite($user);

            if (!$order->save()) {
                DB::rollback();
                abort(500, __('Failed to create order'));
            }
    
            DB::commit();
    
            return response([
                'data' => $order->trade_no
            ]);
        }
        $planService = new PlanService($request->input('plan_id'));

        $plan = $planService->plan;
        $user = User::find($request->user['id']);

        if (!$plan) {
            abort(500, __('Subscription plan does not exist'));
        }

        if ($user->plan_id !== $plan->id && !$planService->haveCapacity() && $request->input('period') !== 'reset_price') {
            abort(500, __('Current product is sold out'));
        }

        if ($plan[$request->input('period')] === NULL) {
            abort(500, __('This payment period cannot be purchased, please choose another period'));
        }

        if ($request->input('period') === 'reset_price') {
            if (!$userService->isAvailable($user) || $plan->id !== $user->plan_id) {
                abort(500, __('Subscription has expired or no active subscription, unable to purchase Data Reset Package'));
            }
        }

        if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
            if ($request->input('period') !== 'reset_price') {
                abort(500, __('This subscription has been sold out, please choose another subscription'));
            }
        }

        if (!$plan->renew && $user->plan_id == $plan->id && $request->input('period') !== 'reset_price') {
            abort(500, __('This subscription cannot be renewed, please change to another subscription'));
        }


        if (!$plan->show && $plan->renew && !$userService->isAvailable($user)) {
            abort(500, __('This subscription has expired, please change to another subscription'));
        }

        DB::beginTransaction();
        $order = new Order();
        $orderService = new OrderService($order);
        $order->user_id = $request->user['id'];
        $order->plan_id = $plan->id;
        $order->period = $request->input('period');
        $order->trade_no = Helper::generateOrderNo();
        $order->total_amount = $plan[$request->input('period')];
        if (Schema::hasTable('v2_subscription')) {
            $subscriptionService = new \App\Services\SubscriptionService();
            $subscriptionId = $request->input('subscription_id');
            if ($subscriptionId) {
                $target = Subscription::where('id', $subscriptionId)
                    ->where('user_id', $user->id)
                    ->first();
                if (!$target) {
                    DB::rollBack();
                    abort(403, __('Subscription does not belong to the user'));
                }
                $order->subscription_id = $target->id;
            }
            $orderService->newSubscription = !$subscriptionId
                && (bool)$request->input('new_subscription')
                && $subscriptionService->multiEnabled();
        }

        if ($request->input('coupon_code')) {
            $couponService = new CouponService($request->input('coupon_code'));
            if (!$couponService->use($order)) {
                DB::rollBack();
                abort(500, __('Coupon failed'));
            }
            $order->coupon_id = $couponService->getId();
        }

        $orderService->setVipDiscount($user);
        $orderService->setOrderType($user);

        if ($user->balance > 0 && $order->total_amount > 0) {
            $remainingBalance = $user->balance - $order->total_amount;
            $userService = new UserService();
            if ($remainingBalance > 0) {
                if (!$userService->addBalance($order->user_id, - $order->total_amount, 'order_balance_pay', ['remark' => $order->trade_no])) {
                    DB::rollBack();
                    abort(500, __('Insufficient balance'));
                }
                $order->balance_amount = $order->total_amount;
                $order->total_amount = 0;
            } else {
                if (!$userService->addBalance($order->user_id, - $user->balance, 'order_balance_pay', ['remark' => $order->trade_no])) {
                    DB::rollBack();
                    abort(500, __('Insufficient balance'));
                }
                $order->balance_amount = $user->balance;
                $order->total_amount -= $user->balance;
            }
        }

        $orderService->setInvite($user);

        if (!$order->save()) {
            DB::rollback();
            abort(500, __('Failed to create order'));
        }

        DB::commit();

        return response([
            'data' => $order->trade_no
        ]);
    }

    public function checkout(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $method = $request->input('method');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->where('status', 0)
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist or has been paid'));
        }
        // free process
        if ($order->total_amount <= 0) {
            $orderService = new OrderService($order);
            if (!$orderService->completeFree()) {
                abort(500, 'Free order could not be opened');
            }
            return response([
                'type' => -1,
                'data' => true
            ]);
        }
        $payment = Payment::find($method);
        if (!$payment || (int)$payment->enable !== 1 || !PaymentAttemptService::isDriverAvailable((string)$payment->payment)) {
            abort(422, __('Payment method is not available'));
        }

        $attemptService = new PaymentAttemptService();
        $attempt = $attemptService->create($order, $payment);
        $paymentService = new PaymentService($attempt->driver, $attempt->payment_id);
        $checkout = [
            'trade_no' => $attempt->attempt_no,
            'display_trade_no' => $order->trade_no,
            'total_amount' => (int)$attempt->order_amount_cents,
            'user_id' => $order->user_id,
            'stripe_token' => $request->input('token')
        ];

        try {
            $quote = $paymentService->prepare($checkout);
            $attempt = $attemptService->markPending($attempt, $quote);
            $checkout['gateway_amount_minor'] = (int)$attempt->gateway_amount_minor;
            $checkout['gateway_currency'] = (string)$attempt->gateway_currency;
            $result = $paymentService->pay($checkout);
        } catch (\Throwable $e) {
            $attemptService->markFailed($attempt, 'payment gateway initialization failed');
            abort(500, __('Payment gateway request failed'));
        }
        return response([
            'type' => $result['type'],
            'data' => $result['data']
        ]);
    }

    public function check(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist'));
        }
        return response([
            'data' => $order->status
        ]);
    }

    public function getPaymentMethod()
    {
        $methods = Payment::select([
            'id',
            'name',
            'payment',
            'icon',
            'handling_fee_fixed',
            'handling_fee_percent'
        ])
            ->where('enable', 1)
            ->orderBy('sort', 'ASC')
            ->get()
            ->filter(function (Payment $payment) {
                return PaymentAttemptService::isDriverAvailable((string)$payment->payment);
            })
            ->values();

        return response([
            'data' => $methods
        ]);
    }

    public function cancel(Request $request)
    {
        if (empty($request->input('trade_no'))) {
            abort(500, __('Invalid parameter'));
        }
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist'));
        }
        if ($order->status !== 0) {
            abort(500, __('You can only cancel pending orders'));
        }
        if (!(new PaymentAttemptService())->cancelOrder($order)) {
            abort(500, __('Cancel failed'));
        }
        return response([
            'data' => true
        ]);
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
