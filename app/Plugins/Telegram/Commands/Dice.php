<?php
namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Services\TelegramRewardService;

class Dice extends Telegram
{
    public $command = '/dice';
    public $description = '丢骰子赢流量';

    public function handle($message, $match = [])
    {
        if (!in_array((string)($message->chat_type ?? ''), ['private', 'group', 'supergroup'], true)) {
            $this->telegramService->sendMessage($message->chat_id, '请在私聊或群组中使用娱乐功能。');
            return;
        }

        $args = array_values(array_filter((array)($message->args ?? []), static function ($arg) {
            return trim((string)$arg) !== '';
        }));
        $rewards = new TelegramRewardService($this->telegramService);
        if (!$args) {
            $rewards->showGame($message->chat_id, $message->telegram_user_id, 'dice');
            return;
        }

        $guess = trim((string)$args[0]);
        if (count($args) !== 1 || !preg_match('/^[1-6]$/', $guess)) {
            $this->telegramService->sendMessage($message->chat_id, '用法：/dice 1-6，例如 /dice 2。');
            return;
        }

        $messageId = (int)($message->message_id ?? 0);
        $requestId = $messageId > 0
            ? 'telegram-command-' . $message->chat_id . '-' . $messageId
            : null;
        $rewards->playDiceWithGuess($message->chat_id, $message->telegram_user_id, (int)$guess, $requestId);
    }
}
