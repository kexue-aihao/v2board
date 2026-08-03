<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserFetch;
use App\Http\Requests\Admin\UserGenerate;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\Ticket;
use App\Models\Order;
use App\Models\Plan;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\Subscription;
use App\Models\SubscribeRequestLog;
use App\Models\NodeConnectionLog;
use App\Services\AuthService;
use App\Services\PasswordPolicyService;
use App\Services\ServerService;
use App\Services\SubscribeAuditRetentionService;
use App\Services\SubscriptionService;
use App\Services\SubscriptionRiskService;
use App\Services\IpLocationService;
use App\Services\OnlineDeviceService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\SubscriptionTokenHistoryService;
use App\Utils\TokenRotationContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class UserController extends Controller
{
    public function resetSecret(Request $request)
    {
        $user = User::find($request->input('id'));
        if (!$user) abort(500, __('用户不存在'));
        // 包 using() 只为给 token 历史标注原因与操作者；捕获本身由 Eloquent 观察者完成，
        // 漏包只会让 issued_reason 退化成 unknown，不会丢记录。
        return TokenRotationContext::using('admin_reset', function () use ($user) {
            $user->token = Helper::guid();
            $user->uuid = Helper::guid(true);
            $primary = (new SubscriptionService())->primary($user);
            if ($primary) {
                $primary->token = $user->token;
                $primary->uuid = $user->uuid;
                $primary->save();
            }
            return response([
                'data' => $user->save()
            ]);
        });
    }

    /**
     * 由系统生成一个新密码并返回明文，供管理员转交本人。
     *
     * 刻意不与 resetSecret 合并：换订阅地址和把用户锁在门外是两件事，一次点击同时做完
     * 意味着任何一次「重置UUID及订阅URL」都会让用户无法登录，直到管理员想起来通知他。
     * 明文不进日志、不发邮件，只在这一个响应里出现一次。
     */
    public function resetPassword(Request $request)
    {
        $user = User::find($request->input('id'));
        if (!$user) abort(500, __('用户不存在'));

        $password = PasswordPolicyService::generate();
        PasswordPolicyService::apply($user, $password);
        if (!$user->save()) abort(500, __('重置失败'));
        // 排在 save() 之后：save 失败时旗标不能先翻。
        PasswordPolicyService::markSatisfied($user);
        // 密码变了，旧会话必须死。
        (new AuthService($user))->removeAllSession();

        $actor = is_array($request->user ?? null) ? ($request->user['id'] ?? null) : null;
        Log::info('ADMIN PASSWORD RESET user_id=' . $user->id . ' by=' . ($actor ?? 'unknown'));

        return response([
            'data' => [
                'password' => $password,
                'email' => $user->email
            ]
        ]);
    }

    private function filter(Request $request, $builder, ?SubscriptionRiskService $riskService = null)
    {
        $filters = $request->input('filter');
        if ($filters) {
            foreach ($filters as $k => $filter) {
                // 风险分支必须先于「模糊」改写：validator 放行什么，这里就原样收什么，
                // 拒绝时报的才是客户端真正发的 condition，而不是被改写成 like 的产物。
                if ($filter['key'] === 'risk') {
                    $this->applyRiskFilter($builder, (string)$filter['condition'], (string)$filter['value'], $riskService);
                    continue;
                }
                if ($filter['condition'] === '模糊') {
                    $filter['condition'] = 'like';
                    $filter['value'] = "%{$filter['value']}%";
                }
                if ($filter['key'] === 'd' || $filter['key'] === 'transfer_enable') {
                    $filter['value'] = $filter['value'] * 1073741824;
                }
                if ($filter['key'] === 'invite_by_email') {
                    $user = User::where('email', $filter['condition'], $filter['value'])->first();
                    $inviteUserId = isset($user->id) ? $user->id : 0;
                    $builder->where('invite_user_id', $inviteUserId);
                    unset($filters[$k]);
                    continue;
                }
                if ($filter['key'] === 'plan_id' && $filter['value'] == 'null') {
                    $builder->whereNull('plan_id');
                    continue;
                }
                $builder->where($filter['key'], $filter['condition'], $filter['value']);
            }
        }
    }

    /**
     * 「风险」列过滤。徽标语义（SubscriptionRiskService::summaryForUser）逐条对译成 SQL：
     *   可疑   = 任一订阅的最新周期判定为 suspicious
     *   待观察 = 非可疑，且（无任何已评估周期 或 有订阅首个 30 天周期未走完 或 最新周期为 pending）
     *   正常   = 非可疑且以上三个待观察来源全部不成立
     * 徽标是逐行现算的，这里必须用同一套判据，否则筛出来的行和列上显示的牌子对不上。
     */
    private function applyRiskFilter($builder, string $condition, string $value, ?SubscriptionRiskService $riskService = null): void
    {
        // 风险是派生徽标，只有相等语义；其余 condition 属 API 面误用，拒绝。
        if ($condition !== '=') {
            abort(500, __('参数有误'));
        }
        // 词汇表外的值筛空集而不是 500：与兄弟 select 字段一致（banned/is_admin 的
        // 陈旧值经 SQL 强转后同样得空结果）。过滤抽屉切换字段名会保留上一字段已填的
        // 旧值，这是管理端可达的正常路径，不能拿 500 打断列表。
        if (!in_array($value, ['suspicious', 'pending', 'normal'], true)) {
            $builder->whereRaw('1 = 0');
            return;
        }

        // fetch() 会把自己那份服务实例传进来：available() 的 9 次 schema 探测是实例级
        // memo，共享实例让过滤与徽标循环只探一次。其余共用 filter() 的端点各建各的。
        $riskService = $riskService ?: new SubscriptionRiskService();
        // 风险表未安装时徽标对全员显示「待观察」，过滤语义保持一致：pending 全命中，其余空集。
        if (!$riskService->available()) {
            if ($value !== 'pending') {
                $builder->whereRaw('1 = 0');
            }
            return;
        }

        // 「某订阅的最新一个已评估周期状态为 X」：同订阅不存在 cycle_end 更大的行。
        $latestCycleWithStatus = function (string $status) {
            return function ($query) use ($status) {
                $query->select(DB::raw(1))
                    ->from('v2_subscription_risk_cycle as risk_latest')
                    ->whereColumn('risk_latest.user_id', 'v2_user.id')
                    ->where('risk_latest.status', $status)
                    ->whereNotExists(function ($newer) {
                        $newer->select(DB::raw(1))
                            ->from('v2_subscription_risk_cycle as risk_newer')
                            ->whereColumn('risk_newer.subscription_id', 'risk_latest.subscription_id')
                            ->whereColumn('risk_newer.cycle_end', '>', 'risk_latest.cycle_end');
                    });
            };
        };
        $anyCycle = function ($query) {
            $query->select(DB::raw(1))
                ->from('v2_subscription_risk_cycle as risk_any')
                ->whereColumn('risk_any.user_id', 'v2_user.id');
        };
        $firstCycleIncomplete = function ($query) {
            $query->select(DB::raw(1))
                ->from('v2_subscription as risk_sub')
                ->whereColumn('risk_sub.user_id', 'v2_user.id')
                ->where('risk_sub.started_at', '>', 0)
                ->where('risk_sub.started_at', '>', time() - SubscriptionRiskService::CYCLE_SECONDS);
        };

        if ($value === 'suspicious') {
            $builder->whereExists($latestCycleWithStatus('suspicious'));
            return;
        }
        if ($value === 'pending') {
            $builder->whereNotExists($latestCycleWithStatus('suspicious'))
                ->where(function ($query) use ($anyCycle, $firstCycleIncomplete, $latestCycleWithStatus) {
                    $query->whereNotExists($anyCycle)
                        ->orWhereExists($firstCycleIncomplete)
                        ->orWhereExists($latestCycleWithStatus('pending'));
                });
            return;
        }
        $builder->whereNotExists($latestCycleWithStatus('suspicious'))
            ->whereExists($anyCycle)
            ->whereNotExists($firstCycleIncomplete)
            ->whereNotExists($latestCycleWithStatus('pending'));
    }

    public function fetch(UserFetch $request)
    {
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $userModel = User::select(
            DB::raw('*'),
            DB::raw('(u+d) as total_used')
        )
            ->orderBy($sort, $sortType);
        // 提前建好传给 filter()：风险过滤与下方徽标循环共享实例，schema 探测只跑一次。
        $riskService = new SubscriptionRiskService();
        $this->filter($request, $userModel, $riskService);
        $total = $userModel->count();
        $res = $userModel->forPage($current, $pageSize)
            ->get();
        $plan = Plan::get();
        $onlineDeviceSummaries = (new OnlineDeviceService())->summariesForUsers($res);
        for ($i = 0; $i < count($res); $i++) {
            for ($k = 0; $k < count($plan); $k++) {
                if ($plan[$k]['id'] == $res[$i]['plan_id']) {
                    $res[$i]['plan_name'] = $plan[$k]['name'];
                }
            }
            //统计在线设备
            $onlineDevices = $onlineDeviceSummaries[(int)$res[$i]['id']];
            $res[$i]['alive_ip'] = $onlineDevices['alive_ip'];
            $res[$i]['ips'] = $onlineDevices['ips'];
            $res[$i]['device_limit'] = $onlineDevices['device_limit'];
            $res[$i]['subscribe_url'] = Helper::getSubscribeUrl($res[$i]['token']);
            $res[$i]['risk'] = $riskService->summaryForUser((int)$res[$i]['id']);
        }
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

    public function getUserInfoById(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, __('参数错误'));
        }
        $user = User::find($request->input('id'));
        if ($user->invite_user_id) {
            $user['invite_user'] = User::find($user->invite_user_id);
        }
        $service = new SubscriptionService();
        $user['subscriptions'] = $service->forUser($user)->map(function ($subscription) {
            $subscription['plan_name'] = optional($subscription->plan)->name;
            $subscription['subscribe_url'] = Helper::getSubscribeUrl($subscription->token, $subscription);
            return $subscription->makeHidden(['token', 'uuid']);
        })->values();
        $user['risk'] = (new SubscriptionRiskService())->summaryForUser((int)$user->id);
        return response([
            'data' => $user
        ]);
    }

    public function update(UserUpdate $request)
    {
        $params = $request->validated();
        $user = User::find($request->input('id'));
        if (!$user) {
            abort(500, __('用户不存在'));
        }
        if (User::where('email', $params['email'])->first() && $user->email !== $params['email']) {
            abort(500, __('邮箱已被使用'));
        }
        if (isset($params['password'])) {
            $params['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
            $params['password_algo'] = NULL;
            $params['password_salt'] = NULL;
            // 管理员手打的密码同样是人选的，按策略不合规。列不存在时不能塞进 update()。
            if (PasswordPolicyService::available()) {
                $params['password_reset_required'] = 1;
            }
        } else {
            unset($params['password']);
        }
        if (isset($params['plan_id'])) {
            $plan = Plan::find($params['plan_id']);
            if (!$plan) {
                abort(500, __('订阅计划不存在'));
            }
            $params['group_id'] = $plan->group_id;
        } else {
            $params['group_id'] = null;
        }
        if ($request->input('invite_user_email')) {
            $inviteUser = User::where('email', $request->input('invite_user_email'))->first();
            if ($inviteUser) {
                $params['invite_user_id'] = $inviteUser->id;
            }
        } else {
            $params['invite_user_id'] = null;
        }

        if (isset($params['banned']) && (int)$params['banned'] === 1) {
            $authService = new AuthService($user);
            $authService->removeAllSession();
        }

        try {
            $user->update($params);
            $primary = (new SubscriptionService())->primary($user);
            if ($primary && isset($params['plan_id'])) {
                $primary->plan_id = $user->plan_id;
                $primary->group_id = $user->group_id;
                $primary->transfer_enable = $user->transfer_enable;
                $primary->device_limit = $user->device_limit;
                $primary->speed_limit = $user->speed_limit;
                $primary->expired_at = $user->expired_at;
                $primary->u = $user->u;
                $primary->d = $user->d;
                $primary->save();
            }
        } catch (\Exception $e) {
            abort(500, __('保存失败'));
        }
        return response([
            'data' => true
        ]);
    }

    public function setPrimarySubscription(Request $request)
    {
        $user = User::findOrFail($request->input('user_id'));
        $subscription = Subscription::where('id', $request->input('subscription_id'))
            ->where('user_id', $user->id)->first();
        if (!$subscription) abort(404, __('订阅不存在'));
        (new SubscriptionService())->setPrimary($user, $subscription);
        return response(['data' => true]);
    }

    public function revokeSubscription(Request $request)
    {
        $user = User::findOrFail($request->input('user_id'));
        $subscription = Subscription::where('id', $request->input('subscription_id'))
            ->where('user_id', $user->id)->first();
        if (!$subscription) abort(404, __('订阅不存在'));
        (new SubscriptionService())->revoke($user, $subscription);
        return response(['data' => true]);
    }

    public function subscribeRequests(Request $request)
    {
        $userId = (int)$request->input('user_id');
        if (!$userId || !User::where('id', $userId)->exists()) {
            abort(404, __('用户不存在'));
        }
        $subscriptionId = $request->input('subscription_id') ? (int)$request->input('subscription_id') : null;
        if ($subscriptionId && !Schema::hasTable('v2_subscription')) {
            abort(404, __('订阅不存在'));
        }
        if ($subscriptionId && !Subscription::where('id', $subscriptionId)->where('user_id', $userId)->exists()) {
            abort(404, __('订阅不存在'));
        }
        if (!Schema::hasTable('v2_subscribe_request_log')) {
            return response(['data' => [], 'total' => 0]);
        }

        $query = SubscribeRequestLog::where('user_id', $userId)->orderByDesc('requested_at');
        if ($subscriptionId) $query->where('subscription_id', $subscriptionId);
        if ($request->filled('user_agent')) $query->where('user_agent', 'like', '%' . $request->input('user_agent') . '%');
        if ($request->filled('request_ip')) $query->where('request_ip', 'like', '%' . $request->input('request_ip') . '%');
        if ($request->filled('cycle_start')) $query->where('requested_at', '>=', (int)$request->input('cycle_start'));
        if ($request->filled('cycle_end')) $query->where('requested_at', '<', (int)$request->input('cycle_end'));

        $page = max(1, (int)($request->input('page') ?: $request->input('current') ?: 1));
        $pageSize = min(100, max(10, (int)($request->input('pageSize') ?: 20)));
        $total = $query->count();
        $uaCount = (int)(clone $query)->reorder()->selectRaw('COUNT(DISTINCT ua_hash) AS count')->value('count');
        $uaSummary = (clone $query)->reorder()
            ->select('ua_hash', 'user_agent')
            ->selectRaw('COUNT(*) AS request_count, MIN(requested_at) AS first_requested_at, MAX(requested_at) AS last_requested_at')
            ->groupBy('ua_hash', 'user_agent')
            ->orderByDesc('request_count')
            ->limit(100)
            ->get();
        $records = $query->forPage($page, $pageSize)->get();
        $ipCountsQuery = SubscribeRequestLog::where('user_id', $userId)
            ->select('subscription_id', 'request_ip')
            ->selectRaw('COUNT(*) AS request_count')
            ->groupBy('subscription_id', 'request_ip');
        if ($subscriptionId) $ipCountsQuery->where('subscription_id', $subscriptionId);
        $ipCounts = [];
        foreach ($ipCountsQuery->get() as $ipCount) {
            $ipCounts[(string)$ipCount->subscription_id . '|' . $ipCount->request_ip] = (int)$ipCount->request_count;
        }
        $subscriptionIds = $records->pluck('subscription_id')->filter()->unique()->values();
        $subscriptions = Schema::hasTable('v2_subscription') && $subscriptionIds->count()
            ? Subscription::with('plan')->whereIn('id', $subscriptionIds)->get()->keyBy('id')
            : collect();
        $locationService = new IpLocationService();
        $records->each(function ($record) use ($subscriptions, $ipCounts, $locationService, $userId) {
            $record['subscription_name'] = optional(optional($subscriptions->get($record->subscription_id))->plan)->name;
            $record['ip_count'] = $ipCounts[(string)$record->subscription_id . '|' . $record->request_ip]
                ?? (int)($ipCounts['|' . $record->request_ip] ?? 0);
            $record['ip_location'] = $locationService->lookup($record->request_ip);
        });
        $connections = $this->nodeConnections($userId, $subscriptionId, $locationService);
        return response([
            'data' => $records,
            'total' => $total,
            'connections' => $connections,
            'summary' => [
                'request_count' => $total,
                'user_agent_count' => $uaCount,
                'distinct_ip_count' => (int)(clone $query)->reorder()->select('request_ip')->distinct()->count('request_ip'),
                'connection_ip_count' => $connections->pluck('ip')->unique()->count(),
                'user_agents' => $uaSummary
            ],
            'risk' => (new SubscriptionRiskService())->summaryForUser($userId)
        ]);
    }

    /**
     * 节点上报的实际连接 IP。与订阅拉取 IP 是两条完全不同的来源：拉取 IP 是客户端
     * 下载配置时访问 Web 服务留下的，连接 IP 是节点看到的真实使用者。
     */
    private function nodeConnections(int $userId, ?int $subscriptionId, IpLocationService $locationService)
    {
        if (!Schema::hasTable('v2_node_connection_log')) {
            return collect();
        }
        $query = NodeConnectionLog::where('user_id', $userId)->orderByDesc('last_seen_at');
        if ($subscriptionId) $query->where('subscription_id', $subscriptionId);
        $records = $query->limit(200)->get();
        if ($records->isEmpty()) {
            return $records;
        }

        $subscriptions = Schema::hasTable('v2_subscription')
            ? Subscription::with('plan')->whereIn('id', $records->pluck('subscription_id')->filter()->unique())->get()->keyBy('id')
            : collect();
        $servers = (new ServerService())->getAllServers();
        $serverNames = [];
        foreach ($servers as $server) {
            $serverNames[$server['type'] . '-' . $server['id']] = $server['name'];
        }

        $records->each(function ($record) use ($subscriptions, $serverNames, $locationService) {
            $record['subscription_name'] = optional(optional($subscriptions->get($record->subscription_id))->plan)->name;
            $record['node_name'] = $serverNames[$record->node_type . '-' . $record->node_id] ?? null;
            $record['ip_location'] = $locationService->lookup($record->ip);
        });
        return $records;
    }

    public function subscriptionRisk(Request $request)
    {
        $userId = (int)$request->input('user_id');
        $user = User::find($userId);
        if (!$user) abort(404, __('用户不存在'));
        $subscriptionId = $request->input('subscription_id') ? (int)$request->input('subscription_id') : null;
        if ($subscriptionId) {
            if (!Schema::hasTable('v2_subscription')) abort(404, __('订阅不存在'));
            $subscription = Subscription::where('id', $subscriptionId)->where('user_id', $userId)->first();
            if (!$subscription) abort(404, __('订阅不存在'));
            (new SubscriptionRiskService())->evaluateCompletedCycles($subscription);
        } else {
            $service = new SubscriptionService();
            if ($service->available()) {
                $riskService = new SubscriptionRiskService();
                foreach ($service->forUser($user) as $subscription) {
                    $riskService->evaluateCompletedCycles($subscription);
                }
            }
        }
        $riskService = new SubscriptionRiskService();
        return response([
            'data' => [
                'summary' => $riskService->summaryForUser($userId),
                'cycles' => $riskService->cyclesForUser($userId, $subscriptionId, $request->input('cycle_start') ? (int)$request->input('cycle_start') : null)
            ]
        ]);
    }

    public function clearSubscribeAudit(Request $request)
    {
        $userId = (int)$request->input('user_id');
        if (!$userId || !User::where('id', $userId)->exists()) {
            abort(404, __('用户不存在'));
        }

        try {
            $counts = (new SubscribeAuditRetentionService())->purgeUser($userId);
        } catch (\Throwable $e) {
            abort(500, __('清空审计记录失败'));
        }

        // 这是面板里唯一一个删除滥用证据的端点，而 RequestLog 中间件只记路径，
        // 不记是谁删的、删了什么，所以这里单独补一条。
        info('ADMIN AUDIT CLEAR user_id=' . $userId
            . ' by=' . (is_array($request->user) ? ($request->user['email'] ?? '-') : '-')
            . ' ' . json_encode($counts));

        return response([
            'data' => $counts
        ]);
    }

    public function dumpCSV(Request $request)
    {
        $userModel = User::orderBy('id', 'asc');
        $this->filter($request, $userModel);
        $res = $userModel->get();
        $plan = Plan::get();
        for ($i = 0; $i < count($res); $i++) {
            for ($k = 0; $k < count($plan); $k++) {
                if ($plan[$k]['id'] == $res[$i]['plan_id']) {
                    $res[$i]['plan_name'] = $plan[$k]['name'];
                }
            }
        }

        $data = "邮箱,余额,推广佣金,总流量,设备数限制,剩余流量,套餐到期时间,订阅计划,订阅地址\r\n";
        foreach($res as $user) {
            $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
            $balance = $user['balance'] / 100;
            $commissionBalance = $user['commission_balance'] / 100;
            $transferEnable = $user['transfer_enable'] ? $user['transfer_enable'] / 1073741824 : 0;
            $deviceLimit = $user['devce_limit'] ? $user['devce_limit'] : NULL;
            $notUseFlow = (($user['transfer_enable'] - ($user['u'] + $user['d'])) / 1073741824) ?? 0;
            $planName = $user['plan_name'] ?? '无订阅';
            $subscribeUrl =  Helper::getSubscribeUrl($user['token']);
            $data .= "{$user['email']},{$balance},{$commissionBalance},{$transferEnable}, {$deviceLimit}, {$notUseFlow},{$expireDate},{$planName},{$subscribeUrl}\r\n";

        }
        echo "\xEF\xBB\xBF" . $data;
    }

    public function generate(UserGenerate $request)
    {
        if ($request->input('email_prefix')) {
            if ($request->input('plan_id')) {
                $plan = Plan::find($request->input('plan_id'));
                if (!$plan) {
                    abort(500, __('订阅计划不存在'));
                }
            }
            $user = [
                'email' => $request->input('email_prefix') . '@' . $request->input('email_suffix'),
                'plan_id' => isset($plan->id) ? $plan->id : NULL,
                'group_id' => isset($plan->group_id) ? $plan->group_id : NULL,
                'transfer_enable' => isset($plan->transfer_enable) ? $plan->transfer_enable * 1073741824 : 0,
                'device_limit' => isset($plan->device_limit) ? $plan->device_limit : NULL,
                'expired_at' => $request->input('expired_at') ?? NULL,
                'uuid' => Helper::guid(true),
                'token' => Helper::guid()
            ];
            if (User::where('email', $user['email'])->first()) {
                abort(500, __('邮箱已存在于系统中'));
            }
            $user['password'] = password_hash($request->input('password') ?? $user['email'], PASSWORD_DEFAULT);
            // 默认密码就是邮箱地址，是全站最弱的口令；管理员指定的也是人选的。都要提醒。
            if (PasswordPolicyService::available()) {
                $user['password_reset_required'] = 1;
            }
            $created = TokenRotationContext::using('admin_generate', function () use ($user) {
                return User::create($user);
            });
            if (!$created) {
                abort(500, __('生成失败'));
            }
            return response([
                'data' => true
            ]);
        }
        if ($request->input('generate_count')) {
            $this->multiGenerate($request);
        }
    }

    private function multiGenerate(Request $request)
    {
        if ($request->input('plan_id')) {
            $plan = Plan::find($request->input('plan_id'));
            if (!$plan) {
                abort(500, __('订阅计划不存在'));
            }
        }
        $users = [];
        for ($i = 0;$i < $request->input('generate_count');$i++) {
            $user = [
                'email' => Helper::randomChar(6) . '@' . $request->input('email_suffix'),
                'plan_id' => isset($plan->id) ? $plan->id : NULL,
                'group_id' => isset($plan->group_id) ? $plan->group_id : NULL,
                'transfer_enable' => isset($plan->transfer_enable) ? $plan->transfer_enable * 1073741824 : 0,
                'device_limit' => isset($plan->device_limit) ? $plan->device_limit : NULL,
                'expired_at' => $request->input('expired_at') ?? NULL,
                'uuid' => Helper::guid(true),
                'token' => Helper::guid(),
                'created_at' => time(),
                'updated_at' => time()
            ];
            $user['password'] = password_hash($request->input('password') ?? $user['email'], PASSWORD_DEFAULT);
            // 同 generate()：批量账号的密码默认就是邮箱地址，必须提醒重置。
            if (PasswordPolicyService::available()) {
                $user['password_reset_required'] = 1;
            }
            array_push($users, $user);
        }
        DB::beginTransaction();
        if (!User::insert($users)) {
            DB::rollBack();
            abort(500, __('生成失败'));
        }
        // User::insert() 绕过全部 Eloquent 事件，是整个项目里唯一需要显式记录 token 历史的
        // 写入点。insert 不回填 id，按 token 反查（该列有唯一索引）拿 id。放在事务内：
        // 批量生成回滚时历史也要跟着回滚。
        $tokens = array_values(array_filter(array_column($users, 'token')));
        if (count($tokens)) {
            $historyRows = [];
            foreach (User::whereIn('token', $tokens)->get(['id', 'token']) as $created) {
                $historyRows[] = ['user_id' => (int)$created->id, 'token' => (string)$created->token];
            }
            (new SubscriptionTokenHistoryService())->recordBulk($historyRows, 'admin_generate_bulk');
        }
        DB::commit();
        $data = "账号,密码,过期时间,UUID,创建时间,订阅地址\r\n";
        foreach($users as $user) {
            $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
            $createDate = date('Y-m-d H:i:s', $user['created_at']);
            $password = $request->input('password') ?? $user['email'];
            $subscribeUrl = Helper::getSubscribeUrl($user['token']);
            $data .= "{$user['email']},{$password},{$expireDate},{$user['uuid']},{$createDate},{$subscribeUrl}\r\n";
        }
        echo $data;
    }

    public function sendMail(UserSendMail $request)
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);
        foreach ($builder->cursor() as $user) {
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => $request->input('subject'),
                'template_name' => 'notify',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => config('v2board.app_url'),
                    'content' => $request->input('content')
                ]
            ], 'send_email_mass');
        }

        return response([
            'data' => true
        ]);
    }

    public function ban(Request $request)
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);
        try {
            $builder->each(function ($user){
                $authService = new AuthService($user);
                $authService->removeAllSession();
            });
            $builder->update([
                'banned' => 1
            ]);
        } catch (\Exception $e) {
            abort(500, __('处理失败'));
        }

        return response([
            'data' => true
        ]);
    }

    public function allDel(Request $request)
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);

        DB::beginTransaction();
        try {
            $builder->each(function ($user){
                $authService = new AuthService($user);
                $authService->removeAllSession();
                Order::where('user_id', $user->id)->delete();
                InviteCode::where('user_id', $user->id)->delete();
                $tickets = Ticket::where('user_id', $user->id)->get();
                foreach($tickets as $ticket) {
                    TicketMessage::where('ticket_id', $ticket->id)->delete();
                }
                Ticket::where('user_id', $user->id)->delete();
                // 走同一个服务，「该用户的审计数据」只有一处定义，与清空按钮不会漂移。
                // 原来这里漏了 v2_node_connection_log，已注销账号的真实 IP 会残留。
                (new SubscribeAuditRetentionService())->purgeUser((int)$user->id);
                // token 历史单独清：它不该被「清空该用户审计记录」那个按钮带走（那个按钮
                // 的用途是重置误判的风险判定），但账号注销后 user_id 已无法解析，必须清。
                (new SubscriptionTokenHistoryService())->purgeUser((int)$user->id);
                User::where('invite_user_id', $user->id)->update(['invite_user_id' => null]);
            });
            $builder->delete();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, __('批量删除用户信息失败'));
        }  

        return response([
            'data' => true
        ]);
    }

    public function delUser(Request $request)
    {
        $user = User::find($request->input('id'));
        if (!$user) {
            abort(500, __('用户不存在'));
        }
        DB::beginTransaction();
        try {
            $authService = new AuthService($user);
            $authService->removeAllSession();
            Order::where('user_id', $request->input('id'))->delete();
            User::where('invite_user_id', $request->input('id'))->update(['invite_user_id' => null]);
            InviteCode::where('user_id', $request->input('id'))->delete();
            
            $tickets = Ticket::where('user_id', $request->input('id'))->get();
            foreach($tickets as $ticket) {
                TicketMessage::where('ticket_id', $ticket->id)->delete();
            }
            Ticket::where('user_id', $request->input('id'))->delete();
            (new SubscribeAuditRetentionService())->purgeUser((int)$user->id);
            // 同 allDel：token 历史不跟随「清空审计记录」按钮，但账号注销时必须清。
            (new SubscriptionTokenHistoryService())->purgeUser((int)$user->id);

            $user->delete();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, __('删除用户失败'));
        }

        return response([
            'data' => true
        ]);
    }
}
