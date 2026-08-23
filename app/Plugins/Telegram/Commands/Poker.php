<?php
namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Services\TelegramRewardService;

class Poker extends Telegram
{
    public $command = '/poker';
    public $description = '群组炸金花';

    public function handle($message, $match = [])
    {
        $rewards = new TelegramRewardService($this->telegramService);
        if ($message->is_private) {
            $rewards->showGame($message->chat_id, $message->telegram_user_id, 'poker');
            return;
        }
        if (!in_array((string)($message->chat_type ?? ''), ['group', 'supergroup'], true)) {
            $this->telegramService->sendMessage($message->chat_id, '请在私聊或群组中使用炸金花。');
            return;
        }

        $action = strtolower(trim((string)($message->args[0] ?? '')));
        if ($action !== '' && $action !== 'start') {
            $this->telegramService->sendMessage($message->chat_id, '群组用法：/poker 加入牌局，/poker start 开始牌局。');
            return;
        }
        $rewards->playGroupPoker($message->chat_id, $message->telegram_user_id, $action === 'start');
    }
}
