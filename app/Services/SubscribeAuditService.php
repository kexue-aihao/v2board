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
        foreach ([
            $request->header('CF-Connecting-IP'),
            $request->header('X-Real-IP'),
            $request->header('X-Forwarded-For')
        ] as $candidate) {
            $candidate = trim(explode(',', (string)$candidate)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        $address = $request->server('REMOTE_ADDR') ?: $request->ip();
        return filter_var($address, FILTER_VALIDATE_IP) ? $address : 'unknown';
    }
}
