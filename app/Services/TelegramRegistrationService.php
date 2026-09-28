<?php

namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\TelegramRegistration;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use App\Utils\TokenRotationContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Telegram 注册申请。
 *
 * 链路：机器人 /regedit 提交虚拟邮箱 → 自动核验 → 下发六位验证码 → 网页凭「邮箱 + 验证码」建号。
 *
 * 与邮箱注册的关键差别：这里的验证码本身就是身份锚点（拿到码就能拿到该 Telegram 对应的
 * 账号），所以校验一律走「同一申请最多 5 次尝试、超时作废、错误响应不区分原因」，库里只存
 * 哈希。验证码用六位数字而不是更长的随机串：用户要在 Telegram 里复制、在手机网页上输入，
 * 数字体验最好；强度由尝试上限与申请级作废保证，不靠空间大小。
 */
class TelegramRegistrationService
{
    public const STATUS_PENDING = 0;
    public const STATUS_CODE_SENT = 1;
    public const STATUS_COMPLETED = 2;
    public const STATUS_VOID = 3;

    /** 校验失败一律回同一句话：不区分"码不存在""码不匹配""申请已过期"，避免被拿来探测 */
    public const INVALID_CODE_MESSAGE = '验证码无效或已过期，请回到机器人重新获取';

    private const CODE_TTL = 300;
    private const SESSION_TTL = 300;
    private const RESEND_THROTTLE = 60;
    private const MAX_ATTEMPTS = 5;

    public function enabled(): bool
    {
        if ((int)config('v2board.telegram_register_enable', 0) !== 1) {
            return false;
        }
        return trim((string)config('v2board.telegram_bot_token', '')) !== '';
    }

    /* ---------------- 机器人侧 ---------------- */

    /**
     * 进入注册会话：/regedit 之后用户直接回一条消息，那条消息就是邮箱。
     * 站点强制邀请码时，会话会先停在「等邀请码」这一步。
     */
    public function startSession(int $chatId): void
    {
        Cache::put($this->sessionKey($chatId), ['state' => 'email'], self::SESSION_TTL);
    }

    /** 当前会话：['state' => 'email'] 或 ['state' => 'invite', 'email' => '...'] */
    public function session(int $chatId): ?array
    {
        $value = Cache::get($this->sessionKey($chatId));
        return is_array($value) && isset($value['state']) ? $value : null;
    }

    /** 邮箱已收到但站点要求邀请码：先把邮箱存进会话，等用户补上邀请码 */
    public function awaitInvite(int $chatId, string $email): void
    {
        Cache::put($this->sessionKey($chatId), ['state' => 'invite', 'email' => $email], self::SESSION_TTL);
    }

    public function endSession(int $chatId): void
    {
        Cache::forget($this->sessionKey($chatId));
    }

    private function sessionKey(int $chatId): string
    {
        return CacheKey::get('TELEGRAM_REGISTER_SESSION', $chatId);
    }

    /**
     * 提交虚拟邮箱，自动核验并下发验证码。
     * 返回 ['ok' => bool, 'message' => string] —— message 由命令层直接回给用户。
     */
    public function apply(int $chatId, ?string $username, string $email, ?string $inviteCode = null): array
    {
        if (!$this->enabled()) {
            return $this->fail('注册功能尚未开启，请联系管理员');
        }
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 64) {
            return $this->fail('邮箱格式不正确，请重新提交（虚拟邮箱也可以，例如 name@example.com）');
        }
        $inviteCode = $inviteCode !== null ? trim($inviteCode) : null;
        if ($inviteCode === '') {
            $inviteCode = null;
        }
        // 强制邀请码的站点：旧的邮箱注册本来就在这一步拦，机器人注册不能成为绕过口
        if ((int)config('v2board.invite_force', 0)) {
            if ($inviteCode === null) {
                return $this->fail('本站注册需要邀请码，请把邀请码发给我');
            }
            if (!InviteCode::where('code', $inviteCode)->where('status', 0)->exists()) {
                return $this->fail('邀请码无效或已被使用，请检查后重新提交');
            }
        }
        // 核验 ①②：邮箱与 Telegram 都不能已被占用。邮箱已存在时不区分"已注册"与"被他人占用"，
        // 否则这里就成了一个邮箱枚举接口。
        if (User::where('email', $email)->exists()) {
            return $this->fail('该邮箱已被使用，请换一个虚拟邮箱后重新提交');
        }
        if (User::where('telegram_id', $chatId)->exists()) {
            return $this->fail('该 Telegram 已经绑定过账号，直接用 /login 生成免密登录链接即可');
        }
        // 核验 ③：发码节流
        $throttleKey = CacheKey::get('TELEGRAM_REGISTER_THROTTLE', $chatId);
        if (Cache::has($throttleKey)) {
            return $this->fail('提交过于频繁，请稍后再试');
        }

        $now = time();
        // 同一 UID 同时只保留一条活跃申请：重新提交时作废旧申请，避免用户卡在过期申请上。
        TelegramRegistration::where('telegram_id', $chatId)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_CODE_SENT])
            ->update(['status' => self::STATUS_VOID, 'updated_at' => $now]);

        $code = $this->randomCode();
        $application = TelegramRegistration::create([
            'telegram_id' => $chatId,
            'telegram_username' => $username !== null ? mb_substr($username, 0, 64) : null,
            'email' => $email,
            'invite_code' => $inviteCode,
            'code_hash' => '',
            'status' => self::STATUS_CODE_SENT,
            'attempts' => 0,
            'sent_at' => $now,
            'expires_at' => $now + self::CODE_TTL,
            'created_at' => $now,
            'updated_at' => $now
        ]);
        // 哈希带申请 ID 作盐：同一验证码落在不同申请上哈希不同，防止拿一张彩虹表横穿全表。
        $application->update(['code_hash' => $this->hashCode((int)$application->id, $code)]);

        Cache::put($throttleKey, 1, self::RESEND_THROTTLE);
        $this->endSession($chatId);
        $this->deliverCode($application, $code);

        $delay = $this->codeDelay();
        return [
            'ok' => true,
            'message' => $delay > 0
                ? '已通过核验申请，验证码将在 ' . $delay . ' 秒后发送到本对话，请稍候。'
                : '已通过核验申请，验证码已发送到本对话。'
        ];
    }

    /* ---------------- 网页侧 ---------------- */

    /**
     * 凭「邮箱 + 验证码」建号。失败一律抛同一句提示。
     */
    public function register(string $email, string $code): User
    {
        if (!$this->enabled()) {
            abort(503, '注册功能尚未开启，请联系管理员');
        }
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            abort(422, self::INVALID_CODE_MESSAGE);
        }
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            abort(422, self::INVALID_CODE_MESSAGE);
        }

        $application = TelegramRegistration::where('email', $email)
            ->where('status', self::STATUS_CODE_SENT)
            ->orderByDesc('id')
            ->first();
        if (!$application) {
            abort(422, self::INVALID_CODE_MESSAGE);
        }
        $now = time();
        if ((int)$application->expires_at < $now || (int)$application->attempts >= self::MAX_ATTEMPTS) {
            $application->update(['status' => self::STATUS_VOID, 'updated_at' => $now]);
            abort(422, self::INVALID_CODE_MESSAGE);
        }
        if (!$this->codeMatches($application, $code)) {
            $application->increment('attempts');
            abort(422, self::INVALID_CODE_MESSAGE);
        }

        return DB::transaction(function () use ($application, $email, $now) {
            // 核验 ④ 的并发兜底：两个请求同时提交同一邮箱/同一 Telegram 时，后到的在这里失败；
            // 最终仍由 v2_user 上的唯一索引把关。
            if (User::where('email', $email)->lockForUpdate()->exists()) {
                abort(409, '该邮箱已被使用，请更换虚拟邮箱后重新注册');
            }
            if (User::where('telegram_id', (int)$application->telegram_id)->lockForUpdate()->exists()) {
                abort(409, '该 Telegram 已绑定账号，请直接用 /login 登录');
            }

            $user = new User();
            $user->email = $email;
            // 注册流程不发邮件，用户也不需要知道密码：给一个不可猜的随机值，登录一律走 Telegram。
            // 因此不打 password_reset_required —— 让用户去重置一个他不知道的密码是荒唐的。
            $user->password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $user->uuid = Helper::guid(true);
            $user->token = Helper::guid();
            $user->telegram_id = (int)$application->telegram_id;
            // 邀请码在这里才真正消费，规则与旧的邮箱注册一致：建立邀请关系、非永久码置为已用；
            // 若在此期间被别人用掉，强制邀请码的站点直接拒绝建号。
            if ((string)($application->invite_code ?? '') !== '') {
                $invite = InviteCode::where('code', $application->invite_code)
                    ->where('status', 0)
                    ->lockForUpdate()
                    ->first();
                if ($invite) {
                    $user->invite_user_id = $invite->user_id ?: null;
                    if (!(int)config('v2board.invite_never_expire', 0)) {
                        $invite->status = 1;
                        $invite->save();
                    }
                } elseif ((int)config('v2board.invite_force', 0)) {
                    abort(422, '邀请码已被使用，请回到机器人重新申请');
                }
            }
            $this->applyTryOutPlan($user, $now);
            if (!TokenRotationContext::using('telegram_register', function () use ($user) {
                return $user->save();
            })) {
                abort(500, '注册失败，请稍后再试');
            }
            $user->last_login_at = $now;
            $user->save();

            $application->update([
                'status' => self::STATUS_COMPLETED,
                'user_id' => $user->id,
                'code_hash' => null,
                'updated_at' => $now
            ]);
            return $user;
        });
    }

    /**
     * 供注册页展示「验证码发去了哪里」：只回掩码后的邮箱与有效期，不回验证码本身。
     */
    public function pendingHint(string $email): array
    {
        $email = strtolower(trim($email));
        $application = TelegramRegistration::where('email', $email)
            ->where('status', self::STATUS_CODE_SENT)
            ->orderByDesc('id')
            ->first();
        if (!$application) {
            return ['pending' => false];
        }
        return [
            'pending' => true,
            'email' => $this->maskEmail($email),
            'expires_at' => (int)$application->expires_at
        ];
    }

    /* ---------------- 内部 ---------------- */

    private function deliverCode(TelegramRegistration $application, string $code): void
    {
        $text = $this->codeMessage((string)$application->email, $code);
        $delay = $this->codeDelay();
        if ($delay > 0) {
            SendTelegramJob::dispatch((int)$application->telegram_id, $text)->delay(now()->addSeconds($delay));
            return;
        }
        (new TelegramService())->sendMessage((int)$application->telegram_id, $text);
    }

    /**
     * 文案刻意不含 markdown 控制字符：延迟发送走 SendTelegramJob，它固定按 markdown 发送，
     * 那边会对下划线做转义，链接里出现下划线会被打断。邮箱在链接里已做 URL 编码。
     */
    private function codeMessage(string $email, string $code): string
    {
        $appName = (string)config('v2board.app_name', 'V2Board');
        $minutes = (int)ceil(self::CODE_TTL / 60);
        $lines = [
            '【' . $appName . '】注册验证码：' . $code,
            '有效期 ' . $minutes . ' 分钟，请勿转发给他人。',
            '',
            '回到网页注册页，填写邮箱与验证码即可完成注册。'
        ];
        $link = $this->registerLink($email);
        if ($link !== '') {
            $lines[] = $link;
        }
        return implode("\n", $lines);
    }

    private function registerLink(string $email): string
    {
        $base = rtrim(trim((string)config('v2board.app_url', '')), '/');
        if ($base === '') {
            return '';
        }
        return $base . '/#/register?tg_email=' . rawurlencode($email);
    }

    private function codeDelay(): int
    {
        $delay = (int)config('v2board.telegram_register_code_delay', 0);
        if ($delay < 0) {
            return 0;
        }
        return $delay > 300 ? 300 : $delay;
    }

    private function randomCode(): string
    {
        return (string)random_int(100000, 999999);
    }

    private function hashCode(int $applicationId, string $code): string
    {
        return hash_hmac('sha256', $code, (string)config('app.key') . '|' . $applicationId);
    }

    private function codeMatches(TelegramRegistration $application, string $code): bool
    {
        $expected = (string)$application->code_hash;
        if ($expected === '') {
            return false;
        }
        return hash_equals($expected, $this->hashCode((int)$application->id, $code));
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        $local = $parts[0] ?? '';
        $domain = $parts[1] ?? '';
        if ($local === '' || $domain === '') {
            return $email;
        }
        $head = mb_substr($local, 0, 1);
        return $head . str_repeat('*', max(1, mb_strlen($local) - 1)) . '@' . $domain;
    }

    private function applyTryOutPlan(User $user, int $now): void
    {
        if (!(int)config('v2board.try_out_plan_id', 0)) {
            return;
        }
        $plan = Plan::find(config('v2board.try_out_plan_id'));
        if (!$plan) {
            return;
        }
        $user->transfer_enable = $plan->transfer_enable * 1073741824;
        $user->device_limit = $plan->device_limit;
        $user->plan_id = $plan->id;
        $user->group_id = $plan->group_id;
        $user->expired_at = $now + (config('v2board.try_out_hour', 1) * 3600);
        $user->speed_limit = $plan->speed_limit;
    }

    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
