<?php

namespace App\Services;

use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

/**
 * 机器人侧的找回密码发码。
 *
 * 刻意与「强制绑定 Telegram」开关解耦：只要机器人配置完整，已绑定 Telegram 的账号就能用这条
 * 路径找回密码 —— 绑定 Telegram 的用途本身就是这个，不该再被另一个开关挡住。
 *
 * 验证码只存缓存不落库：它是短时效的一次性凭据，与注册申请（要跨机器人/网页多段流转、需要
 * 可查）不同，没必要留痕。
 */
class TelegramPasswordResetService
{
    private const CODE_TTL = 300;
    private const RESEND_THROTTLE = 60;

    private $telegram;

    public function __construct(?TelegramService $telegram = null)
    {
        $this->telegram = $telegram ?: new TelegramService();
    }

    public function enabled(): bool
    {
        return trim((string)config('v2board.telegram_bot_token', '')) !== '';
    }

    public function bound(User $user): bool
    {
        return (string)($user->telegram_id ?? '') !== '';
    }

    /**
     * 下发找回密码验证码。返回 ['ok' => bool, 'message' => string]，message 直接回给用户。
     */
    public function issue(User $user): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'message' => '机器人尚未配置，请联系管理员重置密码'];
        }
        if (!$this->bound($user)) {
            return ['ok' => false, 'message' => '该账号还没有绑定 Telegram，无法用这条路径找回密码，请联系管理员重置'];
        }

        $email = strtolower(trim((string)$user->email));
        $sentKey = CacheKey::get('LAST_SEND_TELEGRAM_FORGET_TIMESTAMP', $email);
        if (Cache::get($sentKey)) {
            return ['ok' => false, 'message' => '验证码刚刚已经发送过了，请稍后再试'];
        }

        $code = (string)random_int(100000, 999999);
        // 先发后存：发送抛异常时验证码不会留在缓存里，避免「已下发」的假象。
        $this->telegram->sendMessage((int)$user->telegram_id, $this->message($code));
        Cache::put(CacheKey::get('TELEGRAM_FORGET_CODE', $email), $code, self::CODE_TTL);
        Cache::put($sentKey, time(), self::RESEND_THROTTLE);

        return ['ok' => true, 'message' => '验证码已发送到本对话，请到网站的找回密码页提交。'];
    }

    private function message(string $code): string
    {
        $minutes = (int)ceil(self::CODE_TTL / 60);
        $lines = [
            '【' . config('v2board.app_name', 'V2Board') . '】找回密码验证码：' . $code,
            '有效期 ' . $minutes . ' 分钟，请勿转发给他人。',
            '如非本人操作请忽略本条消息。'
        ];
        $base = rtrim(trim((string)config('v2board.app_url', '')), '/');
        if ($base !== '') {
            $lines[] = '';
            $lines[] = '回到网站的找回密码页，填写邮箱、验证码与新密码：';
            $lines[] = $base . '/#/forget';
        }
        return implode("\n", $lines);
    }
}
