<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

/**
 * 动态倍率的读路径（节点上报时用）。
 *
 * 只在热路径上做两次 Redis 读：一份规则 JSON、一份用户倍率 hash。**读不到一律回落 1.0** ——
 * 这段代码跑在每次节点上报的同步路径上，Redis 抖一下不该让上报失败或者把流量记成 0；
 * 真要出问题，宁可这一分钟不叠加倍率。
 *
 * 有效倍率 = 节点基础倍率(v2_server_*.rate) × 时段倍率(规则) × 用户动态倍率(带宽峰值)。
 * 三个因子都为 1 时结果就是节点基础倍率，与改造前逐字节一致。
 */
class RateResolver
{
    public function __construct(?RateRuleMatcher $matcher = null)
    {
        $this->matcher = $matcher ?: new RateRuleMatcher();
    }

    /** @var RateRuleMatcher */
    private $matcher;

    /** @var array<int, array<string, mixed>>|null 同一进程内的规则缓存（队列 worker 常驻，省下每次解码） */
    private static $rulesCache = null;

    /** @var int 规则缓存的时间戳 */
    private static $rulesCachedAt = 0;

    private const RULES_CACHE_SECONDS = 30;

    /**
     * 按用户算这一批上报的有效倍率。
     *
     * @param array<string, mixed> $server 节点行（节点上报接口传的是 toArray()，含 rate / id）
     * @param array<int, int|string> $userIds 本批上报里的 node_user_id
     * @return array<int, float> [uid => 有效倍率]
     */
    public function resolveForPush(array $server, string $protocol, array $userIds, ?int $timestamp = null): array
    {
        return $this->resolveTraffic($server, $protocol, $userIds, $timestamp)['rates'];
    }

    public function resolveTraffic(array $server, string $protocol, array $userIds, ?int $timestamp = null): array
    {
        $nodeRate = $this->nodeRate($server);
        $policies = new RatePolicyService();
        $config = $policies->runtime();
        if ($config) {
            $policy = $policies->policyFor($config, $protocol, (int) ($server['id'] ?? 0));
            $band = $this->matcher->multiplierFor($config['rules'], $protocol, (int) ($server['id'] ?? 0), $timestamp ?: time());
            $multipliers = $policies->multipliers($policy, $userIds);
            $rates = [];
            foreach ($userIds as $id) $rates[(int) $id] = $this->combine($nodeRate, $band, $multipliers[$id] ?? 1.0);
            return ['rates' => $rates, 'sampling' => $policy
                ? ['mode' => 'policy', 'policy_id' => $policy['id'], 'revision' => $policy['revision']]
                : ['mode' => 'off']];
        }
        if ($policies->ready()) {
            // Do not accidentally revive legacy global penalties during a Redis outage.
            return ['rates' => array_fill_keys($userIds, $nodeRate), 'sampling' => ['mode' => 'off']];
        }
        $band = $this->bandMultiplier($protocol, (int) ($server['id'] ?? 0), $timestamp ?: time());
        $userMultipliers = $this->userMultipliers($userIds);

        $rates = [];
        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            $rates[$userId] = $this->combine($nodeRate, $band, $userMultipliers[$userId] ?? 1.0);
        }

        return ['rates' => $rates, 'sampling' => []];
    }

    /**
     * 三个因子相乘。统一取两位小数：v2_stat_user.server_rate 是 decimal(10,2)，
     * 计费乘进去的倍率与统计记下的倍率必须逐位一致，否则用户的流量日志对不上扣费。
     */
    public function combine(float $nodeRate, float $bandMultiplier, float $userMultiplier): float
    {
        return round($nodeRate * $bandMultiplier * $userMultiplier, 2);
    }

    public function bandMultiplier(string $protocol, int $nodeId, int $timestamp): float
    {
        $rules = $this->rules($timestamp);
        if (!$rules) {
            return 1.0;
        }

        return $this->matcher->multiplierFor($rules, $protocol, $nodeId, $timestamp);
    }

    /**
     * @param array<int, int|string> $userIds
     * @return array<int, float>
     */
    public function userMultipliers(array $userIds): array
    {
        if (!$userIds) {
            return [];
        }

        try {
            $values = Redis::hmget(DynamicRateService::KEY_USER_MULT, array_map('strval', $userIds));
        } catch (\Throwable $e) {
            // 读不到就当没叠加。热路径不因为 Redis 出问题而失败。
            return [];
        }

        $multipliers = [];
        foreach (array_values($userIds) as $index => $userId) {
            $value = $values[$index] ?? null;
            if ($value !== null && $value !== false && (float) $value > 0) {
                $multipliers[(int) $userId] = (float) $value;
            }
        }

        return $multipliers;
    }

    /**
     * @param array<string, mixed> $server
     */
    private function nodeRate(array $server): float
    {
        $rate = (float) ($server['rate'] ?? 1);
        // 脏数据兜底：0 或负数会把用户的流量记成 0 或负，比不精确严重得多。
        if ($rate <= 0) {
            return 1.0;
        }

        return $rate;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rules(int $timestamp): array
    {
        if (self::$rulesCache !== null && $timestamp - self::$rulesCachedAt < self::RULES_CACHE_SECONDS) {
            return self::$rulesCache;
        }

        try {
            $raw = Redis::get(DynamicRateService::KEY_RULES);
            $decoded = $raw ? json_decode((string) $raw, true) : [];
        } catch (\Throwable $e) {
            $decoded = [];
        }

        self::$rulesCache = is_array($decoded) ? $decoded : [];
        self::$rulesCachedAt = $timestamp;

        return self::$rulesCache;
    }

    /**
     * 队列 worker 是常驻进程，规则缓存要能被 tick 主动清掉（管理页改完规则立即生效用）。
     */
    public static function forgetRulesCache(): void
    {
        self::$rulesCache = null;
        self::$rulesCachedAt = 0;
    }
}
