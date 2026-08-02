<?php

namespace App\Services;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\BalanceLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\Subscription;
use Illuminate\Support\Facades\Schema;

class UserService
{
    private function calcResetDayByMonthFirstDay()
    {
        $today = date('d');
        $lastDay = date('d', strtotime('last day of +0 months'));
        return $lastDay - $today;
    }

    private function calcResetDayByExpireDay(int $expiredAt)
    {
        $day = date('d', $expiredAt);
        $today = date('d');
        $lastDay = date('d', strtotime('last day of +0 months'));
        if ((int)$day >= (int)$today && (int)$day >= (int)$lastDay) {
            return $lastDay - $today;
        }
        if ((int)$day >= (int)$today) {
            return $day - $today;
        }

        return $lastDay - $today + $day;
    }

    private function calcResetDayByYearFirstDay(): int
    {
        $nextYear = strtotime(date("Y-01-01", strtotime('+1 year')));
        return (int)(($nextYear - time()) / 86400);
    }

    private function calcResetDayByYearExpiredAt(int $expiredAt): int
    {
        $md = date('m-d', $expiredAt);
        $nowYear = strtotime(date("Y-{$md}"));
        $nextYear = strtotime('+1 year', $nowYear);
        if ($nowYear > time()) {
            return (int)(($nowYear - time()) / 86400);
        }
        return (int)(($nextYear - time()) / 86400);
    }

    public function getResetDay(User $user)
    {
        if (!isset($user->plan)) {
            if ($user->plan_id === NULL) return null;
            $user->plan = Plan::find($user->plan_id);
        }
        if ($user->expired_at <= time() || $user->expired_at === NULL) return null;
        // if reset method is not reset
        if ($user->plan->reset_traffic_method === 2) return null;
        switch (true) {
            case ($user->plan->reset_traffic_method === NULL): {
                $resetTrafficMethod = config('v2board.reset_traffic_method', 0);
                switch ((int)$resetTrafficMethod) {
                    // month first day
                    case 0:
                        return $this->calcResetDayByMonthFirstDay();
                    // expire day
                    case 1:
                        return $this->calcResetDayByExpireDay($user->expired_at);
                    // no action
                    case 2:
                        return null;
                    // year first day
                    case 3:
                        return $this->calcResetDayByYearFirstDay();
                    // year expire day
                    case 4:
                        return $this->calcResetDayByYearExpiredAt($user->expired_at);
                }
                break;
            }
            case ($user->plan->reset_traffic_method === 0): {
                return $this->calcResetDayByMonthFirstDay();
            }
            case ($user->plan->reset_traffic_method === 1): {
                return $this->calcResetDayByExpireDay($user->expired_at);
            }
            case ($user->plan->reset_traffic_method === 2): {
                return null;
            }
            case ($user->plan->reset_traffic_method === 3): {
                return $this->calcResetDayByYearFirstDay();
            }
            case ($user->plan->reset_traffic_method === 4): {
                return $this->calcResetDayByYearExpiredAt($user->expired_at);
            }
        }
        return null;
    }

    public function getResetPeriod(User $user)
    {
        if ($user->plan_id === NULL) return null;
        $plan = Plan::find($user->plan_id);
        if ($user->expired_at <= time() || $user->expired_at === NULL) return null;
        // if reset method is not reset
        if ($plan->reset_traffic_method === 2) return null;
        switch (true) {
            case ($plan->reset_traffic_method === NULL) : {
                $resetTrafficMethod = config('v2board.reset_traffic_method', 0);
                switch ((int)$resetTrafficMethod) {
                    case 0:
                        return 1;
                    case 1:
                        return 30;
                    case 2:
                        return null;
                    case 3:
                        return 12;
                    case 4:
                        return 365;
                }
                break;
            }
            case ($plan->reset_traffic_method === 0): {
                return 1;
            }
            case ($plan->reset_traffic_method === 1): {
                return 30;
            }
            case ($plan->reset_traffic_method === 2): {
                return null;
            }
            case ($plan->reset_traffic_method === 3): {
                return 12;
            }
            case ($plan->reset_traffic_method === 4): {
                return 365;
            }
        }    
        return null;
    }

    public function isAvailable(User $user)
    {
        if (!$user->banned && $user->transfer_enable && ($user->expired_at > time() || $user->expired_at === NULL)) {
            return true;
        }
        return false;
    }

    public function getAvailableUsers()
    {
        return User::whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                ->orWhereNull('expired_at');
            })
            ->where('banned', 0)
            ->get();
    }

    public function getDeviceLimitedUsers()
    {
        $legacy = User::whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                ->orWhereNull('expired_at');
            })
            ->where('banned', 0)
            ->where('device_limit','>', 0)
            ->select('id')
            ->get();
        if (!Schema::hasTable('v2_subscription')) return $legacy;
        $subscriptions = Subscription::whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())->orWhereNull('expired_at');
            })
            ->where('status', 'active')
            ->where('device_limit', '>', 0)
            ->whereHas('user', function ($query) {
                $query->where('banned', 0);
            })
            ->selectRaw('node_user_id as id')
            ->get();
        $legacy = $legacy->filter(function ($user) {
            return !Subscription::where('user_id', $user->id)->exists();
        });
        return $subscriptions->concat($legacy)->values();
    }

    public function getUnAvailbaleUsers()
    {
        return User::where(function ($query) {
            $query->where('expired_at', '<', time())
                ->orWhere('expired_at', 0);
        })
            ->where(function ($query) {
            $query->where('plan_id', NULL)
                ->orWhere('transfer_enable', 0);
        })
            ->get();
    }

    public function getUsersByIds($ids)
    {
        return User::whereIn('id', $ids)->get();
    }

    public function getAllUsers()
    {
        return User::all();
    }

    // 唯一的余额变更原语：加锁读用户行 → 基于新鲜值加减 → 拒绝透支 → 落库 → 记资金流水。
    // 调用方必须已在事务内（lockForUpdate 才有效）；现有调用点均满足。
    // $type/$meta 供审计：meta 可含 source_type/source_id/unique_key/remark。
    // 传了 unique_key 时具备幂等性：同键重复入账（并发/重试）直接视为成功、不再改余额。
    public function addBalance(int $userId, int $balance, ?string $type = null, array $meta = []): bool
    {
        $user = User::lockForUpdate()->find($userId);
        if (!$user) {
            return false;
        }
        // 幂等：并发的同键写会在 user 行锁上串行，先提交者的流水对后来者可见 → 后者在此 return。
        $uniqueKey = $meta['unique_key'] ?? null;
        if ($uniqueKey !== null && $this->balanceLogAvailable()
            && BalanceLog::where('unique_key', $uniqueKey)->exists()) {
            return true;
        }
        $before = (int)$user->balance;
        $after = $before + $balance;
        if ($after < 0) {
            return false;
        }
        $user->balance = $after;
        if (!$user->save()) {
            return false;
        }
        $this->recordBalanceLog($userId, $before, $after, $balance, $type, $meta);
        return true;
    }

    // 资金流水：每次余额变更写一行 v2_balance_log，供事后对账/审计。表未建（升级窗口）时静默跳过。
    private function recordBalanceLog(int $userId, int $before, int $after, int $amount, ?string $type, array $meta): void
    {
        if (!$this->balanceLogAvailable()) {
            return;
        }
        BalanceLog::create([
            'user_id' => $userId,
            'balance_before' => $before,
            'balance_after' => $after,
            'amount' => $amount,
            'type' => $type ?? 'unknown',
            'source_type' => $meta['source_type'] ?? null,
            'source_id' => $meta['source_id'] ?? null,
            'unique_key' => $meta['unique_key'] ?? null,
            'remark' => $meta['remark'] ?? null
        ]);
    }

    // 常驻进程里只探一次表是否存在，避免每次入账都打一条 information_schema 查询。
    private static $balanceLogTableExists = null;
    private function balanceLogAvailable(): bool
    {
        if (self::$balanceLogTableExists === null) {
            self::$balanceLogTableExists = Schema::hasTable('v2_balance_log');
        }
        return self::$balanceLogTableExists;
    }

    public function isNotCompleteOrderByUserId(int $userId): bool
    {
        return Order::where('user_id', $userId)
            ->whereIn('status', [0, 1])
            ->exists();
    }

    public function trafficFetch(array $server, string $protocol, array $data)
    {
        TrafficFetchJob::dispatch($data, $server, $protocol);
        StatUserJob::dispatch($data, $server, $protocol, 'd');
        StatServerJob::dispatch($data, $server, $protocol, 'd');
    }

    public static function getMaxId()
    {
        return User::max('id');
    }
}
