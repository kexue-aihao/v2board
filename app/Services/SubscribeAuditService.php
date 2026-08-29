<?php

namespace App\Services;

use App\Models\SubscribeRequestLog;
use App\Models\SubscribeIpSummary;
use App\Models\SubscribeUserAgentSummary;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SubscribeAuditService
{
    private const MAX_USER_AGENT_LENGTH = 1000;

    private static $decisionColumnsAvailable;

    private static $summaryTablesAvailable;

    private static $summaryUnavailableLogged = false;

    public function record(Request $request, $user, ?Subscription $subscription = null, array $result = []): ?SubscribeRequestLog
    {
        if (!$user) {
            return null;
        }

        try {
            if (!Schema::hasTable('v2_subscribe_request_log')) {
                return null;
            }

            $userAgent = $this->normalizeUserAgent($request);
            $payload = [
                'user_id' => (int)$user->id,
                'subscription_id' => $subscription ? (int)$subscription->id : null,
                'user_agent' => $userAgent,
                'ua_hash' => hash('sha256', strtolower($userAgent)),
                'request_ip' => $this->resolveIp($request),
                'requested_at' => time()
            ];

            if ($this->decisionColumnsAvailable()) {
                $payload = array_merge($payload, [
                    'decision' => $this->decision($result),
                    'block_rule_id' => isset($result['block_rule_id']) ? (int)$result['block_rule_id'] : null,
                    'block_scope' => isset($result['block_scope']) ? (string)$result['block_scope'] : null,
                    'block_reason' => isset($result['block_reason']) ? (string)$result['block_reason'] : null
                ]);
            }

            $audit = SubscribeRequestLog::create($payload);
            $this->syncSummaries($audit);

            return $audit;
        } catch (\Throwable $e) {
            // Audit failure must not make an otherwise valid subscription unusable.
            Log::warning('Subscription audit failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function normalizeUserAgent(Request $request): string
    {
        $userAgent = trim((string)$request->header('User-Agent', ''));
        if ($userAgent === '') {
            $userAgent = '(empty)';
        }

        return function_exists('mb_substr')
            ? mb_substr($userAgent, 0, self::MAX_USER_AGENT_LENGTH)
            : substr($userAgent, 0, self::MAX_USER_AGENT_LENGTH);
    }

    public function userAgentHash(Request $request): string
    {
        return hash('sha256', strtolower($this->normalizeUserAgent($request)));
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
        if (!filter_var($address, FILTER_VALIDATE_IP)) {
            return 'unknown';
        }

        // IPv6 may have multiple equivalent textual forms. Store the packed-and-restored form
        // so the summary uniqueness key is stable for the same address.
        return $this->normalizeIpAddress($address);
    }

    /**
     * Rebuild both summary tables from the currently retained raw audit trail.
     *
     * The command clears derived data first, then replays rows in audit ID order. An upper
     * bound avoids replaying records created after the rebuild has started; those records are
     * independently added by record() through the normal write path. Records removed by the
     * audit retention job cannot be reconstructed by a rebuild.
     */
    public function rebuildSummaries(int $chunk = 1000, ?callable $progress = null): array
    {
        if (!$this->summaryTablesAvailable() || !Schema::hasTable('v2_subscribe_request_log')) {
            return ['available' => false, 'audits' => 0];
        }

        $chunk = max(100, min(5000, $chunk));
        // Establish the replay boundary and clear derived rows atomically. Requests
        // recorded after this commit have IDs above the ceiling and update their
        // summaries through the normal write path, so they cannot be erased by a
        // concurrently running rebuild.
        $ceiling = (int) DB::transaction(function () {
            $ceiling = (int) SubscribeRequestLog::max('id');
            SubscribeIpSummary::query()->delete();
            SubscribeUserAgentSummary::query()->delete();

            return $ceiling;
        });

        $processed = 0;
        if ($ceiling <= 0) {
            return $this->summaryRebuildResult(0);
        }

        SubscribeRequestLog::where('id', '<=', $ceiling)
            ->orderBy('id')
            ->chunkById($chunk, function ($audits) use (&$processed, $progress) {
                foreach ($audits as $audit) {
                    $this->upsertSummaries($audit);
                    $processed++;
                }
                if ($progress) {
                    $progress($processed);
                }
            });

        return $this->summaryRebuildResult($processed);
    }

    /**
     * The summary tables are derived data, but a successful rebuild must not
     * silently leave them empty while there are raw rows to display.  The
     * counters deliberately allow rows written after the rebuild ceiling: the
     * normal request path may add those concurrently while the replay runs.
     */
    private function summaryRebuildResult(int $audits): array
    {
        $ipRows = (int) SubscribeIpSummary::query()->count();
        $userAgentRows = (int) SubscribeUserAgentSummary::query()->count();
        $ipHits = (int) SubscribeIpSummary::query()->sum('hit_count');
        $userAgentHits = (int) SubscribeUserAgentSummary::query()->sum('hit_count');

        return [
            'available' => true,
            'audits' => $audits,
            'ip_rows' => $ipRows,
            'user_agent_rows' => $userAgentRows,
            'ip_hits' => $ipHits,
            'user_agent_hits' => $userAgentHits,
            'verified' => $audits === 0 || (
                $ipRows > 0
                && $userAgentRows > 0
                && $ipHits >= $audits
                && $userAgentHits >= $audits
            )
        ];
    }

    private function syncSummaries(SubscribeRequestLog $audit): void
    {
        if (!$this->summaryTablesAvailable()) {
            if (!self::$summaryUnavailableLogged) {
                Log::warning('Subscription audit summaries are unavailable; raw audit was retained.');
                self::$summaryUnavailableLogged = true;
            }
            return;
        }

        try {
            $this->upsertSummaries($audit);
        } catch (\Throwable $e) {
            // The raw audit is the source of truth. A later rebuild command repairs derived rows.
            Log::warning('Subscription audit summary update failed', [
                'audit_id' => (int) $audit->id,
                'user_id' => (int) $audit->user_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function upsertSummaries(SubscribeRequestLog $audit): void
    {
        $now = time();
        $auditId = (int) $audit->id;
        $userId = (int) $audit->user_id;
        $requestedAt = (int) $audit->getRawOriginal('requested_at');
        $subscriptionId = $audit->subscription_id === null ? null : (int) $audit->subscription_id;
        $decision = $this->auditDecision($audit);
        $requestIp = $this->normalizeIpAddress((string) $audit->request_ip);

        // The two summaries describe the same immutable audit event. Keep them
        // together so a transient database error cannot leave only one view ahead.
        DB::transaction(function () use ($audit, $now, $auditId, $userId, $requestedAt, $subscriptionId, $decision, $requestIp) {
        DB::statement(
            'INSERT INTO `v2_subscribe_ip_summary` '
            . '(`user_id`,`request_ip`,`hit_count`,`first_seen_at`,`last_seen_at`,`recent_audit_id`,'
            . '`recent_subscription_id`,`recent_user_agent`,`recent_decision`,`created_at`,`updated_at`) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE '
            . '`hit_count` = `hit_count` + 1,'
            . '`first_seen_at` = LEAST(`first_seen_at`, VALUES(`first_seen_at`)),'
            . '`recent_subscription_id` = IF(VALUES(`last_seen_at`) > `last_seen_at` OR '
            . '(VALUES(`last_seen_at`) = `last_seen_at` AND VALUES(`recent_audit_id`) > `recent_audit_id`), '
            . 'VALUES(`recent_subscription_id`), `recent_subscription_id`),'
            . '`recent_user_agent` = IF(VALUES(`last_seen_at`) > `last_seen_at` OR '
            . '(VALUES(`last_seen_at`) = `last_seen_at` AND VALUES(`recent_audit_id`) > `recent_audit_id`), '
            . 'VALUES(`recent_user_agent`), `recent_user_agent`),'
            . '`recent_decision` = IF(VALUES(`last_seen_at`) > `last_seen_at` OR '
            . '(VALUES(`last_seen_at`) = `last_seen_at` AND VALUES(`recent_audit_id`) > `recent_audit_id`), '
            . 'VALUES(`recent_decision`), `recent_decision`),'
            . '`recent_audit_id` = IF(VALUES(`last_seen_at`) > `last_seen_at` OR '
            . '(VALUES(`last_seen_at`) = `last_seen_at` AND VALUES(`recent_audit_id`) > `recent_audit_id`), '
            . 'VALUES(`recent_audit_id`), `recent_audit_id`),'
            . '`last_seen_at` = GREATEST(`last_seen_at`, VALUES(`last_seen_at`)),'
            . '`updated_at` = VALUES(`updated_at`)',
            [$userId, $requestIp, 1, $requestedAt, $requestedAt, $auditId,
                $subscriptionId, (string) $audit->user_agent, $decision, $now, $now]
        );

        DB::statement(
            'INSERT INTO `v2_subscribe_user_agent_summary` '
            . '(`user_id`,`ua_hash`,`user_agent`,`hit_count`,`first_seen_at`,`last_seen_at`,`recent_audit_id`,'
            . '`recent_subscription_id`,`recent_request_ip`,`recent_decision`,`created_at`,`updated_at`) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE '
            . '`hit_count` = `hit_count` + 1,'
            . '`first_seen_at` = LEAST(`first_seen_at`, VALUES(`first_seen_at`)),'
            . '`user_agent` = IF(VALUES(`last_seen_at`) > `last_seen_at` OR '
            . '(VALUES(`last_seen_at`) = `last_seen_at` AND VALUES(`recent_audit_id`) > `recent_audit_id`), '
            . 'VALUES(`user_agent`), `user_agent`),'
            . '`recent_subscription_id` = IF(VALUES(`last_seen_at`) > `last_seen_at` OR '
            . '(VALUES(`last_seen_at`) = `last_seen_at` AND VALUES(`recent_audit_id`) > `recent_audit_id`), '
            . 'VALUES(`recent_subscription_id`), `recent_subscription_id`),'
            . '`recent_request_ip` = IF(VALUES(`last_seen_at`) > `last_seen_at` OR '
            . '(VALUES(`last_seen_at`) = `last_seen_at` AND VALUES(`recent_audit_id`) > `recent_audit_id`), '
            . 'VALUES(`recent_request_ip`), `recent_request_ip`),'
            . '`recent_decision` = IF(VALUES(`last_seen_at`) > `last_seen_at` OR '
            . '(VALUES(`last_seen_at`) = `last_seen_at` AND VALUES(`recent_audit_id`) > `recent_audit_id`), '
            . 'VALUES(`recent_decision`), `recent_decision`),'
            . '`recent_audit_id` = IF(VALUES(`last_seen_at`) > `last_seen_at` OR '
            . '(VALUES(`last_seen_at`) = `last_seen_at` AND VALUES(`recent_audit_id`) > `recent_audit_id`), '
            . 'VALUES(`recent_audit_id`), `recent_audit_id`),'
            . '`last_seen_at` = GREATEST(`last_seen_at`, VALUES(`last_seen_at`)),'
            . '`updated_at` = VALUES(`updated_at`)',
            [$userId, (string) $audit->ua_hash, (string) $audit->user_agent, 1, $requestedAt, $requestedAt,
                $auditId, $subscriptionId, $requestIp, $decision, $now, $now]
        );
        });
    }

    private function normalizeIpAddress(string $address): string
    {
        $address = trim($address);
        if (!filter_var($address, FILTER_VALIDATE_IP)) {
            return 'unknown';
        }

        $packed = @inet_pton($address);
        return $packed === false ? $address : inet_ntop($packed);
    }

    private function summaryTablesAvailable(): bool
    {
        if (self::$summaryTablesAvailable !== null) {
            return self::$summaryTablesAvailable;
        }

        try {
            return self::$summaryTablesAvailable = Schema::hasTable('v2_subscribe_ip_summary')
                && Schema::hasTable('v2_subscribe_user_agent_summary');
        } catch (\Throwable $e) {
            return self::$summaryTablesAvailable = false;
        }
    }

    private function auditDecision(SubscribeRequestLog $audit): string
    {
        $decision = (string) ($audit->decision ?: 'allowed');
        return in_array($decision, ['allowed', 'blocked', 'error'], true) ? $decision : 'allowed';
    }

    private function decisionColumnsAvailable(): bool
    {
        if (self::$decisionColumnsAvailable !== null) {
            return self::$decisionColumnsAvailable;
        }

        try {
            return self::$decisionColumnsAvailable = Schema::hasColumn('v2_subscribe_request_log', 'decision')
                && Schema::hasColumn('v2_subscribe_request_log', 'block_rule_id')
                && Schema::hasColumn('v2_subscribe_request_log', 'block_scope')
                && Schema::hasColumn('v2_subscribe_request_log', 'block_reason');
        } catch (\Throwable $e) {
            return self::$decisionColumnsAvailable = false;
        }
    }

    private function decision(array $result): string
    {
        $decision = isset($result['decision']) ? (string)$result['decision'] : 'allowed';

        return in_array($decision, ['allowed', 'blocked', 'error'], true) ? $decision : 'allowed';
    }
}
