<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\RiskRule;
use App\Models\Subscription;
use App\Models\User;
use App\Services\RiskRuleService;
use App\Services\SubscriptionRiskService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RiskRuleController extends Controller
{
    private const BATCH_LIMIT = 200;
    private const BATCH_SECONDS = 4.0;
    private const CURSOR_TTL = 3600;
    private const RESTART_GUARD_SECONDS = 60;
    // 手动评估结果明细的累计上限：明细跟着游标状态存缓存，无界会把缓存值撑到 MB 级；
    // 超限后只累计数不存明细，前端提示收窄窗口或调高阈值。
    private const MANUAL_RESULT_LIMIT = 200;
    private const MANUAL_WINDOW_MIN_HOURS = 1;
    // 92 天 ≈ 三个完整计费月，够覆盖任何回看诉求；再长的窗口该看的是 30 天账本。
    private const MANUAL_WINDOW_MAX_HOURS = 2208;
    // done 态留存时长：结果不落库，最终响应是整轮扫描的唯一副本，传输失败时前端
    // 在这个窗口内重试 step 还能幂等取回，不至于整轮重跑。
    private const MANUAL_DONE_TTL = 300;

    /**
     * 维度与运算符随规则一起返回，管理端不复制第二份列表 —— 唯一事实源是
     * RiskRuleService 的类常量。多键响应是本项目现成的写法。
     */
    public function fetch(Request $request)
    {
        $service = new RiskRuleService();
        return response([
            'data' => $service->available()
                ? RiskRule::orderBy('sort', 'ASC')->orderBy('id', 'ASC')->get()
                : [],
            'dimensions' => RiskRuleService::DIMENSIONS,
            'operators' => RiskRuleService::OPERATORS,
            'available' => $service->available()
        ]);
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'label' => 'required|string|max:255',
            'dimension' => ['required', Rule::in(array_keys(RiskRuleService::DIMENSIONS))],
            'operator' => ['required', Rule::in(array_keys(RiskRuleService::OPERATORS))],
            // decimal(18,8) 的整数部分只有 10 位，超过就会被 MySQL 截断或报错。
            'threshold' => 'required|numeric|min:0|max:9999999999',
            'enabled' => 'nullable|boolean',
            'sort' => 'nullable|integer|min:0'
        ], [
            'label.required' => '规则名称不能为空',
            'label.max' => '规则名称过长',
            'dimension.required' => '判定维度不能为空',
            'dimension.in' => '判定维度不在支持范围内',
            'operator.required' => '运算符不能为空',
            'operator.in' => '运算符不在支持范围内',
            'threshold.required' => '阈值不能为空',
            'threshold.numeric' => '阈值必须为数字',
            'threshold.min' => '阈值不能为负数',
            'threshold.max' => '阈值超出可存储范围'
        ]);

        $this->requireRuleTable();

        // enabled 缺席时不写：更新路径要沿用该行当前值，否则编辑一条已禁用规则的阈值会把它
        // 悄悄重新启用。创建路径下面再补默认值。
        if ($request->input('enabled') === null) {
            unset($params['enabled']);
        } else {
            $params['enabled'] = (int)(bool)$params['enabled'];
        }
        // sort 由 /risk/rule/sort 统一维护。显式传 null 会写出 NULL，而 MySQL 的
        // ORDER BY sort ASC 把 NULL 排在最前面，等于静默把这条规则提到第一位。
        if (array_key_exists('sort', $params) && $params['sort'] === null) {
            unset($params['sort']);
        }
        // threshold 列是 decimal(18,8)，超过 8 位小数 MySQL 会静默四舍五入，这里先归一化，
        // 免得存进去的值和管理员填的不一致。
        $params['threshold'] = round((float)$params['threshold'], 8);

        if ($request->input('id')) {
            $rule = RiskRule::find((int)$request->input('id'));
            if (!$rule) {
                abort(500, __('规则不存在'));
            }
            try {
                $rule->update($params);
            } catch (\Exception $e) {
                abort(500, __('保存失败'));
            }
            $this->audit($request, 'RISK RULE UPDATE id=' . $rule->id . ' ' . json_encode($params, JSON_UNESCAPED_UNICODE));
            return response(['data' => true]);
        }

        if (!array_key_exists('enabled', $params)) {
            $params['enabled'] = 1;
        }
        if (!array_key_exists('sort', $params)) {
            $params['sort'] = (int)RiskRule::max('sort') + 1;
        }
        $rule = RiskRule::create($params);
        if (!$rule) {
            abort(500, __('创建失败'));
        }
        $this->audit($request, 'RISK RULE CREATE id=' . $rule->id . ' ' . json_encode($params, JSON_UNESCAPED_UNICODE));
        return response(['data' => true]);
    }

    /**
     * 启用开关。带 show/enabled 参数时按参数设置，不带时沿用本项目 /show 端点的取反语义。
     */
    public function show(Request $request)
    {
        $rule = $this->findRule($request);
        $explicit = $request->input('show', $request->input('enabled'));
        if ($explicit === null) {
            $rule->enabled = $rule->enabled ? 0 : 1;
        } else {
            // 不能用 (bool) 强转原始输入："false"、"off"、"0.0" 都会变成 true。
            $parsed = filter_var($explicit, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed === null) {
                abort(500, __('参数有误'));
            }
            $rule->enabled = $parsed ? 1 : 0;
        }
        if (!$rule->save()) {
            abort(500, __('保存失败'));
        }
        $this->audit($request, 'RISK RULE TOGGLE id=' . $rule->id . ' enabled=' . $rule->enabled);
        return response(['data' => true]);
    }

    /**
     * sort 只影响列表展示与理由文案的顺序，不影响判定 —— 规则之间是 OR，优先级在 OR
     * 语义下没有判定含义。
     */
    public function sort(Request $request)
    {
        $ids = $request->input('ids');
        if (!is_array($ids) || !count($ids)) {
            abort(500, __('参数有误'));
        }
        $this->requireRuleTable();

        DB::beginTransaction();
        try {
            foreach (array_values($ids) as $index => $id) {
                $rule = RiskRule::find((int)$id);
                if (!$rule) {
                    continue;
                }
                $rule->timestamps = false;
                $rule->update(['sort' => $index + 1]);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, __('保存失败'));
        }
        DB::commit();
        return response(['data' => true]);
    }

    public function drop(Request $request)
    {
        $rule = $this->findRule($request);
        $snapshot = $rule->only(['id', 'label', 'dimension', 'operator', 'threshold']);
        if (!$rule->delete()) {
            abort(500, __('删除失败'));
        }
        $this->audit($request, 'RISK RULE DELETE ' . json_encode($snapshot, JSON_UNESCAPED_UNICODE));
        return response(['data' => true]);
    }

    /**
     * 重算是第二个 $force 入口（另一个是 CLI 的 subscription:risk --force），会改写被冻结的
     * 判定，所以每次都记审计日志。带 user_id 时同步跑单个用户；否则按游标分批跑全站。
     */
    public function recompute(Request $request)
    {
        $service = new SubscriptionRiskService();
        if (!$service->available()) {
            abort(500, __('订阅风险表尚未安装，请先执行数据库升级'));
        }

        $userId = (int)$request->input('user_id');
        if ($userId > 0) {
            return $this->recomputeUser($request, $service, $userId);
        }
        return $this->recomputeAll($request, $service);
    }

    private function recomputeUser(Request $request, SubscriptionRiskService $service, int $userId)
    {
        if (!User::where('id', $userId)->exists()) {
            abort(404, __('用户不存在'));
        }

        $subscriptions = 0;
        $cycles = 0;
        // 单用户爆炸半径小、一秒内完成，不需要分批。
        foreach (Subscription::where('user_id', $userId)->orderBy('id')->get() as $subscription) {
            $subscriptions++;
            $cycles += count($service->evaluateCompletedCycles($subscription, null, true));
        }

        $this->audit($request, 'RISK RECOMPUTE user_id=' . $userId
            . ' subscriptions=' . $subscriptions . ' cycles=' . $cycles);

        return response([
            'data' => [
                'done' => true,
                'subscriptions' => $subscriptions,
                'cycles' => $cycles,
                'total' => $subscriptions
            ]
        ]);
    }

    /**
     * 队列不可用（config/queue.php 默认 sync，horizon 只消费六个业务队列），而无界的
     * chunkById 会占死一个 Webman worker 且没有超时兜底，所以做成前端驱动的游标分批。
     */
    private function recomputeAll(Request $request, SubscriptionRiskService $service)
    {
        $key = CacheKey::get('RISK_RECOMPUTE_CURSOR', 'global');
        $state = Cache::get($key);

        if ($request->input('restart')) {
            // touched_at 每批都续期，所以长任务运行期间的重启会一直被拒；真的卡死超过
            // 60 秒才允许接管。用 started_at 判断会让跑了一分钟以上的任务被人为打断。
            if (is_array($state) && (time() - (int)($state['touched_at'] ?? 0)) < self::RESTART_GUARD_SECONDS) {
                abort(500, __('已有重算任务正在进行，请稍后再试'));
            }
            $state = [
                'last_id' => 0,
                'subscriptions' => 0,
                'cycles' => 0,
                'total' => (int)Subscription::count(),
                'started_at' => time(),
                'touched_at' => time(),
                'started_by' => $this->actor($request)
            ];
            $this->audit($request, 'RISK RECOMPUTE ALL start total=' . $state['total']);
        }

        if (!is_array($state)) {
            abort(500, __('重算任务不存在或已超时，请重新开始'));
        }

        // 两个界限都必要：200 个重度订阅可能跑超 4 秒，而 4 秒内也可能跑完远超 200 个
        // 轻量订阅。前者护住 worker，后者护住单次请求的往返开销。
        $deadline = microtime(true) + self::BATCH_SECONDS;
        $batch = Subscription::where('id', '>', (int)$state['last_id'])
            ->orderBy('id')
            ->limit(self::BATCH_LIMIT)
            ->get();

        $timedOut = false;
        foreach ($batch as $subscription) {
            $state['cycles'] += count($service->evaluateCompletedCycles($subscription, null, true));
            $state['subscriptions']++;
            $state['last_id'] = (int)$subscription->id;
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }
        }

        $done = !$timedOut && $batch->count() < self::BATCH_LIMIT;
        if ($done) {
            Cache::forget($key);
            $this->audit($request, 'RISK RECOMPUTE ALL done subscriptions=' . $state['subscriptions']
                . ' cycles=' . $state['cycles']);
        } else {
            $state['touched_at'] = time();
            Cache::put($key, $state, self::CURSOR_TTL);
        }

        return response([
            'data' => [
                'done' => $done,
                'subscriptions' => (int)$state['subscriptions'],
                'cycles' => (int)$state['cycles'],
                'total' => (int)$state['total']
            ]
        ]);
    }

    /**
     * 手动自定义周期评估：用当前规则对「最近 N 小时」窗口做一次全站即时体检。
     * 纯计算不落库 —— 30 天账本与「风险」列徽标完全不受影响。与 recomputeAll 同一套
     * 前端驱动的游标分批（队列不可用的原因见彼处注释），窗口取在 restart 时并冻结在
     * 游标状态里，整轮各批评估的是同一个时间窗。
     */
    public function manualEvaluate(Request $request)
    {
        $service = new SubscriptionRiskService();
        if (!$service->available()) {
            abort(500, __('订阅风险表尚未安装，请先执行数据库升级'));
        }

        $key = CacheKey::get('RISK_MANUAL_CURSOR', 'global');
        $state = Cache::get($key);

        if ($request->input('restart')) {
            // done 态是等待重放的成品而非在跑的任务，不参与并发守卫，直接接管。
            if (is_array($state) && empty($state['done'])
                && (time() - (int)($state['touched_at'] ?? 0)) < self::RESTART_GUARD_SECONDS) {
                abort(500, __('已有手动评估任务正在进行，请稍后再试'));
            }
            $hours = $request->input('hours');
            if (!is_numeric($hours)
                || (int)$hours != (float)$hours
                || (int)$hours < self::MANUAL_WINDOW_MIN_HOURS
                || (int)$hours > self::MANUAL_WINDOW_MAX_HOURS) {
                abort(500, __('评估窗口需为 1 小时到 92 天之间的整数小时'));
            }
            // 整轮冻结同一份规则快照：跨批现读规则表会让评估中途的规则改动把一轮结果
            // 切成两套判定标准。快照摘要一并写进审计日志，事后能解释这轮按什么判的。
            $ruleSnapshot = (new RiskRuleService())->enabledRules();
            $endAt = time();
            $state = [
                'start_at' => $endAt - (int)$hours * 3600,
                'end_at' => $endAt,
                'rules' => $ruleSnapshot,
                'done' => false,
                'last_id' => 0,
                'scanned' => 0,
                'with_evidence' => 0,
                'flagged' => 0,
                'overflow' => 0,
                'results' => [],
                'total' => (int)Subscription::count(),
                'started_at' => time(),
                'touched_at' => time(),
                'started_by' => $this->actor($request)
            ];
            $this->audit($request, 'RISK MANUAL EVALUATE start hours=' . (int)$hours
                . ' window=[' . $state['start_at'] . ',' . $state['end_at'] . '] total=' . $state['total']
                . ' rules=' . json_encode(array_map(function ($rule) {
                    return ($rule['id'] === null ? 'builtin' : $rule['id'])
                        . ':' . $rule['dimension'] . $rule['operator'] . $rule['threshold'];
                }, $ruleSnapshot)));
        }

        if (!is_array($state)) {
            abort(500, __('评估任务不存在或已超时，请重新开始'));
        }

        // 幂等重放：最终响应在传输中丢了，前端重试 step 仍能取回成品（见 MANUAL_DONE_TTL）。
        if (!empty($state['done'])) {
            return response(['data' => $this->manualPayload($state, true)]);
        }

        if (is_array($state['rules'] ?? null)) {
            $service->useRuleSnapshot($state['rules']);
        }

        $deadline = microtime(true) + self::BATCH_SECONDS;
        $batch = Subscription::where('id', '>', (int)$state['last_id'])
            ->orderBy('id')
            ->limit(self::BATCH_LIMIT)
            ->get();

        $flaggedRows = [];
        $timedOut = false;
        foreach ($batch as $subscription) {
            $assessment = $service->assessWindow($subscription, (int)$state['start_at'], (int)$state['end_at']);
            $state['scanned']++;
            if ($assessment['status'] !== 'no_data') {
                $state['with_evidence']++;
            }
            if ($assessment['status'] === 'suspicious') {
                $state['flagged']++;
                if (count($state['results']) + count($flaggedRows) < self::MANUAL_RESULT_LIMIT) {
                    $flaggedRows[] = [
                        'user_id' => (int)$subscription->user_id,
                        'email' => null,
                        'subscription_id' => (int)$subscription->id,
                        'reasons' => $assessment['reasons'],
                        'metrics' => $assessment['metrics']
                    ];
                } else {
                    $state['overflow']++;
                }
            }
            $state['last_id'] = (int)$subscription->id;
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }
        }

        if (count($flaggedRows)) {
            // 邮箱按批一次点查补齐，不在循环里逐个查。用户已被删除时保留 user_id、邮箱置空。
            $emails = User::whereIn('id', array_column($flaggedRows, 'user_id'))
                ->pluck('email', 'id');
            foreach ($flaggedRows as $row) {
                $row['email'] = $emails[$row['user_id']] ?? null;
                $state['results'][] = $row;
            }
        }

        $done = !$timedOut && $batch->count() < self::BATCH_LIMIT;
        $state['touched_at'] = time();
        if ($done) {
            // 不立即清缓存：结果不落库，这个响应体是整轮扫描的唯一副本。标 done 短存
            // 一段允许幂等重放，下一次 restart 或 TTL 到期自然清掉。
            $state['done'] = true;
            Cache::put($key, $state, self::MANUAL_DONE_TTL);
            $this->audit($request, 'RISK MANUAL EVALUATE done scanned=' . $state['scanned']
                . ' flagged=' . $state['flagged']
                . ' window=[' . $state['start_at'] . ',' . $state['end_at'] . ']');
        } else {
            Cache::put($key, $state, self::CURSOR_TTL);
        }

        return response(['data' => $this->manualPayload($state, $done)]);
    }

    /**
     * done 态的响应体既在完成批返回、也可被幂等重放，收口成一个构造点。
     */
    private function manualPayload(array $state, bool $done): array
    {
        return [
            'done' => $done,
            'scanned' => (int)$state['scanned'],
            'with_evidence' => (int)$state['with_evidence'],
            'flagged' => (int)$state['flagged'],
            'overflow' => (int)$state['overflow'],
            'total' => (int)$state['total'],
            'start_at' => (int)$state['start_at'],
            'end_at' => (int)$state['end_at'],
            // 明细只在完成时返回：中途每批都回传整个列表纯属带宽浪费，进度阶段
            // 前端只需要计数。
            'results' => $done ? array_values((array)($state['results'] ?? [])) : []
        ];
    }

    /**
     * 没有这张表时给出和 save 一致的中文 500，而不是让 Eloquent 抛 QueryException。
     */
    private function requireRuleTable(): void
    {
        if (!(new RiskRuleService())->available()) {
            abort(500, __('风控规则表尚未安装，请先执行数据库升级'));
        }
    }

    private function findRule(Request $request): RiskRule
    {
        if (empty($request->input('id'))) {
            abort(500, __('参数有误'));
        }
        $this->requireRuleTable();
        $rule = RiskRule::find((int)$request->input('id'));
        if (!$rule) {
            abort(500, __('规则不存在'));
        }
        return $rule;
    }

    private function actor(Request $request): string
    {
        return is_array($request->user) ? (string)($request->user['email'] ?? '-') : '-';
    }

    /**
     * RequestLog 中间件只记路径，不记是谁做的、改了什么。规则改动与重算都会影响判定，
     * 照 UserController@clearSubscribeAudit 的先例单独补一条。
     */
    private function audit(Request $request, string $message): void
    {
        info('ADMIN ' . $message . ' by=' . $this->actor($request));
    }
}
