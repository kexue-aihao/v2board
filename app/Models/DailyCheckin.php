<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyCheckin extends Model
{
    protected $table = 'v2_daily_checkin';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = ['reward_bytes' => 'integer', 'streak_days' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
