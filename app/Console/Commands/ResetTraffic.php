<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

class ResetTraffic extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:traffic';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '流量清空';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->builder = User::where('expired_at', '!=', NULL)
            ->where('expired_at', '>', time());
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        ini_set('memory_limit', -1);
        $lockToken = bin2hex(random_bytes(16));
        $locked = Redis::set('traffic_reset_lock', $lockToken, 'EX', 300, 'NX');
        if (!$locked) {
            $this->warn('另一个重置进程正在运行，跳过');
            return self::SUCCESS;
        }
        try {
            if (Schema::hasTable('v2_subscription') && Subscription::exists()) {
                $this->resetSubscriptions();
                return;
            }
            $resetMethods = Plan::select(
            DB::raw("GROUP_CONCAT(`id`) as plan_ids"),
            DB::raw("reset_traffic_method as method")
        )
            ->groupBy('reset_traffic_method')
            ->get()
            ->toArray();
        foreach ($resetMethods as $resetMethod) {
            $planIds = explode(',', $resetMethod['plan_ids']);
            switch (true) {
                case ($resetMethod['method'] === NULL): {
                    $resetTrafficMethod = config('v2board.reset_traffic_method', 0);
                    $builder = with(clone($this->builder))->whereIn('plan_id', $planIds);
                    switch ((int)$resetTrafficMethod) {
                        // month first day
                        case 0:
                            $this->resetByMonthFirstDay($builder);
                            break;
                        // expire day
                        case 1:
                            $this->resetByExpireDay($builder);
                            break;
                        // no action
                        case 2:
                            break;
                        // year first day
                        case 3:
                            $this->resetByYearFirstDay($builder);
                            break;
                        // year expire day
                        case 4:
                            $this->resetByExpireYear($builder);
                            break;
                    }
                    break;
                }
                case ($resetMethod['method'] === 0): {
                    $builder = with(clone($this->builder))->whereIn('plan_id', $planIds);
                    $this->resetByMonthFirstDay($builder);
                    break;
                }
                case ($resetMethod['method'] === 1): {
                    $builder = with(clone($this->builder))->whereIn('plan_id', $planIds);
                    $this->resetByExpireDay($builder);
                    break;
                }
                case ($resetMethod['method'] === 2): {
                    break;
                }
                case ($resetMethod['method'] === 3): {
                    $builder = with(clone($this->builder))->whereIn('plan_id', $planIds);
                    $this->resetByYearFirstDay($builder);
                    break;
                }
                case ($resetMethod['method'] === 4): {
                    $builder = with(clone($this->builder))->whereIn('plan_id', $planIds);
                    $this->resetByExpireYear($builder);
                    break;
                }
            }
        }
        } finally {
            $current = Redis::get('traffic_reset_lock');
            if ($current === $lockToken) {
                Redis::del('traffic_reset_lock');
            }
        }
    }

    private function resetSubscriptions(): void
    {
        $today = date('d');
        $lastDay = date('t');
        Subscription::where('status', 'active')->where('expired_at', '>', time())->with('plan')->chunkById(100, function ($subscriptions) use ($today, $lastDay) {
            foreach ($subscriptions as $subscription) {
                $plan = $subscription->plan;
                if (!$plan) continue;
                $method = $plan->reset_traffic_method === null ? (int)config('v2board.reset_traffic_method', 0) : (int)$plan->reset_traffic_method;
            $shouldReset = $method === 0 && $today === '01';
            if ($method === 1) {
                $expireDay = date('d', $subscription->expired_at);
                $shouldReset = $expireDay === $today || ($today === $lastDay && $expireDay >= $lastDay);
            } elseif ($method === 3) {
                $shouldReset = date('md') === '0101';
            } elseif ($method === 4) {
                $shouldReset = date('m-d') === date('m-d', $subscription->expired_at);
            }
            if ($shouldReset && (int)$subscription->last_reset_at < strtotime(date('Y-m-d'))) {
                $subscription->u = 0;
                $subscription->d = 0;
                $subscription->transfer_enable = (int)$plan->transfer_enable * 1073741824;
                $subscription->last_reset_at = time();
                $subscription->save();
                if ($subscription->is_primary) {
                    $user = \App\Models\User::find($subscription->user_id);
                    if ($user) {
                        $user->transfer_enable = $subscription->transfer_enable;
                        $user->save();
                    }
                }
            }
            }
        });
    }

    private function resetByExpireYear($builder): void
    {
        $users = [];
        foreach ($builder->get() as $item) {
            $expireDay = date('m-d', $item->expired_at);
            $today = date('m-d');
            if ($expireDay === $today) {
                array_push($users, $item->id);
            }
        }
        $this->retryTransaction(function () use ($users) {
            User::whereIn('id', $users)->update([
                'u' => 0,
                'd' => 0
            ]);
        });
    }

    private function resetByYearFirstDay($builder): void
    {
        if ((string)date('md') === '0101') {
            $this->retryTransaction(function () use ($builder) {
                $builder->update([
                    'u' => 0,
                    'd' => 0
                ]);
            });
        }
    }

    private function resetByMonthFirstDay($builder): void
    {
        if ((string)date('d') === '01') {
            $this->retryTransaction(function () use ($builder) {
                $builder->update([
                    'u' => 0,
                    'd' => 0
                ]);
            });
        }
    }

    private function resetByExpireDay($builder): void
    {
        $lastDay = date('t');
        $users = [];
        $today = date('d');
        foreach ($builder->get() as $item) {
            $expireDay = date('d', $item->expired_at);

            if (($expireDay === $today) ||(($today === $lastDay) && $expireDay >= $lastDay)) {
                if (time() < $item->expired_at - 2160000) {
                    array_push($users, $item->id);
                }
            }

        }
        $this->retryTransaction(function () use ($users) {
            User::whereIn('id', $users)->update([
                'u' => 0,
                'd' => 0
            ]);
        });
    }

    private function retryTransaction($callback)
    {
        $attempts = 0;
        $maxAttempts = 3;
        while ($attempts < $maxAttempts) {
            try {
                DB::transaction($callback);
                return;
            } catch (\Exception $e) {
                $attempts++;
                if ($attempts >= $maxAttempts || strpos($e->getMessage(), '40001') === false && strpos(strtolower($e->getMessage()), 'deadlock') === false) {
                    $telegramService = new TelegramService();
                    $message = sprintf(
                        date('Y/m/d H:i:s') . "用户流量重置失败：" . $e->getMessage()
                    );
                    $telegramService->sendMessageWithAdmin($message);
                    abort(500, '用户流量重置失败'. $e->getMessage());
                }
                sleep(5);
            }
        }
    }
}
