<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribeRequestLog extends Model
{
    protected $table = 'v2_subscribe_request_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = true;

    protected $casts = [
        'requested_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
