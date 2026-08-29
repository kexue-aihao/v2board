<?php

namespace App\Services;

use App\Models\SubscribeBlockRule;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SubscribeGatewayService
{
    private const ERROR_LOG_INTERVAL = 300;

    private static $lastErrorLogAt = 0;

    /**
     * Inspect independently managed manual block rules. A failure deliberately
     * returns error so the caller can audit it while continuing the request.
     */
    public function inspect(Request $request, $user, ?Subscription $subscription = null): array
    {
        try {
            if (!Schema::hasTable('v2_subscribe_block_rule')) {
                return $this->allowed();
            }

            $audit = new SubscribeAuditService();
            $targets = [
                ['subscription', 'subscription_id', $subscription ? (int)$subscription->id : null],
                ['user', 'user_id', $user ? (int)$user->id : null],
                ['ip', 'ip', $audit->resolveIp($request)],
                ['user_agent', 'user_agent_hash', $audit->userAgentHash($request)]
            ];
            $now = time();

            foreach ($targets as $target) {
                list($scope, $column, $value) = $target;
                if ($value === null) {
                    continue;
                }

                $rule = SubscribeBlockRule::where('scope', $scope)
                    ->where($column, $value)
                    ->where('status', 'active')
                    ->where(function ($query) use ($now) {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                    })
                    ->orderBy('id')
                    ->first();

                if ($rule) {
                    return [
                        'decision' => 'blocked',
                        'block_rule_id' => (int)$rule->id,
                        'block_scope' => (string)$rule->scope,
                        'block_reason' => $rule->reason === null ? null : (string)$rule->reason
                    ];
                }
            }

            return $this->allowed();
        } catch (\Throwable $e) {
            $this->logFailure($e);

            return [
                'decision' => 'error',
                'block_rule_id' => null,
                'block_scope' => null,
                'block_reason' => null
            ];
        }
    }

    private function allowed(): array
    {
        return [
            'decision' => 'allowed',
            'block_rule_id' => null,
            'block_scope' => null,
            'block_reason' => null
        ];
    }

    private function logFailure(\Throwable $e): void
    {
        $now = time();
        if (self::$lastErrorLogAt + self::ERROR_LOG_INTERVAL > $now) {
            return;
        }

        try {
            if (!Cache::add('subscribe_gateway:inspection_error', 1, self::ERROR_LOG_INTERVAL)) {
                return;
            }
        } catch (\Throwable $cacheError) {
            // A process-local fallback still prevents a failing cache from flooding logs.
        }

        self::$lastErrorLogAt = $now;
        try {
            Log::warning('Subscribe gateway inspection failed; allowing request', [
                'error' => $e->getMessage()
            ]);
        } catch (\Throwable $logError) {
            // Logging must not violate the subscription path's fail-open contract.
        }
    }
}
