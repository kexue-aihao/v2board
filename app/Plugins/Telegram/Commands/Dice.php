<?php
namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Services\TrafficRewardService;

class Dice extends Telegram
{
    public $command = '/dice';
    public $description = '丢骰子赢流量';

    public function handle($message, $match = [])
    {
        if (!$message->is_private && (int)config('v2board.reward_group_enable', 0) !== 1) return;
        $service = new TrafficRewardService();
        $user = $service->userForTelegram($message->telegram_user_id, $message->is_private ? null : $message->chat_id);
        if (!$user) { $this->telegramService->sendMessage($message->chat_id, '请先绑定当前群组中的有效订阅'); return; }
        $requestId = isset($message->update_id) ? 'telegram-dice-' . $message->update_id : null;
        $result = $service->playDice($user, $message->is_private ? 'telegram' : 'telegram_group', $requestId);
        $this->telegramService->sendMessage($message->chat_id, sprintf('🎲 点数：%d\n获得流量：%d GB', $result['result'], $result['reward_gb']), 'markdown');
    }
}
