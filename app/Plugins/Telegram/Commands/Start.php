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
        // 已有账号必须走 /bind：/login 只给「已经绑定过」的人发免密登录链接，它第一件事
        // 就是拿 chat_id 查绑定，查不到就回「请先绑定可用的网站账号」。原来这里只写了
        // /regedit 与 /login，未绑定的老用户于是卡在「用 /login → 请先绑定 → 用 /login」
        // 的死循环里 —— 唯一出路 /bind 从来没被告诉过他们。
        $lines[] = "已有账号绑定：发送 /bind 你的订阅地址（在网站「订阅」页复制完整链接）";
        $lines[] = "/login 只对已绑定的账号有效；还没绑定请先用上面的 /bind。";
        $lines[] = "绑定后可用 /get_traffic 查流量、/get_subscription 购买套餐、/resetpassword 找回密码。";
        $this->telegramService->sendMessage($chatId, implode("\n", $lines));
        return true;
    }
}
