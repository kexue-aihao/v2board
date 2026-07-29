<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResellerAccount extends Model
{
    protected $table = 'v2_reseller_account';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['password'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function customers()
    {
        return $this->hasMany(ResellerCustomer::class, 'reseller_id');
    }

    public function orders()
    {
        return $this->hasMany(ResellerOrder::class, 'reseller_id');
    }

    public function reviewLogs()
    {
        return $this->hasMany(ResellerReviewLog::class, 'reseller_id');
    }

    public function plans()
    {
        return $this->hasMany(ResellerPlan::class, 'reseller_id');
    }

    public function payments()
    {
        return $this->hasMany(ResellerPayment::class, 'reseller_id');
    }

    public function accountStatus(): string
    {
        return (string)($this->attributes['reseller_status'] ?? $this->attributes['status'] ?? 'pending');
    }

    public function storeStatus(): string
    {
        return (string)($this->attributes['store_status'] ?? $this->attributes['status'] ?? 'pending');
    }

    public function isAccountActive(): bool
    {
        return $this->accountStatus() === 'active';
    }

    public function isStoreActive(): bool
    {
        return $this->storeStatus() === 'active';
    }

    public function isFullyActive(): bool
    {
        return $this->isAccountActive() && $this->isStoreActive();
    }
}
