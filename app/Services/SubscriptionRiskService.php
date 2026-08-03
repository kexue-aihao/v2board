<?php

namespace App\Services;

use App\Models\NodeConnectionLog;
use App\Models\StatUser;
use App\Models\SubscribeRequestLog;
use App\Models\Subscription;
use App\Models\SubscriptionRiskCycle;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SubscriptionRiskService
{
    public const CYCLE_SECONDS = 30 * 86400;
    private $availability;
    private $ruleService;
    private $metricsColumn;
    private $nodeLogAvailability;
    private $nodeMetricKeys;
    private $locationService;
    private $locationMemo = [];

    public function available(): bool
    {
        if ($this->availability !== null) {
            return $this->availability;
        }
        return $this->availability = Schema::hasTable('v2_subscription')
            && Schema::hasTable('v2_subscription_risk_cycle')
            && Schema::hasTable('v2_subscribe_request_log')
            && Schema::hasTable('v2_stat_user')
            && Schema::hasColumn('v2_stat_user', 'subscription_id')
            && Schema::hasColumn('v2_subscription_risk_cycle', 'distinct_ip_count')
            && Schema::hasColumn('v2_subscription_risk_cycle', 'city_count')
            && Schema::hasColumn('v2_subscription_risk_cycle', 'region_count')
            && Schema::hasColumn('v2_subscription_risk_cycle', 'country_count');
    }

    public function evaluateCompletedCycles(Subscription $subscription, ?int $now = null, bool $force = false): array
    {
        if (!$this->available() || !$subscription->started_at) {
            return [];
        }

        $now = $now ?: time();
        $startedAt = (int)$subscription->started_at;
        if ($startedAt <= 0 || $startedAt >= $now) {
            return [];
        }

        // 已完成周期的输入是封闭的：窗口关闭后不会再有新行落进该区间，只会因保留期被删。
        // 所以重算等于用「证据已被清理」的空结果覆盖当初的判定，把 suspicious 改写成
        // normal。已评估过的周期一律跳过，只有 CLI 的 --force 能穿透。
        $evaluated = [];
        if (!$force) {
            foreach (SubscriptionRiskCycle::where('subscription_id', (int)$subscription->id)
                ->whereNotNull('evaluated_at')
                ->pluck('cycle_start') as $cycleStart) {
                $evaluated[(int)$cycleStart] = true;
            }
        }

        // IP 定位 memo 按订阅重置。节点连接行按设计会重叠该订阅的每一个周期，memo 若留在
        // 单个周期内，同一批 IP 会被每个周期各查一遍（12 个周期 × 200 个 IP = 2400 次点查），
        // 单个订阅就能冲破重算的 4 秒界限。MMDB reader 留在服务实例上，整轮只打开一次。
        $this->locationMemo = [];

        $completedCycles = intdiv($now - $startedAt, self::CYCLE_SECONDS);
        $results = [];
        for ($cycle = 0; $cycle < $completedCycles; $cycle++) {
            $cycleStart = $startedAt + ($cycle * self::CYCLE_SECONDS);
            if (isset($evaluated[$cycleStart])) {
                continue;
            }
            $cycleEnd = $cycleStart + self::CYCLE_SECONDS;
            $results[] = $this->evaluateCycle($subscription, $cycleStart, $cycleEnd);
        }
        return $results;
    }

    /**
     * 采集任意时间窗内的原始指标。30 天周期评估与后台手动自定义周期评估共用这一段，
     * 「怎么判、判完写不写库」的语义差异留在各自的调用方里。
     *
     * $dayOverlapTraffic：v2_stat_user 是按天分桶的 UPSERT 表（record_at 固定为当天 0 点，
     * 见 StatUserJob），点判定 record_at ∈ [start, end) 只取「0 点时间戳落在窗口内」的整桶——
     * 不足 24 小时且不跨午夜的窗口一个桶都取不到，跨午夜的窗口丢起点侧整天。手动评估的
     * 短窗口传 true 改按天重叠取桶（桶区间 [record_at, record_at+86400) 与窗口相交即计入，
     * 粒度为整天）。30 天周期路径保持原点判定不动：边缘桶误差约 1/30，且是已冻结判定的
     * 既有口径，改了等于让历史与新周期不可比。
     */
    public function collectWindow(Subscription $subscription, int $windowStart, int $windowEnd, bool $dayOverlapTraffic = false): array
    {
        $trafficQuery = StatUser::where('subscription_id', $subscription->id);
        if ($dayOverlapTraffic) {
            $trafficQuery->where('record_at', '>', $windowStart - 86400)
                ->where('record_at', '<', $windowEnd);
        } else {
            $trafficQuery->where('record_at', '>=', $windowStart)
                ->where('record_at', '<', $windowEnd);
        }
        $traffic = $trafficQuery
            ->selectRaw('COALESCE(SUM(u + d), 0) AS used_traffic, COUNT(*) AS sample_count')
            ->first();

        $usedTraffic = (int)($traffic->used_traffic ?? 0);
        $sampleCount = (int)($traffic->sample_count ?? 0);
        $transferEnable = max(0, (int)$subscription->transfer_enable);
        $userAgentCount = (int)SubscribeRequestLog::where('subscription_id', $subscription->id)
            ->where('requested_at', '>=', $windowStart)
            ->where('requested_at', '<', $windowEnd)
            ->selectRaw('COUNT(DISTINCT ua_hash) AS count')
            ->value('count');

        $ipRows = SubscribeRequestLog::where('subscription_id', $subscription->id)
            ->where('requested_at', '>=', $windowStart)
            ->where('requested_at', '<', $windowEnd)
            ->where('request_ip', '<>', '')
            ->select('request_ip')
            ->selectRaw('COUNT(*) AS request_count')
            ->groupBy('request_ip')
            ->orderByDesc('request_count')
            ->get();

        // IpLocationService::lookup() 每次都重新查一遍 v2_ip_location_cache，没有请求内缓存。
        // 拉取 IP 与连接 IP 两组里的重复项会让查询数翻倍，所以两次归约共享 $this->locationMemo。
        $pullGeo = $this->reduceLocations($ipRows->pluck('request_ip')->all());
        $nodeMetrics = $this->nodeMetrics($subscription, $windowStart, $windowEnd);
        $hasTrafficBasis = $transferEnable > 0 && $sampleCount > 0;

        return [
            'used_traffic' => $usedTraffic,
            'sample_count' => $sampleCount,
            'transfer_enable' => $transferEnable,
            'used_ratio' => $hasTrafficBasis ? round($usedTraffic / $transferEnable, 8) : null,
            'user_agent_count' => $userAgentCount,
            'distinct_ip_count' => $ipRows->count(),
            'city_count' => $pullGeo['city_count'],
            'region_count' => $pullGeo['region_count'],
            'country_count' => $pullGeo['country_count'],
            'ip_rows' => $ipRows,
            'node_metrics' => $nodeMetrics,
            'has_traffic_basis' => $hasTrafficBasis,
            'has_log_basis' => ($userAgentCount + $ipRows->count()) > 0,
            'has_node_basis' => $nodeMetrics !== null
        ];
    }

    public function evaluateCycle(Subscription $subscription, int $cycleStart, int $cycleEnd): SubscriptionRiskCycle
    {
        $window = $this->collectWindow($subscription, $cycleStart, $cycleEnd);
        $usedTraffic = $window['used_traffic'];
        $transferEnable = $window['transfer_enable'];
        $userAgentCount = $window['user_agent_count'];
        $ipRows = $window['ip_rows'];
        $distinctIpCount = $window['distinct_ip_count'];
        $cityCount = $window['city_count'];
        $regionCount = $window['region_count'];
        $countryCount = $window['country_count'];
        $nodeMetrics = $window['node_metrics'];

        $hasTrafficBasis = $window['has_traffic_basis'];
        $hasLogBasis = $window['has_log_basis'];
        $hasNodeBasis = $window['has_node_basis'];
        // 有节点证据但没有拉取证据的周期现在也可以判定：节点上报的是实际使用者，
        // 「拉一次订阅分发给多地使用」这种场景只在这一侧留下痕迹。
        $hasAnyEvidence = $hasLogBasis || $hasNodeBasis;
        $ratio = $window['used_ratio'];

        $record = SubscriptionRiskCycle::firstOrNew([
            'subscription_id' => (int)$subscription->id,
            'cycle_start' => $cycleStart
        ]);
        $record->user_id = (int)$subscription->user_id;
        $record->cycle_end = $cycleEnd;

        // 只在本轮确实拿到依据时才覆盖对应字段组，否则沿用已存值。源数据被保留期清掉
        // 之后再跑（尤其 --force）不会把历史判定抹成零。首次创建时无旧值可留，全部写入。
        if ($hasTrafficBasis || !$record->exists) {
            $record->transfer_enable = $transferEnable;
            $record->used_traffic = $usedTraffic;
            $record->used_ratio = $ratio;
        }
        if ($hasLogBasis || !$record->exists) {
            $record->user_agent_count = $userAgentCount;
            $record->distinct_ip_count = $distinctIpCount;
            $record->city_count = $cityCount;
            $record->region_count = $regionCount;
            $record->country_count = $countryCount;
        }

        // 规则引擎的输入用合并后的值构建，而不是本轮的局部变量。这是部分清理场景下不破坏
        // 历史判定的关键：拉取派生的键取（可能被保留下来的）记录列值，节点派生的键只在本轮
        // 确实有依据时才取新值，否则沿用已存 metrics —— 绝不用清理后算出的 0 覆盖历史。
        $storedMetrics = $this->storedMetrics($record);
        $metrics = [
            'user_agent_count' => (int)$record->user_agent_count,
            'distinct_ip_count' => (int)$record->distinct_ip_count,
            'city_count' => (int)$record->city_count,
            'region_count' => (int)$record->region_count,
            'country_count' => (int)$record->country_count,
            'used_ratio' => $record->used_ratio === null ? null : (float)$record->used_ratio
        ];
        foreach ($this->nodeMetricKeys() as $key) {
            if ($hasNodeBasis) {
                $metrics[$key] = $nodeMetrics[$key];
            } elseif (array_key_exists($key, $storedMetrics)) {
                $metrics[$key] = $storedMetrics[$key];
            }
            // 两处都没有 ⇒ 该键在 $metrics 里缺失。缺失不等于 0，规则不会命中。
        }

        // status 与 risk_reasons 只在本轮有证据时重算。没有证据时原样保留，避免出现一条
        // 既没有理由、又把 suspicious 降级掉的记录。
        if ($hasAnyEvidence || !$record->exists) {
            $reasons = [];
            $mergedRatio = $metrics['used_ratio'];
            if ($mergedRatio !== null) {
                if ($mergedRatio < 0.4) {
                    $reasons[] = '低流量使用率：' . round($mergedRatio * 100, 2) . '%';
                }
            } elseif ((int)$record->transfer_enable <= 0) {
                $reasons[] = '套餐总流量无效';
            } else {
                $reasons[] = '历史流量统计数据不足';
            }

            // 判定完全由规则引擎决定。原先写死的 UA 与跨地区理由行由引擎产出等价文案，
            // 且会指名是哪一条规则命中，管理员能直接对上要改的那一行。
            $ruleResult = $this->ruleService()->evaluate($metrics);
            $reasons = array_merge($reasons, $ruleResult['reasons']);

            // 重复 IP 属于证据标注而非判定输入，只能从本轮的 $ipRows 复原。本轮没有拉取依据
            // 时（拉取日志已被保留期清掉，但节点行仍与该周期重叠）把已存的这几行原样搬过来：
            // 门控放宽到 $hasAnyEvidence 之后，这条路径会重写 risk_reasons，不搬就等于静默
            // 删掉一段证据 —— 判定不受影响，但管理员会看到一条缺了证据的判定。
            if ($hasLogBasis) {
                foreach ($ipRows->filter(function ($row) {
                    return (int)$row->request_count > 1;
                })->take(10) as $ipRow) {
                    $reasons[] = '重复 IP：' . $ipRow->request_ip . ' 出现 ' . $ipRow->request_count . ' 次';
                }
            } else {
                foreach ($this->storedReasons($record) as $stored) {
                    if (strpos((string)$stored, '重复 IP：') === 0) {
                        $reasons[] = (string)$stored;
                    }
                }
            }

            $record->status = $ruleResult['has_risk']
                ? 'suspicious'
                : ($mergedRatio !== null ? 'normal' : 'pending');
            $record->risk_reasons = json_encode(array_values(array_unique($reasons)), JSON_UNESCAPED_UNICODE);
            if ($this->metricsColumnAvailable()) {
                // 手工 encode 而不是靠 Eloquent 的 array cast：cast 走的是不带
                // JSON_UNESCAPED_UNICODE 的 json_encode，规则名会存成 \uXXXX 转义，而相邻的
                // risk_reasons 是可读中文 —— 部署验证要在 phpMyAdmin 里看这一列。
                $record->metrics = json_encode([
                    'v' => 1,
                    'metrics' => $metrics,
                    'fired_rules' => $ruleResult['fired']
                ], JSON_UNESCAPED_UNICODE);
            }
        }

        $record->evaluated_at = time();
        $record->save();
        return $record;
    }

    /**
     * 后台手动自定义周期评估：与周期评估用同一套指标采集和规则引擎，但纯计算——
     * 不读不写 v2_subscription_risk_cycle。30 天账本是冻结判定（summaryForUser 的
     * 「风险」列、审计留痕都建立在它的网格语义上），手动窗口只做即时体检，绝不入账。
     */
    public function assessWindow(Subscription $subscription, int $windowStart, int $windowEnd): array
    {
        // 与 evaluateCompletedCycles 同理按订阅重置 memo：手动评估在一个服务实例上
        // 连续扫几百个订阅，memo 不重置会随批次无界增长。
        $this->locationMemo = [];
        // 流量按天重叠取桶（粒度整天），原因见 collectWindow 注释。
        $window = $this->collectWindow($subscription, $windowStart, $windowEnd, true);

        $metrics = [
            'user_agent_count' => $window['user_agent_count'],
            'distinct_ip_count' => $window['distinct_ip_count'],
            'city_count' => $window['city_count'],
            'region_count' => $window['region_count'],
            'country_count' => $window['country_count'],
            'used_ratio' => $window['used_ratio']
        ];
        // 节点无依据 ⇒ 节点键缺失 ⇒ 规则不命中。与周期评估同一约定：缺失不等于 0。
        if ($window['has_node_basis']) {
            foreach ($this->nodeMetricKeys() as $key) {
                $metrics[$key] = $window['node_metrics'][$key];
            }
        }

        // 窗口内三路证据全空时不进规则引擎：这里没有历史记录可沿用（手动评估不落库），
        // 「没有数据」必须与「判定为正常」区分开，否则短窗口会给全站发一遍正常牌。
        if (!$window['has_log_basis'] && !$window['has_node_basis'] && !$window['has_traffic_basis']) {
            return ['status' => 'no_data', 'metrics' => $metrics, 'reasons' => [], 'fired' => []];
        }

        $ruleResult = $this->ruleService()->evaluate($metrics);
        $reasons = $ruleResult['reasons'];
        // 复用周期评估的重复 IP 证据标注。同一文案模板，翻译层对二者的处理保持一致——
        // 当前理由串属库存中文数据，各语种均原样展示（漏翻不坏的既有边界），并无覆盖规则。
        foreach ($window['ip_rows']->filter(function ($row) {
            return (int)$row->request_count > 1;
        })->take(10) as $ipRow) {
            $reasons[] = '重复 IP：' . $ipRow->request_ip . ' 出现 ' . $ipRow->request_count . ' 次';
        }

        return [
            'status' => $ruleResult['has_risk'] ? 'suspicious' : 'normal',
            'metrics' => $metrics,
            'reasons' => array_values(array_unique($reasons)),
            'fired' => $ruleResult['fired']
        ];
    }

    /**
     * 用外部快照顶替规则表读取：手动评估整轮跨几十个 step 请求，每请求都现读规则表会让
     * 「评估中途有人改规则」把同一轮结果切成两套判定标准。restart 时把 enabledRules()
     * 快照冻进游标状态，后续每批注入同一份。
     */
    public function useRuleSnapshot(array $rules): void
    {
        $this->ruleService()->useRules($rules);
    }

    private function ruleService(): RiskRuleService
    {
        if ($this->ruleService === null) {
            // 规则表只读一次：EvaluateSubscriptionRisk 只构造一个 SubscriptionRiskService
            // 然后 chunkById 遍历全部订阅，RiskRuleService 的实例级 memo 就够。
            $this->ruleService = new RiskRuleService();
        }
        return $this->ruleService;
    }

    /**
     * 刻意与 available() 分开：available() 是硬闸门，往里加一个新列的条件就会在未升级的
     * 库上静默关掉全部风控评估。这里只决定「要不要写 metrics」，取不到就跳过写入。
     */
    private function metricsColumnAvailable(): bool
    {
        if ($this->metricsColumn !== null) {
            return $this->metricsColumn;
        }
        try {
            return $this->metricsColumn = Schema::hasColumn('v2_subscription_risk_cycle', 'metrics');
        } catch (\Throwable $e) {
            return $this->metricsColumn = false;
        }
    }

    private function nodeLogAvailable(): bool
    {
        if ($this->nodeLogAvailability !== null) {
            return $this->nodeLogAvailability;
        }
        try {
            return $this->nodeLogAvailability = Schema::hasTable('v2_node_connection_log');
        } catch (\Throwable $e) {
            return $this->nodeLogAvailability = false;
        }
    }

    private function nodeMetricKeys(): array
    {
        if ($this->nodeMetricKeys !== null) {
            return $this->nodeMetricKeys;
        }
        $keys = [];
        foreach (RiskRuleService::DIMENSIONS as $key => $meta) {
            if (($meta['source'] ?? '') === 'node_log') {
                $keys[] = $key;
            }
        }
        return $this->nodeMetricKeys = $keys;
    }

    private function storedMetrics(SubscriptionRiskCycle $record): array
    {
        if (!$record->exists || !$this->metricsColumnAvailable()) {
            return [];
        }
        $stored = json_decode((string)$record->metrics, true);
        if (!is_array($stored) || !isset($stored['metrics']) || !is_array($stored['metrics'])) {
            return [];
        }
        return $stored['metrics'];
    }

    private function storedReasons(SubscriptionRiskCycle $record): array
    {
        if (!$record->exists) {
            return [];
        }
        $decoded = json_decode((string)$record->risk_reasons, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function locationService(): IpLocationService
    {
        if ($this->locationService === null) {
            // 留在服务实例上：IpLocationService 持有 MMDB reader，按周期新建会把 reader
            // 反复打开一遍。
            $this->locationService = new IpLocationService();
        }
        return $this->locationService;
    }

    /**
     * 把地理归约提出来给拉取 IP 和节点连接 IP 两组共用，行为与原先的内联实现一致。
     * 两组共享 $this->locationMemo，重复的 IP 只查一次。
     */
    private function reduceLocations(array $ips): array
    {
        $locationService = $this->locationService();
        $locations = [];
        $countries = [];
        foreach ($ips as $ip) {
            $ip = (string)$ip;
            if ($ip === '') {
                continue;
            }
            if (!array_key_exists($ip, $this->locationMemo)) {
                $this->locationMemo[$ip] = $locationService->lookup($ip);
            }
            $location = $this->locationMemo[$ip];
            if (($location['status'] ?? '') !== 'resolved' || !$location['location_key']) {
                continue;
            }
            $locations[$location['location_key']] = $location;
            if ($location['country_code']) {
                $countries[$location['country_code']] = true;
            }
        }

        $cityKeys = [];
        $regionKeys = [];
        foreach ($locations as $location) {
            if ($location['city']) {
                $cityKeys[$location['country_code'] . '|' . $location['region'] . '|' . $location['city']] = true;
            }
            if ($location['region']) {
                $regionKeys[$location['country_code'] . '|' . $location['region']] = true;
            }
        }

        return [
            'city_count' => count($cityKeys),
            'region_count' => count($regionKeys),
            'country_count' => count($countries)
        ];
    }

    /**
     * 节点连接维度。返回 null 表示本周期没有节点依据（表缺失、查询失败或没有重叠行），
     * 调用方据此保留已存指标而不是写 0。
     */
    private function nodeMetrics(Subscription $subscription, int $cycleStart, int $cycleEnd): ?array
    {
        if (!$this->nodeLogAvailable()) {
            return null;
        }

        try {
            // v2_node_connection_log 是 UPSERT 表：每个「节点用户 + 节点 + IP」一行，
            // first_seen_at 固定、last_seen_at 每次上报刷新，没有按周期的行。所以窗口判定
            // 必须是区间重叠，不能拿单个时间戳去夹。
            $query = NodeConnectionLog::where('user_id', (int)$subscription->user_id)
                ->where(function ($q) use ($subscription) {
                    $q->where('subscription_id', (int)$subscription->id);
                    // 迁移前的老用户上报时 subscription_id 写的是 NULL，用 node_user_id 兜住。
                    // 注意这里必须绑 user_id 而不是订阅的 node_user_id：NodeConnectionAuditService
                    // ::resolveOwners() 对命中不了订阅的上报直接把 v2_user.id 当 node_user_id 存，
                    // 而订阅的 node_user_id 恒为 2000000000+user_id 或 1000000000+subscription_id，
                    // 两个 id 空间永不相交 —— 绑 node_user_id 会让这个分支永远匹配不到任何行。
                    $q->orWhere(function ($inner) use ($subscription) {
                        $inner->whereNull('subscription_id')
                            ->where('node_user_id', (int)$subscription->user_id);
                    });
                })
                ->where('first_seen_at', '<', $cycleEnd)
                ->where('last_seen_at', '>=', $cycleStart);

            // first_seen_at 是插入时固定的，所以 new_ip_count 是窗口精确的，不受重叠影响。
            // 刻意不派生任何 report_count 的指标：那是自 first_seen_at 以来的累计计数器，
            // 对重叠行求 SUM 不是窗口范围内的量，暴露它会是正确性 bug 而不是功能。
            $aggregate = (clone $query)->selectRaw(
                "COUNT(*) AS row_count,
                 COUNT(DISTINCT `ip`) AS ip_count,
                 COUNT(DISTINCT CONCAT(`node_type`, '-', `node_id`)) AS node_count,
                 COUNT(DISTINCT CASE WHEN `first_seen_at` >= ? THEN `ip` END) AS new_ip_count",
                [$cycleStart]
            )->first();

            if ((int)($aggregate->row_count ?? 0) <= 0) {
                return null;
            }

            // 与 UserController::nodeConnections 的上限一致：地理归约只看前 200 个去重 IP。
            $ips = (clone $query)->distinct()->limit(200)->pluck('ip')->all();
            $geo = $this->reduceLocations($ips);

            return [
                'node_ip_count' => (int)($aggregate->ip_count ?? 0),
                'node_new_ip_count' => (int)($aggregate->new_ip_count ?? 0),
                'node_count' => (int)($aggregate->node_count ?? 0),
                'node_country_count' => $geo['country_count'],
                'node_region_count' => $geo['region_count'],
                'node_city_count' => $geo['city_count']
            ];
        } catch (\Throwable $e) {
            // 节点指标是增量能力，取不到就退回「无依据」，不能让它中断整轮评估。
            // 必须记日志：静默失败与「本周期没有重叠行」在结果上完全一样，无法区分。
            Log::warning('节点连接指标读取失败，本周期按无节点依据处理', [
                'subscription_id' => (int)$subscription->id,
                'cycle_start' => $cycleStart,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function summaryForUser(int $userId): array
    {
        if (!$this->available()) {
            return [
                'status' => 'pending', 'suspicious_count' => 0, 'reasons' => [],
                'distinct_ip_count' => 0, 'city_count' => 0, 'region_count' => 0, 'country_count' => 0
            ];
        }

        // 显式列清单：这个方法在 UserController@fetch 里按用户行调用（N+1），SELECT * 会让
        // 每一行都把 metrics 这个 TEXT blob 一起拉出来，而这里从头到尾没有解码它。
        $records = SubscriptionRiskCycle::where('user_id', $userId)
            ->orderByDesc('cycle_end')
            ->get([
                'subscription_id', 'cycle_end', 'status', 'risk_reasons',
                'distinct_ip_count', 'city_count', 'region_count', 'country_count'
            ]);
        $latestBySubscription = [];
        foreach ($records as $record) {
            if (!isset($latestBySubscription[$record->subscription_id])) {
                $latestBySubscription[$record->subscription_id] = $record;
            }
        }

        $reasons = [];
        $suspiciousCount = 0;
        $distinctIpCount = 0;
        $cityCount = 0;
        $regionCount = 0;
        $countryCount = 0;
        $hasPending = count($latestBySubscription) === 0;
        foreach (Subscription::where('user_id', $userId)->get(['id', 'started_at']) as $subscription) {
            $startedAt = (int)$subscription->started_at;
            // 第一个周期还没走完的订阅确实算「待观察」。
            // 这里原先还有一个 (time() - $startedAt) % CYCLE_SECONDS !== 0 的判断，它只在恰好
            // 落在周期边界那一秒才为 0，其余时刻恒为真，使 $hasPending 几乎永远为真、
            // 下面的 'normal' 分支永不可达 —— 所有用户都显示「待观察」。
            if ($startedAt > 0 && $startedAt + self::CYCLE_SECONDS > time()) {
                $hasPending = true;
            }
        }
        foreach ($latestBySubscription as $record) {
            $distinctIpCount += (int)$record->distinct_ip_count;
            $cityCount += (int)$record->city_count;
            $regionCount += (int)$record->region_count;
            $countryCount += (int)$record->country_count;
            if ($record->status === 'suspicious') {
                $suspiciousCount++;
                $recordReasons = json_decode((string)$record->risk_reasons, true);
                $reasons = array_merge($reasons, is_array($recordReasons) ? $recordReasons : []);
            } elseif ($record->status === 'pending') {
                $hasPending = true;
            }
        }

        return [
            'status' => $suspiciousCount > 0 ? 'suspicious' : ($hasPending ? 'pending' : 'normal'),
            'suspicious_count' => $suspiciousCount,
            'reasons' => array_values(array_unique($reasons)),
            'distinct_ip_count' => $distinctIpCount,
            'city_count' => $cityCount,
            'region_count' => $regionCount,
            'country_count' => $countryCount
        ];
    }

    public function cyclesForUser(int $userId, ?int $subscriptionId = null, ?int $cycleStart = null)
    {
        if (!$this->available()) {
            return collect();
        }
        $query = SubscriptionRiskCycle::where('user_id', $userId)->orderByDesc('cycle_end');
        if ($subscriptionId) $query->where('subscription_id', $subscriptionId);
        if ($cycleStart) $query->where('cycle_start', $cycleStart);
        return $query->get();
    }
}
