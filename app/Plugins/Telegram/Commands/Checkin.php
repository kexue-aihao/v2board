<?php
namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Services\TelegramRewardService;

class Checkin extends Telegram
{
    public $command = '/checkin';
    public $description = '每日签到领取流量';

    public function handle($message, $match = [])
    {
        if (!$message->is_private) { $this->telegramService->sendMessage($message->chat_id, '请在私聊机器人中使用娱乐功能。'); return; }
        (new TelegramRewardService($this->telegramService))->checkin($message->chat_id, $message->telegram_user_id);
    }
}
