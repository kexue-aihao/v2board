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
        if (!in_array((string)($message->chat_type ?? ''), ['private', 'group', 'supergroup'], true)) return;
        (new TelegramRewardService($this->telegramService))->showMenu($message->chat_id, $message->telegram_user_id);
    }
}
