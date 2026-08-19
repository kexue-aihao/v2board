<?php
namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Services\TrafficRewardService;

class Slots extends Telegram
{
    public $command = '/slots';
    public $description = '老虎机赢流量';

    public function handle($message, $match = [])
    {
        if (!$message->is_private && (int)config('v2board.reward_group_enable', 0) !== 1) return;
        $service = new TrafficRewardService();
        $user = $service->userForTelegram($message->telegram_user_id, $message->is_private ? null : $message->chat_id);
        if (!$user) { $this->telegramService->sendMessage($message->chat_id, '请先绑定当前群组中的有效订阅'); return; }
        $requestId = isset($message->update_id) ? 'telegram-slots-' . $message->update_id : null;
        $result = $service->playSlots($user, $message->is_private ? 'telegram' : 'telegram_group', $requestId);
        $this->telegramService->sendMessage($message->chat_id, sprintf('🎰 结果：%s\n获得流量：%d GB', implode(' | ', $result['result']), $result['reward_gb']), 'markdown');
    }
}
