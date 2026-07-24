<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $table = 'v2_subscription';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'started_at' => 'timestamp',
        'expired_at' => 'timestamp',
        'last_reset_at' => 'timestamp',
        'next_reset_at' => 'timestamp',
        'enabled' => 'boolean',
        'is_primary' => 'boolean',
        'auto_renewal' => 'boolean'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
