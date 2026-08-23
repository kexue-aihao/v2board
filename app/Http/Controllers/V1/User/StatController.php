<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\StatUser;
use App\Models\TrafficRewardLog;
use App\Models\User;
use App\Services\ResellerSharedSubscriptionService;
use App\Services\TrafficRewardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatController extends Controller
{
    public function getTrafficLog(Request $request)
    {
        $user = User::find($request->user['id']);
        if ($user && (new ResellerSharedSubscriptionService())->hasActiveMembership($user)) {
            return response(['data' => [], 'shared_subscription' => true, 'traffic_log_available' => false]);
        }
        $builder = StatUser::select([
            'u',
            'd',
            'record_at',
            'user_id',
            'server_rate'
        ])
            ->where('user_id', $request->user['id'])
            ->where('record_at', '>=', strtotime(date('Y-m-1')))
            ->orderBy('record_at', 'DESC');
        $traffic = $builder->get()->map(function ($row) {
            $row->record_type = 'traffic';
            $row->reward_label = null;
            $row->increase_bytes = 0;
            $row->deducted_bytes = (int)round(((int)$row->u + (int)$row->d) * (float)$row->server_rate);
            return $row;
        });
        $rewards = TrafficRewardLog::where('user_id', $request->user['id'])
            ->where('created_at', '>=', strtotime(date('Y-m-1')))
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($row) {
                $metadata = (array)$row->metadata;
                $label = $this->rewardLabel((string)$row->source, $metadata);
                $change = TrafficRewardService::splitTrafficChange((int)$row->reward_bytes);
                return [
                    // Signature's restored stock traffic table only renders
                    // u/d. Mirror the signed ledger change here so check-in
                    // and game records remain visible without altering theme
                    // assets or injecting a reward-specific view.
                    'u' => $change['increase_bytes'],
                    'd' => $change['deducted_bytes'],
                    'record_at' => (int)$row->getRawOriginal('created_at'),
                    'user_id' => (int)$row->user_id,
                    'server_rate' => 1,
                    'record_type' => 'reward',
                    'reward_label' => $label,
                    'reward_bytes' => (int)$row->reward_bytes,
                    'entrypoint' => (string)($row->entrypoint ?: ($metadata['entrypoint'] ?? '')),
                    'reward_metadata' => $metadata,
                    'reward_detail' => $this->rewardDetail((string)$row->source, $metadata, (int)$row->reward_bytes),
                    'bet_gb' => $metadata['bet_gb'] ?? null,
                    'payout_gb' => $metadata['payout_gb'] ?? ($metadata['reward_gb'] ?? null),
                    'win_probability' => $metadata['win_probability'] ?? null,
                    'payout_multiplier' => $metadata['payout_multiplier'] ?? null,
                    'net_bytes' => (int)($metadata['net_bytes'] ?? $row->reward_bytes),
                    'increase_bytes' => $change['increase_bytes'],
                    'deducted_bytes' => $change['deducted_bytes'],
                ];
            });
        return response(['data' => $traffic->concat($rewards)->sortByDesc('record_at')->values()]);
    }

    private function rewardLabel(string $source, array $metadata): string
    {
        if ($source === 'checkin') return '每日签到';
        return ['dice' => '骰子娱乐', 'slots' => '老虎机娱乐', 'poker' => '炸金花娱乐'][$metadata['game'] ?? ''] ?? '游戏娱乐';
    }

    private function rewardDetail(string $source, array $metadata, int $rewardBytes): string
    {
        $entrypoint = (string)($metadata['entrypoint'] ?? '');
        $netBytes = (int)($metadata['net_bytes'] ?? $rewardBytes);
        $netGb = round($netBytes / TrafficRewardService::GB, 2);
        if ($source === 'checkin') {
            $rewardGb = $metadata['reward_gb'] ?? round($rewardBytes / TrafficRewardService::GB, 2);
            return "签到奖励 {$rewardGb} GB；入口 {$entrypoint}；净变化 {$netGb} GB";
        }
        $result = $metadata['result'] ?? (isset($metadata['hands']) ? '群组牌局' : '');
        if (is_array($result)) $result = implode(' | ', $result);
        $outcome = !empty($metadata['won']) ? '中奖' : '未中奖';
        return "{$outcome}；入口 {$entrypoint}；结果 {$result}；押注 " . ($metadata['bet_gb'] ?? 0) . " GB；概率 " . ($metadata['win_probability'] ?? '-') . "%；倍率 " . ($metadata['payout_multiplier'] ?? '-') . "；返还 " . ($metadata['payout_gb'] ?? 0) . " GB；净变化 {$netGb} GB";
    }
}
