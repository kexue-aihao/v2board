<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class NodeConnectionAuditService
{
    private $availability;

    public function available(): bool
    {
        if ($this->availability !== null) {
            return $this->availability;
        }
        try {
            return $this->availability = Schema::hasTable('v2_node_connection_log');
        } catch (\Throwable $e) {
            return $this->availability = false;
        }
    }

    /**
     * 节点上报的在线 IP 落库。$data 形如 [node_user_id => ['1.2.3.4_1', ...]]，
     * 与 /UniProxy/alive 收到的结构一致。
     */
    public function record(string $nodeType, $nodeId, array $data): int
    {
        if (!$this->available() || empty($data)) {
            return 0;
        }

        // 上报频率由 server_push_interval 决定，这里按同一节点一个周期只记一次，
        // 节点异常高频重试时不会把 report_count 刷虚。Cache::add 是原子的，
        // TTL 略短于间隔，避免正常节点刚好卡在边界上被丢掉一轮。
        $interval = max(1, (int)config('v2board.server_push_interval', 60));
        $guard = 'NODE_CONN_AUDIT_' . $nodeType . '_' . (int)$nodeId;
        if (!Cache::add($guard, 1, max(1, $interval - 5))) {
            return 0;
        }

        try {
            $rows = $this->buildRows($nodeType, (int)$nodeId, $data);
            if (!$rows) {
                return 0;
            }
            $this->upsert($rows);
            return count($rows);
        } catch (\Throwable $e) {
            // 审计失败绝不能影响节点上报，否则会连带影响在线设备数与限速。
            Log::warning('Node connection audit failed', [
                'node_type' => $nodeType,
                'node_id' => $nodeId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    private function buildRows(string $nodeType, int $nodeId, array $data): array
    {
        $nodeUserIds = [];
        foreach (array_keys($data) as $nodeUserId) {
            if (is_numeric($nodeUserId)) {
                $nodeUserIds[] = (int)$nodeUserId;
            }
        }
        if (!$nodeUserIds) {
            return [];
        }

        $owners = $this->resolveOwners($nodeUserIds);
        $now = time();
        $rows = [];
        foreach ($data as $nodeUserId => $ips) {
            if (!is_numeric($nodeUserId) || !is_array($ips)) {
                continue;
            }
            $owner = $owners[(int)$nodeUserId] ?? null;
            if (!$owner) {
                continue;
            }
            foreach ($this->extractIps($ips) as $ip) {
                // 同一轮上报里同一 IP 可能出现在多条记录上（多端口/多连接），
                // 用唯一键去重，否则一条 INSERT 里出现重复行会触发自我冲突。
                $key = $nodeUserId . '|' . $ip;
                $rows[$key] = [
                    'user_id' => $owner['user_id'],
                    'subscription_id' => $owner['subscription_id'],
                    'node_user_id' => (int)$nodeUserId,
                    'node_type' => $nodeType,
                    'node_id' => $nodeId,
                    'ip' => $ip,
                    'report_count' => 1,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now
                ];
            }
        }
        return array_values($rows);
    }

    /**
     * 节点上报的 IP 形如 "1.2.3.4_5"，下划线后面是连接序号，需要剥掉。
     */
    private function extractIps(array $ips): array
    {
        $result = [];
        foreach ($ips as $entry) {
            if (!is_scalar($entry)) {
                continue;
            }
            $ip = explode('_', trim((string)$entry))[0];
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $result[$ip] = true;
            }
        }
        return array_keys($result);
    }

    private function resolveOwners(array $nodeUserIds): array
    {
        $owners = [];
        if (Schema::hasTable('v2_subscription')) {
            $subscriptions = Subscription::whereIn('node_user_id', $nodeUserIds)
                ->get(['id', 'user_id', 'node_user_id']);
            foreach ($subscriptions as $subscription) {
                $owners[(int)$subscription->node_user_id] = [
                    'user_id' => (int)$subscription->user_id,
                    'subscription_id' => (int)$subscription->id
                ];
            }
        }

        // 命中不了订阅的，就是还没迁移的老用户，节点上报的 id 直接是 v2_user.id。
        $legacyIds = array_values(array_diff($nodeUserIds, array_keys($owners)));
        if ($legacyIds) {
            foreach (User::whereIn('id', $legacyIds)->get(['id']) as $user) {
                $owners[(int)$user->id] = [
                    'user_id' => (int)$user->id,
                    'subscription_id' => null
                ];
            }
        }
        return $owners;
    }

    /**
     * 手写 INSERT ... ON DUPLICATE KEY UPDATE 而不用 Builder::upsert()：后者要求
     * laravel/framework 8.10+，而 composer.json 只约束了 ^8.0。
     */
    private function upsert(array $rows): void
    {
        $columns = ['user_id', 'subscription_id', 'node_user_id', 'node_type', 'node_id',
            'ip', 'report_count', 'first_seen_at', 'last_seen_at', 'created_at', 'updated_at'];
        $placeholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $bindings = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $bindings[] = $row[$column];
            }
        }

        DB::statement(
            'INSERT INTO `v2_node_connection_log` (`' . implode('`,`', $columns) . '`) VALUES '
            . implode(',', array_fill(0, count($rows), $placeholder))
            . ' ON DUPLICATE KEY UPDATE '
            // first_seen_at 只在插入时确定，后续上报只推进 last_seen_at 与计数。
            . '`report_count` = `report_count` + 1,'
            . '`last_seen_at` = VALUES(`last_seen_at`),'
            . '`user_id` = VALUES(`user_id`),'
            . '`subscription_id` = VALUES(`subscription_id`),'
            . '`updated_at` = VALUES(`updated_at`)',
            $bindings
        );
    }
}
