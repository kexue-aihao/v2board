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
            foreach ($subscriptions as $subscription) {
                $key = (string)$subscription->node_user_id;
                $subscription->u += (int)($uploads[$key] ?? 0);
                $subscription->d += (int)($downloads[$key] ?? 0);
                $subscription->updated_at = $time;
                $subscription->save();
            }
            foreach ($users as $user) {
                $key = (string)$user->id;
                $user->u += (int)($uploads[$key] ?? 0);
                $user->d += (int)($downloads[$key] ?? 0);
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
