<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramLoginLink extends Model
{
    protected $table = 'v2_telegram_login_link';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'expires_at' => 'timestamp',
        'consumed_at' => 'timestamp'
    ];
}
