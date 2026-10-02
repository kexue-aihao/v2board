<?php

namespace App\Jobs;

use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StatUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $recordType;

    /** @var array<int, float> [node_user_id => 本次实际生效的倍率] */
    protected $rates;

    public $tries = 3;
    public $timeout = 60;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol, $recordType = 'd', array $rates = [])
    {
        $this->onQueue('stat');
        $this->data =$data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->recordType = $recordType;
        $this->rates = $rates;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $recordAt = strtotime(date('Y-m-d'));
        if ($this->recordType === 'm') {
            //
        }
        $attempt = 0;
        $maxAttempts = 3;
        while ($attempt < $maxAttempts) {
            try {
                DB::beginTransaction();
                foreach ($this->data as $nodeUserId => $trafficData) {
                    $subscription = Schema::hasTable('v2_subscription')
                        ? Subscription::where('node_user_id', $nodeUserId)->first()
                        : null;
                    $userId = $subscription ? $subscription->user_id : $nodeUserId;
                    // server_rate 记「这批流量实际按多少倍计费」：开了动态倍率就是算出来的
                    // 有效倍率，读取端统一 u * server_rate 还原，所以必须与计费用的是同一个数。
                    $rate = $this->rates[$nodeUserId] ?? $this->server['rate'];
                    $query = StatUser::where('record_at', $recordAt)
                        ->where('server_rate', $rate)
                        ->where('user_id', $userId);
                    if (Schema::hasColumn('v2_stat_user', 'subscription_id')) {
                        $query->where('subscription_id', $subscription ? $subscription->id : null);
                    }
                    $userdata = $query->first();
                    if ($userdata) {
                        $userdata->u += $trafficData[0];
                        $userdata->d += $trafficData[1];
                        $userdata->save();
                        continue;
                    }
                    $payload = [
                        'user_id' => $userId,
                        'server_rate' => $rate,
                        'u' => $trafficData[0],
                        'd' => $trafficData[1],
                        'record_type' => $this->recordType,
                        'record_at' => $recordAt
                    ];
                    if (Schema::hasColumn('v2_stat_user', 'subscription_id')) {
                        $payload['subscription_id'] = $subscription ? $subscription->id : null;
                    }
                    StatUser::create($payload);
                }
                DB::commit();
                return;
            } catch (\Exception $e) {
                DB::rollback();
                if (strpos($e->getMessage(), '40001') !== false || strpos(strtolower($e->getMessage()), 'deadlock') !== false) {
                    $attempt++;
                    if ($attempt < $maxAttempts) {
                        sleep(pow(2, $attempt));
                        continue;
                    }
                }
                abort(500, '用户统计数据失败'. $e->getMessage());
            }
        }
    }
}
