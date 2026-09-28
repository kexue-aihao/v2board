<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;

class GetTraffic extends Telegram
{
    public $command = '/get_traffic';
    // 旧命令保留为别名：存量用户记的是 /traffic，改名不能让人找不到
    public $aliases = ['/traffic'];
    public $description = '查询流量信息';

    public function handle($message, $match = [])
    {
        $telegramService = $this->telegramService;
        if (!$message->is_private) return;
        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $telegramService->sendMessage(
                $message->chat_id,
                "没有查询到您的用户信息。\n还没有账号：发送 /regedit 注册。\n已有账号：发送 /login 生成免密登录链接。",
                'markdown'
            );
            return;
        }
        $transferEnable = Helper::trafficConvert($user->transfer_enable);
        $up = Helper::trafficConvert($user->u);
        $down = Helper::trafficConvert($user->d);
        $remaining = Helper::trafficConvert($user->transfer_enable - ($user->u + $user->d));
        $text = "🚥流量查询\n———————————————\n计划流量：`{$transferEnable}`\n已用上行：`{$up}`\n已用下行：`{$down}`\n剩余流量：`{$remaining}`";
        $telegramService->sendMessage($message->chat_id, $text, 'markdown');
    }
}
