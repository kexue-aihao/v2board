<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\TelegramRegistrationService;
use App\Services\TelegramRewardService;

class Start extends Telegram
{
    public $command = '/start';
    public $description = '打开娱乐中心';

    public function handle($message, $match = [])
    {
        if (!in_array((string)($message->chat_type ?? ''), ['private', 'group', 'supergroup'], true)) return;
        $this->guideUnboundUser((int)$message->chat_id, (string)($message->chat_type ?? ''));
        (new TelegramRewardService($this->telegramService))->showMenu($message->chat_id, $message->telegram_user_id);
    }

    /**
     * 未绑定账号的用户先给一条去路说明 —— 注册入口收敛到机器人之后，/start 往往是新用户
     * 见到的第一条消息，把 /regedit 与 /login 讲清楚比让人自己猜要好。已绑定的用户不打扰。
     */
    private function guideUnboundUser(int $chatId, string $chatType): void
    {
        if ($chatType !== 'private' || $chatId === 0) return;
        if (!(new TelegramRegistrationService())->enabled()) return;
        if (User::where('telegram_id', $chatId)->exists()) return;

        $this->telegramService->sendMessage(
            $chatId,
            "你还没有绑定站点账号。\n"
            . "注册新账号：发送 /regedit\n"
            . "已有账号登录：发送 /login\n"
            . "绑定后可用 /get_traffic 查流量、/get_subscription 购买套餐、/resetpassword 找回密码。"
        );
    }
}
