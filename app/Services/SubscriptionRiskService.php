<?php

namespace App\Services;

use App\Models\StatUser;
use App\Models\SubscribeRequestLog;
use App\Models\Subscription;
use App\Models\SubscriptionRiskCycle;
use Illuminate\Support\Facades\Schema;

class SubscriptionRiskService
{
    public const CYCLE_SECONDS = 30 * 86400;
    private $availability;

    public function available(): bool
    {
        if ($this->availability !== null) {
            return $this->availability;
        }
        return $this->availability = Schema::hasTable('v2_subscription')
            && Schema::hasTable('v2_subscription_risk_cycle')
            && Schema::hasTable('v2_subscribe_request_log')
            && Schema::hasTable('v2_stat_user')
            && Schema::hasColumn('v2_stat_user', 'subscription_id');
    }

    public function evaluateCompletedCycles(Subscription $subscription, ?int $now = null): array
    {
        if (!$this->available() || !$subscription->started_at) {
            return [];
        }

        $now = $now ?: time();
        $startedAt = (int)$subscription->started_at;
        if ($startedAt <= 0 || $startedAt >= $now) {
            return [];
        }

        $completedCycles = intdiv($now - $startedAt, self::CYCLE_SECONDS);
        $results = [];
        for ($cycle = 0; $cycle < $completedCycles; $cycle++) {
            $cycleStart = $startedAt + ($cycle * self::CYCLE_SECONDS);
            $cycleEnd = $cycleStart + self::CYCLE_SECONDS;
            $results[] = $this->evaluateCycle($subscription, $cycleStart, $cycleEnd);
        }
        return $results;
    }

    public function evaluateCycle(Subscription $subscription, int $cycleStart, int $cycleEnd): SubscriptionRiskCycle
    {
        $traffic = StatUser::where('subscription_id', $subscription->id)
            ->where('record_at', '>=', $cycleStart)
            ->where('record_at', '<', $cycleEnd)
            ->selectRaw('COALESCE(SUM(u + d), 0) AS used_traffic, COUNT(*) AS sample_count')
            ->first();

        $usedTraffic = (int)($traffic->used_traffic ?? 0);
        $sampleCount = (int)($traffic->sample_count ?? 0);
        $transferEnable = max(0, (int)$subscription->transfer_enable);
        $userAgentCount = (int)SubscribeRequestLog::where('subscription_id', $subscription->id)
            ->where('requested_at', '>=', $cycleStart)
            ->where('requested_at', '<', $cycleEnd)
            ->selectRaw('COUNT(DISTINCT ua_hash) AS count')
            ->value('count');

        $status = 'pending';
        $reasons = [];
        $ratio = null;
        if ($transferEnable > 0 && $sampleCount > 0) {
            $ratio = round($usedTraffic / $transferEnable, 8);
            if ($ratio < 0.4) {
                $reasons[] = '低流量使用率：' . round($ratio * 100, 2) . '%';
            }
            if ($userAgentCount > 3) {
                $reasons[] = '发现订阅 User-Agent：' . $userAgentCount . '种';
            }
            $status = count($reasons) > 0 ? 'suspicious' : 'normal';
        } elseif ($transferEnable <= 0) {
            $reasons[] = '套餐总流量无效';
        } else {
            $reasons[] = '历史流量统计数据不足';
        }

        $record = SubscriptionRiskCycle::firstOrNew([
            'subscription_id' => (int)$subscription->id,
            'cycle_start' => $cycleStart
        ]);
        $record->user_id = (int)$subscription->user_id;
        $record->cycle_end = $cycleEnd;
        $record->transfer_enable = $transferEnable;
        $record->used_traffic = $usedTraffic;
        $record->used_ratio = $ratio;
        $record->user_agent_count = $userAgentCount;
        $record->status = $status;
        $record->risk_reasons = json_encode($reasons, JSON_UNESCAPED_UNICODE);
        $record->evaluated_at = time();
        $record->save();
        return $record;
    }

    public function summaryForUser(int $userId): array
    {
        if (!$this->available()) {
            return [
                'status' => 'pending',
                'suspicious_count' => 0,
                'reasons' => []
            ];
        }

        $records = SubscriptionRiskCycle::where('user_id', $userId)
            ->orderByDesc('cycle_end')
            ->get();
        $latestBySubscription = [];
        foreach ($records as $record) {
            if (!isset($latestBySubscription[$record->subscription_id])) {
                $latestBySubscription[$record->subscription_id] = $record;
            }
        }

        $reasons = [];
        $suspiciousCount = 0;
        $hasPending = count($latestBySubscription) === 0;
        foreach (Subscription::where('user_id', $userId)->get(['id', 'started_at']) as $subscription) {
            $startedAt = (int)$subscription->started_at;
            if ($startedAt > 0 && $startedAt + self::CYCLE_SECONDS > time()) {
                $hasPending = true;
                break;
            }
            if ($startedAt > 0 && ((time() - $startedAt) % self::CYCLE_SECONDS) !== 0) {
                $hasPending = true;
            }
        }
        foreach ($latestBySubscription as $record) {
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
            'reasons' => array_values(array_unique($reasons))
        ];
    }

    public function cyclesForUser(int $userId, ?int $subscriptionId = null, ?int $cycleStart = null)
    {
        if (!$this->available()) {
            return collect();
        }
        $query = SubscriptionRiskCycle::where('user_id', $userId)->orderByDesc('cycle_end');
        if ($subscriptionId) {
            $query->where('subscription_id', $subscriptionId);
        }
        if ($cycleStart) {
            $query->where('cycle_start', $cycleStart);
        }
        return $query->get();
    }
}
