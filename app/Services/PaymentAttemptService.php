<?php

namespace App\Services;

use App\Jobs\OrderHandleJob;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentAttemptService
{
    private const HIGH_RISK_DRIVERS = ['BTCPay', 'Coinbase', 'MGate', 'PaytaroQR'];

    /**
     * An external checkout is immutable. A second payment link for the same
     * order would make it impossible to bind a late callback safely.
     */
    public function create(Order $order, Payment $payment): PaymentAttempt
    {
        return DB::transaction(function () use ($order, $payment) {
            $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->first();
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();

            if (!$lockedOrder || (int)$lockedOrder->status !== 0) {
                abort(422, __('Order does not exist or has been paid'));
            }
            if (!$lockedPayment || (int)$lockedPayment->enable !== 1 || !self::isDriverAvailable((string)$lockedPayment->payment)) {
                abort(422, __('Payment method is not available'));
            }

            $existing = PaymentAttempt::where('order_id', $lockedOrder->id)->lockForUpdate()->first();
            if ($existing) {
                abort(422, __('A payment checkout has already been created for this order'));
            }

            $handlingAmount = null;
            if ($lockedPayment->handling_fee_fixed || $lockedPayment->handling_fee_percent) {
                $handlingAmount = (int)round(
                    ($lockedOrder->total_amount * ($lockedPayment->handling_fee_percent / 100))
                    + $lockedPayment->handling_fee_fixed
                );
            }

            $lockedOrder->handling_amount = $handlingAmount;
            $lockedOrder->payment_id = $lockedPayment->id;
            if (!$lockedOrder->save()) {
                throw new \RuntimeException('Unable to save checkout payment selection');
            }

            return PaymentAttempt::create([
                'order_id' => $lockedOrder->id,
                'payment_id' => $lockedPayment->id,
                'payment_uuid' => (string)$lockedPayment->uuid,
                'driver' => (string)$lockedPayment->payment,
                'attempt_no' => Helper::randomChar(32),
                'order_amount_cents' => (int)$lockedOrder->total_amount + (int)($handlingAmount ?? 0),
                'gateway_amount_minor' => null,
                'gateway_currency' => null,
                'gateway_transaction_id' => null,
                'status' => PaymentAttempt::STATUS_INITIALIZING,
                'failure_reason' => null,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        });
    }

    public function markPending(PaymentAttempt $attempt, array $quote): PaymentAttempt
    {
        $amount = $quote['amount_minor'] ?? null;
        $currency = strtoupper(trim((string)($quote['currency'] ?? '')));
        if (!is_int($amount) || $amount < 1 || !preg_match('/^[A-Z0-9]{3,12}$/', $currency)) {
            throw new \RuntimeException('Payment driver did not provide a verifiable amount and currency');
        }

        return DB::transaction(function () use ($attempt, $amount, $currency) {
            $locked = PaymentAttempt::where('id', $attempt->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== PaymentAttempt::STATUS_INITIALIZING) {
                throw new \RuntimeException('Payment attempt is no longer available');
            }
            $locked->gateway_amount_minor = $amount;
            $locked->gateway_currency = $currency;
            $locked->status = PaymentAttempt::STATUS_PENDING;
            $locked->failure_reason = null;
            if (!$locked->save()) {
                throw new \RuntimeException('Unable to finalize payment attempt');
            }
            return $locked;
        });
    }

    public function markFailed(PaymentAttempt $attempt, string $reason): void
    {
        DB::transaction(function () use ($attempt, $reason) {
            $locked = PaymentAttempt::where('id', $attempt->id)->lockForUpdate()->first();
            if (!$locked || !$locked->isActive()) {
                return;
            }
            $locked->status = PaymentAttempt::STATUS_FAILED;
            $locked->failure_reason = $this->safeReason($reason);
            $locked->save();
        });
    }

    public function bindProviderReference(PaymentAttempt $attempt, string $reference): PaymentAttempt
    {
        if ($reference === '' || strlen($reference) > 128 || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1) {
            throw new \RuntimeException('Payment provider reference is invalid');
        }

        return DB::transaction(function () use ($attempt, $reference) {
            $locked = PaymentAttempt::where('id', $attempt->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== PaymentAttempt::STATUS_PENDING) {
                throw new \RuntimeException('Payment attempt is no longer available');
            }
            if ($locked->provider_reference !== null && !hash_equals((string)$locked->provider_reference, $reference)) {
                throw new \RuntimeException('Payment provider reference cannot be changed');
            }
            $locked->provider_reference = $reference;
            if (!$locked->save()) {
                throw new \RuntimeException('Unable to bind payment provider reference');
            }
            return $locked;
        });
    }

    /**
     * Accept a fully verified gateway result and atomically transition both
     * the attempt and its order. No unbound or stale callback may open access.
     */
    public function complete(array $verified): bool
    {
        $attemptNo = (string)($verified['trade_no'] ?? '');
        $callbackNo = trim((string)($verified['callback_no'] ?? ''));
        $amount = $verified['paid_amount_minor'] ?? null;
        $currency = strtoupper(trim((string)($verified['currency'] ?? '')));
        $paymentId = (int)($verified['payment_id'] ?? 0);
        $paymentUuid = (string)($verified['payment_uuid'] ?? '');
        $driver = (string)($verified['driver'] ?? '');

        if (!preg_match('/^[A-Za-z0-9]{32}$/', $attemptNo) || !$this->isValidCallbackNo($callbackNo) || !is_int($amount) || $amount < 1
            || !preg_match('/^[A-Z0-9]{3,12}$/', $currency) || $paymentId < 1 || $paymentUuid === '' || $driver === '') {
            $this->securityLog('Payment callback rejected: malformed verification result', [
                'attempt_ref' => $this->reference($attemptNo),
                'payment_id' => $paymentId,
            ]);
            return false;
        }

        $transition = DB::transaction(function () use ($attemptNo, $callbackNo, $amount, $currency, $paymentId, $paymentUuid, $driver) {
            $attempt = PaymentAttempt::where('attempt_no', $attemptNo)->lockForUpdate()->first();
            if (!$attempt) {
                $this->securityLog('Payment callback rejected: payment attempt not found', ['attempt_ref' => $this->reference($attemptNo)]);
                return null;
            }

            if ((int)$attempt->payment_id !== $paymentId
                || !hash_equals((string)$attempt->payment_uuid, $paymentUuid)
                || !hash_equals((string)$attempt->driver, $driver)) {
                $this->securityLog('Payment callback rejected: payment method mismatch', ['attempt_ref' => $this->reference($attemptNo)]);
                return null;
            }

            $order = Order::where('id', $attempt->order_id)->lockForUpdate()->first();
            if (!$order) {
                $this->securityLog('Payment callback rejected: order not found', ['attempt_ref' => $this->reference($attemptNo)]);
                return null;
            }

            if ((int)$attempt->gateway_amount_minor !== $amount || !hash_equals((string)$attempt->gateway_currency, $currency)) {
                $this->securityLog('Payment callback rejected: amount or currency mismatch', [
                    'attempt_ref' => $this->reference($attemptNo),
                    'payment_id' => $paymentId,
                ]);
                return null;
            }

            if ($attempt->status === PaymentAttempt::STATUS_PAID) {
                return hash_equals((string)$attempt->gateway_transaction_id, $callbackNo)
                    ? [
                        'trade_no' => $order->trade_no,
                        'newly_paid' => false,
                        'requires_open' => (int)$order->status === 1,
                    ]
                    : null;
            }
            if (!$attempt->isActive() || (int)$order->status !== 0) {
                $this->securityLog('Payment callback rejected: inactive payment attempt or order', [
                    'attempt_ref' => $this->reference($attemptNo),
                    'order_status' => (int)$order->status,
                ]);
                return [
                    'rejected' => true,
                    'cancelled' => (int)$order->status === 2,
                    'trade_no' => $order->trade_no,
                    'amount' => (int)$order->total_amount,
                    'attempt_no' => $attemptNo,
                    'callback_no' => $callbackNo,
                    'paid_amount_minor' => $amount,
                ];
            }

            $callbackHash = hash('sha256', $callbackNo);
            $duplicate = PaymentAttempt::where('payment_id', $paymentId)
                ->where('gateway_transaction_hash', $callbackHash)
                ->where('id', '!=', $attempt->id)
                ->lockForUpdate()
                ->exists();
            if ($duplicate) {
                $this->securityLog('Payment callback rejected: gateway transaction reused', ['payment_id' => $paymentId]);
                return null;
            }

            $attempt->gateway_transaction_id = $callbackNo;
            $attempt->gateway_transaction_hash = $callbackHash;
            $attempt->status = PaymentAttempt::STATUS_PAID;
            $attempt->failure_reason = null;
            if (!$attempt->save()) {
                throw new \RuntimeException('Unable to mark payment attempt as paid');
            }

            $order->status = 1;
            $order->paid_at = time();
            $order->callback_no = $callbackNo;
            if (!$order->save()) {
                throw new \RuntimeException('Unable to mark order as paid');
            }

            return ['trade_no' => $order->trade_no, 'newly_paid' => true, 'requires_open' => true];
        });

        if ($transition === null) {
            return false;
        }
        if (!empty($transition['rejected'])) {
            if (!empty($transition['cancelled'])) {
                $this->alertCancelledCallback(
                    (string)$transition['trade_no'],
                    (int)$transition['amount'],
                    (string)$transition['callback_no'],
                    (string)$transition['attempt_no'],
                    (int)$transition['paid_amount_minor']
                );
            }
            return false;
        }
        if ($transition['requires_open']) {
            OrderHandleJob::dispatch($transition['trade_no']);
        }
        if ($transition['newly_paid']) {
            try {
                $order = Order::where('trade_no', $transition['trade_no'])->first();
                if ($order) {
                    $this->sendPaymentReceiptNotification($order);
                }
            } catch (\Throwable $e) {
                Log::error('Payment receipt notification failed');
            }
        }
        return true;
    }

    /**
     * Reconcile a gateway payment that arrived after the order was cancelled.
     * The operator must verify the gateway transaction before calling this
     * method. All local balance and entitlement changes remain one transaction.
     */
    public function reconcileCancelledOrder(
        Order $order,
        string $callbackNo,
        int $paidAmountMinor,
        string $remark,
        ?int $operatorId = null
    ): bool {
        $callbackNo = trim($callbackNo);
        $remark = trim($remark);
        if (!$this->isValidCallbackNo($callbackNo)) {
            throw new \InvalidArgumentException('Gateway transaction ID is invalid');
        }
        if ($paidAmountMinor < 1) {
            throw new \InvalidArgumentException('Paid amount must be greater than zero');
        }
        if ($remark === '' || strlen($remark) > 255 || preg_match('/[\x00-\x1F\x7F]/', $remark)) {
            throw new \InvalidArgumentException('Reconciliation remark is required');
        }
        if (!$order->id) {
            throw new \InvalidArgumentException('Order does not exist');
        }

        $result = DB::transaction(function () use ($order, $callbackNo, $paidAmountMinor) {
            // Match callback/cancellation lock order: attempt first, then order.
            $attempt = PaymentAttempt::where('order_id', $order->id)->lockForUpdate()->first();
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();
            if (!$lockedOrder) {
                throw new \RuntimeException('Order does not exist');
            }
            if (!$attempt) {
                throw new \RuntimeException('Payment attempt record was not found');
            }
            $callbackHash = hash('sha256', $callbackNo);
            if ((int)$lockedOrder->status === 3) {
                if ($attempt->status === PaymentAttempt::STATUS_PAID
                    && (int)$attempt->gateway_amount_minor === $paidAmountMinor
                    && hash_equals((string)$attempt->gateway_transaction_id, $callbackNo)
                    && hash_equals((string)$attempt->gateway_transaction_hash, $callbackHash)) {
                    return ['trade_no' => $lockedOrder->trade_no, 'already_completed' => true];
                }
                throw new \RuntimeException('Order has already been completed with a different payment');
            }
            if ((int)$lockedOrder->status !== 2) {
                throw new \RuntimeException('Only cancelled orders can be reconciled');
            }
            if ($attempt->status === PaymentAttempt::STATUS_PAID) {
                throw new \RuntimeException('Payment attempt is already marked paid but the order is cancelled');
            }
            if ((int)$attempt->gateway_amount_minor !== $paidAmountMinor) {
                throw new \RuntimeException('Paid amount does not match the payment attempt');
            }

            $duplicate = PaymentAttempt::where('payment_id', $attempt->payment_id)
                ->where('gateway_transaction_hash', $callbackHash)
                ->where('id', '!=', $attempt->id)
                ->lockForUpdate()
                ->exists();
            if ($duplicate) {
                throw new \RuntimeException('Gateway transaction has already been reconciled');
            }

            // Cancellation may already have refunded the wallet portion of the
            // order. Reverse that refund before restoring the entitlement.
            if ((int)$lockedOrder->balance_amount > 0) {
                if (!(new UserService())->addBalance(
                    (int)$lockedOrder->user_id,
                    -(int)$lockedOrder->balance_amount,
                    'order_cancel_refund_reversal',
                    [
                        'source_type' => 'order',
                        'source_id' => $lockedOrder->id,
                        'unique_key' => 'order_cancel_refund_reversal:' . $lockedOrder->id,
                        'remark' => $lockedOrder->trade_no
                    ]
                )) {
                    throw new \RuntimeException('Unable to reverse the cancellation refund; check the user balance');
                }
            }
            $user = User::where('id', $lockedOrder->user_id)->lockForUpdate()->first();
            if (!$user) {
                throw new \RuntimeException('Order user does not exist');
            }

            $attempt->gateway_transaction_id = $callbackNo;
            $attempt->gateway_transaction_hash = $callbackHash;
            $attempt->status = PaymentAttempt::STATUS_PAID;
            $attempt->failure_reason = null;
            if (!$attempt->save()) {
                throw new \RuntimeException('Unable to save reconciled payment attempt');
            }

            $lockedOrder->status = 1;
            $lockedOrder->paid_at = $lockedOrder->paid_at ?: time();
            $lockedOrder->callback_no = $callbackNo;
            if (!$lockedOrder->save()) {
                throw new \RuntimeException('Unable to restore the cancelled order');
            }

            $orderService = new OrderService($lockedOrder);
            if (!$orderService->open()) {
                throw new \RuntimeException('Unable to open the reconciled order');
            }

            return ['trade_no' => $lockedOrder->trade_no, 'already_completed' => false];
        });

        Log::notice('Manual cancelled payment reconciliation completed', [
            'trade_no' => $result['trade_no'],
            'callback_no' => $callbackNo,
            'paid_amount_minor' => $paidAmountMinor,
            'operator_id' => $operatorId,
            'remark' => $remark,
            'already_completed' => $result['already_completed'],
        ]);
        if (!$result['already_completed']) {
            try {
                OrderHandleJob::dispatch($result['trade_no']);
            } catch (\Throwable $e) {
                Log::error('Reconciled order follow-up job dispatch failed', [
                    'trade_no' => $result['trade_no'],
                    'error' => $e->getMessage()
                ]);
            }
            try {
                $reconciledOrder = Order::where('trade_no', $result['trade_no'])->first();
                if ($reconciledOrder) {
                    $this->sendPaymentReceiptNotification($reconciledOrder);
                }
            } catch (\Throwable $e) {
                Log::error('Manual payment receipt notification failed', [
                    'trade_no' => $result['trade_no'],
                    'error' => $e->getMessage()
                ]);
            }
        }
        return true;
    }

    private function sendPaymentReceiptNotification(Order $order): void
    {
        $user = User::find($order->user_id);
        $payment = Payment::find($order->payment_id);
        $plan = $order->plan_id ? Plan::find($order->plan_id) : null;
        $coupon = $order->coupon_id ? Coupon::find($order->coupon_id) : null;
        $inviter = $order->invite_user_id ? User::find($order->invite_user_id) : null;
        $todayIncome = Order::whereNotNull('paid_at')
            ->where('paid_at', '>=', strtotime('today'))
            ->sum('total_amount');
        $siteUrl = (string) config('v2board.app_url', '');
        $siteHost = parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl;

        $message = sprintf(
            "💰 成功收款 %s 元\n———————————————\n🌐 支付接口：%s\n🏦 支付渠道：%s\n📧 用户邮箱：%s\n📦 购买套餐：%s\n📅 套餐周期：%s\n🎫 优  惠  券：%s\n👥 邀  请  人：%s\n🆔 订  单  号：%s\n🌐 来源网址：%s\n📅 注册日期：%s\n📍 下单 IP：%s\n———————————————\n💵 今日总收入：%s 元",
            number_format($order->total_amount / 100, 2, '.', ''),
            $this->telegramValue($payment->name ?? '未知'),
            $this->telegramValue($payment->payment ?? '未知'),
            $this->telegramValue($user->email ?? '未知'),
            $this->telegramValue($plan->name ?? ($order->plan_id ? '套餐已删除' : '余额充值')),
            $this->periodLabel((string) $order->period),
            $this->telegramValue($coupon->code ?? '无'),
            $this->telegramValue($inviter->email ?? '无'),
            $this->telegramValue($order->trade_no),
            $this->telegramValue($siteHost ?: '未配置'),
            $user ? date('Y-m-d H:i:s', (int) $user->created_at) : '未知',
            $this->telegramValue($order->client_ip ?: '暂无记录'),
            number_format($todayIncome / 100, 2, '.', '')
        );

        (new TelegramService())->sendMessageWithAdmin($message);
    }

    private function periodLabel(string $period): string
    {
        $labels = [
            'month_price' => '月付',
            'quarter_price' => '季付',
            'half_year_price' => '半年付',
            'year_price' => '年付',
            'two_year_price' => '两年付',
            'three_year_price' => '三年付',
            'onetime_price' => '一次性',
            'reset_price' => '流量重置包',
            'deposit' => '余额充值',
        ];

        return $labels[$period] ?? $period;
    }

    private function telegramValue($value): string
    {
        return str_replace(['_', '*', '[', ']', '`'], ['\_', '\*', '\[', '\]', '\`'], (string) $value);
    }

    private function isValidCallbackNo(string $callbackNo): bool
    {
        return $callbackNo !== ''
            && strlen($callbackNo) <= 255
            && preg_match('/[\x00-\x1F\x7F]/', $callbackNo) !== 1;
    }

    public function invalidateForPayment(int $paymentId, string $reason): int
    {
        $count = 0;
        PaymentAttempt::where('payment_id', $paymentId)
            ->whereIn('status', [PaymentAttempt::STATUS_INITIALIZING, PaymentAttempt::STATUS_PENDING])
            ->orderBy('id')
            ->chunkById(100, function ($attempts) use (&$count, $reason) {
                foreach ($attempts as $attempt) {
                    $invalidated = DB::transaction(function () use ($attempt, $reason) {
                        $locked = PaymentAttempt::where('id', $attempt->id)->lockForUpdate()->first();
                        if (!$locked || !$locked->isActive()) {
                            return false;
                        }
                        $order = Order::where('id', $locked->order_id)->lockForUpdate()->first();
                        if (!$order || (int)$order->status !== 0) {
                            $locked->status = PaymentAttempt::STATUS_INVALIDATED;
                            $locked->failure_reason = $this->safeReason($reason);
                            $locked->save();
                            return false;
                        }

                        if (!(new OrderService($order))->cancel()) {
                            throw new \RuntimeException('Unable to cancel order while invalidating payment attempt');
                        }
                        $locked->status = PaymentAttempt::STATUS_INVALIDATED;
                        $locked->failure_reason = $this->safeReason($reason);
                        $locked->save();
                        return true;
                    });
                    if ($invalidated) {
                        $count++;
                    }
                }
            });
        return $count;
    }

    public function cancelOrder(Order $order, string $reason = 'order cancelled by user'): bool
    {
        return (bool)DB::transaction(function () use ($order, $reason) {
            // Keep the same attempt -> order lock sequence used by callbacks
            // and payment-method invalidation to avoid a terminal-state deadlock.
            $attempt = PaymentAttempt::where('order_id', $order->id)->lockForUpdate()->first();
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();
            if (!$lockedOrder) {
                return false;
            }

            // Checkout may have committed its attempt while this request waited
            // on the order row. Re-read it before deciding whether to invalidate.
            if (!$attempt) {
                $attempt = PaymentAttempt::where('order_id', $lockedOrder->id)->lockForUpdate()->first();
            }
            if ($attempt && $attempt->isActive()) {
                $attempt->status = PaymentAttempt::STATUS_INVALIDATED;
                $attempt->failure_reason = $this->safeReason($reason);
                if (!$attempt->save()) {
                    throw new \RuntimeException('Unable to invalidate payment attempt');
                }
            }

            return (new OrderService($lockedOrder))->cancel();
        });
    }

    public function invalidateLegacyPendingOrders(): int
    {
        $count = 0;
        Order::where('status', 0)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('v2_payment_attempt')
                    ->whereColumn('v2_payment_attempt.order_id', 'v2_order.id');
            })
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    if ((new OrderService($order))->cancel()) {
                        $count++;
                    }
                }
            });
        return $count;
    }

    public static function isQuarantinedDriver(string $driver): bool
    {
        return $driver === 'MGate';
    }

    /**
     * BTCPay and Coinbase remain unavailable until an administrator has
     * completed the required sandbox review and explicitly allowlisted them.
     * MGate has no trustworthy settlement/query contract and is never allowed.
     */
    public static function isDriverAvailable(string $driver): bool
    {
        if (self::isQuarantinedDriver($driver)) {
            return false;
        }
        if (!in_array($driver, self::HIGH_RISK_DRIVERS, true)) {
            return true;
        }

        return in_array($driver, (array)config('v2board.payment_secure_driver_allowlist', []), true);
    }

    public static function isInstalledDriver(string $driver): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $driver) === 1
            && class_exists('\\App\\Payments\\' . $driver);
    }

    private function safeReason(string $reason): string
    {
        return substr(preg_replace('/[^A-Za-z0-9 _.:\-]/', '', $reason), 0, 255);
    }

    private function securityLog(string $message, array $context): void
    {
        Log::warning($message, $context);
    }

    private function reference(string $value): string
    {
        return substr(hash('sha256', $value), 0, 16);
    }

    private function alertCancelledCallback(
        string $tradeNo,
        int $amount,
        string $callbackNo,
        string $attemptNo,
        int $paidAmountMinor
    ): void
    {
        try {
            (new TelegramService())->sendMessageWithAdmin(sprintf(
                "Payment callback received for a cancelled order. Manual reconciliation required.\nOrder: %s\nAmount: %s\nGateway transaction: %s\nCheckout reference: %s\nCallback amount: %s",
                $this->telegramValue($tradeNo),
                $amount / 100,
                $this->telegramValue($callbackNo),
                $this->telegramValue($attemptNo),
                $paidAmountMinor / 100
            ));
        } catch (\Throwable $e) {
            Log::error('Cancelled payment callback alert failed');
        }
    }
}
