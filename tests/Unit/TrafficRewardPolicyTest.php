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
}
