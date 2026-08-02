<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 多账号同 IP 关联分析的累积层。
 *
 * 订阅拉取（SubscribeAuditService::record）是用户面高频路径，本服务**绝不**在那里
 * 挂任何东西：所有聚合都由 audit:ip-link 命令离线增量完成，读的是已经落库的
 * v2_subscribe_request_log，写的是 v2_ip_account_link。拉取路径的 SQL 一条没变。
 *
 * 之所以需要这张累积表而不是直接对原始日志做 GROUP BY：原始日志有保留期清理
 * （audit:clean，默认 180 天、下限 35 天、可关闭），过期行会被物理删除，而需求要的
 * 「历史累积」恰恰是比保留期更长的记忆。累积表按去重后的三元组存，一个三元组一行，
 * 规模由真实去重基数决定而不随时间线性增长（形制照 v2_node_connection_log）。
 */
class IpAccountLinkService
{
    public const TABLE = 'v2_ip_account_link';
    public const SOURCE_TABLE = 'v2_subscribe_request_log';

    /**
     * 只处理 requested_at 已经早于 now-120s 的行。id 是自增的、但值在事务提交前就已分配，
     * 直接取 MAX(id) 做上界有极小概率把一条尚未提交的行「跨过去」并永久漏算。留 120 秒
     * 滞后可以彻底排除这种情况：漏算是不可恢复的，重复计数才是必须避免的另一半，两者
     * 都由「按 id 严格递增推进游标 + 每批一个事务」保证。
     */
    private const COMMIT_LAG_SECONDS = 120;

    /** 单条 INSERT ... ON DUPLICATE KEY UPDATE 里最多带多少行，控制占位符数量。 */
    private const UPSERT_BATCH = 200;

    private const COLUMNS = ['request_ip', 'user_id', 'ua_hash', 'user_agent', 'hit_count',
        'first_seen_at', 'last_seen_at', 'last_log_id', 'created_at', 'updated_at'];

    public function available(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function sourceAvailable(): bool
    {
        try {
            return Schema::hasTable(self::SOURCE_TABLE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 增量游标。刻意从数据本身推导（MAX(last_log_id)）而不是另存一份状态：
     * 缓存被清、进程被杀、命令被重复执行都不会导致重复计数或漏算，
     * 走 last_log_id 索引是常数级代价。
     */
    public function cursor(): int
    {
        if (!$this->available()) {
            return 0;
        }
        return (int)DB::table(self::TABLE)->max('last_log_id');
    }

    /**
     * 面板用的新鲜度信号，三条查询都是常数级：
     * - aggregated_through：累积表里最近一次活动时间（走 last_seen_at 索引）
     * - pending_since：第一条尚未聚合的原始日志的时间（走主键区间，LIMIT 1）
     * - log_max_id：原始日志的最大 id（主键末端）
     */
    public function status(): array
    {
        $status = [
            'available' => $this->available(),
            'source_available' => $this->sourceAvailable(),
            'cursor' => 0,
            'aggregated_through' => null,
            'pending_since' => null,
            'log_max_id' => 0
        ];
        if ($status['available']) {
            $status['cursor'] = $this->cursor();
            $through = DB::table(self::TABLE)->max('last_seen_at');
            $status['aggregated_through'] = $through === null ? null : (int)$through;
        }
        if ($status['source_available']) {
            $status['log_max_id'] = (int)DB::table(self::SOURCE_TABLE)->max('id');
            $pending = DB::table(self::SOURCE_TABLE)
                ->where('id', '>', $status['cursor'])
                ->orderBy('id')
                ->value('requested_at');
            $status['pending_since'] = $pending === null ? null : (int)$pending;
        }
        return $status;
    }

    /**
     * 增量聚合。
     *
     * @param int  $chunk   每批从原始日志读多少行
     * @param int  $maxRows 单次运行最多处理多少原始行，0 表示不限
     * @param bool $full    忽略游标从头重扫（回填用；upsert 幂等但会重复累加，所以
     *                      回填前必须先 truncate，命令层会强制这一点）
     * @return array{available:bool,source_available:bool,from_log_id:int,to_log_id:int,
     *               ceiling:int,log_rows:int,link_rows:int,truncated:bool}
     */
    public function aggregate(int $chunk = 2000, int $maxRows = 200000, bool $full = false, bool $dryRun = false): array
    {
        $result = [
            'available' => $this->available(),
            'source_available' => $this->sourceAvailable(),
            'from_log_id' => 0,
            'to_log_id' => 0,
            'ceiling' => 0,
            'log_rows' => 0,
            'link_rows' => 0,
            'truncated' => false
        ];
        if (!$result['available'] || !$result['source_available']) {
            return $result;
        }

        $chunk = max(100, min(20000, $chunk));
        $maxRows = max(0, $maxRows);
        $cursor = $full ? 0 : $this->cursor();
        $result['from_log_id'] = $cursor;
        $result['to_log_id'] = $cursor;

        // ORDER BY id DESC + LIMIT 1 是从主键末端往回扫，遇到第一条 requested_at 够老的
        // 行就停，代价与表规模无关；写成 MAX(id) WHERE requested_at <= ? 反而要扫整个
        // requested_at 区间。
        $ceiling = (int)DB::table(self::SOURCE_TABLE)
            ->where('requested_at', '<=', time() - self::COMMIT_LAG_SECONDS)
            ->orderByDesc('id')
            ->value('id');
        $result['ceiling'] = $ceiling;
        if ($ceiling <= $cursor) {
            return $result;
        }

        $now = time();
        while (true) {
            $logRows = DB::table(self::SOURCE_TABLE)
                ->where('id', '>', $cursor)
                ->where('id', '<=', $ceiling)
                ->orderBy('id')
                ->limit($chunk)
                ->get(['id', 'user_id', 'request_ip', 'ua_hash', 'user_agent', 'requested_at']);
            $count = count($logRows);
            if (!$count) {
                break;
            }

            $folded = $this->foldRows($logRows, $now);
            $result['log_rows'] += $count;
            $result['link_rows'] += count($folded);
            $lastId = 0;
            foreach ($logRows as $row) {
                $lastId = max($lastId, (int)$row->id);
            }

            if (!$dryRun) {
                // 整批一个事务：游标是 MAX(last_log_id) 推导出来的，半批落库会让游标
                // 越过没被计入的行。批内 upsert 拆成多条语句只是为了控制占位符数量。
                DB::transaction(function () use ($folded) {
                    foreach (array_chunk($folded, self::UPSERT_BATCH) as $batch) {
                        $statement = $this->buildUpsert($batch);
                        DB::statement($statement['sql'], $statement['bindings']);
                    }
                });
            }

            $cursor = $lastId;
            $result['to_log_id'] = $cursor;
            if ($count < $chunk) {
                break;
            }
            if ($maxRows > 0 && $result['log_rows'] >= $maxRows) {
                $result['truncated'] = true;
                break;
            }
        }

        return $result;
    }

    /**
     * 把一批原始日志折叠成三元组。纯函数，不碰数据库（便于离线验证）。
     *
     * @param iterable $logRows 每项需含 id/user_id/request_ip/ua_hash/user_agent/requested_at
     * @return array<int, array<string, mixed>>
     */
    public function foldRows($logRows, ?int $now = null): array
    {
        $now = $now === null ? time() : $now;
        $folded = [];
        foreach ($logRows as $row) {
            $row = is_array($row) ? (object)$row : $row;
            $ip = trim((string)$row->request_ip);
            $userId = (int)$row->user_id;
            $uaHash = (string)$row->ua_hash;
            if ($ip === '' || $userId <= 0 || $uaHash === '') {
                continue;
            }
            $requestedAt = (int)$row->requested_at;
            $logId = (int)$row->id;
            $key = $ip . "\0" . $userId . "\0" . $uaHash;
            if (!isset($folded[$key])) {
                $folded[$key] = [
                    'request_ip' => $ip,
                    'user_id' => $userId,
                    'ua_hash' => $uaHash,
                    'user_agent' => (string)$row->user_agent,
                    'hit_count' => 0,
                    'first_seen_at' => $requestedAt,
                    'last_seen_at' => $requestedAt,
                    'last_log_id' => $logId,
                    'created_at' => $now,
                    'updated_at' => $now
                ];
            }
            $folded[$key]['hit_count']++;
            $folded[$key]['first_seen_at'] = min($folded[$key]['first_seen_at'], $requestedAt);
            $folded[$key]['last_seen_at'] = max($folded[$key]['last_seen_at'], $requestedAt);
            $folded[$key]['last_log_id'] = max($folded[$key]['last_log_id'], $logId);
        }
        return array_values($folded);
    }

    /**
     * 手写 INSERT ... ON DUPLICATE KEY UPDATE，理由同 NodeConnectionAuditService：
     * Builder::upsert() 要求 laravel/framework 8.10+，composer.json 只约束了 ^8.0。
     * 纯函数，只产出 SQL 与绑定，不执行（便于离线核对占位符与绑定数量）。
     *
     * @return array{sql:string,bindings:array}
     */
    public function buildUpsert(array $rows): array
    {
        $placeholder = '(' . implode(',', array_fill(0, count(self::COLUMNS), '?')) . ')';
        $bindings = [];
        foreach ($rows as $row) {
            foreach (self::COLUMNS as $column) {
                $bindings[] = $row[$column];
            }
        }

        $sql = 'INSERT INTO `' . self::TABLE . '` (`' . implode('`,`', self::COLUMNS) . '`) VALUES '
            . implode(',', array_fill(0, count($rows), $placeholder))
            . ' ON DUPLICATE KEY UPDATE '
            // first_seen_at 用 LEAST 而不是保持不动：回填（--full）是从最老的日志开始扫的，
            // 但增量运行过的表里已有更晚的 first_seen_at，必须允许它往前推。
            . '`hit_count` = `hit_count` + VALUES(`hit_count`),'
            . '`first_seen_at` = LEAST(`first_seen_at`, VALUES(`first_seen_at`)),'
            . '`last_seen_at` = GREATEST(`last_seen_at`, VALUES(`last_seen_at`)),'
            // 游标只能单调前进，否则一次乱序写入会让下一轮从更早的位置重扫、重复计数。
            . '`last_log_id` = GREATEST(`last_log_id`, VALUES(`last_log_id`)),'
            . '`user_agent` = VALUES(`user_agent`),'
            . '`updated_at` = VALUES(`updated_at`)';

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    /**
     * 累积表当前行数。命令层用它判断 --full 是不是在往一张有内容的表上开刀
     * （空表重扫无损，非空表重扫会丢掉超出保留期的那段历史）。
     */
    public function rowCount(): int
    {
        if (!$this->available()) {
            return 0;
        }
        return (int)DB::table(self::TABLE)->count();
    }

    /**
     * 清空累积表。--full 回填前必须先清，否则 hit_count 会在已有基数上重复累加。
     *
     * 危险性说明见 AggregateIpAccountLink：清空后只能从「当前还没被 audit:clean 删掉的」
     * 原始日志重建，早于保留期的历史拿不回来。
     */
    public function truncate(): void
    {
        if ($this->available()) {
            DB::table(self::TABLE)->truncate();
        }
    }

    /**
     * 可选的累积表保留期。默认关闭：这张表是派生结论，按设计要比原始证据活得更久
     * （同 v2_subscription_risk_cycle 的取舍）。只有在表规模失控时才由命令行显式启用。
     */
    public function prune(int $days, int $chunk = 2000): int
    {
        if ($days <= 0 || !$this->available()) {
            return 0;
        }
        $cutoff = time() - ($days * 86400);
        $chunk = max(1, min(50000, $chunk));
        $total = 0;
        do {
            // 带 ORDER BY 才是确定性语句，STATEMENT 格式 binlog 下不会告警。
            $deleted = DB::table(self::TABLE)
                ->where('last_seen_at', '<', $cutoff)
                ->orderBy('last_seen_at')
                ->limit($chunk)
                ->delete();
            $total += $deleted;
        } while ($deleted > 0);
        return $total;
    }
}
