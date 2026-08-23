<?php

namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Services\TelegramRewardService;

class Start extends Telegram
{
    public $command = '/start';
    public $description = '打开娱乐中心';

    public function handle($message, $match = [])
    {
        if (!$message->is_private) return;
        (new TelegramRewardService($this->telegramService))->showMenu($message->chat_id, $message->telegram_user_id);
    }
}
