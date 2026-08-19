<?php
namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Services\TrafficRewardService;

class Poker extends Telegram
{
    public $command = '/poker';
    public $description = '群组炸金花';

    public function handle($message, $match = [])
    {
        if ($message->is_private || (int)config('v2board.reward_group_enable', 0) !== 1) return;
        $action = isset($message->args[0]) && in_array($message->args[0], ['create', 'join', 'start'], true) ? $message->args[0] : 'join';
        $service = new TrafficRewardService();
        $user = $service->userForTelegram($message->telegram_user_id, $message->chat_id);
        if (!$user) { $this->telegramService->sendMessage($message->chat_id, '请先绑定当前群组中的有效订阅'); return; }
        $result = $service->playPoker($user, (string)$message->chat_id, $action, 'telegram_group');
        $text = $result['status'] === 'settled'
            ? sprintf('🃏 牌局结束，赢家用户 #%d，获得 %d GB 流量。', $result['winner_user_id'], $result['reward_gb'])
            : sprintf('🃏 牌局已加入，当前玩家：%d 人。发送 /poker start 开始。', $result['players']);
        $this->telegramService->sendMessage($message->chat_id, $text, 'markdown');
    }
}
