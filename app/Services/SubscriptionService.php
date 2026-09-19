<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Utils\Helper;
use App\Utils\TokenRotationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubscriptionService
{
    private const NODE_ID_OFFSET = 1000000000;

    public function available(): bool
    {
        return Schema::hasTable('v2_subscription');
    }

    public function multiEnabled(): bool
    {
        return $this->available() && (int)config('v2board.multi_subscription_enable', 0) === 1;
    }

    public function primary(User $user): ?Subscription
    {
        if (!$this->available()) return null;
        return Subscription::where('user_id', $user->id)
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->first();
    }

    public function ensurePrimary(User $user): ?Subscription
    {
        if (!$this->available()) return null;

        // 读取接口也会修复用户镜像，必须与订单开通一样先锁用户、再锁订阅。
        // 调用方传入的 User 可能已过时，不能据此迁移旧套餐或回写旧权益。
        return DB::transaction(function () use ($user) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $subscription = Subscription::where('user_id', $lockedUser->id)
                ->where('status', '!=', 'revoked')
                ->orderByDesc('is_primary')
                ->orderByRaw("CASE WHEN status = 'active' AND (expired_at IS NULL OR expired_at >= ?) THEN 1 ELSE 0 END DESC", [time()])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (!$subscription) {
                // 仅迁移完全没有订阅记录的老用户，不能把已撤销的订阅从镜像重新建回来。
                if (!$lockedUser->plan_id || Subscription::where('user_id', $lockedUser->id)->exists()) return null;
                $subscription = new Subscription();
                $subscription->user_id = $lockedUser->id;
                $subscription->plan_id = $lockedUser->plan_id;
                $subscription->token = $lockedUser->token;
                $subscription->uuid = $lockedUser->uuid;
                $subscription->node_user_id = 2000000000 + $lockedUser->id;
                $subscription->group_id = $lockedUser->group_id;
                $subscription->speed_limit = $lockedUser->speed_limit;
                $subscription->device_limit = $lockedUser->device_limit;
                $subscription->transfer_enable = $lockedUser->transfer_enable;
                $subscription->u = $lockedUser->u;
                $subscription->d = $lockedUser->d;
                $subscription->status = 'active';
                $subscription->is_primary = true;
                $subscription->auto_renewal = $lockedUser->auto_renewal;
                $subscription->started_at = $lockedUser->created_at ?: time();
                $subscription->expired_at = $lockedUser->expired_at ?: null;
                $subscription->save();
            }
            if (!$subscription->is_primary) {
                return $this->setPrimary($lockedUser, $subscription);
            }
            $this->syncUser($lockedUser, $subscription);
            return $subscription->fresh();
        });
    }

    /**
     * 只是给 token 历史标注原因的一层包装，方法体原样在 createSubscription()。
     * 包在这里而不是把整个方法体缩进一层，是为了让改动不淹没在缩进 diff 里。
     */
    public function create(User $user, Plan $plan, ?string $period = null, bool $primary = false): Subscription
    {
        return TokenRotationContext::using('subscription_new', function () use ($user, $plan, $period, $primary) {
            return $this->createSubscription($user, $plan, $period, $primary);
        });
    }

    private function createSubscription(User $user, Plan $plan, ?string $period = null, bool $primary = false): Subscription
    {
        // 必须在插入之前判断：primary() 只按 is_primary/id 排序取第一条、并不过滤 is_primary，
        // 插入之后调用它必然返回刚建的这条，会导致主订阅永远设不上、user 表也不同步。
        // 判断「有没有主订阅」而非「有没有订阅」，可顺带纠正历史上缺主订阅的账户。
        $needsPrimary = !Subscription::where('user_id', $user->id)
            ->where('is_primary', true)
            ->exists();

        $subscription = new Subscription();
        $subscription->user_id = $user->id;
        $subscription->plan_id = $plan->id;
        $subscription->token = Helper::guid();
        $subscription->uuid = Helper::guid(true);
        $subscription->group_id = $plan->group_id;
        $subscription->speed_limit = $plan->speed_limit;
        $subscription->device_limit = $plan->device_limit;
        $subscription->u = 0;
        $subscription->d = 0;
        $subscription->status = 'active';
        $subscription->is_primary = false;
        $subscription->auto_renewal = false;
        $subscription->started_at = time();
        $this->applyPlan($subscription, $plan, $period, false);
        // node_user_id 是 NOT NULL 且无默认值的唯一列，strict 模式下首次 INSERT 必须带值，
        // 而正式值依赖自增 id，因此先占位再回写。本方法始终运行在调用方的事务内，
        // 唯一索引上的行锁会让并发插入串行化，占位值不会引起 UNIQUE 冲突，失败也会随事务回滚。
        $subscription->node_user_id = 0;
        $subscription->save();
        $subscription->node_user_id = self::NODE_ID_OFFSET + $subscription->id;
        $subscription->save();

        if ($primary || $needsPrimary) {
            $this->setPrimary($user, $subscription);
        }
        return $subscription->fresh();
    }

    public function renew(Subscription $subscription, Plan $plan, string $period): Subscription
    {
        $expired = $subscription->expired_at;
        $replace = $subscription->plan_id !== $plan->id || ($expired !== null && $expired < time());
        if ($replace) {
            $subscription->u = 0;
            $subscription->d = 0;
            $subscription->started_at = time();
        }
        $subscription->plan_id = $plan->id;
        $subscription->group_id = $plan->group_id;
        $subscription->speed_limit = $plan->speed_limit;
        $subscription->device_limit = $plan->device_limit;
        $subscription->status = 'active';
        $this->applyPlan($subscription, $plan, $period, $replace);
        $subscription->save();
        if ($subscription->is_primary) {
            $this->syncUser(User::findOrFail($subscription->user_id), $subscription);
        }
        return $subscription->fresh();
    }

    public function reset(Subscription $subscription): Subscription
    {
        $plan = Plan::find($subscription->plan_id);
        if ($plan) {
            $subscription->transfer_enable = (int)$plan->transfer_enable * 1073741824;
        }
        $subscription->u = 0;
        $subscription->d = 0;
        $subscription->last_reset_at = time();
        $subscription->save();
        if ($subscription->is_primary) {
            $this->syncUser(User::findOrFail($subscription->user_id), $subscription);
        }
        return $subscription->fresh();
    }

    public function rotateCredential(Subscription $subscription, string $reason = 'subscription_rotate'): Subscription
    {
        return TokenRotationContext::using($reason, function () use ($subscription) {
            $subscription->token = Helper::guid();
            $subscription->uuid = Helper::guid(true);
            $subscription->save();
            if ($subscription->is_primary) {
                $user = User::findOrFail($subscription->user_id);
                $user->token = $subscription->token;
                $user->uuid = $subscription->uuid;
                $user->save();
            }
            return $subscription->fresh();
        });
    }

    public function setPrimary(User $user, Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($user, $subscription) {
            // Match order opening: lock the user before reading the subscription.
            // A caller's models may predate a completed payment or revocation.
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $lockedSubscription = Subscription::where('id', $subscription->id)->lockForUpdate()->firstOrFail();
            if ((int)$lockedSubscription->user_id !== (int)$lockedUser->id) {
                abort(403, __('Subscription does not belong to the user'));
            }
            if ($lockedSubscription->status === 'revoked') {
                abort(422, __('Revoked subscription cannot be primary'));
            }

            // Do not clear the selected row: if its model already holds true,
            // Eloquent will not write that unchanged attribute back on save().
            Subscription::where('user_id', $lockedUser->id)
                ->where('id', '!=', $lockedSubscription->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
            $lockedSubscription->is_primary = true;
            $lockedSubscription->save();
            $this->syncUser($lockedUser, $lockedSubscription);
            return $lockedSubscription->fresh();
        });
    }

    public function revoke(User $user, Subscription $subscription): bool
    {
        if ((int)$subscription->user_id !== (int)$user->id) {
            abort(403, __('Subscription does not belong to the user'));
        }
        if ($subscription->is_primary) {
            abort(422, __('Please set another primary subscription first'));
        }
        $subscription->status = 'revoked';
        $subscription->save();
        return true;
    }

    public function forUser(User $user)
    {
        if (!$this->available()) return collect();
        return Subscription::where('user_id', $user->id)->with('plan')->orderByDesc('is_primary')->orderByDesc('id')->get();
    }

    public function context(User $user, Subscription $subscription): User
    {
        $context = clone $user;
        foreach (['plan_id', 'group_id', 'speed_limit', 'device_limit', 'transfer_enable', 'u', 'd', 'expired_at', 'token', 'uuid', 'auto_renewal'] as $field) {
            $context->{$field} = $subscription->{$field};
        }
        $context->subscription_id = $subscription->id;
        return $context;
    }

    public function byToken(string $token): ?Subscription
    {
        if (!$this->available()) return null;
        return Subscription::where('token', $token)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('expired_at')->orWhere('expired_at', '>=', time());
            })->first();
    }

    public function byNodeUserId($nodeUserId): ?Subscription
    {
        if (!$this->available() || !is_numeric($nodeUserId)) return null;
        return Subscription::where('node_user_id', (int)$nodeUserId)->first();
    }

    private function applyPlan(Subscription $subscription, Plan $plan, ?string $period, bool $keepTraffic): void
    {
        $subscription->transfer_enable = (int)$plan->transfer_enable * 1073741824;
        if (!$keepTraffic) {
            $subscription->u = 0;
            $subscription->d = 0;
        }
        if ($period === 'onetime_price') {
            $subscription->expired_at = null;
            return;
        }
        $base = $subscription->expired_at && $subscription->expired_at > time() ? $subscription->expired_at : time();
        $months = [
            'month_price' => 1,
            'quarter_price' => 3,
            'half_year_price' => 6,
            'year_price' => 12,
            'two_year_price' => 24,
            'three_year_price' => 36
        ][$period] ?? 1;
        $subscription->expired_at = strtotime("+{$months} month", $base);
    }

    private function syncUser(User $user, Subscription $subscription): void
    {
        $user->plan_id = $subscription->plan_id;
        $user->group_id = $subscription->group_id;
        $user->speed_limit = $subscription->speed_limit;
        $user->device_limit = $subscription->device_limit;
        $user->transfer_enable = $subscription->transfer_enable;
        $user->u = $subscription->u;
        $user->d = $subscription->d;
        $user->expired_at = $subscription->expired_at;
        $user->token = $subscription->token;
        $user->uuid = $subscription->uuid;
        $user->auto_renewal = $subscription->auto_renewal;
        // v2_user.token 是主订阅 token 的镜像。稳态下值不变、观察者根本不会触发；真正变化
        // 只发生在 setPrimary 换主时，那时新值是另一条订阅已有的 token，旧值仍活在它自己
        // 的订阅行上 —— 一个用户可以合法地同时有多个活 token。
        TokenRotationContext::using('superseded', function () use ($user) {
            $user->save();
        });
    }
}
