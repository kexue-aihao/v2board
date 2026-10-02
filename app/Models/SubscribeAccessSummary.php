<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 订阅拉取聚合：一行 = 一个账号 × 一个订阅 × 一个 IP × 一个 User-Agent。
 *
 * 这是「订阅清洗网关」唯一的数据源。原始证据仍是 v2_subscribe_request_log，
 * 本表是它的降维视图：保留期内每次拉取都会累计 hit_count，保留期清理会连同
 * 原始日志一起把过期的行删掉，所以页面看到的窗口与保留期严格一致。
 *
 * subscription_id 用 0 表示「审计写入时还没有订阅」（无订阅或已删除），
 * 不用 NULL —— 唯一键里的 NULL 在 MySQL 下不参与去重，同一组合会无限插行。
 */
class SubscribeAccessSummary extends Model
{
    protected $table = 'v2_subscribe_access_summary';

    protected $dateFormat = 'U';

    protected $guarded = ['id'];

    public $timestamps = true;

    protected $casts = [
        'user_id' => 'integer',
        'subscription_id' => 'integer',
        'hit_count' => 'integer',
        'recent_audit_id' => 'integer',
        'first_seen_at' => 'timestamp',
        'last_seen_at' => 'timestamp',
        'location_resolved_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
