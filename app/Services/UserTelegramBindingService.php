<?php

namespace App\Services;

use App\Models\OAuthIdentity;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * 账号级 Telegram 绑定：把站点账号绑到一个 Telegram 私聊（v2_user.telegram_id），
 * 用途是给忘记密码的用户下发验证码。
 *
 * 注意与 TelegramBindingService 区分：那个是订阅↔售后群的绑定（群成员校验、入群链接），
 * 绑的是 v2_telegram_subscription_binding；这里绑的是账号本身，字段与机器人 /bind 命令、
 * 通知推送共用同一个 v2_user.telegram_id。
 *
 * 强制绑定只是「前端弹窗遮挡」，不拦接口：真拦接口会连带挡住移动端 App 和第三方客户端，
 * 而且绑定流程本身也在用户接口里，拦起来要开一堆白名单，风险大于收益。
 */
class UserTelegramBindingService
{
    public function enabled(): bool
    {
        if ((int)config('v2board.telegram_account_binding_enable', 0) !== 1) {
            return false;
        }
        return trim((string)config('v2board.telegram_bot_token', '')) !== '';
    }

    /**
     * OAuth 账号豁免：它们本来就能用第三方登录，不依赖密码找回。
     * 判定读 v2_oauth_identity，不去猜邮箱域名（占位邮箱的规则会随版本变）。
     */
    public function isOauthAccount(User $user): bool
    {
        return OAuthIdentity::where('user_id', $user->id)->exists();
    }

    /**
     * 豁免范围：OAuth 账号（有第三方登录，不依赖密码找回）、管理员与员工
     * （他们本来就能进后台处置账号，强制绑定只会挡住自己人）。
     */
    public function required(User $user): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        if ($user->is_admin || $user->is_staff) {
            return false;
        }
        if ((string)($user->telegram_id ?? '') !== '') {
            return false;
        }
        return !$this->isOauthAccount($user);
    }

    public function status(User $user): array
    {
        return [
            'enabled' => $this->enabled(),
            'required' => $this->required($user),
            'bound' => (string)($user->telegram_id ?? '') !== '',
            'bot_username' => $this->enabled() ? $this->botUsername() : ''
        ];
    }

    /**
     * 生成一次性绑定链接：用户在 Telegram 里对机器人发 /start ubind_<nonce> 即完成绑定。
     * nonce 存 10 分钟，completeFromBot 用 Cache::pull 取用（一次性）。
     */
    public function prepare(User $user): array
    {
        if (!$this->enabled()) {
            abort(503, __('Telegram account binding is not enabled'));
        }
        $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        Cache::put(CacheKey::get('TELEGRAM_ACCOUNT_BINDING', $nonce), [
            'user_id' => (int)$user->id,
            'created_at' => time()
        ], 600);
        $username = $this->botUsername();
        return [
            'bot_username' => $username,
            'binding_url' => 'https://t.me/' . $username . '?start=ubind_' . $nonce,
            'expires_at' => time() + 600
        ];
    }

    public function completeFromBot(string $nonce, $telegramChatId): array
    {
        if (!$this->enabled()) {
            throw new RuntimeException('Telegram account binding is disabled');
        }
        $payload = Cache::pull(CacheKey::get('TELEGRAM_ACCOUNT_BINDING', trim($nonce)));
        if (!is_array($payload)) {
            throw new RuntimeException('Binding link is invalid or expired');
        }
        $chatId = (string)$telegramChatId;
        if (!ctype_digit($chatId) || (int)$chatId <= 0) {
            throw new RuntimeException('Invalid Telegram chat id');
        }
        $user = User::find((int)($payload['user_id'] ?? 0));
        if (!$user) {
            throw new RuntimeException('Account does not exist');
        }
        // 一个 Telegram 只能绑一个账号：否则同一 chat_id 收验证码时无法判断该改谁的密码。
        if (User::where('telegram_id', $chatId)->where('id', '!=', $user->id)->exists()) {
            throw new RuntimeException('This Telegram account is already bound to another account');
        }
        $user->telegram_id = $chatId;
        if (!$user->save()) {
            throw new RuntimeException('Bind failed');
        }
        return ['user_id' => (int)$user->id];
    }

    private function botUsername(): string
    {
        $username = ltrim(trim((string)config('v2board.oauth_telegram_bot_username', '')), '@');
        if ($username !== '') {
            return $username;
        }
        $response = (new TelegramService())->getMe();
        $username = ltrim((string)($response->result->username ?? ''), '@');
        if ($username === '') {
            abort(503, 'Telegram bot username is not configured');
        }
        return $username;
    }
}
