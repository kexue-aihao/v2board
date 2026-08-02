<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\IpAccountLink;
use App\Models\User;
use App\Services\IpAccountLinkService;
use App\Services\IpLocationService;
use App\Services\SubscribeAuditRetentionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 多账号同 IP 关联分析。列出被多个账号共用的订阅拉取 IP，并支持下钻到该 IP 下逐账号的
 * 记录（含 User-Agent）。
 *
 * 数据全部来自累积表 v2_ip_account_link，由 audit:ip-link 离线从
 * v2_subscribe_request_log 增量聚合。本控制器只读，不写任何表，也不碰订阅拉取路径。
 *
 * 为什么另立控制器而不并进 RiskTraceController：那个类是「按 token 反查归属」，数据源是
 * token 历史 + 原始日志，已有 400 多行；本功能的数据源、生命周期（依赖离线聚合的新鲜度）
 * 与失败模式都不同，混在一起会让两边的 meta 互相污染，也更容易和并行改动撞在一起。
 */
class RiskSharedIpController extends Controller
{
    private const SORTABLE = ['account_count', 'last_seen_at', 'request_count'];
    private const DETAIL_SORTABLE = ['request_count', 'last_seen_at', 'first_seen_at', 'user_id'];

    /** 默认时间窗与硬上限。禁止无界聚合：不给参数就是最近一年，最多回看三年。 */
    private const DEFAULT_WINDOW_DAYS = 365;
    private const MAX_WINDOW_DAYS = 1095;

    private const MIN_ACCOUNTS_DEFAULT = 2;
    private const MIN_ACCOUNTS_MAX = 50;

    /** 列表每行最多展示几个账号摘要，超出只给总数。这是**每个 IP** 的额度，不是全页共享的。 */
    private const ACCOUNT_SUMMARY_LIMIT = 5;
    /** 一条 UNION ALL 语句里最多拼多少个分支，控制单条 SQL 的长度与占位符数量。 */
    private const UNION_BRANCH_LIMIT = 25;

    /** SubscribeAuditService 解析不出客户端地址时写下的占位值，不是一个真实 IP。 */
    private const UNRESOLVED_IP = 'unknown';

    /** 邮箱模糊匹配的封顶，口径同 RiskTraceController。 */
    private const EMAIL_MATCH_LIMIT = 200;
    /** 账号筛选先解析出的 IP 集合上限。 */
    private const SCOPE_IP_LIMIT = 1000;
    /** total 只数到这里为止，避免为了一个数字把整个时间窗的分组结果全部物化。 */
    private const TOTAL_COUNT_CAP = 10000;

    /**
     * 明细页每个账号最多回多少条 UA。UA 全文最长 1000 字节，一页 100 个账号 × 20 条就是
     * 2000 条全文，所以额度会按当页账号数在 UA_ROW_CAP 内均摊（见 userAgents()），
     * 但保底不低于 UA_PER_ACCOUNT_MIN —— 绝不允许出现「有 3 个 UA 却一条都不显示」。
     */
    private const UA_PER_ACCOUNT_LIMIT = 20;
    private const UA_PER_ACCOUNT_MIN = 5;
    private const UA_ROW_CAP = 600;

    public function fetch(Request $request)
    {
        $service = new IpAccountLinkService();
        $window = $this->window($request);
        $minAccounts = $this->minAccounts($request);
        $meta = array_merge($this->meta($service), [
            'window' => $window,
            'min_accounts' => $minAccounts
        ]);
        $empty = array_merge([
            'data' => [],
            'total' => 0,
            'total_capped' => false,
            'email_truncated' => false,
            'scope_truncated' => false,
            'non_routable_rows' => 0
        ], $meta);

        if (!$service->available()) {
            return response($empty);
        }

        $page = max(1, (int)($request->input('current') ?: $request->input('page') ?: 1));
        $pageSize = min(100, max(10, (int)($request->input('pageSize') ?: 20)));
        $sort = in_array($request->input('sort'), self::SORTABLE, true)
            ? $request->input('sort') : 'account_count';
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC'], true)
            ? $request->input('sort_type') : 'DESC';

        $ipPrefix = $this->ipPrefix($request);
        $scope = $this->scopeIps($request, $window);
        if ($scope['ips'] !== null && !count($scope['ips'])) {
            return response(array_merge($empty, [
                'email_truncated' => $scope['email_truncated'],
                'scope_truncated' => $scope['truncated']
            ]));
        }

        // total 与列表各自从 linkQuery() 起一条新 builder：count() 作用在带 GROUP BY 的
        // builder 上返回的是某一组的行数，不是组数（口径同 RiskTraceController::fetch）。
        $rows = $this->excludeUnresolved($this->linkQuery($window, $ipPrefix, $scope['ips']))
            ->select('request_ip')
            ->selectRaw('COUNT(DISTINCT user_id) AS account_count, SUM(hit_count) AS request_count,
                         MIN(first_seen_at) AS first_seen_at, MAX(last_seen_at) AS last_seen_at')
            ->groupBy('request_ip')
            ->havingRaw('COUNT(DISTINCT user_id) >= ?', [$minAccounts])
            ->orderBy($sort, $sortType)
            // 次序键必须有：只按 account_count 排会让并列的行在翻页之间漂移。
            ->orderBy('request_ip', 'ASC')
            ->forPage($page, $pageSize)
            ->get();

        // 组数用带 LIMIT 的派生表来数：加了上限 MySQL 就能在数到 CAP+1 组时停下，
        // 不必把整个时间窗的分组结果全部物化。
        $countable = $this->excludeUnresolved($this->linkQuery($window, $ipPrefix, $scope['ips']))
            ->select('request_ip')
            ->groupBy('request_ip')
            ->havingRaw('COUNT(DISTINCT user_id) >= ?', [$minAccounts])
            ->limit(self::TOTAL_COUNT_CAP + 1)
            ->toBase();
        $total = (int)DB::query()->fromSub($countable, 'shared_ip_groups')->count();
        $totalCapped = $total > self::TOTAL_COUNT_CAP;

        $data = $this->decorateRows($rows, $window);
        $nonRoutable = 0;
        foreach ($data as $row) {
            if ($row['ip_kind'] !== 'public') {
                $nonRoutable++;
            }
        }

        return response(array_merge([
            'data' => $data,
            'total' => $totalCapped ? self::TOTAL_COUNT_CAP : $total,
            'total_capped' => $totalCapped,
            'email_truncated' => $scope['email_truncated'],
            'scope_truncated' => $scope['truncated'],
            // 当页有多少行不是公网地址。反向代理没配对时全站 request_ip 会恒为回环/内网
            // 地址，聚合出来就是一条「所有账号共用一个 IP」的假结论；这个计数让面板能把
            // 那种情况当故障提示而不是当头号线索。
            'non_routable_rows' => $nonRoutable
        ], $meta));
    }

    /**
     * 单个 IP 的逐账号明细，含每个账号在该 IP 上出现过的 UA（按 ua_hash 去重）。
     */
    public function detail(Request $request)
    {
        $ip = trim((string)$request->input('ip'));
        // SubscribeAuditService 在解析不出地址时会写字面量 'unknown'，那也是一条合法记录。
        if ($ip === '' || (!filter_var($ip, FILTER_VALIDATE_IP) && $ip !== 'unknown')) {
            abort(500, 'IP 有误');
        }

        $service = new IpAccountLinkService();
        $window = $this->window($request);
        $meta = array_merge($this->meta($service), ['window' => $window]);
        if (!$service->available()) {
            return response(array_merge(['data' => [], 'total' => 0, 'ip' => null], $meta));
        }

        $page = max(1, (int)($request->input('current') ?: $request->input('page') ?: 1));
        $pageSize = min(100, max(10, (int)($request->input('pageSize') ?: 20)));
        $sort = in_array($request->input('sort'), self::DETAIL_SORTABLE, true)
            ? $request->input('sort') : 'request_count';
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC'], true)
            ? $request->input('sort_type') : 'DESC';

        // request_ip 等值命中 ip_user_seen_hits 的前导列，只碰这个 IP 的行。
        $summary = $this->linkQuery($window, null, null)
            ->where('request_ip', $ip)
            ->selectRaw('COUNT(DISTINCT user_id) AS account_count, COUNT(DISTINCT ua_hash) AS ua_count,
                         SUM(hit_count) AS request_count, MIN(first_seen_at) AS first_seen_at,
                         MAX(last_seen_at) AS last_seen_at')
            ->first();
        $accountCount = $summary ? (int)$summary->account_count : 0;

        $accounts = collect();
        if ($accountCount) {
            $accounts = $this->linkQuery($window, null, null)
                ->where('request_ip', $ip)
                ->select('user_id')
                ->selectRaw('SUM(hit_count) AS request_count, MIN(first_seen_at) AS first_seen_at,
                             MAX(last_seen_at) AS last_seen_at, COUNT(DISTINCT ua_hash) AS ua_count')
                ->groupBy('user_id')
                ->orderBy($sort, $sortType)
                ->orderBy('user_id', 'ASC')
                ->forPage($page, $pageSize)
                ->get();
        }

        $userIds = $accounts->pluck('user_id')->map('intval')->all();
        $users = count($userIds)
            ? User::whereIn('id', $userIds)->get(['id', 'email', 'banned'])->keyBy('id')
            : collect();
        $userAgents = $this->userAgents($ip, $userIds, $window);

        $data = [];
        foreach ($accounts as $account) {
            $userId = (int)$account->user_id;
            $user = $users->get($userId);
            $agents = $userAgents[$userId] ?? ['rows' => [], 'truncated' => false];
            $data[] = [
                'user_id' => $userId,
                // delUser/allDel 会留下孤儿记录，邮箱取不到时 UI 必须显示「已删除用户」。
                'email' => $user ? $user->email : null,
                'banned' => $user ? (bool)$user->banned : false,
                'deleted' => !$user,
                'request_count' => (int)$account->request_count,
                'ua_count' => (int)$account->ua_count,
                'first_seen_at' => (int)$account->first_seen_at,
                'last_seen_at' => (int)$account->last_seen_at,
                'user_agents' => $agents['rows'],
                // 用「取回条数 < 该账号的 ua_count」判断截断，比只看每账号封顶更准：
                // UA 查询还有一道全局行数上限，靠后的账号可能一条都没取到。
                'user_agents_truncated' => count($agents['rows']) < (int)$account->ua_count
            ];
        }

        $this->audit($request, 'SHARED IP DETAIL ip=' . $this->maskIp($ip) . ' accounts=' . $accountCount);

        return response(array_merge([
            'data' => $data,
            'total' => $accountCount,
            'ip' => [
                'request_ip' => $ip,
                'ip_kind' => $this->ipKind($ip),
                'ip_location' => (new IpLocationService())->lookup($ip),
                'account_count' => $accountCount,
                'ua_count' => $summary ? (int)$summary->ua_count : 0,
                'request_count' => $summary ? (int)$summary->request_count : 0,
                'first_seen_at' => $summary && $summary->first_seen_at !== null ? (int)$summary->first_seen_at : null,
                'last_seen_at' => $summary && $summary->last_seen_at !== null ? (int)$summary->last_seen_at : null
            ]
        ], $meta));
    }

    /**
     * 时间窗按 last_seen_at 卡：语义是「最近一次出现落在窗内」，与列表里显示的
     * last_seen_at 逐字对应。seen_ip_user_hits 以 last_seen_at 为前导列，所以窗口是真正的
     * 区间扫描而不只是过滤条件；窗口宽到接近全表时优化器会改走 ip_user_seen_hits 做
     * index-only 全扫 + 流式分组，两条路都在索引内，不回表。
     *
     * 窗口**只**约束 last_seen_at：返回的 first_seen_at 是 MIN(first_seen_at)、
     * request_count 是 SUM(hit_count)，都是该三元组的终身值，可能落在窗口之外。
     */
    private function linkQuery(array $window, ?string $ipPrefix, ?array $scopeIps)
    {
        $query = IpAccountLink::query()
            ->where('last_seen_at', '>=', $window['start_at'])
            ->where('last_seen_at', '<=', $window['end_at']);
        if ($ipPrefix !== null) {
            $query->where('request_ip', 'like', $ipPrefix . '%');
        }
        if ($scopeIps !== null) {
            $query->whereIn('request_ip', $scopeIps);
        }
        return $query;
    }

    /**
     * 列表页排除字面量 'unknown'。那不是一个 IP，而是 SubscribeAuditService 解析不出客户端
     * 地址时写下的占位值：把一批彼此无关的未知来源聚成一行，account_count 会等于全站解析
     * 失败过的账号数，并且因为默认按 account_count 倒序，它会稳坐首页第一行 —— 一条纯噪音
     * 且极具误导性的「所有账号共用一个 IP」。
     *
     * 明细页不套这个条件：显式下钻 ip=unknown 仍然能看（那是管理员主动要看的诊断视图）。
     */
    private function excludeUnresolved($query)
    {
        return $query->where('request_ip', '<>', self::UNRESOLVED_IP);
    }

    /**
     * IP 的可路由性，给面板降权用。
     *
     * 站点经反向代理接入时 REMOTE_ADDR 恒为回环地址，只有 config/trustedproxy.php 配对了
     * 才会去解析转发头（见 SubscribeAuditService::resolveIp()）。一旦可信代理没配好或换了
     * 新代理，全站每个账号的 request_ip 都会是同一个 127.0.0.1 / 内网地址，聚合出来就是
     * 一行 account_count = 全站账号数的假结论。后端不替管理员判断那是不是故障，但必须把
     * 「这一行不是公网地址」这个事实标出来，让面板能提示排查代理配置。
     *
     * 判定口径与 IpLocationService::isPublicIp() 一致（FILTER_FLAG_NO_PRIV_RANGE |
     * FILTER_FLAG_NO_RES_RANGE）。
     *
     * @return string public | non_routable | unresolved
     */
    private function ipKind(string $ip): string
    {
        if ($ip === self::UNRESOLVED_IP || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return 'unresolved';
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            ? 'public'
            : 'non_routable';
    }

    /**
     * 给列表每行补上归属地与账号摘要。两处都是批量的：账号摘要按 IP 各取各的额度，
     * 归属地一次 whereIn 取缓存 —— 一整页都不会逐行查库。
     *
     * 注意 first_seen_at / request_count 是**终身值**：时间窗只过滤 last_seen_at，
     * MIN(first_seen_at) 取的是该 IP 下三元组的最早出现时间，SUM(hit_count) 是累计次数，
     * 两者都可能落在窗口之外（一个标着「最近 365 天」的行完全可以显示三年前的首次出现）。
     * 这是累积表方案的固有代价，契约里已声明，面板不要拿 window 去校验这两个字段。
     */
    private function decorateRows($rows, array $window): array
    {
        $ips = [];
        foreach ($rows as $row) {
            $ips[] = (string)$row->request_ip;
        }
        if (!count($ips)) {
            return [];
        }

        $summaryRows = $this->accountSummaries($ips, $window);

        $userIds = [];
        foreach ($summaryRows as $ipRows) {
            foreach ($ipRows as $summaryRow) {
                $userIds[(int)$summaryRow->user_id] = true;
            }
        }
        $users = count($userIds)
            ? User::whereIn('id', array_keys($userIds))->get(['id', 'email', 'banned'])->keyBy('id')
            : collect();

        $grouped = [];
        foreach ($summaryRows as $ip => $ipRows) {
            $grouped[$ip] = [];
            foreach ($ipRows as $summaryRow) {
                $userId = (int)$summaryRow->user_id;
                $user = $users->get($userId);
                $grouped[$ip][] = [
                    'user_id' => $userId,
                    'email' => $user ? $user->email : null,
                    'banned' => $user ? (bool)$user->banned : false,
                    'deleted' => !$user,
                    'request_count' => (int)$summaryRow->request_count,
                    'first_seen_at' => (int)$summaryRow->first_seen_at,
                    'last_seen_at' => (int)$summaryRow->last_seen_at
                ];
            }
        }

        $locations = (new IpLocationService())->lookupMany($ips);
        $data = [];
        foreach ($rows as $row) {
            $ip = (string)$row->request_ip;
            $accounts = $grouped[$ip] ?? [];
            $accountCount = (int)$row->account_count;
            $data[] = [
                'request_ip' => $ip,
                'ip_kind' => $this->ipKind($ip),
                'ip_location' => $locations[$ip] ?? [],
                'account_count' => $accountCount,
                'request_count' => (int)$row->request_count,
                'first_seen_at' => (int)$row->first_seen_at,
                'last_seen_at' => (int)$row->last_seen_at,
                'accounts' => $accounts,
                'accounts_shown' => count($accounts),
                // 每个 IP 的摘要额度都是独立的 ACCOUNT_SUMMARY_LIMIT，所以这个判断精确等于
                // 「账号数超过了每行的展示额度」，不会再因为别的 IP 占满预算而误报。
                'accounts_truncated' => count($accounts) < $accountCount
            ];
        }
        return $data;
    }

    /**
     * 取当页每个 IP 的「前 ACCOUNT_SUMMARY_LIMIT 个账号」。
     *
     * 为什么不是一条 `GROUP BY request_ip, user_id` + 全局 LIMIT：那种全局上限是按排序顺序
     * 被吃掉的。ORDER BY request_ip ASC 会让排在最前面的 IP 先拿行，一个有几千个
     * (账号,UA) 三元组的 CGNAT 出口能把整页的行预算吃光，后面的 IP 全部拿到空数组 ——
     * 恰恰就是那个上限本来想防止的后果。
     *
     * 改成「每个 IP 一条带 LIMIT 的子查询、用 UNION ALL 拼起来」：额度按 IP 独立，总行数
     * 恒等于 Σ min(该 IP 账号数, 5)，上界 pageSize × 5 ≤ 500 行。每个分支都是 request_ip
     * 等值 + last_seen_at 区间，命中 ip_user_seen_hits 的前两列；分支数按 UNION_BRANCH_LIMIT
     * 分批，一页最多 4 条语句（pageSize 上限 100）。
     *
     * MySQL 对带括号的 UNION 分支保留 ORDER BY ... LIMIT 的语义（LIMIT 决定取哪些行），
     * 这正是这里依赖的行为；分支之间的顺序不重要，结果按 request_ip 归组后再用。
     *
     * @return array<string, array> IP => 该 IP 的摘要行（stdClass），已按次数倒序
     */
    private function accountSummaries(array $ips, array $window): array
    {
        $grouped = [];
        $unique = array_values(array_unique($ips));
        if (!count($unique)) {
            return $grouped;
        }
        $table = IpAccountLinkService::TABLE;
        foreach (array_chunk($unique, self::UNION_BRANCH_LIMIT) as $chunk) {
            $branches = [];
            $bindings = [];
            foreach ($chunk as $ip) {
                $branches[] = '(SELECT `request_ip`, `user_id`, SUM(`hit_count`) AS `request_count`,'
                    . ' MIN(`first_seen_at`) AS `first_seen_at`, MAX(`last_seen_at`) AS `last_seen_at`'
                    . ' FROM `' . $table . '`'
                    . ' WHERE `request_ip` = ? AND `last_seen_at` >= ? AND `last_seen_at` <= ?'
                    . ' GROUP BY `request_ip`, `user_id`'
                    . ' ORDER BY `request_count` DESC, `user_id` ASC'
                    . ' LIMIT ' . self::ACCOUNT_SUMMARY_LIMIT . ')';
                $bindings[] = $ip;
                $bindings[] = $window['start_at'];
                $bindings[] = $window['end_at'];
            }
            foreach (DB::select(implode(' UNION ALL ', $branches), $bindings) as $row) {
                $grouped[(string)$row->request_ip][] = $row;
            }
        }
        return $grouped;
    }

    /**
     * 明细页每个账号在该 IP 上用过的 UA。ua_hash 已经是去重后的粒度，所以直接取行。
     *
     * 额度按账号独立发放，理由同 accountSummaries()：原来那条
     * `whereIn(user_id) ORDER BY user_id ASC, hit_count DESC LIMIT 600` 的全局上限是按
     * user_id 升序被吃掉的，小 user_id 的账号先拿满，一页 100 个账号时靠后的账号会返回
     * 空数组配 truncated=true —— 面板上就是「有 3 个 UA」却一条都不显示。
     *
     * 每账号额度 = UA_ROW_CAP / 当页账号数，上限 UA_PER_ACCOUNT_LIMIT、下限
     * UA_PER_ACCOUNT_MIN：UA 全文最长 1000 字节，一页 100 个账号各 20 条会让响应体膨胀到
     * 兆级，所以要均摊；但均摊结果绝不允许是 0。总行数上界 max(UA_ROW_CAP,
     * UA_PER_ACCOUNT_MIN × pageSize) = 600 行。
     *
     * @return array<int, array{rows:array, truncated:bool}>
     */
    private function userAgents(string $ip, array $userIds, array $window): array
    {
        $result = [];
        $unique = array_values(array_unique(array_map('intval', $userIds)));
        if (!count($unique)) {
            return $result;
        }
        $perAccount = min(
            self::UA_PER_ACCOUNT_LIMIT,
            max(self::UA_PER_ACCOUNT_MIN, (int)floor(self::UA_ROW_CAP / count($unique)))
        );

        $table = IpAccountLinkService::TABLE;
        foreach (array_chunk($unique, self::UNION_BRANCH_LIMIT) as $chunk) {
            $branches = [];
            $bindings = [];
            foreach ($chunk as $userId) {
                $branches[] = '(SELECT `user_id`, `ua_hash`, `user_agent`, `hit_count`,'
                    . ' `first_seen_at`, `last_seen_at`'
                    . ' FROM `' . $table . '`'
                    . ' WHERE `request_ip` = ? AND `user_id` = ?'
                    . ' AND `last_seen_at` >= ? AND `last_seen_at` <= ?'
                    // ua_hash 做次序键：只按 hit_count 排会让并列的 UA 在两次请求之间漂移。
                    . ' ORDER BY `hit_count` DESC, `ua_hash` ASC'
                    . ' LIMIT ' . $perAccount . ')';
                $bindings[] = $ip;
                $bindings[] = $userId;
                $bindings[] = $window['start_at'];
                $bindings[] = $window['end_at'];
            }
            foreach (DB::select(implode(' UNION ALL ', $branches), $bindings) as $row) {
                $rowUserId = (int)$row->user_id;
                if (!isset($result[$rowUserId])) {
                    $result[$rowUserId] = ['rows' => [], 'truncated' => false];
                }
                $result[$rowUserId]['rows'][] = [
                    'ua_hash' => (string)$row->ua_hash,
                    'user_agent' => (string)$row->user_agent,
                    'request_count' => (int)$row->hit_count,
                    'first_seen_at' => (int)$row->first_seen_at,
                    'last_seen_at' => (int)$row->last_seen_at
                ];
            }
        }
        foreach ($result as $userId => $bucket) {
            $result[$userId]['truncated'] = count($bucket['rows']) >= $perAccount;
        }
        return $result;
    }

    /**
     * 账号维度的筛选：先把邮箱/UID 解析成该账号涉及的 IP 集合，再回到按 IP 的聚合 ——
     * 这样每行的 account_count 仍是该 IP 的完整账号数，而不是「筛选后剩下几个」。
     *
     * @return array{ips:?array,email_truncated:bool,truncated:bool}
     */
    private function scopeIps(Request $request, array $window): array
    {
        $result = ['ips' => null, 'email_truncated' => false, 'truncated' => false];
        $userIds = [];

        $userId = (int)$request->input('user_id');
        if ($userId > 0) {
            $userIds[] = $userId;
        }

        $email = trim((string)$request->input('email'));
        if ($email !== '') {
            // 前导 % 用不上 email 的唯一索引，所以封顶。口径同 RiskTraceController。
            $matched = User::where('email', 'like', '%' . $email . '%')
                ->limit(self::EMAIL_MATCH_LIMIT + 1)
                ->pluck('id')->all();
            $result['email_truncated'] = count($matched) > self::EMAIL_MATCH_LIMIT;
            $userIds = array_merge($userIds, array_slice(array_map('intval', $matched), 0, self::EMAIL_MATCH_LIMIT));
            if (!count($userIds)) {
                $result['ips'] = [];
                return $result;
            }
        }

        if (!count($userIds)) {
            return $result;
        }

        $ips = IpAccountLink::whereIn('user_id', array_values(array_unique($userIds)))
            ->where('last_seen_at', '>=', $window['start_at'])
            ->where('last_seen_at', '<=', $window['end_at'])
            ->distinct()
            ->limit(self::SCOPE_IP_LIMIT + 1)
            ->pluck('request_ip')->all();
        $result['truncated'] = count($ips) > self::SCOPE_IP_LIMIT;
        $result['ips'] = array_slice(array_map('strval', $ips), 0, self::SCOPE_IP_LIMIT);
        return $result;
    }

    /**
     * IP 筛选做前缀匹配而不是 %...%：前缀能用上索引区间，而 IP 上有意义的模糊查询本来
     * 就是按网段前缀查。只放行 IP 字面量里可能出现的字符，顺带把 LIKE 通配符挡在外面。
     */
    private function ipPrefix(Request $request): ?string
    {
        $value = trim((string)$request->input('ip'));
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^[0-9a-fA-F:.]{1,45}$/', $value)) {
            abort(500, 'IP 关键词只能包含数字、字母 a-f、点与冒号');
        }
        return $value;
    }

    private function minAccounts(Request $request): int
    {
        $value = (int)($request->input('min_accounts') ?: self::MIN_ACCOUNTS_DEFAULT);
        return max(2, min(self::MIN_ACCOUNTS_MAX, $value));
    }

    /**
     * 时间窗。不给参数就是最近 DEFAULT_WINDOW_DAYS 天；跨度超过 MAX_WINDOW_DAYS 会被
     * 收紧并在 clamped 里告知前端 —— 无界聚合一律不提供。
     *
     * 契约里必须说清的两件事：
     * ① 窗口只约束 last_seen_at。返回的 first_seen_at 与 request_count 是该三元组/该 IP 的
     *    终身值（MIN(first_seen_at) / SUM(hit_count)），可以早于 start_at、也可以包含窗口外
     *    的次数。这是累积表方案换来的「比原始日志保留期更长的记忆」的代价，面板不要拿
     *    window 去校验这两个字段，也不要用它们做以 window 为轴的图表。
     * ② 窗口窄下来能减少扫描量（seen_ip_user_hits 以 last_seen_at 为前导列），但在接近全表
     *    的宽窗口上优化器会改走整索引扫描，此时窗口只是语义边界。列表页每翻一页都要重算
     *    一次 total（带 LIMIT 的派生表 count，封顶 TOTAL_COUNT_CAP，刻意不缓存 —— 缓存会
     *    让「刚聚合完」和「刚 prune 完」的数字对不上），所以每页是两次聚合。
     *
     * @return array{start_at:int,end_at:int,days:int,clamped:bool}
     */
    private function window(Request $request): array
    {
        $now = time();
        $endAt = (int)$request->input('end_at');
        if ($endAt <= 0) {
            $endAt = $now;
        }
        $startAt = (int)$request->input('start_at');
        if ($startAt <= 0) {
            $startAt = $endAt - (self::DEFAULT_WINDOW_DAYS * 86400);
        }
        if ($startAt > $endAt) {
            $startAt = $endAt - (self::DEFAULT_WINDOW_DAYS * 86400);
        }
        $clamped = false;
        $maxSpan = self::MAX_WINDOW_DAYS * 86400;
        if ($endAt - $startAt > $maxSpan) {
            $startAt = $endAt - $maxSpan;
            $clamped = true;
        }
        return [
            'start_at' => max(0, $startAt),
            'end_at' => $endAt,
            'days' => (int)ceil(($endAt - $startAt) / 86400),
            'clamped' => $clamped
        ];
    }

    /**
     * 面板要能看出「数字为什么可能不是最新的」：累积表是离线聚合的，游标落后时列表就落后。
     */
    private function meta(IpAccountLinkService $service): array
    {
        $status = $service->status();
        return [
            'available' => [
                'ip_account_link' => (bool)$status['available'],
                'subscribe_request_log' => Schema::hasTable('v2_subscribe_request_log')
            ],
            'retention_days' => (new SubscribeAuditRetentionService())->retentionDays(),
            'aggregation' => [
                'cursor' => (int)$status['cursor'],
                'log_max_id' => (int)$status['log_max_id'],
                'aggregated_through' => $status['aggregated_through'],
                'pending_since' => $status['pending_since']
            ]
        ];
    }

    /**
     * 日志里只写掩码后的 IP。完整 IP 与完整 UA 一律不落任何日志文件（约束项）。
     */
    private function maskIp(string $ip): string
    {
        if ($ip === 'unknown') {
            return 'unknown';
        }
        if (strpos($ip, ':') !== false) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 2)) . ':x';
        }
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            return $parts[0] . '.' . $parts[1] . '.x.x';
        }
        return 'masked';
    }

    private function actor(Request $request): string
    {
        return is_array($request->user) ? (string)($request->user['email'] ?? '-') : '-';
    }

    /**
     * RequestLog 只记路径，不记是谁做的。本页把「IP → 账号」去匿名化，这条日志是唯一的
     * 补偿控制，理由同 RiskTraceController::audit()。只记掩码 IP 与命中账号数。
     */
    private function audit(Request $request, string $message): void
    {
        info('ADMIN ' . $message . ' by=' . $this->actor($request));
    }
}
