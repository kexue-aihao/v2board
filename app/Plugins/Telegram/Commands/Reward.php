<?php

namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Services\TelegramRewardService;

class Reward extends Telegram
{
    public $command = '/reward';
    public $description = '打开娱乐中心';

    public function handle($message, $match = [])
    {
        if (!in_array((string)($message->chat_type ?? ''), ['private', 'group', 'supergroup'], true)) {
            $this->telegramService->sendMessage($message->chat_id, '请在私聊或群组中使用娱乐功能。');
            return;
        }
        (new TelegramRewardService($this->telegramService))->showMenu($message->chat_id, $message->telegram_user_id);
    }
}
