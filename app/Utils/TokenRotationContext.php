<?php

namespace App\Utils;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/**
 * Token 轮换的归因上下文。
 *
 * 捕获本身由 Eloquent 观察者无条件完成，归因是尽力而为：漏包一处只会让 issued_reason
 * 退化成 unknown，不会丢记录。所以这里的每一条都必须是「取不到就说不知道」，绝不猜。
 */
final class TokenRotationContext
{
    /** @var string|null */
    private static $reason;

    /** @var array<int, string|null> spl_object_id => 保存前的 token */
    private static $originals = [];

    private const STASH_LIMIT = 1000;

    /**
     * 在 $reason 上下文中执行 $callback。
     *
     * 必须在 finally 里恢复上一个值：AdapterMan 下静态属性在 worker 内跨请求存活，
     * 裸 setter 会把 reason 泄漏进下一个用户的请求；save/restore 同时让嵌套安全 ——
     * SubscriptionService::create 可能跑在已设上下文的订单流程里，内层 reason 胜出。
     */
    public static function using(string $reason, callable $callback)
    {
        $previous = self::$reason;
        self::$reason = $reason;
        try {
            return $callback();
        } finally {
            self::$reason = $previous;
        }
    }

    public static function reason(): string
    {
        return self::$reason ?: 'unknown';
    }

    /**
     * @return array{type: string, user_id: ?int}
     */
    public static function actor(): array
    {
        try {
            if (App::runningInConsole()) {
                return ['type' => 'cli', 'user_id' => null];
            }
            // admin / user 中间件把解码后的账号 merge 成**数组**；而 Client 中间件 merge 的
            // 是 User 模型，那是被操作的主体不是操作者，所以只认数组。
            $actor = app('request')->user;
            if (!is_array($actor)) {
                return ['type' => 'unknown', 'user_id' => null];
            }
            return [
                'type' => empty($actor['is_admin']) ? 'self' : 'admin',
                'user_id' => isset($actor['id']) ? (int)$actor['id'] : null
            ];
        } catch (\Throwable $e) {
            return ['type' => 'unknown', 'user_id' => null];
        }
    }

    /**
     * 暂存保存前的 token。
     *
     * 用 spl_object_id 为键的静态映射，**不能**赋给模型的未声明属性 —— Eloquent 的
     * __set 会把它塞进 $attributes，下次 save 会试图写一个不存在的列。
     */
    public static function stashOriginal(Model $model, ?string $original): void
    {
        if (count(self::$originals) > self::STASH_LIMIT) {
            // 只在 isDirty('token') 时暂存，而那保证 updated 必然触发并消费掉它。这里是
            // 防御 updating 与 updated 之间抛异常的极端情况，不让映射无界增长。
            self::$originals = [];
        }
        self::$originals[spl_object_id($model)] = $original;
    }

    public static function hasOriginal(Model $model): bool
    {
        return array_key_exists(spl_object_id($model), self::$originals);
    }

    public static function takeOriginal(Model $model): ?string
    {
        $key = spl_object_id($model);
        if (!array_key_exists($key, self::$originals)) {
            return null;
        }
        $value = self::$originals[$key];
        unset(self::$originals[$key]);
        return $value;
    }

    public static function forget(Model $model): void
    {
        unset(self::$originals[spl_object_id($model)]);
    }
}
