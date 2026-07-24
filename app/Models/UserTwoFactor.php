<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserTwoFactor extends Model
{
    protected $table = 'v2_user_two_factor';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'enabled' => 'boolean',
        'confirmed_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
