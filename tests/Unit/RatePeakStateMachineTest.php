<?php

namespace Tests\Unit;

use App\Services\RatePeakStateMachine;
use Tests\TestCase;

/**
 * 峰值状态机的时间线。
 *
 * 这是整个动态倍率里最容易出事的一块（判早了叫乱扣费，判晚了叫形同虚设），所以用
 * 固定序列把它钉死：一次性大流量必须豁免、长时间高带宽必须叠加、回落必须能回到 1.0。
 */
class RatePeakStateMachineTest extends TestCase
{
    private const PARAMS = [
        'enabled' => 1,
        'instant_mbps' => 50,
        'sustained_mbps' => 10,
        'burst_exempt_minutes' => 3,
        'stack_minutes' => 5,
        'stack_multiplier' => 1.5,
        'decay_step' => 1
    ];

    private function timeline(array $sequence, array $params = self::PARAMS): array
    {
        $machine = new RatePeakStateMachine();
        $state = ['high' => 0, 'burst' => 0];
        $timeline = [];
        foreach ($sequence as $mbps) {
            $state = $machine->advance($state, (float) $mbps, $params);
            $timeline[] = $state;
        }

        return $timeline;
    }

    public function testTwoMinuteBurstIsNeverStacked(): void
    {
        $timeline = $this->timeline([100, 100, 0, 0, 0, 0]);

        foreach ($timeline as $index => $step) {
            $this->assertSame(1.0, $step['multiplier'], '第 ' . ($index + 1) . ' 分钟不该叠加');
        }
        // 突发期间不进持续计数，所以一轮下来计数仍是 0
        $this->assertSame(0, $timeline[1]['high']);
        $this->assertSame(RatePeakStateMachine::STATE_BURST, $timeline[1]['state']);
    }

    public function testSustainedBurstStopsBeingExemptAfterTheGraceWindow(): void
    {
        $timeline = $this->timeline(array_fill(0, 10, 100));

        // 前 3 分钟算突发，第 4 分钟起才开始计入持续
        $this->assertSame(0, $timeline[2]['high']);
        $this->assertSame(1, $timeline[3]['high']);
        // 持续计数到 5 时开始叠加 → 第 4 + 5 - 1 = 8 分钟
        $this->assertSame(1.0, $timeline[6]['multiplier']);
        $this->assertSame(1.5, $timeline[7]['multiplier']);
        $this->assertSame(RatePeakStateMachine::STATE_STACKED, $timeline[7]['state']);
    }

    public function testSteadyModerateBandwidthStacksAfterFiveMinutes(): void
    {
        $timeline = $this->timeline([20, 20, 20, 20, 20, 20]);

        $this->assertSame(1.0, $timeline[3]['multiplier']);
        $this->assertSame(1.5, $timeline[4]['multiplier']);
    }

    public function testFourMinutesOfModerateBandwidthIsNotEnough(): void
    {
        $timeline = $this->timeline([20, 20, 20, 20]);

        $this->assertSame(4, $timeline[3]['high']);
        $this->assertSame(1.0, $timeline[3]['multiplier']);
    }

    public function testDecayBringsTheUserBackToNormal(): void
    {
        $timeline = $this->timeline([20, 20, 20, 20, 20, 0, 0]);
        $this->assertSame(1.5, $timeline[4]['multiplier']);

        // 掉到阈值以下就按 decay_step 回落，5 分制的计数一步就跌破门槛
        $this->assertSame(4, $timeline[5]['high']);
        $this->assertSame(1.0, $timeline[5]['multiplier']);
        $this->assertSame(RatePeakStateMachine::STATE_NORMAL, $timeline[5]['state']);
    }

    public function testDisabledSwitchTracksStateButNeverCharges(): void
    {
        $timeline = $this->timeline([20, 20, 20, 20, 20, 20], array_merge(self::PARAMS, ['enabled' => 0]));

        $last = end($timeline);
        // 返回理论倍率供观察；调用方只有 applied 为 true 时才采用它。
        $this->assertSame(RatePeakStateMachine::STATE_STACKED, $last['state']);
        $this->assertSame(1.5, $last['multiplier']);
        $this->assertFalse($last['applied']);
    }

    public function testBytesPerSecondBecomesMbpsAgainstTheRealSampleWindow(): void
    {
        $machine = new RatePeakStateMachine();

        // 60 秒里跑了 75 MB（629145600 位）→ 10.48576 Mbps
        $this->assertSame(10.48576, round($machine->bytesToMbps(78643200, 60), 5));
        // 采样窗口写死 60 会把「实际隔了 30 秒」的速率算成一半
        $this->assertSame(20.97152, round($machine->bytesToMbps(78643200, 30), 5));
        $this->assertSame(0.0, $machine->bytesToMbps(78643200, 0));
    }

    public function testParamsAreClamped(): void
    {
        $machine = new RatePeakStateMachine();
        $params = $machine->normalizeParams([
            'enabled' => 2,
            'instant_mbps' => -5,
            'sustained_mbps' => -1,
            'burst_exempt_minutes' => -3,
            'stack_minutes' => 0,
            'stack_multiplier' => 0.5,
            'decay_step' => 0
        ]);

        $this->assertSame(0, $params['enabled']);
        $this->assertSame(0.0, $params['instant_mbps']);
        $this->assertSame(0.0, $params['sustained_mbps']);
        $this->assertSame(0, $params['burst_exempt_minutes']);
        // 门槛最低 1 分钟、叠加倍率最低 1 倍、回落至少 1 —— 否则状态机会卡死或倒扣
        $this->assertSame(1, $params['stack_minutes']);
        $this->assertSame(1.0, $params['stack_multiplier']);
        $this->assertSame(1, $params['decay_step']);
    }

    public function testMultiplierIsRoundedToTwoDecimals(): void
    {
        $timeline = $this->timeline([20, 20, 20, 20, 20], array_merge(self::PARAMS, ['stack_multiplier' => 1.337]));

        // v2_stat_user.server_rate 是 decimal(10,2)：账面上乘的倍率必须与统计记的一致
        $this->assertSame(1.34, end($timeline)['multiplier']);
    }
}
