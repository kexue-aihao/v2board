<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NodeConnectionLog extends Model
{
    protected $table = 'v2_node_connection_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = true;

    protected $casts = [
        'report_count' => 'integer',
        'first_seen_at' => 'timestamp',
        'last_seen_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
