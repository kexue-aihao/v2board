<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IpLocationCache extends Model
{
    protected $table = 'v2_ip_location_cache';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = true;

    protected $casts = [
        'ip_version' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'resolved_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
