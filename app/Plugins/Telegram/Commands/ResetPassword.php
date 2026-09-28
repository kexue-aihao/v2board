<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\TelegramPasswordResetService;

class ResetPassword extends Telegram
{
    public $command = '/resetpassword';
    public $description = '找回密码：向本对话发送验证码';

    public function handle($message, $match = [])
    {
        if (!$message->is_private) {
            $this->telegramService->sendMessage($message->chat_id, '请在机器人的私聊里使用本命令。');
            return;
        }

        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $this->telegramService->sendMessage(
                $message->chat_id,
                "本 Telegram 还没有绑定账号。\n"
                . "还没有账号：发送 /regedit 注册。\n"
                . "已有账号：发送 /login 生成免密登录链接。"
            );
            return;
        }

        $result = (new TelegramPasswordResetService())->issue($user);
        $this->telegramService->sendMessage($message->chat_id, $result['message']);
    }
}
