<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\SubscribeAccessSummary;
use App\Models\SubscribeBlockRule;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 订阅清洗网关的取数层。
 *
 * 一行 = 账号 × 订阅 × IP × User-Agent（v2_subscribe_access_summary）。列表、筛选、
 * CSV 导出全部走同一套 buildQuery()，保证「导出的就是屏幕上筛出来的」。
 *
 * 时间一律按 UTC+8 解析与展示：筛选框里输入的 "2026-10-01 00:00" 是北京时间，
 * 返回给前端的 *_text 也是北京时间，前端不做任何时区换算。
 */
class SubscribeCleanGatewayService
{
    public const TABLE = 'v2_subscribe_access_summary';
    public const RULE_TABLE = 'v2_subscribe_block_rule';
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE = 100;
    // 导出上限：CSV 在响应里一次性生成，行数必须封顶，否则一次点击就能把 PHP 内存打满。
    public const MAX_EXPORT_ROWS = 200000;
    public const SORT_COLUMNS = ['last_seen_at', 'first_seen_at', 'hit_count', 'user_id'];
    public const BEIJING_OFFSET = 8 * 3600;

    private $availability;

    // 地理列（country_* / region / city）是否就位。静态的：一个请求里可能建多个服务实例
    // （列表、导出各一个），schema 探测没必要每个实例跑一遍。
    private static $geoColumns;

    public function available(): bool
    {
        if ($this->availability !== null) {
            return $this->availability;
        }

        try {
            return $this->availability = Schema::hasTable(self::TABLE)
                && Schema::hasColumns(self::TABLE, [
                    'user_id', 'subscription_id', 'request_ip', 'ua_hash', 'user_agent',
                    'hit_count', 'first_seen_at', 'last_seen_at', 'recent_decision',
                    'isp', 'organization', 'asn', 'location_status', 'location_resolved_at'
                ]);
        } catch (\Throwable $e) {
            return $this->availability = false;
        }
    }

    /**
     * 阻断规则表是否就绪。取数不依赖它，缺表时列表照常显示，只是「阻断状态」列恒为空。
     */
    public function blocksAvailable(): bool
    {
        try {
            return Schema::hasTable(self::RULE_TABLE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ---------------------------------------------------------------- 时间

    /**
     * 把筛选框里的北京时间解析成 Unix 时间戳。
     *
     * 接受 "2026-10-01"、"2026-10-01 12:30"、"2026-10-01 12:30:45"（按 UTC+8 解释）
     * 与纯数字 Unix 时间戳（内部/脚本调用方）。解析不出来返回 null，调用方忽略该条件
     * 而不是报错 —— 筛选框输到一半不该 500。
     */
    public function parseBeijingTime($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $stamp = (int)$value;
            return $stamp > 0 ? $stamp : null;
        }
        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        if ($text === '') {
            return null;
        }
        if (!preg_match(
            '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?$/',
            $text,
            $m
        )) {
            return null;
        }

        $year = (int)$m[1];
        $month = (int)$m[2];
        $day = (int)$m[3];
        $hour = isset($m[4]) && $m[4] !== '' ? (int)$m[4] : 0;
        $minute = isset($m[5]) && $m[5] !== '' ? (int)$m[5] : 0;
        $second = isset($m[6]) && $m[6] !== '' ? (int)$m[6] : 0;
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        // gmmktime 把输入当作 UTC，再平移 +8 小时 —— 等价于「按北京时间解释」，
        // 且不依赖服务器时区设置（date_default_timezone_set 在这套代码里没被固定）。
        return gmmktime($hour, $minute, $second, $month, $day, $year) - self::BEIJING_OFFSET;
    }

    /**
     * Unix 时间戳 → 北京时间文本。空值统一返回空串，前端据此渲染占位符。
     */
    public function beijingText($timestamp): string
    {
        $timestamp = $this->stamp($timestamp);
        if ($timestamp <= 0) {
            return '';
        }
        return gmdate('Y-m-d H:i:s', $timestamp + self::BEIJING_OFFSET);
    }

    /**
     * 时间字段统一收敛成 Unix 时间戳。
     *
     * 必要：模型上的 `'blocked_at' => 'timestamp'` 这类 cast 会把列读成 Carbon 实例，
     * 对对象直接 `(int)` 在 PHP 8 下抛 "Object of class Carbon could not be converted
     * to int"（PHP 7 下静默得到 1），时间会显示成 1970。凡是从带 cast 的模型上取时间
     * 字段，都必须走这里。
     */
    public function stamp($value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if ($value === null || $value === '') {
            return 0;
        }

        return (int)$value;
    }

    // ---------------------------------------------------------------- 查询

    /**
     * 按请求里的筛选条件构造查询。条件全部落在这里，导出与列表共用。
     */
    public function buildQuery(Request $request)
    {
        $query = SubscribeAccessSummary::query();

        $userId = (int)$request->input('user_id', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        $email = $this->text($request, 'email');
        if ($email !== '') {
            $query->whereIn('user_id', function ($sub) use ($email) {
                $sub->select('id')->from('v2_user')->where('email', 'like', '%' . $this->escapeLike($email) . '%');
            });
        }

        $subscriptionId = $this->text($request, 'subscription_id');
        if ($subscriptionId !== '' && ctype_digit($subscriptionId)) {
            $query->where('subscription_id', (int)$subscriptionId);
        }

        $planId = (int)$request->input('plan_id', 0);
        if ($planId > 0) {
            $query->whereIn('subscription_id', function ($sub) use ($planId) {
                $sub->select('id')->from('v2_subscription')->where('plan_id', $planId);
            });
        }

        $ip = $this->text($request, 'ip');
        if ($ip !== '') {
            // 精确 IP（运维排查时最常用的输入）走 request_ip 索引；带通配或残缺的输入
            // 退化成 LIKE，前导 % 用不上索引但这类查询本来就少。
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $query->where('request_ip', $ip);
            } else {
                $query->where('request_ip', 'like', '%' . $this->escapeLike($ip) . '%');
            }
        }

        $carrier = $this->text($request, 'carrier');
        if ($carrier !== '') {
            $like = '%' . $this->escapeLike($carrier) . '%';
            $query->where(function ($inner) use ($like) {
                $inner->where('isp', 'like', $like)->orWhere('organization', 'like', $like);
            });
        }

        $asn = $this->text($request, 'asn');
        if ($asn !== '') {
            // 允许 AS4134 / as4134 / 4134 三种写法，统一取数字部分精确匹配。
            $digits = preg_replace('/\D/', '', $asn);
            if ($digits !== '') {
                $query->where('asn', (int)$digits);
            }
        }

        $userAgent = $this->text($request, 'user_agent');
        if ($userAgent !== '') {
            $query->where('user_agent', 'like', '%' . $this->escapeLike($userAgent) . '%');
        }

        $this->applyHitCountFilter($query, $request);
        $this->applyTimeFilter($query, $request);
        $this->applyBlockedFilter($query, $request);
        $this->applyRiskFilter($query, $request);

        return $query;
    }

    /**
     * 「次数」列的比较筛选：条件 + 数值分离，与用户列表的数值筛选手法一致。
     */
    private function applyHitCountFilter($query, Request $request): void
    {
        $raw = $this->text($request, 'hit_count');
        if ($raw === '' || !ctype_digit($raw)) {
            return;
        }
        $condition = $this->text($request, 'hit_condition');
        $allowed = [
            '=' => '=', '>' => '>', '>=' => '>=', '<' => '<', '<=' => '<=',
        ];
        $operator = $allowed[$condition] ?? '>=';
        $query->where('hit_count', $operator, (int)$raw);
    }

    private function applyTimeFilter($query, Request $request): void
    {
        $start = $this->parseBeijingTime($request->input('start_time'));
        if ($start !== null) {
            $query->where('last_seen_at', '>=', $start);
        }
        $end = $this->parseBeijingTime($request->input('end_time'));
        if ($end !== null) {
            $query->where('last_seen_at', '<=', $end);
        }
    }

    /**
     * 「阻断状态」筛选。判定口径与列表里那一列完全同源：同一条 EXISTS 子查询，
     * 四个 scope 任一命中即为已阻断。
     */
    private function applyBlockedFilter($query, Request $request): void
    {
        $blocked = $this->text($request, 'blocked');
        if (!in_array($blocked, ['yes', 'no'], true) || !$this->blocksAvailable()) {
            return;
        }

        $method = $blocked === 'yes' ? 'whereExists' : 'whereNotExists';
        $query->{$method}(function ($sub) {
            $sub->select(DB::raw('1'))
                ->from(self::RULE_TABLE . ' as block_rule')
                ->where('block_rule.status', 'active')
                ->where(function ($expiry) {
                    $expiry->whereNull('block_rule.expires_at')->orWhere('block_rule.expires_at', '>', time());
                })
                ->whereRaw($this->blockMatchExpression());
        });
    }

    /**
     * 「风险程度」筛选：账号的阻断次数 ÷ 拉取总次数。
     *
     * 风险是**账号级**的，所以命中的是该账号的全部拉取记录 —— 这与列表里那一列的口径
     * 一致：同一个账号的每一行显示的都是同一个百分比，筛出来的是同样的整组行。
     *
     * 走的是一张按账号去重的台账（v2_subscribe_account_risk），先取 user_id 再
     * whereIn 收口，不在列表查询里现算聚合。
     */
    private function applyRiskFilter($query, Request $request): void
    {
        $raw = $this->text($request, 'risk_percent');
        if ($raw === '' || !is_numeric($raw)) {
            return;
        }

        $condition = $this->text($request, 'risk_condition');
        $sub = (new SubscribeAccountRiskService())->userIdsMatchingRisk(
            in_array($condition, ['=', '>', '>=', '<', '<='], true) ? $condition : '>=',
            $raw
        );
        if ($sub === null) {
            // 台账表还没建（未升级的库）：忽略这条筛选，而不是让整个列表 500。
            return;
        }

        $query->whereIn('user_id', $sub);
    }

    /**
     * 阻断规则的命中条件（配合 block_rule 别名使用）。
     *
     * 订阅用 subscription_id > 0 兜底：0 表示「写入时没有订阅」，不能让一条
     * subscription 维度的规则意外命中所有无订阅行。
     */
    public function blockMatchExpression(string $alias = 'block_rule', string $rowAlias = 'v2_subscribe_access_summary'): string
    {
        return "(({$alias}.scope = 'user' AND {$alias}.user_id = {$rowAlias}.user_id)"
            . " OR ({$alias}.scope = 'subscription' AND {$rowAlias}.subscription_id > 0"
            . " AND {$alias}.subscription_id = {$rowAlias}.subscription_id)"
            . " OR ({$alias}.scope = 'ip' AND {$alias}.ip = {$rowAlias}.request_ip)"
            . " OR ({$alias}.scope = 'user_agent' AND {$alias}.user_agent_hash = {$rowAlias}.ua_hash))";
    }

    public function sort(Request $request): array
    {
        $column = $this->text($request, 'sort');
        if (!in_array($column, self::SORT_COLUMNS, true)) {
            $column = 'last_seen_at';
        }
        $direction = strtolower($this->text($request, 'sort_dir')) === 'asc' ? 'asc' : 'desc';

        return [$column, $direction];
    }

    public function pagination(Request $request): array
    {
        $page = max(1, (int)($request->input('current') ?: $request->input('page') ?: 1));
        $pageSize = (int)($request->input('pageSize') ?: self::DEFAULT_PAGE_SIZE);
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, $pageSize));

        return [$page, $pageSize];
    }

    // ---------------------------------------------------------------- 取数

    /**
     * 列表页数据。返回给前端的每个字段都是展示-ready 的：时间已转北京时间文本，
     * 归属地已解析并回写，阻断状态已合并。
     */
    public function list(Request $request): array
    {
        [$page, $pageSize] = $this->pagination($request);
        [$sortColumn, $sortDirection] = $this->sort($request);

        $query = $this->buildQuery($request);
        $total = (int)(clone $query)->count();
        $rows = $query->orderBy($sortColumn, $sortDirection)
            ->orderByDesc('id')
            ->forPage($page, $pageSize)
            ->get();

        return [
            'data' => $this->shapeRows($rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'sort' => $sortColumn,
            'sort_dir' => $sortDirection
        ];
    }

    /**
     * 把聚合行补齐成前端要的一行。
     *
     * 关联数据全部批量取：一页最多 100 行，用户/订阅/套餐/归属地各一条查询，
     * 不做逐行的 N+1。
     */
    public function shapeRows($rows): array
    {
        if (!count($rows)) {
            return [];
        }

        $userIds = [];
        $subscriptionIds = [];
        $ips = [];
        foreach ($rows as $row) {
            $userIds[(int)$row->user_id] = true;
            if ((int)$row->subscription_id > 0) {
                $subscriptionIds[(int)$row->subscription_id] = true;
            }
            $ips[(string)$row->request_ip] = true;
        }
        $userIds = array_keys($userIds);
        $subscriptionIds = array_keys($subscriptionIds);
        $ips = array_keys($ips);

        $users = User::whereIn('id', $userIds)->get(['id', 'email'])->keyBy('id');
        // 每个账号名下的全部订阅：列表的「订阅」列按需求展示该账号的订阅清单
        // （多订阅时是一个列表），不只展示本行这一条。
        $subscriptionsByUser = [];
        $subscriptions = Subscription::whereIn('user_id', $userIds)
            ->get(['id', 'user_id', 'plan_id', 'status', 'expired_at', 'created_at'])
            ->sortBy('id');
        $planNames = Plan::whereIn('id', $subscriptions->pluck('plan_id')->filter()->unique()->all())
            ->pluck('name', 'id');
        foreach ($subscriptions as $subscription) {
            $subscriptionsByUser[(int)$subscription->user_id][] = [
                'id' => (int)$subscription->id,
                'plan_id' => (int)$subscription->plan_id,
                'plan_name' => (string)($planNames[(int)$subscription->plan_id] ?? ''),
                'status' => (string)$subscription->status,
                'expired_at' => $this->stamp($subscription->expired_at),
                'expired_at_text' => $this->beijingText($subscription->expired_at)
            ];
        }

        $locations = $this->resolveLocations($rows, $ips);
        $blocks = $this->blocksByTarget($rows);
        // 账号级风险台账，一条 whereIn 取回本页所有账号，逐行查会是一屏 100 次。
        $risksByUser = (new SubscribeAccountRiskService())->summaryForUsers($userIds);

        $data = [];
        foreach ($rows as $row) {
            $userId = (int)$row->user_id;
            $subscriptionId = (int)$row->subscription_id;
            $ip = (string)$row->request_ip;
            $location = $locations[$ip] ?? null;
            $risk = $risksByUser[$userId] ?? null;

            $data[] = [
                'id' => (int)$row->id,
                'user_id' => $userId,
                'user_email' => (string)($users->get($userId)->email ?? ''),
                'subscription_id' => $subscriptionId,
                'subscriptions' => $subscriptionsByUser[$userId] ?? [],
                'request_ip' => $ip,
                'isp' => $location['isp'] ?? '',
                'organization' => $location['organization'] ?? '',
                'asn' => $location['asn'] ?? null,
                'country_name' => $location['country_name'] ?? '',
                'region' => $location['region'] ?? '',
                'city' => $location['city'] ?? '',
                'location_status' => $location['status'] ?? 'pending',
                'user_agent' => (string)$row->user_agent,
                'ua_hash' => (string)$row->ua_hash,
                'hit_count' => (int)$row->hit_count,
                'first_seen_at' => $this->stamp($row->first_seen_at),
                'first_seen_text' => $this->beijingText($row->first_seen_at),
                'last_seen_at' => $this->stamp($row->last_seen_at),
                'last_seen_text' => $this->beijingText($row->last_seen_at),
                // 账号级风险程度：阻断次数 ÷ 拉取总次数。同一个账号的每一行都是同一个值
                // （需求就是按账号算的）。台账里没有这个账号时为 null —— 界面上「—」与
                // 「0%」是两件事：没算过 vs 算过且一次没被阻断。
                'risk_percent' => $risk === null ? null : (float)$risk->risk_percent,
                'risk_blocked_count' => $risk === null ? 0 : (int)$risk->blocked_count,
                'risk_total_count' => $risk === null ? 0 : (int)$risk->total_count,
                'risk_computed_text' => $risk === null ? '' : $this->beijingText($risk->computed_at),
                'risk_handled_at' => $risk === null || $risk->handled_at === null
                    ? null : (int)$risk->handled_at,
                'risk_handled_text' => $risk === null || $risk->handled_at === null
                    ? '' : $this->beijingText($risk->handled_at),
                'block' => $this->matchBlock($blocks, $userId, $subscriptionId, $ip, (string)$row->ua_hash)
            ];
        }

        return $data;
    }

    /**
     * 归属地：库里已经有解析结果的直接用；没有的现查一次并回写，让下一次（以及
     * 按运营商/ASN 的筛选）能命中。
     *
     * 现查是批量的一次 lookupMany（内部已合并缓存查询），回写只作用于本页这几行，
     * 不构成写放大。写失败不影响本次展示 —— 返回的内存值就是刚查到的结果。
     */
    private function resolveLocations($rows, array $ips): array
    {
        $locations = [];
        $pending = [];
        $hasGeo = $this->geoColumnsAvailable();
        foreach ($rows as $row) {
            $ip = (string)$row->request_ip;
            if ($row->location_resolved_at === null) {
                $pending[$ip] = true;
                continue;
            }
            $locations[$ip] = [
                'status' => (string)($row->location_status ?: 'unknown'),
                'isp' => (string)($row->isp ?? ''),
                'organization' => (string)($row->organization ?? ''),
                'asn' => $row->asn === null || $row->asn === '' ? null : (int)$row->asn,
                'country_name' => $hasGeo ? (string)($row->country_name ?? '') : '',
                'region' => $hasGeo ? (string)($row->region ?? '') : '',
                'city' => $hasGeo ? (string)($row->city ?? '') : ''
            ];
        }
        if (!count($pending)) {
            return $locations;
        }

        try {
            $resolved = (new IpLocationService())->lookupMany(array_keys($pending));
        } catch (\Throwable $e) {
            // IP 库缺失/损坏时页面仍要能看：归属地留空，状态标记为未解析。
            foreach (array_keys($pending) as $ip) {
                $locations[$ip] = [
                    'status' => 'pending', 'isp' => '', 'organization' => '', 'asn' => null,
                    'country_name' => '', 'region' => '', 'city' => ''
                ];
            }
            return $locations;
        }

        $now = time();
        foreach (array_keys($pending) as $ip) {
            $location = $resolved[$ip] ?? [];
            $status = (string)($location['status'] ?? 'unknown');
            $asn = isset($location['asn']) && $location['asn'] !== '' && $location['asn'] !== null
                ? (int)$location['asn'] : null;
            $countryName = (string)($location['country_name'] ?? '');
            if ($countryName === '') {
                // 全球库偶尔只给两字母代码，用它兜底总好过留空。
                $countryName = (string)($location['country_code'] ?? '');
            }
            $locations[$ip] = [
                'status' => $status,
                'isp' => (string)($location['isp'] ?? ''),
                'organization' => (string)($location['organization'] ?? ''),
                'asn' => $asn,
                'country_name' => $countryName,
                'region' => (string)($location['region'] ?? ''),
                'city' => (string)($location['city'] ?? '')
            ];

            try {
                // 只回写还没解析过的行：解析过一次就不再重复写，也避免覆盖并发写入的
                // 新行（WHERE 里的 location_resolved_at IS NULL 就是这层保护）。
                $update = [
                    'isp' => $locations[$ip]['isp'] === '' ? null : $locations[$ip]['isp'],
                    'organization' => $locations[$ip]['organization'] === '' ? null : $locations[$ip]['organization'],
                    'asn' => $asn,
                    'location_status' => $status,
                    'location_resolved_at' => $now,
                    'updated_at' => $now
                ];
                if ($hasGeo) {
                    $update['country_code'] = ($location['country_code'] ?? '') === ''
                        ? null : (string)$location['country_code'];
                    $update['country_name'] = $countryName === '' ? null : $countryName;
                    $update['region'] = $locations[$ip]['region'] === '' ? null : $locations[$ip]['region'];
                    $update['city'] = $locations[$ip]['city'] === '' ? null : $locations[$ip]['city'];
                }
                DB::table(self::TABLE)
                    ->where('request_ip', $ip)
                    ->whereNull('location_resolved_at')
                    ->update($update);
            } catch (\Throwable $e) {
                // 回写失败只是下次还要再查一遍，不影响本次展示。
            }
        }

        return $locations;
    }

    /**
     * 地理列是否就位。老库升级到这一版之前没有 country_* / region / city，任何把
     * 它们写进 UPDATE 的语句都会直接抛 SQL 错误，所以写入前先问一次。
     *
     * 刻意不放进 available()：那会让「库没升级」从「归属地少一档」升级成「整页不可用」，
     * 而这三列只是兜底显示，缺了不该拖垮页面。
     */
    private function geoColumnsAvailable(): bool
    {
        if (self::$geoColumns !== null) {
            return self::$geoColumns;
        }

        try {
            return self::$geoColumns = Schema::hasColumn(self::TABLE, 'country_name')
                && Schema::hasColumn(self::TABLE, 'city');
        } catch (\Throwable $e) {
            return self::$geoColumns = false;
        }
    }

    /**
     * 本页所有行涉及的账号/订阅/IP/UA 上生效中的阻断规则。
     *
     * 一条 OR 查询取回全部候选，再在内存里按四个维度各自归集 —— 逐行查规则表是
     * 一屏 100 次查询。
     */
    public function blocksByTarget($rows): array
    {
        $result = ['user' => [], 'subscription' => [], 'ip' => [], 'user_agent' => []];
        if (!$this->blocksAvailable() || !count($rows)) {
            return $result;
        }

        $userIds = [];
        $subscriptionIds = [];
        $ips = [];
        $uaHashes = [];
        foreach ($rows as $row) {
            $userIds[(int)$row->user_id] = true;
            if ((int)$row->subscription_id > 0) {
                $subscriptionIds[(int)$row->subscription_id] = true;
            }
            $ips[(string)$row->request_ip] = true;
            $uaHashes[(string)$row->ua_hash] = true;
        }

        try {
            $rules = SubscribeBlockRule::where('status', 'active')
                ->where(function ($expiry) {
                    $expiry->whereNull('expires_at')->orWhere('expires_at', '>', time());
                })
                ->where(function ($outer) use ($userIds, $subscriptionIds, $ips, $uaHashes) {
                    $outer->where(function ($q) use ($userIds) {
                        $q->where('scope', 'user')->whereIn('user_id', array_keys($userIds));
                    })->orWhere(function ($q) use ($subscriptionIds) {
                        $q->where('scope', 'subscription')->whereIn('subscription_id', array_keys($subscriptionIds));
                    })->orWhere(function ($q) use ($ips) {
                        $q->where('scope', 'ip')->whereIn('ip', array_keys($ips));
                    })->orWhere(function ($q) use ($uaHashes) {
                        $q->where('scope', 'user_agent')->whereIn('user_agent_hash', array_keys($uaHashes));
                    });
                })
                ->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
            return $result;
        }

        foreach ($rules as $rule) {
            $summary = $this->ruleSummary($rule);
            switch ((string)$rule->scope) {
                case 'user':
                    $result['user'][(int)$rule->user_id] = $summary;
                    break;
                case 'subscription':
                    $result['subscription'][(int)$rule->subscription_id] = $summary;
                    break;
                case 'ip':
                    $result['ip'][(string)$rule->ip] = $summary;
                    break;
                case 'user_agent':
                    $result['user_agent'][(string)$rule->user_agent_hash] = $summary;
                    break;
            }
        }

        return $result;
    }

    public function matchBlock(array $blocks, int $userId, int $subscriptionId, string $ip, string $uaHash): ?array
    {
        foreach ([
            'user' => $userId,
            'subscription' => $subscriptionId,
            'ip' => $ip,
            'user_agent' => $uaHash
        ] as $scope => $value) {
            if ($scope === 'subscription' && $subscriptionId <= 0) {
                continue;
            }
            if (isset($blocks[$scope][$value])) {
                return $blocks[$scope][$value];
            }
        }

        return null;
    }

    public function ruleSummary(SubscribeBlockRule $rule): array
    {
        return [
            'id' => (int)$rule->id,
            'scope' => (string)$rule->scope,
            'reason' => (string)($rule->reason ?? ''),
            'blocked_at' => $this->stamp($rule->blocked_at),
            'blocked_at_text' => $this->beijingText($rule->blocked_at),
            'expires_at' => $rule->expires_at === null ? null : $this->stamp($rule->expires_at),
            'expires_at_text' => $rule->expires_at === null ? '' : $this->beijingText($rule->expires_at),
            'user_id' => $rule->user_id === null ? null : (int)$rule->user_id,
            'subscription_id' => $rule->subscription_id === null ? null : (int)$rule->subscription_id,
            'ip' => (string)($rule->ip ?? ''),
            'user_agent' => (string)($rule->user_agent ?? '')
        ];
    }

    // ---------------------------------------------------------------- CSV

    /**
     * 分块产出 CSV。回调按块拿到行数组，导出动作因此不受总行数影响内存。
     *
     * 用 OFFSET 翻页而不是 cursor()：Laravel 默认开缓冲查询，cursor() 在拿到第一行
     * 之前就已经把整个结果集读进 PHP 内存 —— 导出 20 万行正好把内存吃光，与「分块」
     * 的初衷相反。每页都是一条带上限的独立查询，峰值内存只与 chunk 有关。
     *
     * 代价是导出期间新写入的行可能让某一行在页边界上重复或跳过。导出是只读快照，
     * 这个偏差可以接受；换成游标换来的内存风险不可接受。
     *
     * @return array{rows:int,truncated:bool}
     */
    public function eachExportRow(Request $request, callable $callback, int $chunk = 1000): array
    {
        [$sortColumn, $sortDirection] = $this->sort($request);
        $query = $this->buildQuery($request)
            ->orderBy($sortColumn, $sortDirection)
            ->orderByDesc('id');

        $chunk = max(100, min(5000, $chunk));
        $processed = 0;
        $truncated = false;
        $page = 1;
        while (true) {
            $rows = (clone $query)->forPage($page, $chunk)->get();
            if (!count($rows)) {
                break;
            }
            $callback($this->shapeRows($rows));
            $processed += count($rows);
            if ($processed >= self::MAX_EXPORT_ROWS) {
                $truncated = true;
                break;
            }
            $page++;
        }

        return ['rows' => $processed, 'truncated' => $truncated];
    }

    /**
     * CSV 字段转义。必须满足 RFC 4180：含分隔符、引号、换行的字段整体加引号，
     * 内部引号翻倍 —— User-Agent 里出现引号和逗号是常态（curl/8.0、Mozilla/5.0 (…）,
     * 不转义的 CSV 在 Excel 里会整列错位。
     */
    public function csvField($value): string
    {
        $text = (string)$value;
        if ($text === '') {
            return '';
        }
        // 前导 = + - @ 会被 Excel 当公式执行，前面加一个单引号拆掉。
        if (preg_match('/^[=+\-@]/', $text)) {
            $text = "'" . $text;
        }
        if (strpbrk($text, ",;\"\r\n\t") !== false) {
            return '"' . str_replace('"', '""', $text) . '"';
        }

        return $text;
    }

    /**
     * 「订阅」列的导出形式：账号名下全部订阅，一条一行文本。
     */
    public function subscriptionsText(array $subscriptions): string
    {
        $parts = [];
        foreach ($subscriptions as $subscription) {
            $plan = $subscription['plan_name'] !== '' ? $subscription['plan_name'] : ('套餐#' . $subscription['plan_id']);
            $parts[] = '#' . $subscription['id'] . ' ' . $plan;
        }

        return implode(' / ', $parts);
    }

    private function text(Request $request, string $key): string
    {
        $value = $request->input($key);
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
