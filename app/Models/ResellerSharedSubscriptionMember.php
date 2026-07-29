<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResellerSharedSubscriptionMember extends Model
{
    protected $table = 'v2_reseller_shared_subscription_member';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'joined_at' => 'timestamp',
        'removed_at' => 'timestamp',
    ];

    public function group()
    {
        return $this->belongsTo(ResellerSharedSubscription::class, 'shared_subscription_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
