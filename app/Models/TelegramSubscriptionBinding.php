<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramSubscriptionBinding extends Model
{
    protected $table = 'v2_telegram_subscription_binding';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['subscription_token_hash'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'bound_at' => 'timestamp',
        'last_checked_at' => 'timestamp'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
}
