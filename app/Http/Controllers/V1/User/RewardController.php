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

    public function poker(Request $request)
    {
        $action = (string)$request->input('action', 'join');
        if (!in_array($action, ['create', 'join', 'start'], true)) abort(422, 'Invalid poker action');
        return response(['data' => (new TrafficRewardService())->playPoker(\App\Models\User::findOrFail($request->user['id']), (string)$request->input('chat_id', 'web'), $action, 'web')]);
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
