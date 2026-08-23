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
        if ($message->is_private) {
            (new TelegramRewardService($this->telegramService))->showMenu($message->chat_id, $message->telegram_user_id);
            return;
        }
        if (in_array((string)($message->chat_type ?? ''), ['group', 'supergroup'], true)) {
            $this->telegramService->sendMessage(
                $message->chat_id,
                "群组娱乐仅支持多人炸金花。\n发送 /poker 加入牌局；至少两人加入后，任意已加入玩家发送 /poker start 开始。"
            );
            return;
        }
        $this->telegramService->sendMessage($message->chat_id, '请在私聊或群组中使用娱乐功能。');
    }
}
