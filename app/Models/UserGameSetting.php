<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserGameSetting extends Model
{
    protected $table = 'v2_user_game_setting';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'user_id' => 'integer',
        'bet_gb' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
