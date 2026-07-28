<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResellerPayment extends Model
{
    protected $table = 'v2_reseller_payment';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['config_encrypted'];
    protected $casts = [
        'enabled' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function reseller()
    {
        return $this->belongsTo(ResellerAccount::class, 'reseller_id');
    }
}
