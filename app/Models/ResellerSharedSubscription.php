<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResellerSharedSubscription extends Model
{
    protected $table = 'v2_reseller_shared_subscription';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'member_limit' => 'integer',
        'member_count' => 'integer',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function plan()
    {
        return $this->belongsTo(ResellerPlan::class, 'reseller_plan_id');
    }

    public function members()
    {
        return $this->hasMany(ResellerSharedSubscriptionMember::class, 'shared_subscription_id');
    }
}
