<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubscribeAuditRetentionService
{
    public const DEFAULT_RETENTION_DAYS = 180;

    // 风险周期是 30 天且只在周期完成后评估，保留期低于 31 天会在周期被评估前就删掉
    // 证据。35 给延迟的调度留 4 天余量。校验层同样按这个下限拒绝。
    public const MIN_RETENTION_DAYS = 35;

    /**
     * 0 表示关闭清理。
     */
    public function retentionDays(): int
    {
        // 不能依赖 config() 的默认值参数：ConfigController::save 会把键写进
        // config/v2board.php，键存在而值为空时 config() 返回的是 null 不是默认值。
        $raw = config('v2board.subscribe_audit_retention_days', self::DEFAULT_RETENTION_DAYS);
        if ($raw === null || $raw === '') {
            return self::DEFAULT_RETENTION_DAYS;
        }
        $days = (int)$raw;
        if ($days <= 0) {
            return 0;
        }
        return max(self::MIN_RETENTION_DAYS, $days);
    }

    /**
     * 清理超过保留期的审计记录。判定表 v2_subscription_risk_cycle 刻意不参与 ——
     * 它是派生结论，必须比原始证据活得更久。
     *
     * @return array{cutoff:int,days:int,subscribe_request_log:int,node_connection_log:int,truncated:bool}
     */
    public function purgeExpired(?int $days = null, int $chunk = 2000, int $maxRows = 500000, bool $dryRun = false): array
    {
        $days = $days === null ? $this->retentionDays() : max(0, $days);
        $result = [
            'days' => $days,
            'cutoff' => 0,
            'subscribe_request_log' => 0,
            'node_connection_log' => 0,
            'truncated' => false
        ];
        if ($days <= 0) {
            return $result;
        }

        $chunk = max(1, min(50000, $chunk));
        $cutoff = time() - ($days * 86400);
        $result['cutoff'] = $cutoff;

        $result['subscribe_request_log'] = $this->purgeByColumn(
            'v2_subscribe_request_log', 'requested_at', $cutoff, $chunk, $maxRows, $dryRun, $result['truncated']
        );
        $result['node_connection_log'] = $this->purgeByColumn(
            'v2_node_connection_log', 'last_seen_at', $cutoff, $chunk, $maxRows, $dryRun, $result['truncated']
        );
        return $result;
    }

    /**
     * 清理单个用户的审计记录。这里连判定一起删：冻结之后这是唯一能重置误判徽章的
     * 路径，而留着引用了具体 IP 的判定、下面证据却已清空，等于一条无法核实的指控。
     *
     * @return array{subscribe_request_log:int,node_connection_log:int,subscription_risk_cycle:int,ip_account_link:int}
     */
    public function purgeUser(int $userId, bool $withRisk = true, int $chunk = 5000): array
    {
        $chunk = max(1, min(50000, $chunk));
        $counts = [
            'subscribe_request_log' => 0,
            'node_connection_log' => 0,
            'subscription_risk_cycle' => 0,
            'ip_account_link' => 0
        ];
        if ($userId <= 0) {
            return $counts;
        }

        $counts['subscribe_request_log'] = $this->purgeUserTable('v2_subscribe_request_log', $userId, $chunk);
        // 同 IP 关联累积表跟着原始日志一起清。它不参与保留期清理（派生结论要比证据活得久），
        // 但按用户清必须带上：否则清空/注销之后，该账号的真实 IP 会以派生形式残留在关联
        // 分析里，与当年漏掉 v2_node_connection_log 是同一类问题。
        $counts['ip_account_link'] = $this->purgeUserTable('v2_ip_account_link', $userId, $chunk);
        $counts['node_connection_log'] = $this->purgeUserTable('v2_node_connection_log', $userId, $chunk);
        if ($withRisk) {
            $counts['subscription_risk_cycle'] = $this->purgeUserTable('v2_subscription_risk_cycle', $userId, $chunk);
        }
        return $counts;
    }

    private function purgeByColumn(
        string $table,
        string $column,
        int $cutoff,
        int $chunk,
        int $maxRows,
        bool $dryRun,
        bool &$truncated
    ): int {
        if (!Schema::hasTable($table)) {
            return 0;
        }
        if ($dryRun) {
            return (int)DB::table($table)->where($column, '<', $cutoff)->count();
        }

        // 分块删除而不是一条 DELETE：这两张表都在热路径上被写入，单条长事务比
        // 部分完成更糟。每块都走新加的单列索引，ORDER BY 让语句在 STATEMENT
        // 格式的 binlog 下也是确定的。
        $total = 0;
        do {
            $deleted = DB::table($table)
                ->where($column, '<', $cutoff)
                ->orderBy($column)
                ->limit($chunk)
                ->delete();
            $total += $deleted;
            if ($maxRows > 0 && $total >= $maxRows) {
                $truncated = true;
                break;
            }
        } while ($deleted > 0);
        return $total;
    }

    private function purgeUserTable(string $table, int $userId, int $chunk): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }
        // 带 ORDER BY 才是确定性语句，STATEMENT 格式 binlog 下不会告警。
        $total = 0;
        do {
            $deleted = DB::table($table)
                ->where('user_id', $userId)
                ->orderBy('id')
                ->limit($chunk)
                ->delete();
            $total += $deleted;
        } while ($deleted > 0);
        return $total;
    }
}
