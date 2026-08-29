<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribeBlockRuleEvent extends Model
{
    protected $table = 'v2_subscribe_block_rule_event';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'rule_id' => 'integer',
        'actor_id' => 'integer',
        'metadata' => 'array',
        'created_at' => 'timestamp'
    ];
}
