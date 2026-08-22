<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\TrafficRewardService;
use Illuminate\Http\Request;
use RuntimeException;

class RewardController extends Controller
{
    public function checkinStatus(Request $request)
    {
        return response(['data' => (new TrafficRewardService())->checkinStatus(\App\Models\User::findOrFail($request->user['id']))]);
    }

    public function settings(Request $request)
    {
        return response(['data' => (new TrafficRewardService())->gameSettings(\App\Models\User::findOrFail($request->user['id']))]);
    }

    public function saveSettings(Request $request)
    {
        try {
            $data = $request->validate([
                'dice_bet_gb' => 'required|integer|min:1|max:10',
                'slots_bet_gb' => 'required|integer|min:1|max:10',
                'poker_bet_gb' => 'required|integer|min:1|max:10',
            ]);
            return response(['data' => (new TrafficRewardService())->saveGameSettings(\App\Models\User::findOrFail($request->user['id']), $data)]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function checkin(Request $request)
    {
        return $this->runReward(function () use ($request) {
            return (new TrafficRewardService())->checkin(\App\Models\User::findOrFail($request->user['id']));
        });
    }

    public function dice(Request $request)
    {
        return $this->runReward(function () use ($request) {
            return (new TrafficRewardService())->playDice(\App\Models\User::findOrFail($request->user['id']), 'web', $this->requestId($request));
        });
    }

    public function slots(Request $request)
    {
        return $this->runReward(function () use ($request) {
            return (new TrafficRewardService())->playSlots(\App\Models\User::findOrFail($request->user['id']), 'web', $this->requestId($request));
        });
    }

    private function runReward(callable $action)
    {
        try {
            return response(['data' => $action()]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
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
