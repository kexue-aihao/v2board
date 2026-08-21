<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\StatUser;
use App\Models\TrafficRewardLog;
use App\Models\User;
use App\Services\ResellerSharedSubscriptionService;
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
            return $row;
        });
        $rewards = TrafficRewardLog::where('user_id', $request->user['id'])
            ->where('created_at', '>=', strtotime(date('Y-m-1')))
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($row) {
                $metadata = (array)$row->metadata;
                $label = $row->source === 'checkin' ? '每日签到' : (($metadata['game'] ?? '') === 'slots' ? '老虎机娱乐' : (($metadata['game'] ?? '') === 'poker' ? '炸金花娱乐' : '骰子娱乐'));
                return [
                    'u' => 0,
                    'd' => (int)$row->reward_bytes,
                    'record_at' => (int)$row->getRawOriginal('created_at'),
                    'user_id' => (int)$row->user_id,
                    'server_rate' => 1,
                    'record_type' => 'reward',
                    'reward_label' => $label,
                ];
            });
        return response(['data' => $traffic->concat($rewards)->sortByDesc('record_at')->values()]);
    }
}
