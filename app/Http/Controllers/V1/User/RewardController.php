<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\TrafficRewardService;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    public function checkinStatus(Request $request)
    {
        return response(['data' => (new TrafficRewardService())->checkinStatus(\App\Models\User::findOrFail($request->user['id']))]);
    }

    public function checkin(Request $request)
    {
        return response(['data' => (new TrafficRewardService())->checkin(\App\Models\User::findOrFail($request->user['id']))]);
    }

    public function dice(Request $request)
    {
        return response(['data' => (new TrafficRewardService())->playDice(\App\Models\User::findOrFail($request->user['id']), 'web', $this->requestId($request))]);
    }

    public function slots(Request $request)
    {
        return response(['data' => (new TrafficRewardService())->playSlots(\App\Models\User::findOrFail($request->user['id']), 'web', $this->requestId($request))]);
    }

    private function requestId(Request $request): ?string
    {
        return $request->header('Idempotency-Key') ?: $request->input('request_id');
    }

    public function history(Request $request)
    {
        $rows = \App\Models\TrafficRewardLog::where('user_id', $request->user['id'])->orderByDesc('id')->limit(100)->get();
        return response(['data' => $rows]);
    }
}
