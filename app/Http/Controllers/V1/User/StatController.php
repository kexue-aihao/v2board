<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\StatUser;
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
        return response([
            'data' => $builder->get()
        ]);
    }
}
