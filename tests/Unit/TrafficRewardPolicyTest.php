<?php

namespace Tests\Unit;

use App\Services\TrafficRewardService;
use Tests\TestCase;

class TrafficRewardPolicyTest extends TestCase
{
    public function testRewardConstantsUseBytesAndTenGbMaximum(): void
    {
        $this->assertSame(1073741824, TrafficRewardService::GB);
        $this->assertSame(1, TrafficRewardService::MIN_GB);
        $this->assertSame(10, TrafficRewardService::MAX_GB);
    }

    public function testRewardConfigurationIsClampedToOneThroughTenGb(): void
    {
        $this->assertSame(1, TrafficRewardService::normalizeRewardGb(0));
        $this->assertSame(10, TrafficRewardService::normalizeRewardGb(99));
        $this->assertSame(5, TrafficRewardService::normalizeRewardGb('5'));
        $this->assertSame(3, TrafficRewardService::normalizeRewardGb('invalid', 3));
    }

    public function testTrafficChangesAreSplitIntoIncreaseAndDeduction(): void
    {
        $this->assertSame(
            ['increase_bytes' => TrafficRewardService::GB, 'deducted_bytes' => 0],
            TrafficRewardService::splitTrafficChange(TrafficRewardService::GB)
        );
        $this->assertSame(
            ['increase_bytes' => 0, 'deducted_bytes' => TrafficRewardService::GB],
            TrafficRewardService::splitTrafficChange(-TrafficRewardService::GB)
        );
        $this->assertSame(
            ['increase_bytes' => 0, 'deducted_bytes' => 0],
            TrafficRewardService::splitTrafficChange(0)
        );
    }

    public function testGameWagersUseTheDedicatedMaximum(): void
    {
        $service = new \ReflectionClass(TrafficRewardService::class);
        $method = $service->getMethod('normalizeGameGb');
        $method->setAccessible(true);

        $this->assertSame(100, $method->invoke(null, 100));
        $this->assertSame(TrafficRewardService::MAX_GAME_GB, $method->invoke(null, 5000));
    }

    public function testGameProbabilityAndPayoutMultiplierAreIndependent(): void
    {
        config([
            'v2board.reward_dice_win_probability' => 100,
            'v2board.reward_dice_payout_multiplier' => '1.50',
            'v2board.reward_dice_win_face' => 6,
        ]);
        $service = new TrafficRewardService();
        $method = (new \ReflectionClass($service))->getMethod('gameSettlement');
        $method->setAccessible(true);

        $settlement = $method->invoke($service, 'dice', 6);

        $this->assertTrue($settlement['won']);
        $this->assertSame(1.5, $settlement['payout_gb']);
        $this->assertSame('1.50', $settlement['payout_multiplier']);
        $this->assertSame(100, $settlement['win_probability']);
    }

    public function testLossDoesNotReceiveABaseReward(): void
    {
        config([
            'v2board.reward_dice_win_probability' => 0,
            'v2board.reward_dice_payout_multiplier' => '100.00',
            'v2board.reward_dice_win_face' => 6,
        ]);
        $service = new TrafficRewardService();
        $method = (new \ReflectionClass($service))->getMethod('gameSettlement');
        $method->setAccessible(true);

        $settlement = $method->invoke($service, 'dice', 6);

        $this->assertFalse($settlement['won']);
        $this->assertSame(0, $settlement['payout_gb']);
    }

    public function testGameRulesExposeThePerGameDailyLimit(): void
    {
        config(['v2board.reward_dice_daily_limit' => 20]);
        $service = new TrafficRewardService();
        $method = (new \ReflectionClass($service))->getMethod('gameRule');
        $method->setAccessible(true);

        $this->assertSame(20, $method->invoke($service, 'dice')['daily_limit']);
    }
}
