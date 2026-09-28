<?php

namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Services\TelegramShopService;

class GetSubscription extends Telegram
{
    public $command = '/get_subscription';
    public $description = '浏览在售套餐并直接下单支付';

    public function handle($message, $match = [])
    {
        if (!$message->is_private) {
            $this->telegramService->sendMessage($message->chat_id, '请在机器人的私聊中购买套餐。');
            return;
        }
        (new TelegramShopService())->showPlans((int)$message->chat_id);
    }
}
