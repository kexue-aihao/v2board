<?php

namespace App\Services;

/**
 * 时段倍率规则的匹配。
 *
 * 纯函数，不碰数据库也不读缓存 —— 规则由调用方一次性取出来传进来，方便单测直接喂数组。
 * 时间一律按站点时区（config('app.timezone')，本站是 Asia/Shanghai）判定：运营嘴里的
 * 「晚高峰 20:00」指的是用户的钟，不是 UTC。
 *
 * 优先级：节点规则覆盖全局规则 —— 同一次判定里只要有一条 node 规则命中，就完全不看
 * 全局规则，只在命中的节点规则之间取倍率最大的那条；没有节点规则命中才看全局规则。
 * 「覆盖」比「相乘」可预测：给某个节点单独定 2 倍时，运营期望的是 2 倍，而不是
 * 全局 1.5 × 2 = 3 倍。
 */
class RateRuleMatcher
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_NODE = 'node';

    /** 一天的分钟数，end_minute 取到它就表示「到当天结束」。 */
    public const DAY_MINUTES = 1440;

    /**
     * @param array<int, array<string, mixed>> $rules 规则行
     */
    public function multiplierFor(array $rules, string $nodeType, int $nodeId, int $timestamp): float
    {
        $weekday = (int) date('N', $timestamp);        // 1=周一 … 7=周日
        $minute = $this->minuteOfDay($timestamp);

        $nodeBest = null;
        $globalBest = null;

        foreach ($rules as $rule) {
            if (!$this->isEnabled($rule)) {
                continue;
            }
            $scope = (string) ($rule['scope'] ?? self::SCOPE_GLOBAL);
            if ($scope !== self::SCOPE_GLOBAL && $scope !== self::SCOPE_NODE) {
                continue;
            }
            if ($scope === self::SCOPE_NODE
                && ((string) ($rule['node_type'] ?? '') !== $nodeType || (int) ($rule['node_id'] ?? 0) !== $nodeId)) {
                continue;
            }
            if (!$this->coversWeekday((string) ($rule['weekdays'] ?? ''), $weekday)) {
                continue;
            }
            if (!$this->coversMinute((int) ($rule['start_minute'] ?? 0), (int) ($rule['end_minute'] ?? 0), $minute)) {
                continue;
            }

            $multiplier = (float) ($rule['multiplier'] ?? 1);
            if ($scope === self::SCOPE_NODE) {
                $nodeBest = $nodeBest === null ? $multiplier : max($nodeBest, $multiplier);
            } else {
                $globalBest = $globalBest === null ? $multiplier : max($globalBest, $multiplier);
            }
        }

        return $nodeBest ?? $globalBest ?? 1.0;
    }

    /**
     * 当前生效的全局倍率（不含任何节点规则）。给「节点上没有单独配置」的兜底值用。
     *
     * @param array<int, array<string, mixed>> $rules
     */
    public function globalMultiplier(array $rules, int $timestamp): float
    {
        return $this->multiplierFor($rules, '', 0, $timestamp);
    }

    /**
     * 该时刻命中了哪些规则 —— 管理页用它提示「同一时段有多条规则在抢」。
     *
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array<string, mixed>>
     */
    public function matchesAt(array $rules, string $nodeType, int $nodeId, int $timestamp): array
    {
        $weekday = (int) date('N', $timestamp);
        $minute = $this->minuteOfDay($timestamp);
        $matched = [];

        foreach ($rules as $rule) {
            if (!$this->isEnabled($rule)) {
                continue;
            }
            $scope = (string) ($rule['scope'] ?? self::SCOPE_GLOBAL);
            if ($scope === self::SCOPE_NODE
                && ((string) ($rule['node_type'] ?? '') !== $nodeType || (int) ($rule['node_id'] ?? 0) !== $nodeId)) {
                continue;
            }
            if ($scope !== self::SCOPE_GLOBAL && $scope !== self::SCOPE_NODE) {
                continue;
            }
            if (!$this->coversWeekday((string) ($rule['weekdays'] ?? ''), $weekday)) {
                continue;
            }
            if (!$this->coversMinute((int) ($rule['start_minute'] ?? 0), (int) ($rule['end_minute'] ?? 0), $minute)) {
                continue;
            }
            $matched[] = $rule;
        }

        return $matched;
    }

    public function minuteOfDay(int $timestamp): int
    {
        return ((int) date('G', $timestamp)) * 60 + (int) date('i', $timestamp);
    }

    /**
     * weekdays 是 "1,2,3" 这种写法，空值按「每天」处理 —— 面板上新建规则时默认就是全周，
     * 清空不该变成「永不生效」。
     *
     * @param string $weekdays
     */
    private function coversWeekday(string $weekdays, int $weekday): bool
    {
        $weekdays = trim($weekdays);
        if ($weekdays === '') {
            return true;
        }
        foreach (explode(',', $weekdays) as $day) {
            if ((int) trim($day) === $weekday) {
                return true;
            }
        }

        return false;
    }

    /**
     * 左闭右开区间 [start, end)。start > end 表示跨零点（23:00-02:00），此时两侧都算命中。
     * end 取 1440 表示到当天结束。
     */
    private function coversMinute(int $start, int $end, int $minute): bool
    {
        $start = max(0, min(self::DAY_MINUTES, $start));
        $end = max(0, min(self::DAY_MINUTES, $end));
        if ($start === $end) {
            // 起止相同：要么是「整天」（0-1440 之外的写法），要么是空区间。按整天处理更符合直觉。
            return true;
        }
        if ($start < $end) {
            return $minute >= $start && $minute < $end;
        }

        return $minute >= $start || $minute < $end;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function isEnabled(array $rule): bool
    {
        return (int) ($rule['enabled'] ?? 1) === 1;
    }
}
