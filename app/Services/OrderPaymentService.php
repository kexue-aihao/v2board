<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;

/**
 * 发起支付：创建支付尝试、调用网关，返回 ['type' => int, 'data' => mixed]。
 *
 * 从 OrderController::checkout 原样抽出，供网页与 Telegram 机器人共用 —— 两处的差别只在
 * 谁来解析参数、谁来校验订单归属，网关这一段完全一致，不该各写一份。
 *
 * type 语义（与前端一致）：-1 免支付已完成；0 二维码（data 是二维码内容）；1 跳转链接（data 是 URL）。
 */
class OrderPaymentService
{
    public function initiate(Order $order, $paymentId, ?string $stripeToken = null): array
    {
        // 免支付订单：金额为 0 时直接开通
        if ($order->total_amount <= 0) {
            $orderService = new OrderService($order);
            if (!$orderService->completeFree()) {
                abort(500, 'Free order could not be opened');
            }
            return ['type' => -1, 'data' => true];
        }

        $payment = Payment::find($paymentId);
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
            'stripe_token' => $stripeToken
        ];

        try {
            $quote = $paymentService->prepare($checkout);
            $attempt = $attemptService->markPending($attempt, $quote);
            $checkout['gateway_amount_minor'] = (int)$attempt->gateway_amount_minor;
            $checkout['gateway_currency'] = (string)$attempt->gateway_currency;
            $result = $paymentService->pay($checkout);
            if (!empty($result['provider_reference'])) {
                $attempt = $attemptService->bindProviderReference($attempt, (string)$result['provider_reference']);
            }
        } catch (\Throwable $e) {
            $attemptService->markFailed($attempt, 'payment gateway initialization failed');
            abort(500, __('Payment gateway request failed'));
        }

        return [
            'type' => $result['type'],
            'data' => $result['data']
        ];
    }
}
