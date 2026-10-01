<?php

namespace Tests\Unit;

use App\Services\RiskRuleService;
use PHPUnit\Framework\TestCase;

/**
 * 风险值（百分比）的计分口径：命中规则权重累加、封顶 100，缺权重按默认值。
 * 纯逻辑测试，不起应用、不连数据库 —— useRules() 注入的规则集不走规则表读取。
 */
class RiskRuleScoreTest extends TestCase
{
    private function service(array $rules): RiskRuleService
    {
        $service = new RiskRuleService();
        $service->useRules($rules);

        return $service;
    }

    private function rule(string $dimension, float $threshold, ?int $weight, string $operator = '>'): array
    {
        $rule = [
            'id' => 1,
            'label' => $dimension,
            'dimension' => $dimension,
            'operator' => $operator,
            'threshold' => $threshold
        ];
        // weight 允许缺席：老快照（跨部署残留的游标状态）里没有这一项。
        if ($weight !== null) {
            $rule['weight'] = $weight;
        }

        return $rule;
    }

    public function testWeightAccumulatesAndCapsAtOneHundred(): void
    {
        $service = $this->service([
            $this->rule('user_agent_count', 3, 40),
            $this->rule('region_count', 3, 40, '>='),
            $this->rule('city_count', 3, 40, '>=')
        ]);

        $result = $service->evaluate(['user_agent_count' => 4, 'region_count' => 5, 'city_count' => 6]);

        $this->assertTrue($result['has_risk']);
        $this->assertCount(3, $result['fired']);
        $this->assertSame(100, $result['score'], '三条 40 分累加应封顶 100');
        $this->assertSame(40, $result['fired'][0]['weight'], 'fired 明细要带上权重，便于事后核对分数');
    }

    public function testSingleHitScoresItsOwnWeight(): void
    {
        $service = $this->service([
            $this->rule('user_agent_count', 3, 30),
            $this->rule('city_count', 3, 30, '>=')
        ]);

        $result = $service->evaluate(['user_agent_count' => 4, 'city_count' => 1]);

        $this->assertSame(30, $result['score']);
    }

    public function testZeroWeightMarksWithoutScoring(): void
    {
        $service = $this->service([$this->rule('city_count', 3, 0, '>=')]);

        $result = $service->evaluate(['city_count' => 9]);

        $this->assertTrue($result['has_risk'], '权重 0 仍然命中（只标记不计分）');
        $this->assertNotEmpty($result['reasons']);
        $this->assertSame(0, $result['score']);
    }

    public function testMissingWeightFallsBackToDefault(): void
    {
        $service = $this->service([$this->rule('city_count', 3, null, '>=')]);

        $result = $service->evaluate(['city_count' => 5]);

        $this->assertSame(RiskRuleService::DEFAULT_RULE_WEIGHT, $result['score']);
        $this->assertSame(RiskRuleService::DEFAULT_RULE_WEIGHT, $result['fired'][0]['weight']);
    }

    public function testWeightIsClampedToZeroAndOneHundred(): void
    {
        $high = $this->service([$this->rule('city_count', 3, 250, '>=')]);
        $this->assertSame(100, $high->evaluate(['city_count' => 5])['score']);

        $negative = $this->service([$this->rule('city_count', 3, -7, '>=')]);
        $this->assertSame(0, $negative->evaluate(['city_count' => 5])['score']);
    }

    public function testMissingMetricNeitherFiresNorScores(): void
    {
        $service = $this->service([$this->rule('used_ratio', 0.4, 50, '<')]);

        $result = $service->evaluate(['used_ratio' => null]);

        $this->assertFalse($result['has_risk'], '没有依据不等于命中');
        $this->assertSame(0, $result['score']);
        $this->assertSame([], $result['fired']);
    }

    public function testDefaultRulesAddUpToTheAlertThreshold(): void
    {
        // 三条内置默认规则各 20 分，全中正好 60% —— 与 risk_notify_threshold 的默认值对齐。
        $service = $this->service([
            $this->rule('user_agent_count', 3, RiskRuleService::DEFAULT_RULE_WEIGHT),
            $this->rule('region_count', 3, RiskRuleService::DEFAULT_RULE_WEIGHT, '>='),
            $this->rule('city_count', 3, RiskRuleService::DEFAULT_RULE_WEIGHT, '>=')
        ]);

        $result = $service->evaluate(['user_agent_count' => 4, 'region_count' => 4, 'city_count' => 4]);

        $this->assertSame(60, $result['score']);
    }
}
