<?php

namespace App\Services;

use App\Models\SubscribeRequestLog;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SubscribeAuditService
{
    private const MAX_USER_AGENT_LENGTH = 1000;

    public function record(Request $request, $user, ?Subscription $subscription = null): ?SubscribeRequestLog
    {
        if (!$user || !Schema::hasTable('v2_subscribe_request_log')) {
            return null;
        }

        $userAgent = trim((string)$request->header('User-Agent', ''));
        if ($userAgent === '') {
            $userAgent = '(empty)';
        }
        $userAgent = function_exists('mb_substr')
            ? mb_substr($userAgent, 0, self::MAX_USER_AGENT_LENGTH)
            : substr($userAgent, 0, self::MAX_USER_AGENT_LENGTH);

        try {
            return SubscribeRequestLog::create([
                'user_id' => (int)$user->id,
                'subscription_id' => $subscription ? (int)$subscription->id : null,
                'user_agent' => $userAgent,
                'ua_hash' => hash('sha256', strtolower($userAgent)),
                'request_ip' => $this->resolveIp($request),
                'requested_at' => time()
            ]);
        } catch (\Throwable $e) {
            // Audit failure must not make an otherwise valid subscription unusable.
            return null;
        }
    }

    public function resolveIp(Request $request): string
    {
        // 站点经反向代理接入，REMOTE_ADDR 恒为回环地址，直接读它审计不到任何东西。
        // 改用 $request->ip()：它只有在对端属于 config/trustedproxy.php 声明的可信
        // 代理时才解析转发头，否则依旧回退到 REMOTE_ADDR，所以客户端自行伪造的
        // 转发头仍然进不了审计记录。
        $address = $request->ip();
        if (!filter_var($address, FILTER_VALIDATE_IP)) {
            $address = $request->server('REMOTE_ADDR');
        }
        return filter_var($address, FILTER_VALIDATE_IP) ? $address : 'unknown';
    }
}
