<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionRiskCycle extends Model
{
    protected $table = 'v2_subscription_risk_cycle';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = true;

    protected $casts = [
        'cycle_start' => 'timestamp',
        'cycle_end' => 'timestamp',
        'evaluated_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
