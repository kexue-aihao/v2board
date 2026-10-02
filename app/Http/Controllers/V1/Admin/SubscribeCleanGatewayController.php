<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\SubscribeAccessSummary;
use App\Models\SubscribeBlockRule;
use App\Models\SubscribeBlockRuleEvent;
use App\Models\User;
use App\Services\SubscribeAccountRiskService;
use App\Services\SubscribeAuditRetentionService;
use App\Services\SubscribeCleanGatewayService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 管理端「订阅清洗网关」。
 *
 * 一个页面同时承担两件事：
 *   1. 看：谁、用哪个订阅、从哪个 IP（含运营商与 ASN）、用什么 User-Agent、拉了多少次、
 *      最后一次是什么时候（一律 UTC+8）；
 *   2. 处置：把某个 IP / User-Agent / 账号 / 订阅加入阻断名单，命中即整站拒绝订阅。
 *
 * 阻断目标只能来自已经落库的拉取记录 —— 这个接口不是任意封禁入口。
 */
class SubscribeCleanGatewayController extends Controller
{
    private const EVENT_TABLE = 'v2_subscribe_block_rule_event';
    private const PAGE_SIZE_DEFAULT = 20;
    private const PAGE_SIZE_MAX = 100;
    // 留存统计里行数的上界，见 cappedCount()。两万这个数是这么定的：一般站点
    // 的规模远在它之下（拿到的是精确值），而真到了几百万行时，精确值对运维也
    // 没有额外的信息量 —— 「20000+」已经说明该收窄保留期了。
    private const COUNT_CAP = 20000;

    public function fetch(Request $request)
    {
        $service = new SubscribeCleanGatewayService();
        if (!$service->available()) {
            return response([
                'data' => [],
                'total' => 0,
                'available' => false,
                'blocks_available' => false
            ]);
        }

        try {
            $result = $service->list($request);

            return response($result + [
                'available' => true,
                'blocks_available' => $service->blocksAvailable()
            ]);
        } catch (\Throwable $e) {
            report($e);
            abort(500, __('读取订阅拉取记录失败'));
        }
    }

    /**
     * 筛选器要用的下拉项与边界值。与列表分开取，翻页时不必重复拉。
     */
    public function options()
    {
        $retention = new SubscribeAuditRetentionService();

        return response([
            'data' => [
                'plans' => Plan::orderBy('id')->get(['id', 'name']),
                'scopes' => [
                    ['key' => 'ip', 'label' => __('IP 地址')],
                    ['key' => 'user_agent', 'label' => __('User-Agent')],
                    ['key' => 'user', 'label' => __('账号')],
                    ['key' => 'subscription', 'label' => __('订阅')]
                ],
                'retention_days' => $retention->retentionDays(),
                'retention_default' => SubscribeAuditRetentionService::DEFAULT_RETENTION_DAYS,
                'retention_min' => SubscribeAuditRetentionService::MIN_RETENTION_DAYS
            ]
        ]);
    }

    /**
     * 按当前筛选条件导出 CSV。
     *
     * 与用户列表的「导出 CSV」同一条链路：直接 echo 出 CSV 文本，响应不是
     * application/json，管理端请求助手据此把 body 读成 ArrayBuffer，前端再生成
     * Blob 触发下载（文件名也在前端定）。刻意不调 header()/exit —— 那是这套代码里
     * 唯一被验证过的下载写法，换写法要考虑 webman 下的响应生命周期。
     */
    public function export(Request $request)
    {
        $service = new SubscribeCleanGatewayService();
        if (!$service->available()) {
            abort(500, __('订阅拉取记录表尚未安装，请先执行 php artisan v2board:update'));
        }

        $columns = [
            __('账号'), __('账号ID'), __('风险程度（%）'), __('订阅ID'), __('订阅'), __('IP 地址'), __('运营商'),
            __('ASN'), __('归属机构'), __('国家/地区'), __('User-Agent'), __('拉取次数'), __('阻断次数'),
            __('首次拉取（UTC+8）'), __('最近拉取（UTC+8）'), __('阻断状态'), __('阻断原因')
        ];

        // BOM：Excel 只有见到它才认 UTF-8，否则中文列名全变乱码。
        echo "\xEF\xBB\xBF";
        echo implode(',', array_map([$service, 'csvField'], $columns)) . "\r\n";

        $result = $service->eachExportRow($request, function (array $rows) use ($service) {
            $lines = '';
            foreach ($rows as $row) {
                $block = $row['block'];
                $fields = [
                    $row['user_email'],
                    $row['user_id'],
                    $row['risk_percent'] === null ? '' : (string)$row['risk_percent'],
                    $row['subscription_id'],
                    $service->subscriptionsText($row['subscriptions']),
                    $row['request_ip'],
                    $row['isp'],
                    $row['asn'] === null ? '' : (string)$row['asn'],
                    $row['organization'],
                    implode(' · ', array_values(array_unique(array_filter([
                        $row['country_name'], $row['region'], $row['city']
                    ], function ($part) {
                        return trim((string)$part) !== '';
                    })))),
                    $row['user_agent'],
                    $row['hit_count'],
                    $row['risk_blocked_count'],
                    $row['first_seen_text'],
                    $row['last_seen_text'],
                    $block ? __('已阻断') : __('未阻断'),
                    $block ? $block['reason'] : ''
                ];
                $lines .= implode(',', array_map([$service, 'csvField'], $fields)) . "\r\n";
            }
            echo $lines;
        });

        if ($result['truncated']) {
            echo $service->csvField(sprintf(
                '# 已达导出上限 %d 行，导出被截断，请收窄筛选条件后重试',
                SubscribeCleanGatewayService::MAX_EXPORT_ROWS
            )) . "\r\n";
        }
    }

    // ------------------------------------------------------------ 阻断名单

    public function block(Request $request)
    {
        $request->validate([
            'summary_id' => 'required|integer|min:1',
            'scope' => 'required|in:user,subscription,ip,user_agent',
            'reason' => 'required|string|max:500',
            'expires_at' => 'nullable'
        ]);

        $service = new SubscribeCleanGatewayService();
        if (!$service->available() || !$service->blocksAvailable()) {
            abort(500, __('阻断名单表尚未安装，请先执行 php artisan v2board:update'));
        }

        $expiresAt = (new SubscribeCleanGatewayService())->parseBeijingTime($request->input('expires_at'));
        if ($expiresAt !== null && $expiresAt <= time()) {
            abort(500, __('过期时间必须晚于当前时间'));
        }

        try {
            return DB::transaction(function () use ($request, $expiresAt) {
                $summary = SubscribeAccessSummary::find((int)$request->input('summary_id'));
                if (!$summary) {
                    abort(404, __('该拉取记录已不在保留期内，请刷新后重试'));
                }

                $target = $this->targetFromSummary($summary, (string)$request->input('scope'));
                $existing = SubscribeBlockRule::where('scope', $target['scope'])
                    ->where($target['column'], $target['value'])
                    ->lockForUpdate()
                    ->get();
                foreach ($existing as $rule) {
                    if ($this->effectiveStatus($rule) === 'active') {
                        abort(500, __('该目标已在阻断名单中'));
                    }
                }

                $rule = new SubscribeBlockRule();
                $rule->setAttribute('scope', $target['scope']);
                $rule->setAttribute($target['column'], $target['value']);
                $rule->setAttribute('user_id', $target['user_id']);
                $rule->setAttribute('subscription_id', $target['subscription_id']);
                $rule->setAttribute('ip', $target['ip']);
                $rule->setAttribute('user_agent', $target['user_agent']);
                $rule->setAttribute('user_agent_hash', $target['user_agent_hash']);
                $rule->setAttribute('reason', trim((string)$request->input('reason')));
                $rule->setAttribute('status', 'active');
                $rule->setAttribute('expires_at', $expiresAt);
                $rule->setAttribute('blocked_by', $this->actorId($request));
                $rule->setAttribute('blocked_at', time());
                $rule->save();

                $this->writeEvent($rule, 'block', $request, (string)$request->input('reason'), [
                    'scope' => $target['scope'],
                    'summary_id' => (int)$summary->id,
                    'target' => $target['summary']
                ]);
                $this->audit($request, 'CLEAN GATEWAY BLOCK rule_id=' . $rule->id
                    . ' summary_id=' . $summary->id . ' scope=' . $target['scope']);

                return response([
                    'data' => (new SubscribeCleanGatewayService())->ruleSummary($rule),
                    'available' => true
                ]);
            });
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            abort(500, __('阻断失败，请稍后重试'));
        }
    }

    public function release(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|min:1',
            'reason' => 'nullable|string|max:500'
        ]);

        $service = new SubscribeCleanGatewayService();
        if (!$service->blocksAvailable()) {
            abort(500, __('阻断名单表尚未安装，请先执行 php artisan v2board:update'));
        }

        try {
            return DB::transaction(function () use ($request) {
                $rule = SubscribeBlockRule::where('id', (int)$request->input('id'))->lockForUpdate()->first();
                if (!$rule) {
                    abort(404, __('阻断规则不存在'));
                }
                if (!in_array($this->effectiveStatus($rule), ['active', 'expired'], true)) {
                    abort(500, __('该规则已被解除'));
                }

                $reason = trim((string)$request->input('reason'));
                $rule->setAttribute('status', 'disabled');
                $rule->setAttribute('released_by', $this->actorId($request));
                $rule->setAttribute('released_at', time());
                $rule->setAttribute('release_reason', $reason === '' ? null : $reason);
                $rule->save();

                $this->writeEvent($rule, 'release', $request, $reason, ['scope' => (string)$rule->scope]);
                $this->audit($request, 'CLEAN GATEWAY RELEASE rule_id=' . $rule->id . ' scope=' . $rule->scope);

                return response([
                    'data' => (new SubscribeCleanGatewayService())->ruleSummary($rule),
                    'available' => true
                ]);
            });
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            abort(500, __('解除失败，请稍后重试'));
        }
    }

    /**
     * 生效中的阻断名单。
     */
    public function rules(Request $request)
    {
        [$page, $pageSize] = $this->pagination($request);
        if (!(new SubscribeCleanGatewayService())->blocksAvailable()) {
            return response(['data' => [], 'total' => 0, 'available' => false]);
        }

        $now = time();
        $query = SubscribeBlockRule::query();
        $status = trim((string)$request->input('status', ''));
        if ($status === 'active') {
            $query->where('status', 'active')
                ->where(function ($expiry) use ($now) {
                    $expiry->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                });
        } elseif ($status === 'released') {
            $query->where('status', 'disabled');
        }

        $keyword = trim((string)$request->input('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($inner) use ($keyword) {
                $inner->where('ip', 'like', '%' . $keyword . '%')
                    ->orWhere('user_agent', 'like', '%' . $keyword . '%')
                    ->orWhere('reason', 'like', '%' . $keyword . '%');
            });
        }

        $total = (int)(clone $query)->count();
        $rules = $query->orderByDesc('id')->forPage($page, $pageSize)->get();

        $service = new SubscribeCleanGatewayService();
        $emails = User::whereIn('id', $rules->pluck('user_id')->filter()->unique()->all())
            ->pluck('email', 'id');
        $subscriptionIds = $rules->pluck('subscription_id')->filter()->unique()->all();
        $subscriptionLabels = empty($subscriptionIds)
            ? collect()
            : DB::table('v2_subscription')
                ->leftJoin('v2_plan', 'v2_plan.id', '=', 'v2_subscription.plan_id')
                ->whereIn('v2_subscription.id', $subscriptionIds)
                ->pluck('v2_plan.name', 'v2_subscription.id');

        $data = [];
        foreach ($rules as $rule) {
            $row = $service->ruleSummary($rule);
            $row['status'] = $this->effectiveStatus($rule);
            $row['user_email'] = $rule->user_id === null ? '' : (string)($emails[(int)$rule->user_id] ?? '');
            $row['subscription_label'] = $rule->subscription_id === null
                ? '' : (string)($subscriptionLabels[(int)$rule->subscription_id] ?? '');
            $row['released_at_text'] = $service->beijingText($rule->released_at);
            $row['release_reason'] = (string)($rule->release_reason ?? '');
            $data[] = $row;
        }

        return response(['data' => $data, 'total' => $total, 'available' => true]);
    }

    /**
     * 阻断/解除的操作留痕。
     */
    public function history(Request $request)
    {
        if (!$this->hasTable(self::EVENT_TABLE)) {
            return response(['data' => [], 'total' => 0, 'available' => false]);
        }

        [$page, $pageSize] = $this->pagination($request);
        $query = SubscribeBlockRuleEvent::query();
        $action = trim((string)$request->input('action', ''));
        if (in_array($action, ['block', 'release'], true)) {
            $query->where('action', $action);
        }

        $total = (int)(clone $query)->count();
        $events = $query->orderByDesc('id')->forPage($page, $pageSize)->get();

        $service = new SubscribeCleanGatewayService();
        $rules = SubscribeBlockRule::whereIn('id', $events->pluck('rule_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');
        $actorIds = $events->pluck('actor_id')->filter()->unique()->all();
        $actors = empty($actorIds) ? collect() : User::whereIn('id', $actorIds)->pluck('email', 'id');

        $data = [];
        foreach ($events as $event) {
            $rule = $rules->get((int)$event->rule_id);
            $data[] = [
                'id' => (int)$event->id,
                'rule_id' => (int)$event->rule_id,
                'action' => (string)$event->action,
                'actor_id' => $event->actor_id === null ? null : (int)$event->actor_id,
                'actor_email' => $event->actor_id === null ? '' : (string)($actors[(int)$event->actor_id] ?? ''),
                'reason' => (string)($event->reason ?? ''),
                'created_at' => $service->stamp($event->created_at),
                'created_at_text' => $service->beijingText($event->created_at),
                'scope' => $rule ? (string)$rule->scope : '',
                'target' => $rule ? $this->targetLabel($rule, $service) : ''
            ];
        }

        return response(['data' => $data, 'total' => $total, 'available' => true]);
    }

    // ------------------------------------------------------------ 风险程度

    /**
     * 未处理且风险达到阈值的账号，按风险程度倒序。
     *
     * 与列表页那列同源（v2_subscribe_account_risk），但这里是「待办」视角：只要没被
     * 标记已处理就一直列在这里，也正是每 15 分钟那次提醒发送的对象。
     */
    public function riskPending(Request $request)
    {
        $risk = new SubscribeAccountRiskService();
        if (!$risk->available()) {
            return response(['data' => [], 'total' => 0, 'available' => false]);
        }

        $format = new SubscribeCleanGatewayService();
        [$page, $pageSize] = $this->pagination($request);
        $threshold = $risk->threshold();

        try {
            $query = DB::table(SubscribeAccountRiskService::TABLE)
                ->whereNull('handled_at')
                ->where('risk_percent', '>=', $threshold);
            $total = (int)(clone $query)->count();
            $rows = $query->orderByDesc('risk_percent')
                ->orderBy('user_id')
                ->forPage($page, $pageSize)
                ->get();
        } catch (\Throwable $e) {
            report($e);
            return response(['data' => [], 'total' => 0, 'available' => false]);
        }

        $emails = User::whereIn('id', $rows->pluck('user_id')->all())->pluck('email', 'id');

        $data = [];
        foreach ($rows as $row) {
            $userId = (int)$row->user_id;
            $data[] = [
                'user_id' => $userId,
                'user_email' => (string)($emails[$userId] ?? ''),
                'risk_percent' => (float)$row->risk_percent,
                'blocked_count' => (int)$row->blocked_count,
                'total_count' => (int)$row->total_count,
                'last_seen_text' => $format->beijingText($row->last_seen_at),
                'notify_count' => (int)$row->notify_count,
                'notified_at_text' => $format->beijingText($row->notified_at)
            ];
        }

        return response([
            'data' => $data,
            'total' => $total,
            'available' => true,
            'threshold' => $threshold
        ]);
    }

    /**
     * 标记已处理。这是「未处理就一直提醒」唯一的终止动作，所以它必须是个显式按钮，
     * 而不是靠风险自己降下去。
     */
    public function handleRisk(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|min:1',
            'note' => 'nullable|string|max:255'
        ]);

        $risk = new SubscribeAccountRiskService();
        if (!$risk->available()) {
            abort(500, __('风险台账尚未安装，请先执行 php artisan v2board:update'));
        }

        $userId = (int)$request->input('user_id');
        if (!$risk->handle($userId, $this->actorId($request), (string)$request->input('note'))) {
            abort(404, __('该账号没有风险记录'));
        }

        $this->audit($request, 'CLEAN GATEWAY RISK HANDLED user_id=' . $userId);

        return response(['data' => true, 'available' => true]);
    }

    // ------------------------------------------------------------ 留存设置

    public function config()
    {
        $retention = new SubscribeAuditRetentionService();
        $service = new SubscribeCleanGatewayService();
        $lastCleanedAt = (int)Cache::get(CacheKey::get('SUBSCRIBE_AUDIT_LAST_CLEANED_AT', null), 0);

        $records = $this->cappedCount(SubscribeCleanGatewayService::TABLE);
        $rawLogs = $this->cappedCount('v2_subscribe_request_log');

        return response([
            'data' => [
                'retention_days' => $retention->retentionDays(),
                'retention_default' => SubscribeAuditRetentionService::DEFAULT_RETENTION_DAYS,
                'retention_min' => SubscribeAuditRetentionService::MIN_RETENTION_DAYS,
                // 风险程度阈值：达到它就会每 15 分钟提醒一次管理员，直到被标记已处理。
                // 页面用它给超过阈值的百分比上红色。
                'risk_threshold' => (new SubscribeAccountRiskService())->threshold(),
                'records' => $records,
                'raw_logs' => $rawLogs,
                // 触顶时前端在数字后面补一个「+」。触顶说明表已经很大，这时候
                // 「20000+」比一个精确但要跑十几秒的数字更有用。
                'counts_capped' => $records >= self::COUNT_CAP || $rawLogs >= self::COUNT_CAP,
                'earliest_text' => $this->earliestText($service),
                'last_cleaned_at' => $lastCleanedAt,
                'last_cleaned_text' => $service->beijingText($lastCleanedAt)
            ]
        ]);
    }

    /**
     * 有上界的精确行数。
     *
     * `SELECT COUNT(*) FROM (SELECT 1 FROM t LIMIT n) x` —— 内层的 LIMIT 让 MySQL
     * 最多只读 n 行，小表拿到的是**精确值**（页面上「列表行数」和列表的「共 N 条」
     * 必须对得上），大表则在常数时间内返回 n，由前端显示成「n+」。
     *
     * 为什么不用 information_schema.TABLE_ROWS：那是采样值，实测能差 3%（3570 vs
     * 3696），同一个屏幕上两个数字对不上，比慢一点更难解释。为什么不直接
     * COUNT(*)：它的开销随表线性增长，而这是每次打开页面都要走的路径。
     */
    private function cappedCount(string $table): int
    {
        try {
            if (!$this->hasTable($table)) {
                return 0;
            }

            return (int)DB::query()
                ->fromSub(DB::table($table)->selectRaw('1')->limit(self::COUNT_CAP), 'capped')
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 保存留存天数。
     *
     * 写 config/v2board.php 的手法与 ConfigController::save 完全一致（临时文件 +
     * 原子 rename + opcache/config 缓存刷新），区别只在只改这一个键、不动其它配置。
     */
    public function saveConfig(Request $request)
    {
        $value = $request->input('retention_days');
        if (!is_numeric($value)) {
            abort(500, __('留存天数必须是数字，0 表示永久保留'));
        }
        $days = (int)$value;
        $min = SubscribeAuditRetentionService::MIN_RETENTION_DAYS;
        if ($days !== 0 && ($days < $min || $days > 3650)) {
            abort(422, __('留存天数必须为 0（永久保留）或 :min 至 3650 天', ['min' => $min]));
        }

        $config = config('v2board');
        $config['subscribe_audit_retention_days'] = $days;
        $path = base_path() . '/config/v2board.php';
        $tempPath = $path . '.tmp.' . bin2hex(random_bytes(8));
        if (!File::put($tempPath, "<?php\n return " . var_export($config, 1) . " ;", LOCK_EX)) {
            abort(500, __('修改失败'));
        }
        @chmod($tempPath, 0644);
        if (!@rename($tempPath, $path)) {
            @unlink($tempPath);
            abort(500, __('修改失败'));
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        Artisan::call('config:cache');

        $this->audit($request, 'CLEAN GATEWAY RETENTION days=' . $days);

        return response(['data' => ['retention_days' => $days]]);
    }

    // ------------------------------------------------------------ 内部

    private function earliestText(SubscribeCleanGatewayService $service): string
    {
        if (!$service->available()) {
            return '';
        }
        try {
            // 按主键取第一行再读它的 first_seen_at。first_seen_at 上没有索引（这张表
            // 是全站最高频的写路径，无用的二级索引一律不加），MIN() 会退化成全表扫；
            // 主键有序，ORDER BY id LIMIT 1 是一次索引定位。
            $earliest = DB::table(SubscribeCleanGatewayService::TABLE)
                ->orderBy('id')
                ->limit(1)
                ->value('first_seen_at');
        } catch (\Throwable $e) {
            return '';
        }

        return $service->beijingText($earliest);
    }

    /**
     * 把一条拉取记录翻译成阻断规则的目标。
     *
     * 与执行侧 SubscribeGatewayService::inspect() 的四个 scope 一一对应：规则写进去
     * 什么，命中判定就查什么。
     */
    private function targetFromSummary(SubscribeAccessSummary $summary, string $scope): array
    {
        $base = [
            'scope' => $scope, 'user_id' => null, 'subscription_id' => null, 'ip' => null,
            'user_agent' => null, 'user_agent_hash' => null, 'summary' => ''
        ];

        if ($scope === 'user') {
            $userId = (int)$summary->user_id;
            if ($userId <= 0) {
                abort(500, __('该记录没有账号，不能按账号阻断'));
            }
            $email = (string)(User::where('id', $userId)->value('email') ?? '');
            return array_merge($base, [
                'column' => 'user_id', 'value' => (string)$userId, 'user_id' => $userId,
                'summary' => $email !== '' ? $email : ('#' . $userId)
            ]);
        }

        if ($scope === 'subscription') {
            $subscriptionId = (int)$summary->subscription_id;
            if ($subscriptionId <= 0) {
                abort(500, __('该记录没有关联订阅，不能按订阅阻断'));
            }
            return array_merge($base, [
                'column' => 'subscription_id', 'value' => (string)$subscriptionId,
                'subscription_id' => $subscriptionId, 'summary' => '#' . $subscriptionId
            ]);
        }

        if ($scope === 'ip') {
            $ip = trim((string)$summary->request_ip);
            if ($ip === '' || strtolower($ip) === 'unknown' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                abort(500, __('该记录的 IP 无效，不能阻断'));
            }
            return array_merge($base, [
                'column' => 'ip', 'value' => $ip, 'ip' => $ip, 'summary' => $ip
            ]);
        }

        $userAgent = trim((string)$summary->user_agent);
        if ($userAgent === '' || $userAgent === '(empty)') {
            abort(500, __('该记录没有 User-Agent，不能阻断'));
        }
        $userAgentHash = hash('sha256', strtolower($userAgent));
        return array_merge($base, [
            'column' => 'user_agent_hash', 'value' => $userAgentHash,
            'user_agent' => $userAgent, 'user_agent_hash' => $userAgentHash,
            'summary' => 'ua:sha256:' . $userAgentHash
        ]);
    }

    private function targetLabel(SubscribeBlockRule $rule, SubscribeCleanGatewayService $service): string
    {
        switch ((string)$rule->scope) {
            case 'user':
                $email = (string)(User::where('id', (int)$rule->user_id)->value('email') ?? '');
                return $email !== '' ? $email : ('#' . (int)$rule->user_id);
            case 'subscription':
                $name = (string)(DB::table('v2_subscription')
                    ->leftJoin('v2_plan', 'v2_plan.id', '=', 'v2_subscription.plan_id')
                    ->where('v2_subscription.id', (int)$rule->subscription_id)
                    ->value('v2_plan.name') ?? '');
                return '#' . (int)$rule->subscription_id . ($name !== '' ? ' ' . $name : '');
            case 'ip':
                return (string)$rule->ip;
            default:
                return (string)$rule->user_agent;
        }
    }

    private function effectiveStatus(SubscribeBlockRule $rule): string
    {
        if ((string)$rule->status !== 'active') {
            return 'released';
        }
        if ($rule->expires_at !== null && (new SubscribeCleanGatewayService())->stamp($rule->expires_at) <= time()) {
            return 'expired';
        }

        return 'active';
    }

    private function writeEvent(SubscribeBlockRule $rule, string $type, Request $request, string $reason, array $metadata): void
    {
        if (!$this->hasTable(self::EVENT_TABLE)) {
            return;
        }
        try {
            $event = new SubscribeBlockRuleEvent();
            $event->setAttribute('rule_id', (int)$rule->id);
            $event->setAttribute('action', $type);
            $event->setAttribute('actor_id', $this->actorId($request));
            $event->setAttribute('reason', trim($reason) === '' ? null : $reason);
            $event->setAttribute('metadata', $metadata);
            $event->setAttribute('created_at', time());
            $event->save();
        } catch (\Throwable $e) {
            // 留痕失败不回滚已经生效的阻断：阻断本身比操作日志重要。
            report($e);
        }
    }

    private function actorId(Request $request): int
    {
        return is_array($request->user ?? null) ? (int)($request->user['id'] ?? 0) : 0;
    }

    private function audit(Request $request, string $message): void
    {
        try {
            info($message . ' by=' . (is_array($request->user ?? null)
                ? ($request->user['email'] ?? '-') : '-'));
        } catch (\Throwable $e) {
            // 记日志失败不阻断管理动作。
        }
    }

    private function pagination(Request $request): array
    {
        $page = max(1, (int)($request->input('current') ?: $request->input('page') ?: 1));
        $pageSize = min(self::PAGE_SIZE_MAX, max(1, (int)($request->input('pageSize') ?: self::PAGE_SIZE_DEFAULT)));

        return [$page, $pageSize];
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
