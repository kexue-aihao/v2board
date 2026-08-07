<?php

namespace App\Services;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentAttemptService
{
    private const HIGH_RISK_DRIVERS = ['BTCPay', 'Coinbase', 'MGate'];

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

        if (!preg_match('/^[A-Za-z0-9]{32}$/', $attemptNo) || $callbackNo === '' || strlen($callbackNo) > 255 || !is_int($amount) || $amount < 1
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
                $this->alertCancelledCallback((string)$transition['trade_no'], (int)$transition['amount']);
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
                    (new TelegramService())->sendMessageWithAdmin(sprintf(
                        "Payment received: %s\nOrder: %s",
                        $order->total_amount / 100,
                        $order->trade_no
                    ));
                }
            } catch (\Throwable $e) {
                Log::error('Payment receipt notification failed');
            }
        }
        return true;
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

    private function alertCancelledCallback(string $tradeNo, int $amount): void
    {
        try {
            (new TelegramService())->sendMessageWithAdmin(sprintf(
                "Payment callback received for a cancelled order. Manual reconciliation required.\nOrder: %s\nAmount: %s",
                $tradeNo,
                $amount / 100
            ));
        } catch (\Throwable $e) {
            Log::error('Cancelled payment callback alert failed');
        }
    }
}
