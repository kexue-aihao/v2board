<?php

namespace App\Services;

/**
 * 带宽峰值状态机：把「每分钟一个 bps 样本」推进成「这一分钟该不该叠加倍率」。
 *
 * 纯函数（喂进去一串样本就能断言倍率时间线），不读库也不写 Redis。
 *
 * 判定口径（对应需求里的「瞬时大流量豁免、长时间峰值才叠加」）：
 *   - bps 超过瞬时阈值 → 记一次突发；突发连续不超过 BURST_EXEMPT_MINUTES 分钟时，
 *     这一分钟算「一次性大流量」，不进叠加计数（用户偶尔下个大文件不该被加倍收费）。
 *   - 突发持续超过豁免时长，就不再是瞬时了，开始按持续高带宽计数 —— 连续跑满十分钟
 *     和「一口气下完就走」必须区别对待，否则挂机跑量的用户永远豁免。
 *   - bps 在持续阈值之上 → 叠加计数 +1。
 *   - 低于持续阈值 → 计数按 DECAY_STEP 回落，避免「一天里零散高几分钟」无限累积。
 *   - 计数达到 STACK_MINUTES → 叠加倍率。
 *
 * 采样是分钟粒度（节点上报间隔默认 60 秒），所以叠加最长有 1 分钟延迟 —— 这一分钟的
 * 流量按上一轮定下的倍率计费。
 */
class RatePeakStateMachine
{
    public const STATE_NORMAL = 'normal';
    public const STATE_BURST = 'burst';
    public const STATE_HIGH = 'high';
    public const STATE_STACKED = 'stacked';

    public const DEFAULT_PARAMS = [
        'enabled' => 0,
        'instant_mbps' => 50.0,
        'sustained_mbps' => 10.0,
        'burst_exempt_minutes' => 3,
        'stack_minutes' => 5,
        'stack_multiplier' => 1.5,
        'decay_step' => 1
    ];

    /**
     * 推进一分钟。
     *
     * @param array<string, mixed> $state 上一轮的 {high, burst}
     * @param float $mbps 这一分钟的平均速率
     * @param array<string, mixed> $params 见 DEFAULT_PARAMS
     * @return array{high: int, burst: int, multiplier: float, state: string}
     */
    public function advance(array $state, float $mbps, array $params = []): array
    {
        $params = $this->normalizeParams($params);
        $high = max(0, (int) ($state['high'] ?? 0));
        $burst = max(0, (int) ($state['burst'] ?? 0));

        if ($mbps > $params['instant_mbps']) {
            $burst++;
        } else {
            $burst = 0;
        }

        if ($mbps > $params['instant_mbps'] && $burst <= $params['burst_exempt_minutes']) {
            // 突发豁免期内：不推进也不清零叠加计数，等这一波过去再谈。
            $label = self::STATE_BURST;
        } elseif ($mbps > $params['sustained_mbps']) {
            $high++;
            $label = self::STATE_HIGH;
        } else {
            $high = max(0, $high - $params['decay_step']);
            $label = self::STATE_NORMAL;
        }

        $stacked = $high >= $params['stack_minutes'];
        if ($stacked) {
            $label = self::STATE_STACKED;
        }

        return [
            'high' => $high,
            'burst' => $burst,
            // 两位小数：v2_stat_user.server_rate 是 decimal(10,2)，账面上乘进去的倍率与
            // 统计里记下的倍率必须逐位一致，否则用户的流量日志会对不上扣费。
            'multiplier' => $stacked ? round((float) $params['stack_multiplier'], 2) : 1.0,
            // 总开关关掉时仍然推进状态（管理页要能看到「如果开了会怎样」），但倍率恒为 1。
            'state' => $label,
            'applied' => $stacked && (int) $params['enabled'] === 1
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function normalizeParams(array $params): array
    {
        $merged = array_merge(self::DEFAULT_PARAMS, $params);

        return [
            'enabled' => (int) ($merged['enabled'] ?? 0) === 1 ? 1 : 0,
            'instant_mbps' => max(0.0, (float) $merged['instant_mbps']),
            'sustained_mbps' => max(0.0, (float) $merged['sustained_mbps']),
            'burst_exempt_minutes' => max(0, (int) $merged['burst_exempt_minutes']),
            'stack_minutes' => max(1, (int) $merged['stack_minutes']),
            'stack_multiplier' => max(1.0, (float) $merged['stack_multiplier']),
            'decay_step' => max(1, (int) $merged['decay_step'])
        ];
    }

    /**
     * 每分钟字节数换算成 Mbps。采样间隔由调用方给出（两次 tick 的实际间隔），
     * 写死 60 会在调度抖动时把速率算歪。
     */
    public function bytesToMbps(int $bytes, int $elapsedSeconds): float
    {
        if ($elapsedSeconds <= 0) {
            return 0.0;
        }

        return $bytes * 8 / $elapsedSeconds / 1000000;
    }
}
