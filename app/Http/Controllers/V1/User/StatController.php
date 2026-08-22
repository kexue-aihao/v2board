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
                $label = $row->source === 'checkin' ? '每日签到' : (($metadata['game'] ?? '') === 'slots' ? '老虎机娱乐' : (($metadata['game'] ?? '') === 'poker' ? '炸金花娱乐' : '骰子娱乐'));
                $change = TrafficRewardService::splitTrafficChange((int)$row->reward_bytes);
                return [
                    'u' => 0,
                    'd' => 0,
                    'record_at' => (int)$row->getRawOriginal('created_at'),
                    'user_id' => (int)$row->user_id,
                    'server_rate' => 1,
                    'record_type' => 'reward',
                    'reward_label' => $label,
                    'reward_bytes' => (int)$row->reward_bytes,
                    'increase_bytes' => $change['increase_bytes'],
                    'deducted_bytes' => $change['deducted_bytes'],
                ];
            });
        return response(['data' => $traffic->concat($rewards)->sortByDesc('record_at')->values()]);
    }
}
