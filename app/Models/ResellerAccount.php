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
}
