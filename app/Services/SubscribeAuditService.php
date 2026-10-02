<?php

namespace App\Services;

use App\Models\SubscribeAccessSummary;
use App\Models\SubscribeRequestLog;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 订阅拉取审计。
 *
 * 原始证据落 v2_subscribe_request_log（每次拉取一行，写入路径上唯一的 INSERT）；
 * 同一笔事件同时累加到 v2_subscribe_access_summary（账号 × 订阅 × IP × UA 四元组），
 * 「订阅清洗网关」页面只读聚合表，不再对原始日志做任何 GROUP BY。
 *
 * 审计失败不得让一条本来可用的订阅不可用 —— 全流程 catch + 放行。
 */
class SubscribeAuditService
{
    private const MAX_USER_AGENT_LENGTH = 1000;

    private static $decisionColumnsAvailable;

    private static $summaryAvailable;

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
            $this->syncSummary($audit);

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
     * Rebuild the aggregate from the currently retained raw audit trail.
     *
     * The command clears derived data first, then replays rows in audit ID order. An upper
     * bound avoids replaying records created after the rebuild has started; those records are
     * independently added by record() through the normal write path. Records removed by the
     * audit retention job cannot be reconstructed by a rebuild.
     */
    public function rebuildSummaries(int $chunk = 1000, ?callable $progress = null): array
    {
        if (!$this->summaryAvailable() || !Schema::hasTable('v2_subscribe_request_log')) {
            return ['available' => false, 'audits' => 0];
        }

        $chunk = max(100, min(5000, $chunk));
        // Establish the replay boundary and clear derived rows atomically. Requests
        // recorded after this commit have IDs above the ceiling and update their
        // summaries through the normal write path, so they cannot be erased by a
        // concurrently running rebuild.
        $ceiling = (int) DB::transaction(function () {
            $ceiling = (int) SubscribeRequestLog::max('id');
            SubscribeAccessSummary::query()->delete();

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
                    $this->upsertSummary($audit);
                    $processed++;
                }
                if ($progress) {
                    $progress($processed);
                }
            });

        return $this->summaryRebuildResult($processed);
    }

    /**
     * The aggregate is derived data, but a successful rebuild must not
     * silently leave it empty while there are raw rows to display. The counter
     * deliberately allows rows written after the rebuild ceiling: the normal
     * request path may add those concurrently while the replay runs.
     */
    private function summaryRebuildResult(int $audits): array
    {
        $rows = (int) SubscribeAccessSummary::query()->count();
        $hits = (int) SubscribeAccessSummary::query()->sum('hit_count');

        return [
            'available' => true,
            'audits' => $audits,
            'rows' => $rows,
            'hits' => $hits,
            'verified' => $audits === 0 || ($rows > 0 && $hits >= $audits)
        ];
    }

    private function syncSummary(SubscribeRequestLog $audit): void
    {
        if (!$this->summaryAvailable()) {
            if (!self::$summaryUnavailableLogged) {
                Log::warning('Subscription access summary is unavailable; raw audit was retained.');
                self::$summaryUnavailableLogged = true;
            }
            return;
        }

        try {
            $this->upsertSummary($audit);
        } catch (\Throwable $e) {
            // The raw audit is the source of truth. A later rebuild command repairs derived rows.
            Log::warning('Subscription access summary update failed', [
                'audit_id' => (int) $audit->id,
                'user_id' => (int) $audit->user_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 一行 = 账号 × 订阅 × IP × UA。四元组相同即累加，不存在则插入。
     *
     * 归属地列（isp / organization / asn / location_status）刻意不出现在 UPDATE 分支：
     * 它们由 access:locations 命令离线回填，写路径上不做归属地查询，回填过的行也不该
     * 因为一次新拉取被清空。
     */
    private function upsertSummary(SubscribeRequestLog $audit): void
    {
        $now = time();
        $userId = (int) $audit->user_id;
        $subscriptionId = $audit->subscription_id === null ? 0 : (int) $audit->subscription_id;
        $requestIp = $this->normalizeIpAddress((string) $audit->request_ip);
        $uaHash = (string) $audit->ua_hash;
        $userAgent = (string) $audit->user_agent;
        $requestedAt = (int) $audit->getRawOriginal('requested_at');
        $auditId = (int) $audit->id;
        $decision = $this->auditDecision($audit);
        // 「最近一次」的判定条件：时间更新的赢；同一秒内（bigint 秒级精度下很常见）
        // 审计 ID 更大的赢。少了后半句，同一秒内的并发拉取会让最近值随机漂移。
        $isNewer = 'VALUES(`last_seen_at`) > `last_seen_at` OR '
            . '(VALUES(`last_seen_at`) = `last_seen_at` AND VALUES(`recent_audit_id`) > `recent_audit_id`)';

        DB::statement(
            'INSERT INTO `v2_subscribe_access_summary` '
            . '(`user_id`,`subscription_id`,`request_ip`,`ua_hash`,`user_agent`,`hit_count`,'
            . '`first_seen_at`,`last_seen_at`,`recent_audit_id`,`recent_decision`,`created_at`,`updated_at`) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE '
            . '`hit_count` = `hit_count` + 1,'
            . '`user_agent` = IF(' . $isNewer . ', VALUES(`user_agent`), `user_agent`),'
            . '`recent_decision` = IF(' . $isNewer . ', VALUES(`recent_decision`), `recent_decision`),'
            . '`recent_audit_id` = IF(' . $isNewer . ', VALUES(`recent_audit_id`), `recent_audit_id`),'
            . '`first_seen_at` = LEAST(`first_seen_at`, VALUES(`first_seen_at`)),'
            . '`last_seen_at` = GREATEST(`last_seen_at`, VALUES(`last_seen_at`)),'
            . '`updated_at` = VALUES(`updated_at`)',
            [$userId, $subscriptionId, $requestIp, $uaHash, $userAgent, 1,
                $requestedAt, $requestedAt, $auditId, $decision, $now, $now]
        );
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

    private function summaryAvailable(): bool
    {
        if (self::$summaryAvailable !== null) {
            return self::$summaryAvailable;
        }

        try {
            return self::$summaryAvailable = Schema::hasTable('v2_subscribe_access_summary');
        } catch (\Throwable $e) {
            return self::$summaryAvailable = false;
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
