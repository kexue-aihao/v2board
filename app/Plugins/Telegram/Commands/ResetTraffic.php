<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\TelegramShopService;

class ResetTraffic extends Telegram
{
    public $command = '/reset';
    public $description = '重置订阅流量（按套餐重置包价格下单）';

    public function handle($message, $match = [])
    {
        if (!$message->is_private) {
            $this->telegramService->sendMessage($message->chat_id, '请在机器人的私聊中操作。');
            return;
        }

        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $this->telegramService->sendMessage(
                $message->chat_id,
                "本 Telegram 还没有绑定账号。\n还没有账号：发送 /regedit 注册。\n已有账号：发送 /login 生成免密登录链接。"
            );
            return;
        }

        $plan = Plan::find($user->plan_id);
        if (!$plan || $plan->reset_price === null || $plan->reset_price === '' || (float)$plan->reset_price <= 0) {
            $this->telegramService->sendMessage(
                $message->chat_id,
                '当前订阅不支持重置流量（管理员未为该套餐设置重置包价格）。'
            );
            return;
        }

        // 直接进入重置包下单：选支付方式并付款后才真正重置，不会误扣
        (new TelegramShopService())->createOrder((int)$message->chat_id, (int)$plan->id, 'reset_price');
    }
}
