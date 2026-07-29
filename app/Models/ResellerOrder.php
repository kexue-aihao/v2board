<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResellerOrder extends Model
{
    protected $table = 'v2_reseller_order';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function reseller()
    {
        return $this->belongsTo(ResellerAccount::class, 'reseller_id');
    }

    public function platformOrder()
    {
        return $this->belongsTo(Order::class, 'platform_order_id');
    }

    public function plan()
    {
        return $this->belongsTo(ResellerPlan::class, 'reseller_plan_id');
    }

    public function payment()
    {
        return $this->belongsTo(ResellerPayment::class, 'reseller_payment_id');
    }

    public function sharedSubscription()
    {
        return $this->belongsTo(ResellerSharedSubscription::class, 'shared_subscription_id');
    }
}
