<?php
namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Services\TelegramRewardService;

class Slots extends Telegram
{
    public $command = '/slots';
    public $description = '老虎机赢流量';

    public function handle($message, $match = [])
    {
        if (!in_array((string)($message->chat_type ?? ''), ['private', 'group', 'supergroup'], true)) {
            $this->telegramService->sendMessage($message->chat_id, '请在私聊或群组中使用娱乐功能。');
            return;
        }
        (new TelegramRewardService($this->telegramService))->showGame($message->chat_id, $message->telegram_user_id, 'slots');
    }
}
