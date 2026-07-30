<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TelegramBindingService;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function getBotInfo()
    {
        $telegramService = new TelegramService();
        $response = $telegramService->getMe();
        return response([
            'data' => [
                'username' => $response->result->username
            ]
        ]);
    }

    public function unbind(Request $request)
    {
        $user = User::where('user_id', $request->user['id'])->first();
    }

    public function binding(Request $request)
    {
        $user = User::findOrFail($request->user['id']);
        $service = new TelegramBindingService();
        $binding = $service->latestForUser($user);
        return response(['data' => [
            'enabled' => $service->enabled() && $service->available(),
            'chat_id' => $service->chatId(),
            'binding' => $binding ? [
                'id' => (int)$binding->id,
                'subscription_id' => (int)$binding->subscription_id,
                'telegram_user_id' => (string)$binding->telegram_user_id,
                'telegram_username' => $binding->telegram_username,
                'status' => $binding->status,
                'invalid_reason' => $binding->invalid_reason,
                'bound_at' => $binding->bound_at,
                'last_checked_at' => $binding->last_checked_at
            ] : null
        ]]);
    }

    public function prepareBinding(Request $request)
    {
        $user = User::findOrFail($request->user['id']);
        $subscription = Subscription::where('id', (int)$request->input('subscription_id'))
            ->where('user_id', $user->id)->first();
        if (!$subscription) abort(404, 'Subscription does not exist');
        return response(['data' => (new TelegramBindingService())->prepare($user, $subscription)]);
    }

    public function revokeBinding(Request $request)
    {
        $user = User::findOrFail($request->user['id']);
        return response(['data' => (new TelegramBindingService())->revoke($user)]);
    }
}
