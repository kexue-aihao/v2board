<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionRiskManual extends Model
{
    protected $table = 'v2_subscription_risk_manual';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = true;

    // 与 SubscriptionRiskCycle 同理：risk_reasons/metrics 在服务里手工
    // json_encode（要 JSON_UNESCAPED_UNICODE），不加 cast 免得双重编码。
    protected $casts = [
        'user_id' => 'integer',
        'subscription_id' => 'integer',
        // 可空：no_data 的行不写分数（NULL = 没判过，0 = 判过且干净）。
        'risk_score' => 'integer',
        'window_start' => 'integer',
        'window_end' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
