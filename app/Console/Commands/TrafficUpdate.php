<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

class TrafficUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'traffic:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '流量更新任务';

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
        if (Redis::exists('traffic_reset_lock')) {
            return;
        }
        $uploads = Redis::hgetall('v2board_upload_traffic');
        Redis::del('v2board_upload_traffic');
        $downloads = Redis::hgetall('v2board_download_traffic');
        Redis::del('v2board_download_traffic');
        if (empty($uploads) && empty($downloads)) {
            return;
        }

        try {
            DB::beginTransaction();
            $keys = array_unique(array_map('intval', array_merge(array_keys($uploads), array_keys($downloads))));
            $subscriptions = Schema::hasTable('v2_subscription')
                ? Subscription::whereIn('node_user_id', $keys)->get()->keyBy('node_user_id')
                : collect();
            $legacyIds = array_values(array_diff($keys, $subscriptions->keys()->map('intval')->all()));
            $users = User::whereIn('id', $legacyIds)->get()->keyBy('id');
            $time = time();
            $primaryByUserId = [];
            $touchedUserIds = [];
            foreach ($subscriptions as $subscription) {
                $key = (string)$subscription->node_user_id;
                $subscription->u += (int)($uploads[$key] ?? 0);
                $subscription->d += (int)($downloads[$key] ?? 0);
                $subscription->updated_at = $time;
                $subscription->save();
                $touchedUserIds[(int)$subscription->user_id] = true;
                if ($subscription->is_primary) {
                    $primaryByUserId[(int)$subscription->user_id] = $subscription;
                }
            }
            foreach ($users as $user) {
                $key = (string)$user->id;
                $user->u += (int)($uploads[$key] ?? 0);
                $user->d += (int)($downloads[$key] ?? 0);
                $user->t = $time;
                $user->updated_at = $time;
                $user->save();
            }
            // v2_user 始终是主订阅的镜像：管理端用户列表、CSV 导出、流量预警邮件、
            // Telegram 流量查询、仪表盘在线人数都直接读 v2_user 的 u/d/t，只写
            // v2_subscription 会让这些地方永远显示 0。这里用赋值而不是累加，
            // 所以即使漏过几轮也能自动追平，不会重复计数。
            $mirrorIds = array_values(array_diff(array_keys($touchedUserIds), $users->keys()->all()));
            foreach (User::whereIn('id', $mirrorIds)->get() as $user) {
                $primary = $primaryByUserId[(int)$user->id] ?? null;
                if ($primary) {
                    $user->u = (int)$primary->u;
                    $user->d = (int)$primary->d;
                }
                // 只有非主订阅在跑流量也算在线，所以 t 无条件更新。
                $user->t = $time;
                $user->updated_at = $time;
                $user->save();
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('流量更新失败: ' . $e->getMessage());
            return;
        }
    }
}
