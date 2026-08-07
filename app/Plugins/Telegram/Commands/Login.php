<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\TelegramLoginLinkService;

class Login extends Telegram
{
    public $command = '/login';
    public $description = '生成 60 秒有效的免密码登录链接';

    public function handle($message, $match = [])
    {
        if (!$message->is_private) {
            return;
        }

        $users = User::where('telegram_id', $message->chat_id)->limit(2)->get();
        $user = $users->count() === 1 ? $users->first() : null;
        if (!$user || $user->banned) {
            $this->telegramService->sendMessage($message->chat_id, '无法生成登录链接，请先绑定可用的网站账号。');
            return;
        }

        try {
            $url = (new TelegramLoginLinkService())->issue($user, (string)$message->chat_id, 'dashboard', true);
        } catch (\Throwable $exception) {
            report($exception);
            $this->telegramService->sendMessage($message->chat_id, '登录链接暂时无法生成，请稍后再试。');
            return;
        }

        $this->telegramService->sendMessage(
            $message->chat_id,
            "登录链接将在 60 秒后失效，且只能使用一次。请勿转发给他人：\n" . $url
        );
    }
}
