<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribeBlockRule extends Model
{
    protected $table = 'v2_subscribe_block_rule';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = true;

    protected $casts = [
        'user_id' => 'integer',
        'subscription_id' => 'integer',
        'blocked_by' => 'integer',
        'blocked_at' => 'timestamp',
        'expires_at' => 'timestamp',
        'released_by' => 'integer',
        'released_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
