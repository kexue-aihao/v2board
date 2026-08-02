<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 「IP + 账号 + UA 指纹」三元组的累积记录，由 audit:ip-link 从
 * v2_subscribe_request_log 增量聚合出来，不在订阅拉取写路径上产生。
 */
class IpAccountLink extends Model
{
    protected $table = 'v2_ip_account_link';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = true;

    protected $casts = [
        'user_id' => 'integer',
        'hit_count' => 'integer',
        'first_seen_at' => 'timestamp',
        'last_seen_at' => 'timestamp',
        'last_log_id' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
