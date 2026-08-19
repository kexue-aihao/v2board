<?php
namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Services\TrafficRewardService;

class Checkin extends Telegram
{
    public $command = '/checkin';
    public $description = '每日签到领取流量';

    public function handle($message, $match = [])
    {
        $service = new TrafficRewardService();
        $user = $service->userForTelegram($message->telegram_user_id, $message->is_private ? null : $message->chat_id);
        if (!$user) { $this->telegramService->sendMessage($message->chat_id, '请先绑定有效订阅后再签到'); return; }
        $result = $service->checkin($user, 'telegram');
        $this->telegramService->sendMessage($message->chat_id, sprintf('签到成功，获得 %d GB 流量。过期时间：%s', $result['reward_gb'], $result['expires_at'] ? date('Y-m-d H:i:s', $result['expires_at']) : '订阅到期'), 'markdown');
    }
}
