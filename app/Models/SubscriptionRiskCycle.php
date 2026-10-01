<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionRiskCycle extends Model
{
    protected $table = 'v2_subscription_risk_cycle';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = true;

    // metrics 与 risk_reasons 都刻意不加 cast：两列都在服务里手工 json_encode（metrics 要
    // JSON_UNESCAPED_UNICODE，array cast 做不到），加 cast 会双重编码。
    protected $casts = [
        'distinct_ip_count' => 'integer',
        'city_count' => 'integer',
        'region_count' => 'integer',
        'country_count' => 'integer',
        // 可空：没有证据的周期不写分数（NULL = 没判过，0 = 判过且干净）。
        'risk_score' => 'integer',
        'cycle_start' => 'timestamp',
        'cycle_end' => 'timestamp',
        'evaluated_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
