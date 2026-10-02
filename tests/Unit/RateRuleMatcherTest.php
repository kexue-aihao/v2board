<?php

namespace Tests\Unit;

use App\Services\RateRuleMatcher;
use Tests\TestCase;

/**
 * 时段规则的匹配。时间一律按站点时区（Asia/Shanghai）判定 —— 运营嘴里的「晚高峰 20:00」
 * 指的是用户的钟，所以这里的断言都建立在固定的本地时间字符串上。
 */
class RateRuleMatcherTest extends TestCase
{
    private function matcher(): RateRuleMatcher
    {
        return new RateRuleMatcher();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function rule(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'scope' => RateRuleMatcher::SCOPE_GLOBAL,
            'node_type' => '',
            'node_id' => 0,
            'weekdays' => '',
            'start_minute' => 0,
            'end_minute' => RateRuleMatcher::DAY_MINUTES,
            'multiplier' => 1.5,
            'enabled' => 1,
            'remark' => ''
        ], $overrides);
    }

    public function testGlobalRuleAppliesInsideItsWindowOnly(): void
    {
        $rules = [$this->rule(['start_minute' => 20 * 60, 'end_minute' => 23 * 60])];

        // 2026-10-05 是周一
        $this->assertSame(1.5, $this->matcher()->multiplierFor($rules, 'vmess', 3, strtotime('2026-10-05 21:00:00')));
        $this->assertSame(1.0, $this->matcher()->multiplierFor($rules, 'vmess', 3, strtotime('2026-10-05 19:59:00')));
        // 结束时间是左闭右开：23:00 已经不在窗口里
        $this->assertSame(1.0, $this->matcher()->multiplierFor($rules, 'vmess', 3, strtotime('2026-10-05 23:00:00')));
    }

    public function testWeekdayFilter(): void
    {
        $rules = [$this->rule(['weekdays' => '6,7'])];

        $this->assertSame(1.0, $this->matcher()->multiplierFor($rules, 'vmess', 1, strtotime('2026-10-05 21:00:00')));
        // 2026-10-10 是周六
        $this->assertSame(1.5, $this->matcher()->multiplierFor($rules, 'vmess', 1, strtotime('2026-10-10 21:00:00')));
    }

    public function testEmptyWeekdaysMeansEveryDay(): void
    {
        $rules = [$this->rule(['weekdays' => ''])];

        $this->assertSame(1.5, $this->matcher()->multiplierFor($rules, 'vmess', 1, strtotime('2026-10-05 10:00:00')));
        $this->assertSame(1.5, $this->matcher()->multiplierFor($rules, 'vmess', 1, strtotime('2026-10-10 10:00:00')));
    }

    public function testNodeRuleOverridesGlobalOne(): void
    {
        $rules = [
            $this->rule(['id' => 1, 'multiplier' => 1.5]),
            $this->rule([
                'id' => 2,
                'scope' => RateRuleMatcher::SCOPE_NODE,
                'node_type' => 'v2node',
                'node_id' => 7,
                'multiplier' => 2.0
            ])
        ];
        $at = strtotime('2026-10-05 12:00:00');

        // 命中节点规则的那个节点按 2 倍，其它节点照旧吃全局规则
        $this->assertSame(2.0, $this->matcher()->multiplierFor($rules, 'v2node', 7, $at));
        $this->assertSame(1.5, $this->matcher()->multiplierFor($rules, 'v2node', 8, $at));
        $this->assertSame(1.5, $this->matcher()->multiplierFor($rules, 'vmess', 7, $at));
    }

    public function testNodeRulesDoNotMultiplyWithGlobalOnes(): void
    {
        $rules = [
            $this->rule(['id' => 1, 'multiplier' => 1.5]),
            $this->rule([
                'id' => 2,
                'scope' => RateRuleMatcher::SCOPE_NODE,
                'node_type' => 'vmess',
                'node_id' => 1,
                'multiplier' => 2.0
            ])
        ];

        // 覆盖而不是相乘：单独定 2 倍就是 2 倍，不该变 3 倍
        $this->assertSame(2.0, $this->matcher()->multiplierFor($rules, 'vmess', 1, strtotime('2026-10-05 12:00:00')));
    }

    public function testOverlappingRulesTakeTheLargestMultiplier(): void
    {
        $rules = [
            $this->rule(['id' => 1, 'start_minute' => 20 * 60, 'end_minute' => 23 * 60, 'multiplier' => 1.2]),
            $this->rule(['id' => 2, 'start_minute' => 21 * 60, 'end_minute' => 22 * 60, 'multiplier' => 1.8])
        ];

        $this->assertSame(1.2, $this->matcher()->multiplierFor($rules, 'vmess', 1, strtotime('2026-10-05 20:30:00')));
        $this->assertSame(1.8, $this->matcher()->multiplierFor($rules, 'vmess', 1, strtotime('2026-10-05 21:30:00')));
    }

    public function testDisabledRulesAreIgnored(): void
    {
        $rules = [$this->rule(['enabled' => 0])];

        $this->assertSame(1.0, $this->matcher()->multiplierFor($rules, 'vmess', 1, strtotime('2026-10-05 12:00:00')));
    }

    public function testWindowCanCrossMidnight(): void
    {
        // 23:00 - 02:00，也就是 start > end
        $rules = [$this->rule(['start_minute' => 23 * 60, 'end_minute' => 2 * 60])];

        $this->assertSame(1.5, $this->matcher()->multiplierFor($rules, 'vmess', 1, strtotime('2026-10-05 23:30:00')));
        $this->assertSame(1.5, $this->matcher()->multiplierFor($rules, 'vmess', 1, strtotime('2026-10-06 01:00:00')));
        $this->assertSame(1.0, $this->matcher()->multiplierFor($rules, 'vmess', 1, strtotime('2026-10-06 12:00:00')));
    }

    public function testGlobalMultiplierIgnoresNodeRules(): void
    {
        $rules = [
            $this->rule(['id' => 1, 'multiplier' => 1.25]),
            $this->rule([
                'id' => 2,
                'scope' => RateRuleMatcher::SCOPE_NODE,
                'node_type' => 'vmess',
                'node_id' => 1,
                'multiplier' => 9.0
            ])
        ];

        $this->assertSame(1.25, $this->matcher()->globalMultiplier($rules, strtotime('2026-10-05 12:00:00')));
    }

    public function testMatchesAtReportsEveryRuleThatFires(): void
    {
        $rules = [
            $this->rule(['id' => 1, 'start_minute' => 0, 'end_minute' => RateRuleMatcher::DAY_MINUTES]),
            $this->rule(['id' => 2, 'enabled' => 0]),
            $this->rule(['id' => 3, 'scope' => RateRuleMatcher::SCOPE_NODE, 'node_type' => 'vmess', 'node_id' => 5])
        ];

        $matched = $this->matcher()->matchesAt($rules, 'vmess', 5, strtotime('2026-10-05 12:00:00'));
        $this->assertSame([1, 3], array_column($matched, 'id'));

        $matched = $this->matcher()->matchesAt($rules, 'vmess', 6, strtotime('2026-10-05 12:00:00'));
        $this->assertSame([1], array_column($matched, 'id'));
    }
}
