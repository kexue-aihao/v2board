<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OAuthIdentity extends Model
{
    protected $table = 'v2_user_oauth_identity';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['provider_subject', 'provider_tenant'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
