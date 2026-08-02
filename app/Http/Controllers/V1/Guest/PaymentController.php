<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) abort(500, 'verify error');
            if (!$this->handle($verify)) {
                abort(500, 'handle error');
            }
            return(isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            abort(500, 'fail');
        }
    }

    private function handle(array $verify)
    {
        $tradeNo = $verify['trade_no'];
        $callbackNo = $verify['callback_no'];
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            abort(500, 'order is not found');
        }
        if ($order->status !== 0) {
            // 幂等：已支付(1)/已完成(3) 的重复回调是网关正常重试，静默确认即可。
            // 但「已取消(2)」的订单又收到真实支付回调 = 用户的钱进了网关、平台已取消该单 —— 原实现
            // 这里直接 return true 把它无声吞掉（不退款、不开通、无任何记录）。改为记日志 + 通知管理员，
            // 让这笔钱可见、可人工处置。是否自动退款/重开属产品决策，未在此自动执行。
            if ((int)$order->status === 2) {
                Log::warning('Paid callback received for a cancelled order', [
                    'trade_no' => $order->trade_no,
                    'user_id' => $order->user_id,
                    'callback_no' => $callbackNo,
                    'total_amount' => $order->total_amount
                ]);
                try {
                    (new TelegramService())->sendMessageWithAdmin(sprintf(
                        "⚠️已取消的订单又收到支付回调，请人工核实是否需要退款\n———————————————\n订单号：%s\n用户ID：%s\n金额：%s 元\n回调号：%s",
                        $order->trade_no,
                        $order->user_id,
                        $order->total_amount / 100,
                        $callbackNo
                    ));
                } catch (\Throwable $e) {
                    Log::error('Failed to alert admin about a paid cancelled order', ['error' => $e->getMessage()]);
                }
            }
            return true;
        }
        // #25：实付金额校验。驱动回传 paid_amount（分）时，与应付额（订单金额 + 手续费）比对，
        // 欠款（超过 1 分四舍五入误差）则拒绝开通并告警 —— 防止用户少付却拿到全额服务。
        // 未回传 paid_amount 的驱动不受影响（向后兼容，其余网关需按各自回调样本逐一接入）。
        $paidAmount = $verify['paid_amount'] ?? null;
        if ($paidAmount !== null) {
            $expected = (int)$order->total_amount + (int)($order->handling_amount ?? 0);
            if ((int)$paidAmount + 1 < $expected) {
                Log::warning('Underpaid payment callback rejected', [
                    'trade_no' => $order->trade_no,
                    'user_id' => $order->user_id,
                    'expected' => $expected,
                    'paid' => (int)$paidAmount,
                    'callback_no' => $callbackNo
                ]);
                try {
                    (new TelegramService())->sendMessageWithAdmin(sprintf(
                        "⚠️支付回调金额不足，已拒绝开通\n———————————————\n订单号：%s\n用户ID：%s\n应付：%s 元\n实付：%s 元",
                        $order->trade_no,
                        $order->user_id,
                        $expected / 100,
                        ((int)$paidAmount) / 100
                    ));
                } catch (\Throwable $e) {
                    Log::error('Failed to alert admin about an underpaid callback', ['error' => $e->getMessage()]);
                }
                return false;
            }
        }
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }
        $telegramService = new TelegramService();
        $message = sprintf(
            "💰成功收款%s元\n———————————————\n订单号：%s",
            $order->total_amount / 100,
            $order->trade_no
        );
        $telegramService->sendMessageWithAdmin($message);
        return true;
    }
}
