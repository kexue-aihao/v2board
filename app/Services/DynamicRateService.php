<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/**
 * 动态倍率的配置、判定与台账。
 *
 * 数据分三处：
 *   - v2_rate_rule    时段/节点规则（运营在管理页维护）
 *   - v2_rate_setting 峰值判定的六个参数 + 总开关
 *   - v2_rate_state   每用户当前状态（既给判定用，也是管理页看到的实时台账）
 *
 * 热路径（节点上报）不读这三张表，只读 tick 预先写好的 Redis 键 —— 见 RateResolver。
 * 判定状态刻意留在数据库而不是 Redis：重启 Redis 不该让「已经持续跑了 4 分钟」归零。
 */
class DynamicRateService
{
    public const TABLE_RULE = 'v2_rate_rule';
    public const TABLE_SETTING = 'v2_rate_setting';
    public const TABLE_STATE = 'v2_rate_state';

    /** 热路径用的裸键，与既有的 v2board_upload_traffic 同一风格（不走 CacheKey 白名单）。 */
    public const KEY_RULES = 'v2board_rate_rules';
    public const KEY_USER_MULT = 'v2board_rate_user_mult';
    public const KEY_RAW_UP = 'v2board_raw_upload_traffic';
    public const KEY_RAW_DOWN = 'v2board_raw_download_traffic';
    public const KEY_LAST_TICK = 'v2board_rate_last_tick';

    public const DEFAULT_SAMPLE_SECONDS = 60;
    public const MIN_SAMPLE_SECONDS = 10;
    public const MAX_SAMPLE_SECONDS = 600;

    private const UPSERT_CHUNK = 500;

    // ---------------------------------------------------------------- 参数

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $stored = [];
        if ($this->hasTable(self::TABLE_SETTING)) {
            foreach (DB::table(self::TABLE_SETTING)->get() as $row) {
                $stored[(string) $row->setting_key] = (string) $row->setting_value;
            }
        }

        return (new RatePeakStateMachine())->normalizeParams($stored);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed> 归一化后的实际值
     */
    public function saveSettings(array $values): array
    {
        return (new RatePolicyService())->mutate(function () use ($values) {
            $params = (new RatePeakStateMachine())->normalizeParams($values);
            $now = time();
            foreach ($params as $key => $value) {
                SecurityAuditMutation::upsertOne(DB::table(self::TABLE_SETTING),
                    ['setting_key' => $key],
                    ['setting_value' => $this->scalarToString($value), 'updated_at' => $now]
                );
            }

            (new RatePolicyService())->resetGlobal();
            return $params;
        });
    }

    // ---------------------------------------------------------------- 规则

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rules(bool $onlyEnabled = false): array
    {
        if (!$this->hasTable(self::TABLE_RULE)) {
            return [];
        }
        $query = DB::table(self::TABLE_RULE);
        if ($onlyEnabled) {
            $query->where('enabled', 1);
        }

        return array_map(function ($row) {
            return (array) $row;
        }, $query->orderBy('scope')->orderBy('start_minute')->orderBy('id')->get()->all());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveRule(array $data, ?int $id = null): int
    {
        return (new RatePolicyService())->mutate(function () use ($data, $id) {
            $attributes = $this->normalizeRule($data) + ['updated_at' => time()];
            if ($id !== null) {
                $attributes['updated_at'] = time();
                SecurityAuditMutation::update(DB::table(self::TABLE_RULE)->where('id', $id), $attributes);

                return $id;
            }

            $attributes['created_at'] = time();

            return SecurityAuditMutation::insertGetId(DB::table(self::TABLE_RULE), $attributes);
        });
    }

    public function deleteRule(int $id): bool
    {
        return (new RatePolicyService())->mutate(function () use ($id) {
            return SecurityAuditMutation::delete(DB::table(self::TABLE_RULE)->where('id', $id)) > 0;
        });
    }

    /**
     * 规则入库前的归一化。管理端已经校验过一遍，这里再夹一次边界 —— 手写 SQL 改库、
     * 或者以后加了别的写入口，都不该把 0 分钟/负倍率这种东西塞进来。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalizeRule(array $data): array
    {
        $scope = (string) ($data['scope'] ?? RateRuleMatcher::SCOPE_GLOBAL);
        if (!in_array($scope, [RateRuleMatcher::SCOPE_GLOBAL, RateRuleMatcher::SCOPE_NODE], true)) {
            $scope = RateRuleMatcher::SCOPE_GLOBAL;
        }

        $weekdays = $this->normalizeWeekdays($data['weekdays'] ?? '');
        $start = $this->clampMinute($data['start_minute'] ?? 0);
        // start > end 表示跨零点（23:00-02:00），start == end 表示整天 —— 两种都由
        // RateRuleMatcher::coversMinute() 解释，这里只裁上下界，不改写运营填的值。
        $end = $this->clampMinute($data['end_minute'] ?? RateRuleMatcher::DAY_MINUTES);

        return [
            'scope' => $scope,
            'node_type' => $scope === RateRuleMatcher::SCOPE_NODE ? (string) ($data['node_type'] ?? '') : '',
            'node_id' => $scope === RateRuleMatcher::SCOPE_NODE ? max(0, (int) ($data['node_id'] ?? 0)) : 0,
            'weekdays' => $weekdays,
            'start_minute' => $start,
            'end_minute' => $end,
            'multiplier' => round(max(0.0, (float) ($data['multiplier'] ?? 1)), 3),
            'enabled' => (int) ($data['enabled'] ?? 1) === 1 ? 1 : 0,
            'remark' => isset($data['remark']) ? mb_substr((string) $data['remark'], 0, 255) : null
        ];
    }

    // ---------------------------------------------------------------- 台账

    /**
     * 管理页的「当前状态」列表。
     *
     * @param array<string, mixed> $options
     * @return array{total: int, rows: array<int, array<string, mixed>>}
     */
    public function listStates(array $options = []): array
    {
        if ((new RatePolicyService())->ready()) return (new RatePolicyService())->listStates($options);
        if (!$this->hasTable(self::TABLE_STATE)) {
            return ['total' => 0, 'rows' => []];
        }
        $onlyStacked = (bool) ($options['only_stacked'] ?? true);
        $limit = max(1, min(200, (int) ($options['limit'] ?? 50)));
        $page = max(1, (int) ($options['page'] ?? 1));
        $keyword = trim((string) ($options['keyword'] ?? ''));

        $build = function () use ($onlyStacked, $keyword) {
            $query = DB::table(self::TABLE_STATE);
            if ($onlyStacked) {
                $query->where(function ($q) {
                    $q->where('multiplier', '>', 1)->orWhere('state', RatePeakStateMachine::STATE_STACKED);
                });
            }
            if ($keyword !== '') {
                $userIds = DB::table('v2_user')
                    ->where('email', 'like', '%' . $keyword . '%')
                    ->orWhere('id', (int) $keyword)
                    ->pluck('id')
                    ->all();
                $query->whereIn('user_id', $userIds ?: [0]);
            }

            return $query;
        };

        $total = (int) $build()->count();
        $rows = $build()
            ->orderByDesc('computed_at')
            ->orderBy('user_id')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->all();

        $userIds = array_map(function ($row) {
            return (int) $row->user_id;
        }, $rows);
        $emails = $userIds
            ? DB::table('v2_user')->whereIn('id', $userIds)->pluck('email', 'id')->all()
            : [];

        return [
            'total' => $total,
            'rows' => array_map(function ($row) use ($emails) {
                $row = (array) $row;

                return [
                    'user_id' => (int) $row['user_id'],
                    'email' => (string) ($emails[(int) $row['user_id']] ?? ''),
                    'multiplier' => (float) $row['multiplier'],
                    'rate_bps' => (int) $row['rate_bps'],
                    'high' => (int) $row['high'],
                    'burst' => (int) $row['burst'],
                    'state' => (string) $row['state'],
                    'sampled_at' => (int) $row['sampled_at'],
                    'computed_at' => (int) $row['computed_at']
                ];
            }, $rows)
        ];
    }

    // ---------------------------------------------------------------- 判定

    /**
     * 每分钟跑一次：取样本 → 推进状态机 → 发布热路径要用的键。
     *
     * @return array<string, mixed>
     */
    public function tick(bool $dryRun = false): array
    {
        if ((new RatePolicyService())->ready()) return (new RatePolicyService())->tick($dryRun);
        $now = time();
        $elapsed = $this->elapsedSeconds($now, $dryRun);
        $bytes = $this->drainRawCounters($dryRun);

        $params = $this->settings();
        $machine = new RatePeakStateMachine();

        $pending = $this->stateRowsFor(array_keys($bytes));
        $advanced = [];
        foreach ($pending as $userId => $row) {
            $mbps = $machine->bytesToMbps((int) ($bytes[$userId] ?? 0), $elapsed);
            $next = $machine->advance($row, $mbps, $params);
            $advanced[$userId] = [
                'user_id' => $userId,
                // 总开关关掉时状态照推、倍率恒为 1：管理页能看到「开了会怎样」，
                // 但不会多扣用户一个字节
                'multiplier' => $next['applied'] ? $next['multiplier'] : 1.0,
                'state' => $next['state'],
                'high' => $next['high'],
                'burst' => $next['burst'],
                'rate_bps' => (int) round($mbps * 1000000),
                'sampled_at' => $now,
                'computed_at' => $now
            ];
        }

        $stacked = array_filter($advanced, function (array $row) {
            return $row['multiplier'] > 1;
        });

        if (!$dryRun) {
            $this->upsertStates(array_values($advanced));
            $this->publishRuntime($stacked);
            Redis::set(self::KEY_LAST_TICK, (string) $now);
        }

        return [
            'sampled_seconds' => $elapsed,
            'sampled_users' => count($bytes),
            'advanced_users' => count($advanced),
            'stacked_users' => count($stacked),
            'dry_run' => $dryRun
        ];
    }

    /**
     * 「为什么他被加倍了」——把三个因子拆开给运维看。
     *
     * @return array<string, mixed>
     */
    public function explain(int $userId, ?int $timestamp = null, ?int $nodeUserId = null): array
    {
        if ((new RatePolicyService())->ready()) return (new RatePolicyService())->explain($userId, $nodeUserId, $timestamp);
        $timestamp = $timestamp ?: time();
        $matcher = new RateRuleMatcher();
        $rules = $this->rules(true);

        $state = $this->hasTable(self::TABLE_STATE)
            ? DB::table(self::TABLE_STATE)->where('user_id', $userId)->first()
            : null;

        $userMultiplier = $state ? (float) $state->multiplier : 1.0;
        $globalRule = null;
        $globalMultiplier = $matcher->globalMultiplier($rules, $timestamp);
        foreach ($matcher->matchesAt($rules, '', 0, $timestamp) as $rule) {
            if (($rule['scope'] ?? '') === RateRuleMatcher::SCOPE_GLOBAL) {
                $globalRule = $rule;
                break;
            }
        }

        $nodes = [];
        foreach (array_keys(ServerIdService::TYPES) as $type) {
            if (!$this->hasTable('v2_server_' . $type)) {
                continue;
            }
            foreach (DB::table('v2_server_' . $type)->orderBy('id')->limit(50)->get(['id', 'name', 'rate']) as $node) {
                $band = $matcher->multiplierFor($rules, $type, (int) $node->id, $timestamp);
                $nodes[] = [
                    'type' => $type,
                    'id' => (int) $node->id,
                    'name' => (string) $node->name,
                    'rate' => (float) $node->rate,
                    'band_multiplier' => $band,
                    'effective' => round((float) $node->rate * $band * $userMultiplier, 2)
                ];
            }
        }

        return [
            'user_id' => $userId,
            'state' => $state ? (array) $state : null,
            'user_multiplier' => $userMultiplier,
            'global_multiplier' => $globalMultiplier,
            'global_rule' => $globalRule,
            'rules_matched_now' => $matcher->matchesAt($rules, '', 0, $timestamp),
            'nodes' => $nodes,
            'settings' => $this->settings()
        ];
    }

    // ---------------------------------------------------------------- 内部

    private function elapsedSeconds(int $now, bool $dryRun): int
    {
        $previous = 0;
        try {
            $previous = (int) (Redis::get(self::KEY_LAST_TICK) ?: 0);
        } catch (\Throwable $e) {
            $previous = 0;
        }
        if ($dryRun || $previous <= 0) {
            return self::DEFAULT_SAMPLE_SECONDS;
        }

        // 调度抖动、机器挂起、手动补跑都会让间隔偏离 60 秒；按实际间隔算速率，
        // 夹在 [10, 600] 之间是为了别把「停了三天」算成一次巨大的速率。
        return max(self::MIN_SAMPLE_SECONDS, min(self::MAX_SAMPLE_SECONDS, $now - $previous));
    }

    /**
     * drain 原始字节计数（不乘倍率的那份，避免倍率推高采样、采样再推高倍率的正反馈）。
     *
     * @return array<int, int> [uid => 字节]
     */
    private function drainRawCounters(bool $dryRun): array
    {
        if ($dryRun) {
            return $this->peekRawCounters();
        }

        $uploads = Redis::hgetall(self::KEY_RAW_UP);
        Redis::del(self::KEY_RAW_UP);
        $downloads = Redis::hgetall(self::KEY_RAW_DOWN);
        Redis::del(self::KEY_RAW_DOWN);

        return $this->sumCounters($uploads, $downloads);
    }

    /**
     * @return array<int, int>
     */
    private function peekRawCounters(): array
    {
        return $this->sumCounters(Redis::hgetall(self::KEY_RAW_UP), Redis::hgetall(self::KEY_RAW_DOWN));
    }

    /**
     * @param array<string, mixed> $uploads
     * @param array<string, mixed> $downloads
     * @return array<int, int>
     */
    private function sumCounters(array $uploads, array $downloads): array
    {
        $bytes = [];
        foreach ([$uploads, $downloads] as $source) {
            foreach ($source as $userId => $value) {
                $userId = (int) $userId;
                if ($userId <= 0) {
                    continue;
                }
                $bytes[$userId] = ($bytes[$userId] ?? 0) + (int) $value;
            }
        }

        return $bytes;
    }

    /**
     * 这一轮要推进的用户：本分钟有流量的，加上上一轮还在抬升状态里的（让它们自然回落，
     * 否则一个用户跑完一波就永远停在 1.5 倍）。已经回到 normal 的行不再参与，避免每分钟
     * 全表扫描。
     *
     * @param array<int, int|string> $activeUserIds
     * @return array<int, array<string, mixed>>
     */
    private function stateRowsFor(array $activeUserIds): array
    {
        $rows = [];
        if ($this->hasTable(self::TABLE_STATE)) {
            $query = DB::table(self::TABLE_STATE)->where(function ($q) {
                $q->where('high', '>', 0)
                    ->orWhere('burst', '>', 0)
                    ->orWhere('multiplier', '>', 1);
            });
            if ($activeUserIds) {
                $query->orWhereIn('user_id', array_map('intval', $activeUserIds));
            }
            foreach ($query->get() as $row) {
                $rows[(int) $row->user_id] = (array) $row;
            }
        }

        foreach ($activeUserIds as $userId) {
            $userId = (int) $userId;
            if (!isset($rows[$userId])) {
                $rows[$userId] = ['high' => 0, 'burst' => 0, 'multiplier' => 1.0];
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function upsertStates(array $rows): void
    {
        if (!$rows) {
            return;
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach ($rows as $row) {
                $key = ['user_id' => $row['user_id']];
                unset($row['user_id']);
                DB::table(self::TABLE_STATE)->updateOrInsert($key, $row);
            }
            return;
        }
        foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?)'));
            $bindings = [];
            foreach ($chunk as $row) {
                $bindings[] = $row['user_id'];
                $bindings[] = $row['multiplier'];
                $bindings[] = $row['rate_bps'];
                $bindings[] = $row['high'];
                $bindings[] = $row['burst'];
                $bindings[] = $row['state'];
                $bindings[] = $row['sampled_at'];
                $bindings[] = $row['computed_at'];
            }
            // 手写 SQL 而不是 Builder::upsert()：它要 laravel/framework >= 8.10，
            // 项目只约束 ^8.0（同 NodeConnectionAuditService 里的说明）。
            DB::statement(
                'INSERT INTO `' . self::TABLE_STATE . '`
                 (`user_id`,`multiplier`,`rate_bps`,`high`,`burst`,`state`,`sampled_at`,`computed_at`)
                 VALUES ' . $placeholders . '
                 ON DUPLICATE KEY UPDATE
                    `multiplier` = VALUES(`multiplier`),
                    `rate_bps` = VALUES(`rate_bps`),
                    `high` = VALUES(`high`),
                    `burst` = VALUES(`burst`),
                    `state` = VALUES(`state`),
                    `sampled_at` = VALUES(`sampled_at`),
                    `computed_at` = VALUES(`computed_at`)',
                $bindings
            );
        }
    }

    /**
     * 把热路径要用的两份数据写出去：当前生效的规则、正在叠加的用户倍率。
     * 用「整体重建」而不是增量维护 —— 每分钟几百个 key，比纠结增量一致性省心得多。
     *
     * @param array<int, array<string, mixed>> $stacked
     */
    private function publishRuntime(array $stacked): void
    {
        Redis::set(self::KEY_RULES, json_encode($this->rules(true), JSON_UNESCAPED_UNICODE));

        // 用回调形式而不是自己 new 一个 pipeline 再收尾：phpredis 用 exec() 收尾、
        // predis 用 execute()，框架的 pipeline(callable) 两种驱动都认。
        Redis::pipeline(function ($pipe) use ($stacked) {
            $pipe->del(self::KEY_USER_MULT);
            foreach ($stacked as $row) {
                $pipe->hset(self::KEY_USER_MULT, (string) $row['user_id'], (string) $row['multiplier']);
            }
        });

        // 常驻的队列 worker 里缓存着规则，改完规则要让它们下一分钟就看到新的。
        RateResolver::forgetRulesCache();
    }

    private function normalizeWeekdays($value): string
    {
        $days = [];
        foreach (is_array($value) ? $value : explode(',', (string) $value) as $day) {
            $day = (int) trim((string) $day);
            if ($day >= 1 && $day <= 7 && !in_array($day, $days, true)) {
                $days[] = $day;
            }
        }
        sort($days);

        return implode(',', $days);
    }

    private function clampMinute($value): int
    {
        return max(0, min(RateRuleMatcher::DAY_MINUTES, (int) $value));
    }

    /**
     * @param mixed $value
     */
    private function scalarToString($value): string
    {
        if (is_float($value)) {
            $text = number_format($value, 2, '.', '');

            return rtrim(rtrim($text, '0'), '.') ?: '0';
        }

        return (string) $value;
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
