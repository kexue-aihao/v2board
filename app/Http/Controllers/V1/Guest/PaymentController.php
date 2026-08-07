<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\PaymentAttemptService;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verified = $paymentService->notify($request->input());
            if (!$verified) {
                abort(500, 'Payment verification failed');
            }

            // Gateways may send signed non-terminal notifications. They are
            // acknowledged without opening an order.
            if (!is_array($verified)) {
                return $verified;
            }

            if (!(new PaymentAttemptService())->complete($verified)) {
                abort(500, 'Payment callback does not match an active checkout');
            }

            return isset($verified['custom_result']) ? $verified['custom_result'] : 'success';
        } catch (\Throwable $e) {
            abort(500, 'Payment callback failed');
        }
    }
}
