<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubscribeAuditRetentionService
{
    public const DEFAULT_RETENTION_DAYS = 180;

    // 下限 1 天：订阅读取侧曾经有一条「保留期必须盖过 30 天风险周期」的约束，判定引擎
    // 删除后这条约束消失，剩下的唯一要求是别把保留期设成 0 以外的无意义小值。
    // 0 仍然表示关闭清理（永久保留）。
    public const MIN_RETENTION_DAYS = 1;

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
     * 清理超过保留期的记录。
     *
     * v2_subscribe_access_summary 跟着一起清：它是「订阅清洗网关」列表页的数据源，
     * 原始证据删了、聚合行还挂在页面上，等于把保留期设置变成一句空话。这两张表必须
     * 同进同退，页面看到的窗口才与留存设置严格一致。
     *
     * v2_ip_account_link 刻意不参与 —— 多账号同 IP 关联要的正是跨保留期的长期记忆。
     *
     * @return array{cutoff:int,days:int,subscribe_request_log:int,node_connection_log:int,subscribe_access_summary:int,truncated:bool}
     */
    public function purgeExpired(?int $days = null, int $chunk = 2000, int $maxRows = 500000, bool $dryRun = false): array
    {
        $days = $days === null ? $this->retentionDays() : max(0, $days);
        $result = [
            'days' => $days,
            'cutoff' => 0,
            'subscribe_request_log' => 0,
            'node_connection_log' => 0,
            'subscribe_access_summary' => 0,
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
        // 聚合行按「最后一次拉取」判断：只要这个四元组在保留期内还出现过就不删，
        // 与同一四元组在原始日志里还留着证据保持一致。
        $result['subscribe_access_summary'] = $this->purgeByColumn(
            'v2_subscribe_access_summary', 'last_seen_at', $cutoff, $chunk, $maxRows, $dryRun, $result['truncated']
        );
        return $result;
    }

    /**
     * 清理单个用户的全部拉取痕迹。
     *
     * 聚合表必须按用户一起清：只删原始日志的话，该账号的 IP、UA 与拉取次数会以派生
     * 形式残留在清洗网关页面上，与当年漏掉 v2_node_connection_log 是同一类问题。
     *
     * @return array{subscribe_request_log:int,node_connection_log:int,subscribe_access_summary:int,ip_account_link:int}
     */
    public function purgeUser(int $userId, int $chunk = 5000): array
    {
        $chunk = max(1, min(50000, $chunk));
        $counts = [
            'subscribe_request_log' => 0,
            'node_connection_log' => 0,
            'subscribe_access_summary' => 0,
            'ip_account_link' => 0
        ];
        if ($userId <= 0) {
            return $counts;
        }

        $counts['subscribe_request_log'] = $this->purgeUserTable('v2_subscribe_request_log', $userId, $chunk);
        $counts['node_connection_log'] = $this->purgeUserTable('v2_node_connection_log', $userId, $chunk);
        $counts['subscribe_access_summary'] = $this->purgeUserTable('v2_subscribe_access_summary', $userId, $chunk);
        // 同 IP 关联累积表跟着原始日志一起清。它不参与保留期清理（派生结论要比证据活得久），
        // 但按用户清必须带上：否则清空/注销之后，该账号的真实 IP 会以派生形式残留在关联
        // 分析里。
        $counts['ip_account_link'] = $this->purgeUserTable('v2_ip_account_link', $userId, $chunk);

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

        // 分块删除而不是一条 DELETE：这几张表都在热路径上被写入，单条长事务比
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
