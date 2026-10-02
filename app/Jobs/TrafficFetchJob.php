<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\DynamicRateService;
use Illuminate\Support\Facades\Redis;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;

    /** @var array<int, float> [node_user_id => 本次实际生效的倍率] */
    protected $rates;

    public $tries = 3;
    public $timeout = 10;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol, array $rates = [])
    {
        $this->onQueue('traffic_fetch');
        $this->data =$data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->rates = $rates;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        foreach(array_keys($this->data) as $userId){
            // 有效倍率由 UserService::trafficFetch 解析好传进来；没有传的入口（老任务、
            // 别处直接 dispatch）回落到节点自身的倍率，行为与改造前逐字节一致。
            $rate = $this->rates[$userId] ?? $this->server['rate'];
            Redis::hincrby('v2board_upload_traffic', $userId, $this->data[$userId][0] * $rate);
            Redis::hincrby('v2board_download_traffic', $userId, $this->data[$userId][1] * $rate);
            // 峰值判定用的原始字节，刻意不乘倍率：拿乘过的数去判倍率会自我放大 ——
            // 倍率抬高账面流量，账面流量又抬高判定用的速率，一圈比一圈高。
            Redis::hincrby(DynamicRateService::KEY_RAW_UP, $userId, $this->data[$userId][0]);
            Redis::hincrby(DynamicRateService::KEY_RAW_DOWN, $userId, $this->data[$userId][1]);
        }
    }
}
