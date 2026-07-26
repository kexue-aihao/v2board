<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Utils\Helper;
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
        if (!$this->available() || !$user->plan_id) return null;
        $subscription = Subscription::where('user_id', $user->id)->first();
        if (!$subscription) {
            $subscription = new Subscription();
            $subscription->user_id = $user->id;
            $subscription->plan_id = $user->plan_id;
            $subscription->token = $user->token;
            $subscription->uuid = $user->uuid;
            $subscription->node_user_id = 2000000000 + $user->id;
            $subscription->group_id = $user->group_id;
            $subscription->speed_limit = $user->speed_limit;
            $subscription->device_limit = $user->device_limit;
            $subscription->transfer_enable = $user->transfer_enable;
            $subscription->u = $user->u;
            $subscription->d = $user->d;
            $subscription->status = 'active';
            $subscription->is_primary = true;
            $subscription->auto_renewal = $user->auto_renewal;
            $subscription->started_at = $user->created_at ?: time();
            $subscription->expired_at = $user->expired_at ?: null;
            $subscription->save();
        }
        if (!$subscription->is_primary) {
            $this->setPrimary($user, $subscription);
        }
        $this->syncUser($user, $subscription);
        return $subscription->fresh();
    }

    public function create(User $user, Plan $plan, ?string $period = null, bool $primary = false): Subscription
    {
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

        if ($primary || !$this->primary($user)) {
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
        $subscription->u = 0;
        $subscription->d = 0;
        $subscription->last_reset_at = time();
        $subscription->save();
        if ($subscription->is_primary) {
            $this->syncUser(User::findOrFail($subscription->user_id), $subscription);
        }
        return $subscription->fresh();
    }

    public function setPrimary(User $user, Subscription $subscription): Subscription
    {
        if ((int)$subscription->user_id !== (int)$user->id) {
            abort(403, __('Subscription does not belong to the user'));
        }
        if ($subscription->status === 'revoked') {
            abort(422, __('Revoked subscription cannot be primary'));
        }
        DB::transaction(function () use ($user, $subscription) {
            Subscription::where('user_id', $user->id)->update(['is_primary' => false]);
            $subscription->is_primary = true;
            $subscription->save();
            $this->syncUser($user, $subscription);
        });
        return $subscription->fresh();
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
        $user->save();
    }
}
