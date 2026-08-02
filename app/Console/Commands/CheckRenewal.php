<?php

namespace App\Console\Commands;

use App\Services\MailService;
use App\Services\PlanService;
use App\Services\OrderService;
use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Order;
use App\Models\Subscription;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use Exception;

class CheckRenewal extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:renewal';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '自动续费';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        ini_set('memory_limit', -1);
        if (Schema::hasTable('v2_subscription') && Subscription::exists()) {
            $this->handleSubscriptions();
            return;
        }
        $users = User::all();

        //$mailService = new MailService();
        foreach ($users as $user) {
            if ($user->auto_renewal && $user->plan_id !== NULL && $user->expired_at !== NULL && $user->expired_at > time() && $user->expired_at - time() < 86400 * 2) {
                try {
                    $latestOrder = Order::where('user_id', $user->id)
                        ->where('period', '!=', 'reset_price')
                        ->where('period', '!=', 'onetime_price')
                        ->where('period', '!=', 'deposit')
                        ->where('status', 3)
                        ->orderBy('created_at', 'desc')
                        ->first();
                    if (!$latestOrder) {
                        throw new Exception("No valid order");
                    }
                    $latestPeriod = $latestOrder->period;

                    $planService = new PlanService($user->plan_id);
                    $plan = $planService->plan;
                    if (!$plan) {
                        throw new Exception("No such plan");
                    }
                    if (!$plan->renew) {
                        throw new Exception('This subscription cannot be renewed');
                    }
                    if($user->balance < $plan[$latestPeriod]) {
                        throw new Exception('No enough balance');
                    }

                    DB::beginTransaction();
                    $order = new Order();
                    $orderService = new OrderService($order);
                    $order->user_id = $user->id;
                    $order->plan_id = $plan->id;
                    $order->period = $latestPeriod;
                    $order->trade_no = Helper::generateOrderNo();
                    $order->balance_amount = $plan[$latestPeriod];
                    $order->total_amount = 0;
                    $orderService->setVipDiscount($user);
                    $order->type = 2;
                    
                    // 余额扣款走 addBalance（加锁 + 记资金流水），余额不足返回 false；到期时间单独更新。
                    // （原为无锁绝对值回写：User::all() 快照余额可能已过期数十秒，循环期间用户并发
                    // 下单/取消会被那次绝对值 save 整列覆盖，把已花掉的余额写回来。）
                    $renewAmount = (int)$plan[$latestPeriod];
                    if (!(new \App\Services\UserService())->addBalance($user->id, -$renewAmount, 'renewal_deduct', [
                        'remark' => $order->trade_no
                    ])) {
                        DB::rollback();
                        throw new Exception('自动续费失败');
                    }
                    User::where('id', $user->id)->update([
                        'expired_at' => $this->getTime($latestPeriod, $user->expired_at)
                    ]);
                    $order->status = 3;
                    if (!$order->save()) {
                        DB::rollback();
                        throw new Exception('自动续费失败');
                    }
                    DB::commit();
                    //$mailService->remindAutorenewal($user);
                } catch (\Exception $e) {
                    $user->auto_renewal = 0;
                    if(!$user->save()){
                        info('用户自动续费失败,调整设置失败', [$e->getMessage() , $user]);
                    };
                }
            }
        }
    }

    private function handleSubscriptions(): void
    {
        $service = new \App\Services\SubscriptionService();
        foreach (Subscription::where('status', 'active')->where('auto_renewal', 1)->get() as $subscription) {
            if (!$subscription->expired_at || $subscription->expired_at <= time() || $subscription->expired_at - time() >= 86400 * 2) continue;
            $user = User::find($subscription->user_id);
            $latestOrder = Order::where(function ($query) use ($subscription) {
                $query->where('subscription_id', $subscription->id)
                    ->orWhere(function ($legacy) use ($subscription) {
                        $legacy->whereNull('subscription_id')->where('user_id', $subscription->user_id)->where('plan_id', $subscription->plan_id);
                    });
            })
                ->where('status', 3)
                ->whereNotIn('period', ['reset_price', 'onetime_price', 'deposit'])
                ->orderByDesc('created_at')->first();
            if (!$user || !$latestOrder) continue;
            $plan = (new PlanService($subscription->plan_id))->plan;
            $period = $latestOrder->period;
            if (!$plan || !$plan->renew || $plan[$period] === null || $user->balance < $plan[$period]) {
                $subscription->auto_renewal = 0;
                $subscription->save();
                continue;
            }
            try {
                DB::transaction(function () use ($service, $subscription, $user, $plan, $period) {
                    $amount = (int)$plan[$period];
                    // 余额扣款走 addBalance（加锁 + 记资金流水）；余额不足返回 false → 抛出 → 关自动续费。
                    if (!(new \App\Services\UserService())->addBalance($user->id, -$amount, 'renewal_deduct', [
                        'source_type' => 'subscription',
                        'source_id' => $subscription->id
                    ])) {
                        throw new Exception('No enough balance');
                    }
                    $service->renew($subscription, $plan, $period);
                    $order = new Order();
                    $order->user_id = $user->id;
                    $order->plan_id = $plan->id;
                    $order->subscription_id = $subscription->id;
                    $order->type = 2;
                    $order->period = $period;
                    $order->trade_no = Helper::generateOrderNo();
                    $order->balance_amount = $amount;
                    $order->total_amount = 0;
                    $order->status = 3;
                    $order->paid_at = time();
                    $order->save();
                });
            } catch (Exception $e) {
                $subscription->auto_renewal = 0;
                $subscription->save();
            }
        }
    }

    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        switch ($str) {
            case 'month_price':
                return strtotime('+1 month', $timestamp);
            case 'quarter_price':
                return strtotime('+3 month', $timestamp);
            case 'half_year_price':
                return strtotime('+6 month', $timestamp);
            case 'year_price':
                return strtotime('+12 month', $timestamp);
            case 'two_year_price':
                return strtotime('+24 month', $timestamp);
            case 'three_year_price':
                return strtotime('+36 month', $timestamp);
        }
    }
}
