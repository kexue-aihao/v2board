<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramRegistration extends Model
{
    protected $table = 'v2_telegram_registration';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
