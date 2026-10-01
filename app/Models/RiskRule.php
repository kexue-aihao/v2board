<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiskRule extends Model
{
    protected $table = 'v2_risk_rule';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'enabled' => 'integer',
        'sort' => 'integer',
        // 命中该规则给风险值加多少分（0-100），累加后封顶 100。
        'weight' => 'integer',
        // threshold 是 decimal(18,8)，PDO 会返回 "3.00000000"。转成 float 让管理端拿到 3
        // 而不是补零字符串；引擎本来也按 float 比较，精度足够表达计数与使用率两类阈值。
        'threshold' => 'float',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
