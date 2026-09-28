<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\TelegramRegistrationService;
use App\Services\TelegramRewardService;

class Start extends Telegram
{
    public $command = '/start';
    public $description = '打开娱乐中心';

    public function handle($message, $match = [])
    {
        if (!in_array((string)($message->chat_type ?? ''), ['private', 'group', 'supergroup'], true)) return;
        if ($this->guideUnboundUser((int)$message->chat_id, (string)($message->chat_type ?? ''))) {
            return;
        }
        (new TelegramRewardService($this->telegramService))->showMenu($message->chat_id, $message->telegram_user_id);
    }

    /**
     * 未绑定的私聊用户只给注册/登录入口：注册收敛到机器人之后 /start 是新用户见到的第一条消息，
     * 而娱乐菜单对未绑定用户本来就会抛「请先在网站绑定有效订阅」——不如把去路讲清楚。
     *
     * @return bool true 表示已处理，调用方不要再进娱乐菜单
     */
    private function guideUnboundUser(int $chatId, string $chatType): bool
    {
        if ($chatType !== 'private' || $chatId === 0) return false;
        if (User::where('telegram_id', $chatId)->exists()) return false;

        // 别把「有没有开注册」当成要不要引导的条件：注册关掉时未绑定用户会掉进娱乐菜单，
        // 而菜单对他们只会抛「请先在网站绑定有效订阅」—— 那是一条报错，不是出路。
        $lines = ["你还没有绑定站点账号。"];
        if ((new TelegramRegistrationService())->enabled()) {
            $lines[] = "注册新账号：发送 /regedit";
        }
        $lines[] = "已有账号登录：发送 /login";
        $lines[] = "绑定后可用 /get_traffic 查流量、/get_subscription 购买套餐、/resetpassword 找回密码。";
        $this->telegramService->sendMessage($chatId, implode("\n", $lines));
        return true;
    }
}
