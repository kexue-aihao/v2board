<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResellerSharedInvitation extends Model
{
    protected $table = 'v2_reseller_shared_invitation';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['token_hash'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'expires_at' => 'timestamp',
        'accepted_at' => 'timestamp',
        'revoked_at' => 'timestamp',
    ];

    public function group()
    {
        return $this->belongsTo(ResellerSharedSubscription::class, 'shared_subscription_id');
    }
}
