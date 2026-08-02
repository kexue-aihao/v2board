<?php

namespace App\Console\Commands;

use App\Models\CommissionLog;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CheckCommission extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:commission';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '返佣服务';

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
        $this->autoCheck();
        $this->autoPayCommission();
    }

    public function autoCheck()
    {
        if ((int)config('v2board.commission_auto_check_enable', 1)) {
            Order::where('commission_status', 0)
                ->where('invite_user_id', '!=', NULL)
                ->whereIn('status', [3, 4])
                ->where('updated_at', '<=', strtotime('-3 day', time()))
                ->update([
                    'commission_status' => 1
                ]);
        }
    }

    public function autoPayCommission()
    {
        $orders = Order::where('commission_status', 1)
            ->where('invite_user_id', '!=', NULL)
            ->whereIn('status', [3, 4])   // 只对已完成/已折抵的订单派佣金：原来不过滤订单状态，
            ->get();                       // 一张变成 commission_status=1 后又被取消(status=2)的单仍会派佣。
        foreach ($orders as $order) {
            DB::beginTransaction();
            if (!$this->payHandle($order->invite_user_id, $order)) {
                DB::rollBack();
                continue;
            }
            $order->commission_status = 2;
            if (!$order->save()) {
                DB::rollBack();
                continue;
            }
            DB::commit();
        }
    }

    public function payHandle($inviteUserId, Order $order)
    {
        $level = 3;
        if ((int)config('v2board.commission_distribution_enable', 0)) {
            $commissionShareLevels = [
                0 => (int)config('v2board.commission_distribution_l1'),
                1 => (int)config('v2board.commission_distribution_l2'),
                2 => (int)config('v2board.commission_distribution_l3')
            ];
        } else {
            $commissionShareLevels = [
                0 => 100
            ];
        }
        for ($l = 0; $l < $level; $l++) {
            $inviter = User::lockForUpdate()->find($inviteUserId);
            if (!$inviter) continue;
            if (!isset($commissionShareLevels[$l])) continue;
            $commissionBalance = (int)round($order->commission_balance * ($commissionShareLevels[$l] / 100));
            if (!$commissionBalance) continue;
            // 上级入账：提现关闭时进 balance（走 addBalance 加锁记流水），否则进 commission_balance（原子加）。
            // 金额已取整到分。原为无锁读改写：同一上级并发/多笔佣金会丢失更新。
            if ((int)config('v2board.withdraw_close_enable', 0)) {
                if (!(new \App\Services\UserService())->addBalance($inviter->id, $commissionBalance, 'commission_payout', [
                    'source_type' => 'order',
                    'source_id' => $order->id,
                    'remark' => $order->trade_no
                ])) {
                    DB::rollBack();
                    return false;
                }
            } else {
                if (User::where('id', $inviter->id)->update(['commission_balance' => DB::raw('commission_balance + ' . $commissionBalance)]) !== 1) {
                    DB::rollBack();
                    return false;
                }
            }
            if (!CommissionLog::create([
                'invite_user_id' => $inviteUserId,
                'user_id' => $order->user_id,
                'trade_no' => $order->trade_no,
                'order_amount' => $order->total_amount,
                'get_amount' => $commissionBalance
            ])) {
                DB::rollBack();
                return false;
            }
            $inviteUserId = $inviter->invite_user_id;
            // update order actual commission balance
            $order->actual_commission_balance = $order->actual_commission_balance + $commissionBalance;
        }
        return true;
    }

}
