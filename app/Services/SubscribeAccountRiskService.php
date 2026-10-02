<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 账号级风险程度。
 *
 * 口径（需求原文）：账号的**阻断次数 ÷ 订阅拉取总次数**，范围是留存期内的全部记录
 * —— 也就是页面上能看到多久，就算多久。分母分子都来自 v2_subscribe_access_summary：
 * 每次拉取在同一个 UPSERT 里给 hit_count 加一、被阻断时再给 blocked_count 加一。
 *
 * 为什么单独落一张 v2_subscribe_account_risk 而不是每次现算：
 *   1. 列表要按它排序 / 按数值筛选，现算就得在 ORDER BY / WHERE 里塞 GROUP BY 子查询；
 *   2. 定时的超阈值提醒本来就要扫一遍全站账号，结果顺手落库，页面直接读；
 *   3. 「已处理」得有个按账号去重的地方存 —— 提醒要一直发到有人处理为止。
 *
 * 代价是页面上的百分比最多滞后一个刷新周期（15 分钟）。风险程度是 180 天窗口上的
 * 比值，这点滞后没有意义。
 */
class SubscribeAccountRiskService
{
    public const TABLE = 'v2_subscribe_account_risk';
    public const SUMMARY_TABLE = SubscribeCleanGatewayService::TABLE;
    public const DEFAULT_THRESHOLD = 60;
    // 一次提醒里最多列多少个账号，超出只报数量 —— 与「摘要 + 明细」的写法一致。
    public const NOTIFY_DETAIL_LIMIT = 20;

    private $availability;

    public function available(): bool
    {
        if ($this->availability !== null) {
            return $this->availability;
        }

        try {
            return $this->availability = Schema::hasTable(self::TABLE)
                && Schema::hasTable(self::SUMMARY_TABLE);
        } catch (\Throwable $e) {
            return $this->availability = false;
        }
    }

    /**
     * 触发通知的阈值（百分比）。配置键缺失或写坏时回落到 60。
     */
    public function threshold(): float
    {
        $raw = config('v2board.subscribe_risk_notify_threshold', self::DEFAULT_THRESHOLD);
        if ($raw === null || $raw === '') {
            return (float)self::DEFAULT_THRESHOLD;
        }

        return max(0.0, min(100.0, (float)$raw));
    }

    /**
     * 按 access_summary 重算台账。返回写入/更新的账号数。
     *
     * 一条 INSERT ... SELECT ... ON DUPLICATE KEY UPDATE 搞定：读一遍聚合表、按账号
     * 分组求比值。分多条语句写会在中途留下半新半旧的状态，而页面随时可能读到它。
     */
    public function refresh(): int
    {
        if (!$this->available()) {
            return 0;
        }

        $now = time();
        // GREATEST(...,1) 兜底除零；比值本身不可能超过 1（blocked <= hit），
        // ROUND 到两位小数是因为 risk_percent 是 decimal(5,2)。
        DB::statement(
            'INSERT INTO `' . self::TABLE . '` '
            . '(`user_id`,`total_count`,`blocked_count`,`risk_percent`,`first_seen_at`,`last_seen_at`,'
            . '`computed_at`,`created_at`,`updated_at`) '
            . 'SELECT `user_id`, SUM(`hit_count`), SUM(`blocked_count`), '
            . 'ROUND(SUM(`blocked_count`) / GREATEST(SUM(`hit_count`), 1) * 100, 2), '
            . 'MIN(`first_seen_at`), MAX(`last_seen_at`), ?, ?, ? '
            . 'FROM `' . self::SUMMARY_TABLE . '` GROUP BY `user_id` '
            . 'ON DUPLICATE KEY UPDATE '
            . '`total_count` = VALUES(`total_count`),'
            . '`blocked_count` = VALUES(`blocked_count`),'
            . '`risk_percent` = VALUES(`risk_percent`),'
            . '`first_seen_at` = VALUES(`first_seen_at`),'
            . '`last_seen_at` = VALUES(`last_seen_at`),'
            . '`computed_at` = VALUES(`computed_at`),'
            . '`updated_at` = VALUES(`updated_at`)',
            [$now, $now, $now]
        );

        // 清掉本轮没被覆盖的残留：账号的聚合行全被保留期清理掉之后，台账里那一行
        // 就没有依据了，留着会让页面继续显示一个早就不存在的账号。
        // 判据用 computed_at 而不是「重新查一遍聚合表」—— 后者是又一次全表聚合。
        DB::table(self::TABLE)->where('computed_at', '<>', $now)->delete();

        return (int)DB::table(self::TABLE)->count();
    }

    /**
     * 未处理且达到阈值的账号，按风险程度倒序。
     */
    public function pending(int $limit = 200): array
    {
        if (!$this->available()) {
            return [];
        }

        try {
            return DB::table(self::TABLE)
                ->whereNull('handled_at')
                ->where('risk_percent', '>=', $this->threshold())
                ->orderByDesc('risk_percent')
                ->orderBy('user_id')
                ->limit(max(1, $limit))
                ->get()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function pendingCount(): int
    {
        if (!$this->available()) {
            return 0;
        }

        try {
            return (int)DB::table(self::TABLE)
                ->whereNull('handled_at')
                ->where('risk_percent', '>=', $this->threshold())
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 标记已处理。处理后不再产生提醒 —— 这是「未处理就一直发」的另一半：
     * 15 分钟一条的节奏必须有一个明确的终止动作，否则只能靠改代码停下。
     */
    public function handle(int $userId, int $actorId, string $note = ''): bool
    {
        if (!$this->available() || $userId <= 0) {
            return false;
        }

        $now = time();

        return (bool)DB::table(self::TABLE)->where('user_id', $userId)->update([
            'handled_at' => $now,
            'handled_by' => $actorId > 0 ? $actorId : null,
            'handled_note' => trim($note) === '' ? null : trim($note),
            'updated_at' => $now
        ]);
    }

    /**
     * 记一笔「已提醒」。发送失败也要记 —— 否则下一轮会重复发同一条，而发送本身是
     * 队列投递，成败在这一层看不到。
     */
    public function markNotified(array $userIds, int $now = null): int
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds || !$this->available()) {
            return 0;
        }
        $now = $now ?: time();

        return (int)DB::table(self::TABLE)->whereIn('user_id', $userIds)->update([
            'notified_at' => $now,
            'notify_count' => DB::raw('`notify_count` + 1'),
            'updated_at' => $now
        ]);
    }

    /**
     * 给列表页批量取风险行，避免逐行查。
     *
     * @return array<int, object> user_id => 台账行
     */
    public function summaryForUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds || !$this->available()) {
            return [];
        }

        try {
            return DB::table(self::TABLE)
                ->whereIn('user_id', $userIds)
                ->get([
                    'user_id', 'total_count', 'blocked_count', 'risk_percent',
                    'computed_at', 'handled_at', 'notified_at', 'notify_count'
                ])
                ->keyBy('user_id')
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 列表筛选要用到的子查询：命中「风险程度 满足某数值条件」的账号。
     * 走 risk_percent 索引，返回 user_id，外层用 whereIn 收口。
     */
    public function userIdsMatchingRisk(string $condition, $value)
    {
        $allowed = ['=', '>', '>=', '<', '<='];
        if (!$this->available() || !in_array($condition, $allowed, true) || !is_numeric($value)) {
            return null;
        }

        return DB::table(self::TABLE)
            ->select('user_id')
            ->where('risk_percent', $condition, (float)$value);
    }
}
