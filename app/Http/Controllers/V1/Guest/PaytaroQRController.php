<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Payments\PaytaroQR;
use App\Services\PaymentReturnUrlService;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class PaytaroQRController extends Controller
{
    public function page(string $attemptNo, string $invoiceUuid)
    {
        list($attempt, $invoice, $details) = $this->loadInvoice($attemptNo, $invoiceUuid);
        $payment = isset($invoice['payment']) && is_array($invoice['payment']) ? $invoice['payment'] : [];
        $paymentData = trim((string)($payment['data'] ?? ''));
        if ($paymentData === '') {
            abort(502, 'Payment data is unavailable');
        }

        try {
            $qr = (new QRCode(new QROptions([
                'outputType' => QRCode::OUTPUT_MARKUP_SVG,
                'eccLevel' => QRCode::ECC_L,
                'scale' => 5,
            ])))->render($paymentData);
        } catch (\Throwable $e) {
            abort(500, 'Unable to render payment QR code');
        }

        $order = $attempt->order;
        if (!$order) {
            abort(404, 'Payment order does not exist');
        }
        $returnUrl = (new PaymentReturnUrlService())->forOrder((string)$order->trade_no);
        $statusUrl = url('/api/v1/guest/payment/paytaro-qr/' . rawurlencode($attemptNo) . '/' . rawurlencode($invoiceUuid) . '/status');
        $paymentName = $this->escape((string)($payment['name'] ?? 'Paytaro'));
        $amount = $this->escape(number_format($details['amount_minor'] / 100, 2, '.', '') . ' ' . $details['currency']);
        $address = $this->escape($paymentData);
        $expiresAt = isset($invoice['expired_at']) ? (int)$invoice['expired_at'] : 0;
        $pageConfig = json_encode([
            'statusUrl' => $statusUrl,
            'returnUrl' => $returnUrl,
            'expiresAt' => $expiresAt,
            'serverTime' => isset($invoice['server_time']) ? (int)$invoice['server_time'] : time(),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        if ($pageConfig === false) {
            abort(500, 'Unable to render payment page');
        }

        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>Paytaro Payment</title><style>'
            . 'body{margin:0;background:#f5f7fa;color:#1f2937;font:15px -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}'
            . '.wrap{max-width:440px;margin:28px auto;padding:0 16px}.panel{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:24px;text-align:center}'
            . 'h1{font-size:20px;margin:0 0 12px}.amount{font-size:24px;font-weight:600;margin:8px 0 20px}.qr{width:260px;max-width:100%;height:auto;background:#fff}'
            . '.field{margin-top:18px;text-align:left}.field label{display:block;color:#6b7280;margin-bottom:6px}.value{word-break:break-all;background:#f9fafb;border:1px solid #e5e7eb;padding:10px;border-radius:4px;font-family:ui-monospace,monospace}'
            . '#state{min-height:20px;margin-top:18px;color:#4b5563}.expired{color:#b45309}.failed{color:#b91c1c}</style></head><body><main class="wrap"><section class="panel">'
            . '<h1>' . $paymentName . '</h1><div class="amount">' . $amount . '</div>'
            . '<img class="qr" alt="Payment QR code" src="data:image/svg+xml;base64,' . base64_encode($qr) . '">'
            . '<div class="field"><label>Payment address</label><div class="value">' . $address . '</div></div>'
            . '<div id="state">Waiting for payment confirmation</div></section></main><script>(function(){var c=' . $pageConfig . ';var state=document.getElementById("state");'
            . 'function show(t,k){state.textContent=t;state.className=k||""}function poll(){fetch(c.statusUrl,{cache:"no-store"}).then(function(r){return r.json()}).then(function(r){var d=r.data||r;var s=String(d.status||"").toUpperCase();if(s==="PAID"||s==="SUCCESS"){show("Payment confirmed. Redirecting...");setTimeout(function(){location.replace(c.returnUrl)},800);return}if(s==="CANCEL"||s==="EXPIRED"){show("This payment has expired.","expired");return}if(c.expiresAt&&Math.floor(Date.now()/1000)>c.serverTime+(c.expiresAt-c.serverTime)){show("This payment has expired.","expired");return}setTimeout(poll,3000)}).catch(function(){setTimeout(poll,5000)})}poll()})();</script>'
            . '</body></html>';

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    public function status(string $attemptNo, string $invoiceUuid)
    {
        list($attempt, $invoice) = $this->loadInvoice($attemptNo, $invoiceUuid);
        return response([
            'data' => [
                'status' => strtoupper(trim((string)($invoice['status'] ?? ''))),
                'expired_at' => isset($invoice['expired_at']) ? (int)$invoice['expired_at'] : null,
                'server_time' => isset($invoice['server_time']) ? (int)$invoice['server_time'] : time(),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    private function loadInvoice(string $attemptNo, string $invoiceUuid): array
    {
        if (!preg_match('/^[A-Za-z0-9]{32}$/', $attemptNo)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $invoiceUuid)) {
            abort(404);
        }
        $attempt = PaymentAttempt::with('order')
            ->where('attempt_no', $attemptNo)
            ->where('driver', 'PaytaroQR')
            ->where('provider_reference', $invoiceUuid)
            ->where('status', PaymentAttempt::STATUS_PENDING)
            ->first();
        if (!$attempt) {
            abort(404);
        }

        $invoice = PaytaroQR::fetchInvoice($invoiceUuid);
        $details = $invoice ? PaytaroQR::invoiceDetails($attempt, $invoice) : null;
        if ($details === null) {
            abort(502, 'Payment invoice cannot be verified');
        }
        return [$attempt, $invoice, $details];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
