<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResellerCustomer extends Model
{
    protected $table = 'v2_reseller_customer';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
