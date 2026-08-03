<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\SubscribeRequestLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscribeAuditRetentionService;
use App\Services\SubscriptionTokenHistoryService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * 订阅溯源。列出留下过订阅拉取记录的用户，并支持按 token 反查归属（含已被重置的 token）。
 */
class RiskTraceController extends Controller
{
    private const SORTABLE = ['last_requested_at', 'first_requested_at', 'user_id'];
    private const EMAIL_MATCH_LIMIT = 200;

    public function fetch(Request $request)
    {
        $historyService = new SubscriptionTokenHistoryService();
        $available = [
            'subscribe_request_log' => Schema::hasTable('v2_subscribe_request_log'),
            'token_history' => $historyService->available(),
            'subscription' => Schema::hasTable('v2_subscription')
        ];
        $meta = [
            'available' => $available,
            'retention_days' => (new SubscribeAuditRetentionService())->retentionDays(),
            'token_history_started_at' => $historyService->startedAt(),
            'reasons' => SubscriptionTokenHistoryService::REASONS,
            'subscribe_method' => (int)config('v2board.show_subscribe_method', 0)
        ];

        if (!$available['subscribe_request_log']) {
            return response(array_merge(['data' => [], 'total' => 0, 'keyword_truncated' => false], $meta));
        }

        $page = max(1, (int)($request->input('current') ?: $request->input('page') ?: 1));
        $pageSize = min(100, max(10, (int)($request->input('pageSize') ?: 20)));
        $sort = in_array($request->input('sort'), self::SORTABLE, true)
            ? $request->input('sort') : 'last_requested_at';
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC'], true)
            ? $request->input('sort_type') : 'DESC';

        $keyword = trim((string)$request->input('keyword'));
        $userIds = null;
        $keywordTruncated = false;
        if ($keyword !== '' && !ctype_digit($keyword)) {
            // 先把邮箱解析成 id 再 whereIn，是仓库既有惯例（filter() 对 invite_by_email 就
            // 这么做）。前导 % 用不上 email 的唯一索引，所以封顶。
            $matched = User::where('email', 'like', '%' . $keyword . '%')
                ->limit(self::EMAIL_MATCH_LIMIT + 1)
                ->pluck('id')->all();
            $keywordTruncated = count($matched) > self::EMAIL_MATCH_LIMIT;
            $userIds = array_slice(array_map('intval', $matched), 0, self::EMAIL_MATCH_LIMIT);
            if (!count($userIds)) {
                return response(array_merge(['data' => [], 'total' => 0, 'keyword_truncated' => false], $meta));
            }
        }

        // total 与列表各自从 traceQuery() 起一条新 builder：count() 作用在带 GROUP BY 的
        // builder 上返回的是某一组的行数，不是组数。
        $rows = $this->traceQuery($keyword, $userIds)
            ->select('user_id')
            ->selectRaw('MIN(requested_at) AS first_requested_at, MAX(requested_at) AS last_requested_at')
            ->groupBy('user_id')
            ->orderBy($sort, $sortType)
            ->forPage($page, $pageSize)
            ->get();
        $total = (int)$this->traceQuery($keyword, $userIds)->distinct()->count('user_id');

        // 一次性水化邮箱，绝不逐行 User::find。
        $users = User::whereIn('id', $rows->pluck('user_id')->all())
            ->get(['id', 'email', 'banned'])->keyBy('id');
        foreach ($rows as $index => $row) {
            $user = $users->get($row->user_id);
            // delUser/allDel 会留下孤儿日志行，邮箱取不到时 UI 必须显示「已删除用户」。
            $rows[$index]['email'] = $user ? $user->email : null;
            $rows[$index]['banned'] = $user ? (bool)$user->banned : false;
            $rows[$index]['deleted'] = !$user;
        }

        return response(array_merge([
            'data' => $rows,
            'total' => $total,
            'keyword_truncated' => $keywordTruncated
        ], $meta));
    }

    /**
     * 按 token 反查归属。
     *
     * 用 POST 而不是 GET 是故意打破 read=GET 惯例：请求助手的 GET 会把参数拼进 query
     * string，token 会落进 nginx 访问日志、浏览器历史和后续导航的 Referer；而 RequestLog
     * 只记 POST 的路径、不记 body。不要「修正」回 GET。
     */
    public function lookup(Request $request)
    {
        // 刻意只用 required|string|max，不用任何会把值回显进错误消息的规则：非 200 时
        // 请求助手会把校验消息渲染进通知，等于把 token 画到屏幕和 DOM 上。
        $request->validate(['token' => 'required|string|max:2048']);

        $parsed = $this->normalizeToken((string)$request->input('token'));
        $historyService = new SubscriptionTokenHistoryService();
        $notes = [];
        $result = $this->resolveToken($parsed['token'], $historyService);

        if (!$result) {
            $this->audit($request, 'TOKEN TRACE match=miss user_id=-');
            return response(['data' => array_merge([
                'found' => false,
                'token_masked' => $historyService->mask($parsed['token'])
            ], $parsed['meta'], ['notes' => $this->missNotes($historyService, $parsed)])]);
        }

        $userId = (int)$result['user_id'];
        $user = $userId > 0 ? User::find($userId, ['id', 'email', 'banned']) : null;
        $record = $result['record'] ?? null;
        $subscription = $result['subscription'] ?? null;
        if (!$subscription && $record && $record->subscription_id && Schema::hasTable('v2_subscription')) {
            $subscription = Subscription::find((int)$record->subscription_id);
        }

        $this->audit($request, 'TOKEN TRACE match=' . $result['match_type'] . ' user_id=' . ($userId ?: '-'));

        return response(['data' => array_merge([
            'found' => true,
            'match_type' => $result['match_type'],
            'token_masked' => $historyService->mask($record ? $record->token_prefix : $parsed['token']),
            'token_certain' => (bool)$result['token_certain'],
            'user' => [
                'id' => $userId,
                'email' => $user ? $user->email : null,
                'banned' => $user ? (bool)$user->banned : false,
                'deleted' => !$user
            ],
            'subscription' => $subscription ? [
                'id' => (int)$subscription->id,
                'plan_name' => $this->planName($subscription->plan_id),
                'status' => $subscription->status,
                'expired_at' => $subscription->expired_at ? (int)$subscription->expired_at : null
            ] : null,
            'history_id' => $record ? (int)$record->id : null,
            'token_status' => $record ? ($record->retired_at ? 'retired' : 'active') : 'unknown',
            'issued_at' => $record && $record->issued_at ? (int)$record->issued_at : null,
            'issued_at_exact' => $record ? (bool)$record->issued_at_exact : false,
            'issued_reason' => $record ? $record->issued_reason : null,
            'retired_at' => $record && $record->retired_at ? (int)$record->retired_at : null,
            'retired_at_exact' => $record && $record->retired_at_exact !== null ? (bool)$record->retired_at_exact : null,
            'retired_reason' => $record ? $record->retired_reason : null,
            'has_audit_records' => $userId > 0 && Schema::hasTable('v2_subscribe_request_log')
                && SubscribeRequestLog::where('user_id', $userId)->exists()
        ], $parsed['meta'], ['notes' => $notes])]);
    }

    public function history(Request $request)
    {
        $userId = (int)$request->input('user_id');
        if (!$userId || !User::where('id', $userId)->exists()) {
            abort(404, __('用户不存在'));
        }

        $historyService = new SubscriptionTokenHistoryService();
        $tokens = [];
        $subscriptions = collect();
        $records = $historyService->forUser($userId);
        if ($records->count() && Schema::hasTable('v2_subscription')) {
            $subscriptions = Subscription::whereIn('id', $records->pluck('subscription_id')->filter()->all())
                ->get(['id', 'plan_id', 'status', 'expired_at'])->keyBy('id');
        }
        foreach ($records as $record) {
            $subscription = $record->subscription_id ? $subscriptions->get((int)$record->subscription_id) : null;
            $tokens[] = [
                'id' => (int)$record->id,
                'token_masked' => $historyService->mask($record->token_prefix),
                'subscription_id' => $record->subscription_id ? (int)$record->subscription_id : null,
                'plan_name' => $subscription ? $this->planName($subscription->plan_id) : null,
                // 未退役 ≠ 还能用：被 revoke 的订阅和已过期订阅的 token 仍留在列里因而不
                // 退役，却已经过不了 byToken 的过滤。所以把订阅状态一并给出来。
                'subscription_status' => $subscription ? $subscription->status : null,
                'subscription_expired_at' => $subscription && $subscription->expired_at
                    ? (int)$subscription->expired_at : null,
                'issued_at' => $record->issued_at ? (int)$record->issued_at : null,
                'issued_at_exact' => (bool)$record->issued_at_exact,
                'issued_reason' => $record->issued_reason,
                'issued_actor_type' => $record->issued_actor_type,
                'retired_at' => $record->retired_at ? (int)$record->retired_at : null,
                'retired_at_exact' => $record->retired_at_exact !== null ? (bool)$record->retired_at_exact : null,
                'retired_reason' => $record->retired_reason,
                'active' => !$record->retired_at
            ];
        }

        $audit = ['request_count' => 0, 'first_requested_at' => null,
            'last_requested_at' => null, 'user_agent_count' => 0];
        if (Schema::hasTable('v2_subscribe_request_log')) {
            // user_id 等值命中 user_requested_at 的前导列，只碰这个用户的行。
            // 刻意不含 distinct_ip_count：request_ip 不在任何索引里，那要读基表。
            $row = SubscribeRequestLog::where('user_id', $userId)
                ->selectRaw('COUNT(*) AS request_count, MIN(requested_at) AS first_requested_at,
                             MAX(requested_at) AS last_requested_at, COUNT(DISTINCT ua_hash) AS user_agent_count')
                ->first();
            if ($row) {
                $audit = [
                    'request_count' => (int)$row->request_count,
                    'first_requested_at' => $row->first_requested_at ? (int)$row->first_requested_at : null,
                    'last_requested_at' => $row->last_requested_at ? (int)$row->last_requested_at : null,
                    'user_agent_count' => (int)$row->user_agent_count
                ];
            }
        }

        return response(['data' => [
            'tokens' => $tokens,
            'audit' => $audit,
            'available' => [
                'token_history' => $historyService->available(),
                'subscribe_request_log' => Schema::hasTable('v2_subscribe_request_log')
            ],
            'token_history_started_at' => $historyService->startedAt(),
            'reasons' => SubscriptionTokenHistoryService::REASONS
        ]]);
    }

    /**
     * 解密并返回单条历史记录的原值。这是「看到该用户的历史 token 是多少」的唯一出口。
     * 用 POST 的理由同 lookup()。每次调用单独记审计日志，但日志里不含 token 本身。
     */
    public function reveal(Request $request)
    {
        $id = (int)$request->input('id');
        if (!$id) {
            abort(500, __('参数有误'));
        }
        $result = (new SubscriptionTokenHistoryService())->reveal($id);
        if ($result['token'] === null) {
            abort(500, $result['error'] ?: '无法读取原值');
        }

        $record = \App\Models\SubscriptionTokenHistory::find($id, ['user_id']);
        $this->audit($request, 'TOKEN REVEAL id=' . $id . ' user_id=' . ($record ? (int)$record->user_id : '-'));

        return response(['data' => ['token' => $result['token']]]);
    }

    /**
     * 只挂 where，不挂 groupBy —— 见 fetch() 里关于 count() 的注释。
     */
    private function traceQuery(string $keyword, ?array $userIds)
    {
        $query = SubscribeRequestLog::query();
        if ($userIds !== null) {
            return $query->whereIn('user_id', $userIds);
        }
        if ($keyword !== '' && ctype_digit($keyword)) {
            return $query->where('user_id', (int)$keyword);
        }
        return $query;
    }

    /**
     * 六步解析。前三步是关键的安全网：万一种子或某个写入路径漏了，仍在使用的 token 也
     * 绝不能报「归属未知」，那是本功能最坏的失败。
     */
    private function resolveToken(string $token, SubscriptionTokenHistoryService $historyService): ?array
    {
        $candidates = [['token' => $token, 'alias' => null]];
        // 必须用 Cache::get，绝不能用 pull —— Client 中间件会 pull otpn_*，在这里消费掉
        // 会弄坏用户尚未使用的一次性链接。
        foreach (['otpn_' => 'otp_alias', 'totp_' => 'totp_alias'] as $prefix => $aliasType) {
            $real = Cache::get($prefix . $token);
            if (is_string($real) && $real !== '' && $real !== $token) {
                $candidates[] = ['token' => $real, 'alias' => $aliasType];
            }
        }

        foreach ($candidates as $candidate) {
            $record = $historyService->findByToken($candidate['token']);
            if ($record) {
                return [
                    'match_type' => $candidate['alias'] ?: 'history',
                    'user_id' => (int)$record->user_id,
                    'record' => $record,
                    'subscription' => null,
                    'token_certain' => true
                ];
            }
            $user = User::where('token', $candidate['token'])->first(['id']);
            if ($user) {
                return [
                    'match_type' => $candidate['alias'] ?: 'live_user',
                    'user_id' => (int)$user->id,
                    'record' => null,
                    'subscription' => null,
                    'token_certain' => true
                ];
            }
            if (Schema::hasTable('v2_subscription')) {
                $subscription = Subscription::where('token', $candidate['token'])->first();
                if ($subscription) {
                    return [
                        'match_type' => $candidate['alias'] ?: 'live_subscription',
                        'user_id' => (int)$subscription->user_id,
                        'record' => null,
                        'subscription' => $subscription,
                        'token_certain' => true
                    ];
                }
            }
        }

        // 动态签名（show_subscribe_method=2）的 token 是 base64url("{userId}:{hmac}")，
        // userId 是明文且与时间无关。HMAC 的计数器是 5 分钟一步，几天前的链接无法验证，
        // 所以只能确定用户、不能确定是哪一个 token；也不要拿 HMAC 去暴力匹配历史 token，
        // 失败无法区分「不是这个用户」和「窗口已过期」。
        $decoded = Helper::base64DecodeUrlSafe($token);
        if (is_string($decoded) && strpos($decoded, ':') !== false) {
            [$maybeUserId] = explode(':', $decoded, 2);
            if (ctype_digit($maybeUserId) && User::where('id', (int)$maybeUserId)->exists()) {
                return [
                    'match_type' => 'totp_user',
                    'user_id' => (int)$maybeUserId,
                    'record' => null,
                    'subscription' => null,
                    'token_certain' => false
                ];
            }
        }

        return null;
    }

    /**
     * @return array{token: string, meta: array}
     */
    private function normalizeToken(string $raw): array
    {
        $value = trim($raw);
        $value = trim($value, "\"'`<>");
        $hashPos = strpos($value, '#');
        if ($hashPos !== false) {
            $value = substr($value, 0, $hashPos);
        }

        $kind = 'token';
        if (stripos($value, 'token=') !== false) {
            $kind = 'url';
            $query = parse_url($value, PHP_URL_QUERY);
            $parsedQuery = [];
            if (is_string($query) && $query !== '') {
                parse_str($query, $parsedQuery);
            }
            if (!empty($parsedQuery['token'])) {
                $value = (string)$parsedQuery['token'];
            } elseif (preg_match('/[?&]token=([^&\s#]+)/', $value, $m)) {
                // 粘贴的文本里有空格时 parse_url 会失败，回退到正则。
                $value = urldecode($m[1]);
            }
        } elseif (strpos($value, '/') !== false) {
            // 自定义 subscribe_path 可能把 token 放在路径末段。
            $segments = array_filter(explode('/', $value));
            $value = (string)end($segments);
        }

        $value = trim($value);
        if ($value === '' || !preg_match('/^[A-Za-z0-9_\-=]{1,512}$/', $value)) {
            abort(500, __('无法从输入中解析出 token'));
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $value)) {
            $kind = 'uuid';
        }

        return ['token' => $value, 'meta' => ['input_kind' => $kind]];
    }

    private function missNotes(SubscriptionTokenHistoryService $historyService, array $parsed): array
    {
        $notes = ['未找到该 token 的归属。'];
        if ($historyService->available()) {
            $startedAt = $historyService->startedAt();
            $notes[] = 'Token 历史自 ' . ($startedAt ? date('Y-m-d', $startedAt) : '安装时')
                . ' 起记录，在此之前被重置的 token 没有留下任何记录，无法回填。';
        } else {
            $notes[] = 'Token 历史表尚未安装（数据库尚未升级），当前只能匹配仍在使用的 token。';
        }
        if ((int)config('v2board.show_subscribe_method', 0) !== 0) {
            $notes[] = '系统设置里的订阅地址是一次性/动态签名模式，链接中的 token 是临时别名，过期后无法反查。';
        }
        if (($parsed['meta']['input_kind'] ?? '') === 'uuid') {
            $notes[] = '请确认粘贴的是订阅链接或 token，而不是 UUID。';
        }
        $notes[] = '也可能是该账号已被删除，或某次写入漏记、尚未被夜间对账补上。';
        return $notes;
    }

    private function planName($planId): ?string
    {
        if (!$planId) {
            return null;
        }
        $plan = Plan::find((int)$planId, ['id', 'name']);
        return $plan ? $plan->name : null;
    }

    private function actor(Request $request): string
    {
        return is_array($request->user) ? (string)($request->user['email'] ?? '-') : '-';
    }

    /**
     * RequestLog 只记路径，不记是谁做的。本页会把用户去匿名化，这条日志是唯一的补偿控制，
     * 不是可选项。只记 match_type 与 user_id —— 不记 token，连掩码都不记。
     */
    private function audit(Request $request, string $message): void
    {
        info('ADMIN ' . $message . ' by=' . $this->actor($request));
    }
}
