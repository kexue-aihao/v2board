<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 管理员提醒台账。一行 = 一次「这个订阅在这个窗口的判定超过了阈值」：
 *   sent_at 为空       —— 已进入待发队列（发送失败会留在这一状态，下轮补发）
 *   sent_at 非空       —— 已经给管理员发过摘要
 *   handled_at 为空    —— 尚未处理，期间不再为同一订阅产生新提醒行
 *
 * notify_once(subscription_id, source, window_start) 唯一键是幂等闸门。
 */
class SubscriptionRiskNotify extends Model
{
    protected $table = 'v2_subscription_risk_notify';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = true;

    public const SOURCE_CYCLE = 'cycle';
    public const SOURCE_MANUAL = 'manual';

    // reasons 与判定表一样手工 json_encode（要 JSON_UNESCAPED_UNICODE），不加 cast。
    // sent_at / handled_at 刻意不加 timestamp cast：管理端直接把它们当 Unix 秒返回，
    // 加了 cast 会变成 Carbon 对象，转 int 时反而要额外绕一圈。
    protected $casts = [
        'user_id' => 'integer',
        'subscription_id' => 'integer',
        'window_start' => 'integer',
        'window_end' => 'integer',
        'risk_score' => 'integer',
        'recipients' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
