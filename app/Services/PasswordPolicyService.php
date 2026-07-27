<?php

namespace App\Services;

use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 密码策略：密码由系统生成，用户不再自定义。
 *
 * 格式为 64 位无分隔符随机字符（大小写字母 + 数字，即 8 位 × 8 组连写）。选 64 而不是更
 * 长，是因为 password_hash(PASSWORD_DEFAULT) 在 PHP 里仍是 bcrypt，超过 72 字节的部分会
 * 被**静默截断** —— 那意味着密码看着长、实际熵停在 72 字节，且没有任何报错。64 留了余量。
 * 存储侧同样有上限：v2_user.password 是 varchar(64)，bcrypt 哈希固定 60 字符，放得下。
 *
 * 「是否提醒用户重置」必须走 requiresReset()，不要在别处裸读 password_reset_required：
 * 排除 is_admin / is_staff 这条规则只有一处实现，员工被提拔或降级时提醒会自动跟着变，
 * 前端也就只需要信后端给的一个布尔。
 *
 * 列可能不存在（迁移之前、或有人手工 DROP 过），所以 available() 为假时写旗标是静默跳过
 * 的空操作 —— 密码本身照样能改，只是不再有提醒。fail open：把用户锁在门外的代价远大于
 * 少提醒一次。
 */
class PasswordPolicyService
{
    /** 生成密码的长度。前后端文案都引用它，别在别处再写一个 64。 */
    public const LENGTH = 64;

    private const COLUMN = 'password_reset_required';

    /** @var bool|null */
    private static $availability;

    /**
     * 生成一个符合策略的密码。
     *
     * Helper::randomChar() 的字符集正好是 a-zA-Z0-9 且用的是 random_int()，直接复用。
     * 64 位里缺某一类字符的概率约 3e-6，但「字母加数字」是明确的格式要求，所以显式重取
     * 而不是靠概率 —— 重取几乎永远不会发生，成本可以忽略。
     */
    public static function generate(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $password = Helper::randomChar(self::LENGTH);
            if (preg_match('/[a-z]/', $password)
                && preg_match('/[A-Z]/', $password)
                && preg_match('/\d/', $password)
            ) {
                return $password;
            }
        }
        // 连续 8 次都不满足只可能是 randomChar 被改坏了，硬拼一个合规值兜底，绝不返回空。
        return substr(Helper::randomChar(self::LENGTH - 3) . 'aZ7', 0, self::LENGTH);
    }

    public static function available(): bool
    {
        if (self::$availability !== null) {
            return self::$availability;
        }
        try {
            return self::$availability = Schema::hasColumn('v2_user', self::COLUMN);
        } catch (\Throwable $e) {
            Log::warning('密码策略列探测失败，按未安装处理', ['error' => $e->getMessage()]);
            return self::$availability = false;
        }
    }

    /**
     * 是否要提醒这个用户重置密码。
     *
     * @param User|array|null $user User 模型，或 user 中间件注入的 $request->user 数组
     */
    public static function requiresReset($user): bool
    {
        if (!$user || !self::available()) {
            return false;
        }
        $flag = is_array($user) ? ($user[self::COLUMN] ?? null) : ($user->{self::COLUMN} ?? null);
        if ((int)$flag !== 1) {
            return false;
        }
        $isAdmin = (int)(is_array($user) ? ($user['is_admin'] ?? 0) : ($user->is_admin ?? 0));
        $isStaff = (int)(is_array($user) ? ($user['is_staff'] ?? 0) : ($user->is_staff ?? 0));
        return !$isAdmin && !$isStaff;
    }

    /** 用户自己敲进来的密码 —— 按定义不合规，开始提醒。 */
    public static function markRequired(?User $user): void
    {
        self::writeFlag($user, 1);
    }

    /** 系统生成的密码 —— 合规，停止提醒。 */
    public static function markSatisfied(?User $user): void
    {
        self::writeFlag($user, 0);
    }

    /**
     * 给一个还没入库的 User 打上待重置标记，让它跟着同一条 INSERT 走（注册路径用）。
     *
     * 必须走这里而不是直接 $user->password_reset_required = 1：列不存在时那样赋值会让
     * INSERT 带上一个不存在的列，直接把注册炸掉。
     */
    public static function stampRequired(User $user): void
    {
        if (!self::available()) {
            return;
        }
        $user->setAttribute(self::COLUMN, 1);
    }

    /**
     * 落一个新密码。
     *
     * 只赋值不 save()，由调用方决定何时写库（有些路径还要在同一次 save 里改别的列）。
     * password_algo 与 password_salt 两个都要清：清了 algo，multiPasswordVerify 才会走
     * password_verify 分支；salt 不清虽然不影响校验，但会留下一条会误导人的脏数据
     * （Admin\UserController::update 至今只清了 algo）。
     */
    public static function apply(User $user, string $plain): void
    {
        $user->password = password_hash($plain, PASSWORD_DEFAULT);
        $user->password_algo = null;
        $user->password_salt = null;
    }

    /**
     * 直接走查询构造器而不是模型 save()：密码写入路径上没必要再触发一次
     * SubscriptionTokenObserver 的 dirty 检查链路，那条链路是为 token 轮换设计的。
     */
    private static function writeFlag(?User $user, int $value): void
    {
        if (!$user || !$user->id || !self::available()) {
            return;
        }
        try {
            DB::table('v2_user')->where('id', $user->id)->update([
                self::COLUMN => $value,
                'updated_at' => time()
            ]);
            // 同步内存里的值，好让紧接着的 requiresReset() 看到实情；syncOriginalAttribute
            // 是为了别把它标成 dirty —— 否则调用方后续任何一次 save() 都会把它再写一遍。
            $user->setAttribute(self::COLUMN, $value);
            $user->syncOriginalAttribute(self::COLUMN);
        } catch (\Throwable $e) {
            Log::error('写入密码策略旗标失败', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
