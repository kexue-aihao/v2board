<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\TelegramRegistrationService;

class Regedit extends Telegram
{
    public $command = '/regedit';
    public $description = '注册账号：提交一个邮箱作为登录账号';

    public function handle($message, $match = [])
    {
        if (!$message->is_private) {
            $this->telegramService->sendMessage($message->chat_id, '请在机器人的私聊中完成注册。');
            return;
        }

        $service = new TelegramRegistrationService();
        if (!$service->enabled()) {
            $this->telegramService->sendMessage($message->chat_id, '注册功能尚未开启，请联系管理员。');
            return;
        }
        if (User::where('telegram_id', $message->chat_id)->exists()) {
            $this->telegramService->sendMessage(
                $message->chat_id,
                '该 Telegram 已经绑定过账号。直接发送 /login 即可生成免密登录链接。'
            );
            return;
        }

        $service->startSession((int)$message->chat_id);
        $this->telegramService->sendMessage(
            $message->chat_id,
            "请直接在对话里发送一个邮箱，作为你的登录账号。\n"
            . "虚拟邮箱也可以（例如 name@example.com），它只用来标识账号，不会收到邮件。"
        );
    }
}
