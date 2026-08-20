<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficRewardLog extends Model
{
    protected $table = 'v2_traffic_reward_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = ['metadata' => 'array', 'reward_bytes' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
