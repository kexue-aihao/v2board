<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribeUserAgentSummary extends Model
{
    protected $table = 'v2_subscribe_user_agent_summary';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = true;

    protected $casts = [
        'user_id' => 'integer',
        'hit_count' => 'integer',
        'first_seen_at' => 'timestamp',
        'last_seen_at' => 'timestamp',
        'recent_audit_id' => 'integer',
        'recent_subscription_id' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
