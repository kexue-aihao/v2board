<?php

namespace App\Services;

use App\Models\RiskRule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RiskRuleService
{
    /**
     * 维度注册表是唯一事实源：后端校验器和管理端 UI 都读它，JS 里不复制第二份。
     * 放类常量而不是 config/ —— v2board:update 会先跑 config:cache，类常量对它免疫。
     */
    public const DIMENSIONS = [
        'user_agent_count'   => ['label' => '订阅 UA 种类数',       'unit' => '种', 'source' => 'subscribe_log'],
        'distinct_ip_count'  => ['label' => '订阅拉取 IP 数',       'unit' => '个', 'source' => 'subscribe_log'],
        'city_count'         => ['label' => '拉取 IP 城市数',       'unit' => '个', 'source' => 'subscribe_log'],
        'region_count'       => ['label' => '拉取 IP 省/州数',      'unit' => '个', 'source' => 'subscribe_log'],
        'country_count'      => ['label' => '拉取 IP 国家数',       'unit' => '个', 'source' => 'subscribe_log'],
        'used_ratio'         => ['label' => '流量使用率',           'unit' => '',   'source' => 'traffic'],
        'node_ip_count'      => ['label' => '节点连接 IP 数',       'unit' => '个', 'source' => 'node_log'],
        'node_new_ip_count'  => ['label' => '本周期新增连接 IP 数', 'unit' => '个', 'source' => 'node_log'],
        'node_count'         => ['label' => '使用节点数',           'unit' => '个', 'source' => 'node_log'],
        'node_country_count' => ['label' => '连接 IP 国家数',       'unit' => '个', 'source' => 'node_log'],
        'node_region_count'  => ['label' => '连接 IP 省/州数',      'unit' => '个', 'source' => 'node_log'],
        'node_city_count'    => ['label' => '连接 IP 城市数',       'unit' => '个', 'source' => 'node_log']
    ];

    public const OPERATORS = [
        '>' => '大于',
        '>=' => '大于等于',
        '<' => '小于',
        '<=' => '小于等于'
    ];

    /**
     * 新规则的默认权重，也是缺权重时的兜底值。20 的用意：三条内置默认规则全部命中时正好
     * 60%，与管理员提醒的默认阈值（risk_notify_threshold）对齐 —— 开箱即提醒，管理员再按
     * 自己的口径逐条调。
     */
    public const DEFAULT_RULE_WEIGHT = 20;
    public const MAX_RULE_WEIGHT = 100;

    /**
     * 规则表缺失时的内置兜底，与 SchemaUpgradeService 写入的三条种子一致。
     * 未升级的库必须与升级前逐位同构，这一点不可妥协。
     */
    private const FALLBACK_RULES = [
        ['id' => null, 'label' => '订阅 UA 种类过多', 'dimension' => 'user_agent_count', 'operator' => '>', 'threshold' => 3.0, 'weight' => self::DEFAULT_RULE_WEIGHT],
        ['id' => null, 'label' => '跨省/州请求过多', 'dimension' => 'region_count', 'operator' => '>=', 'threshold' => 3.0, 'weight' => self::DEFAULT_RULE_WEIGHT],
        ['id' => null, 'label' => '跨市请求过多', 'dimension' => 'city_count', 'operator' => '>=', 'threshold' => 3.0, 'weight' => self::DEFAULT_RULE_WEIGHT]
    ];

    private $availability;
    private $rules;

    public function available(): bool
    {
        if ($this->availability !== null) {
            return $this->availability;
        }
        try {
            return $this->availability = Schema::hasTable('v2_risk_rule');
        } catch (\Throwable $e) {
            // 探测失败按「表不存在」处理，让判定退回内置规则而不是变成无规则可用。
            Log::warning('清洗策略表探测失败，按未安装处理并使用内置默认规则', ['error' => $e->getMessage()]);
            return $this->availability = false;
        }
    }

    /**
     * 规则在一次命令运行里只加载一次：EvaluateSubscriptionRisk 只构造一个
     * SubscriptionRiskService 然后 chunkById 遍历全部订阅，实例级 memo 就够。
     */
    public function enabledRules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }
        if (!$this->available()) {
            return $this->rules = self::FALLBACK_RULES;
        }

        try {
            $rows = RiskRule::where('enabled', 1)
                ->orderBy('sort', 'ASC')
                ->orderBy('id', 'ASC')
                ->get(['id', 'label', 'dimension', 'operator', 'threshold', 'weight']);
        } catch (\Throwable $e) {
            // 表存在但读取失败时返回空规则集，而不是回落到内置默认规则。管理员清空规则表
            // 正是「关掉风控标记」的唯一手段，一次瞬时数据库错误不应该把默认规则重新装回去
            // 并重新给用户打标。
            Log::warning('清洗策略读取失败，本轮不启用任何规则', ['error' => $e->getMessage()]);
            return $this->rules = [];
        }

        $rules = [];
        foreach ($rows as $row) {
            if (!isset(self::DIMENSIONS[$row->dimension]) || !isset(self::OPERATORS[$row->operator])) {
                // 维度或运算符不在注册表里的行只可能来自手工改库或旧版本，直接忽略而不是
                // 让评估抛异常中断整轮。
                continue;
            }
            $rules[] = [
                'id' => (int)$row->id,
                'label' => (string)$row->label,
                'dimension' => (string)$row->dimension,
                'operator' => (string)$row->operator,
                'threshold' => (float)$row->threshold,
                'weight' => $this->normalizeWeight($row->weight ?? null)
            ];
        }

        // 表存在但零条启用是管理员逐条禁用/删除的结果（每步都有确认），尊重该意图：
        // 不命中任何规则。管理端会为这个状态渲染常驻警告。
        return $this->rules = $rules;
    }

    /**
     * 注入固定规则集，顶替 enabledRules() 的规则表读取。调用方（手动评估）把一份快照
     * 冻结在游标状态里逐批复用，保证整轮判定标准一致。快照来自缓存，可能跨部署残留，
     * 所以按 enabledRules() 同一口径重新过一遍注册表校验，不合法的行静默丢弃。
     */
    public function useRules(array $rules): void
    {
        $clean = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)
                || !isset($rule['dimension'], $rule['operator'], $rule['threshold'])
                || !isset(self::DIMENSIONS[$rule['dimension']])
                || !isset(self::OPERATORS[$rule['operator']])) {
                continue;
            }
            $clean[] = [
                'id' => isset($rule['id']) ? (int)$rule['id'] : null,
                'label' => (string)($rule['label'] ?? ''),
                'dimension' => (string)$rule['dimension'],
                'operator' => (string)$rule['operator'],
                'threshold' => (float)$rule['threshold'],
                // 老快照里没有这一项（跨部署残留的游标状态），缺键按默认值补齐 —— 绝不能因为
                // 少一个字段就把整条规则丢掉，那会让手动评估整轮判不出结果。
                'weight' => $this->normalizeWeight($rule['weight'] ?? null)
            ];
        }
        $this->rules = $clean;
    }

    /**
     * @param array $metrics 维度 => 值。缺键或 null 代表本周期拿不到该依据。
     * @return array{has_risk: bool, score: int, reasons: string[], fired: array[]}
     *
     * score 是风险值百分比：命中规则的权重累加，封顶 100。0 表示「判过且干净」，
     * 调用方在「没有依据」时必须自己写 NULL 而不是 0（见 SubscriptionRiskService）。
     */
    public function evaluate(array $metrics): array
    {
        $reasons = [];
        $fired = [];
        $score = 0;

        foreach ($this->enabledRules() as $rule) {
            $dimension = $rule['dimension'];
            // 指标缺失或为 null ⇒ 规则不命中、不产生理由。这对设计上可空的 used_ratio
            // 和节点表缺失时的节点指标是关键的：不能把「没有依据」当成 0 来判定。
            if (!array_key_exists($dimension, $metrics) || $metrics[$dimension] === null) {
                continue;
            }
            $value = (float)$metrics[$dimension];
            $threshold = (float)$rule['threshold'];
            if (!$this->compare($value, $rule['operator'], $threshold)) {
                continue;
            }

            $weight = $this->normalizeWeight($rule['weight'] ?? null);
            $score += $weight;
            $reasons[] = '命中清洗策略「' . $rule['label'] . '」：'
                . self::DIMENSIONS[$dimension]['label'] . ' ' . $this->formatNumber($value)
                . ' ' . self::OPERATORS[$rule['operator']] . ' ' . $this->formatNumber($threshold);
            $fired[] = [
                'id' => $rule['id'],
                'label' => $rule['label'],
                'dimension' => $dimension,
                'operator' => $rule['operator'],
                'threshold' => $threshold,
                'value' => $value,
                'weight' => $weight
            ];
        }

        return [
            'has_risk' => count($fired) > 0,
            'score' => min(self::MAX_RULE_WEIGHT, $score),
            'reasons' => $reasons,
            'fired' => $fired
        ];
    }

    /**
     * 权重钳位：0-100 之外的值、缺键、空串一律收敛到默认值。规则表可能还没有 weight 列
     * （未升级的库），那时 Eloquent 取出的是 null —— 与「快照里没有这个键」同一处理。
     */
    private function normalizeWeight($value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_RULE_WEIGHT;
        }

        return max(0, min(self::MAX_RULE_WEIGHT, (int)$value));
    }

    private function compare(float $value, string $operator, float $threshold): bool
    {
        switch ($operator) {
            case '>':
                return $value > $threshold;
            case '>=':
                return $value >= $threshold;
            case '<':
                return $value < $threshold;
            case '<=':
                return $value <= $threshold;
        }
        return false;
    }

    private function formatNumber(float $value): string
    {
        if ($value === floor($value) && abs($value) < 1.0e15) {
            return (string)(int)$value;
        }
        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
    }
}
