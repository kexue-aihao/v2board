<?php

namespace App\Services;

use App\Models\SubscriptionRiskNotify;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 高风险订阅的管理员提醒（订阅清洗网关）。
 *
 * 分两阶段，刻意不塞进判定流程里：
 *   collect() —— 把「风险值 >= 阈值」的订阅登记进 v2_subscription_risk_notify（只登记，不发送）。
 *                幂等闸门是 (subscription_id, source, window_start) 唯一键；同一订阅只要还有
 *                未处理的待办行，就不再产生新行 —— 这就是「每个周期提醒一次，直到被处理」。
 *   flush()   —— 取所有 sent_at 为空的行，合成**一条**摘要私聊给管理员，然后统一标记已发送。
 *                发送失败（或压根没人可发）时不标记，下一轮自动补发：先记后发，至少一次。
 *
 * 判定与提醒解耦的好处：cron、管理员手动评估、重算三条路径都只需要调一次 run()，
 * 崩溃/超时/发送失败都能被兜底调度（risk:notify，每 15 分钟）补齐。
 */
class SubscriptionRiskNotifyService
{
    public const DEFAULT_THRESHOLD = 60;
    public const DEFAULT_MAX_PER_RUN = 20;
    public const MESSAGE_MAX_LENGTH = 3500;

    private $riskService;

    /**
     * 提醒开关：与 Telegram 机器人共用 token 与总闸；默认跟随 telegram_bot_enable。
     * 关闭时 collect 照常登记（管理员仍能在页面上看到待办），只是不发消息。
     */
    public function enabled(): bool
    {
        if ((int)config('v2board.risk_notify_enable', 1) !== 1) {
            return false;
        }
        if ((int)config('v2board.telegram_bot_enable', 0) !== 1) {
            return false;
        }
        return trim((string)config('v2board.telegram_bot_token', '')) !== '';
    }

    public function threshold(): int
    {
        $threshold = (int)config('v2board.risk_notify_threshold', self::DEFAULT_THRESHOLD);

        return max(1, min(100, $threshold));
    }

    public function maxPerRun(): int
    {
        $max = (int)config('v2board.risk_notify_max_per_run', self::DEFAULT_MAX_PER_RUN);

        return max(1, min(200, $max));
    }

    /**
     * 把超过阈值、且还没有未处理待办的订阅登记进台账。返回新增行数。
     */
    public function collect(): int
    {
        if (!Schema::hasTable('v2_subscription_risk_notify')) {
            return 0;
        }

        $source = $this->riskService()->scoreSource();
        if ($source === null) {
            // 未升级的库上没有 risk_score 列：没有分数就没有提醒。
            return 0;
        }

        $threshold = $this->threshold();
        if ($source === 'manual') {
            $rows = DB::table('v2_subscription_risk_manual as risk_score_src')
                ->join('v2_subscription as risk_score_sub', 'risk_score_sub.id', '=', 'risk_score_src.subscription_id')
                ->where('risk_score_src.risk_score', '>=', $threshold)
                ->whereNotExists($this->pendingExists())
                ->get([
                    'risk_score_src.subscription_id',
                    'risk_score_src.user_id',
                    'risk_score_src.risk_score',
                    'risk_score_src.risk_reasons',
                    'risk_score_src.window_start',
                    'risk_score_src.window_end'
                ]);
            $sourceName = SubscriptionRiskNotify::SOURCE_MANUAL;
        } else {
            // 周期账本：只认「每个订阅最新的一个已评估周期」，且订阅仍然存在 ——
            // 已删除订阅的历史周期没有可处理的对象，不进待办。
            $rows = DB::table('v2_subscription_risk_cycle as risk_score_src')
                ->join('v2_subscription as risk_score_sub', 'risk_score_sub.id', '=', 'risk_score_src.subscription_id')
                ->where('risk_score_src.risk_score', '>=', $threshold)
                ->whereNotExists(function ($newer) {
                    $newer->select(DB::raw(1))
                        ->from('v2_subscription_risk_cycle as risk_score_newer')
                        ->whereColumn('risk_score_newer.subscription_id', 'risk_score_src.subscription_id')
                        ->whereColumn('risk_score_newer.cycle_end', '>', 'risk_score_src.cycle_end');
                })
                ->whereNotExists($this->pendingExists())
                ->get([
                    'risk_score_src.subscription_id',
                    'risk_score_src.user_id',
                    'risk_score_src.risk_score',
                    'risk_score_src.risk_reasons',
                    'risk_score_src.cycle_start as window_start',
                    'risk_score_src.cycle_end as window_end'
                ]);
            $sourceName = SubscriptionRiskNotify::SOURCE_CYCLE;
        }

        if ($rows->isEmpty()) {
            return 0;
        }

        $now = time();
        $payload = [];
        foreach ($rows as $row) {
            $payload[] = [
                'user_id' => (int)$row->user_id,
                'subscription_id' => (int)$row->subscription_id,
                'source' => $sourceName,
                'window_start' => (int)$row->window_start,
                'window_end' => (int)$row->window_end,
                'risk_score' => (int)$row->risk_score,
                'reasons' => $this->compactReasons($row->risk_reasons),
                'recipients' => 0,
                'sent_at' => null,
                'handled_at' => null,
                'handled_by' => null,
                'created_at' => $now,
                'updated_at' => $now
            ];
        }

        // insertOrIgnore 而不是 INSERT IGNORE ... SELECT：唯一键冲突时静默跳过，
        // 并发下（cron 与兜底调度同时跑）不会重复登记，也不依赖 MySQL 方言。
        $created = 0;
        foreach (array_chunk($payload, 500) as $chunk) {
            $created += SubscriptionRiskNotify::insertOrIgnore($chunk);
        }

        return $created;
    }

    /**
     * 把待发行合成一条摘要发给管理员。返回 ['sent' => 标记数, 'detailed' => 明细行数, 'recipients' => 收件人数]。
     */
    public function flush(): array
    {
        $result = ['sent' => 0, 'detailed' => 0, 'recipients' => 0];
        if (!Schema::hasTable('v2_subscription_risk_notify')) {
            return $result;
        }

        $pending = SubscriptionRiskNotify::whereNull('sent_at')
            ->orderByDesc('risk_score')
            ->orderBy('id')
            ->get();
        if ($pending->isEmpty()) {
            return $result;
        }

        if (!$this->enabled()) {
            // 机器人没开（或 token 没配）：保持待发状态，等开启后一轮补齐。
            Log::info('风险提醒已登记但未发送：Telegram 机器人未启用或未配置 token', ['pending' => $pending->count()]);
            return $result;
        }

        $recipients = $this->resolveRecipients();
        if ($recipients->isEmpty()) {
            // 一个绑定了 Telegram 的管理员都没有：不标记，等有人绑定后补发。
            Log::warning('风险提醒无法送达：没有已绑定 Telegram 的管理员', ['pending' => $pending->count()]);
            return $result;
        }

        $detailed = $pending->take($this->maxPerRun());
        $message = $this->buildDigest($detailed, $pending->count());
        // sendMessageWithAdmin 内部走队列（SendTelegramJob），发送异常必须自己吞掉：
        // 提醒失败绝不能影响判定结果或 cron 的退出码。
        try {
            (new TelegramService())->sendMessageWithAdmin($message);
        } catch (\Throwable $e) {
            Log::warning('风险提醒发送失败，保留待发状态由下一轮补发', ['error' => $e->getMessage()]);
            return $result;
        }

        $now = time();
        // 整批一起标记：摘要末尾已经指向后台待办页，剩下的行不必再发第二条消息。
        SubscriptionRiskNotify::whereIn('id', $pending->pluck('id')->all())
            ->update(['sent_at' => $now, 'recipients' => $recipients->count(), 'updated_at' => $now]);

        $result['sent'] = $pending->count();
        $result['detailed'] = $detailed->count();
        $result['recipients'] = $recipients->count();

        return $result;
    }

    /**
     * 收集 + 发送，带锁。cron、手动评估发布后、重算结束后、以及兜底调度都调这一个入口。
     */
    public function run(): array
    {
        $result = ['collected' => 0, 'sent' => 0, 'detailed' => 0, 'recipients' => 0, 'skipped' => false];
        if (!Schema::hasTable('v2_subscription_risk_notify')) {
            return $result;
        }

        try {
            $lock = Cache::lock(CacheKey::get('RISK_NOTIFY_LOCK', 'global'), 60);
            if (!$lock->get()) {
                // 另一条路径正在跑：让给它，下一轮兜底调度会补上。
                $result['skipped'] = true;
                return $result;
            }

            try {
                $result['collected'] = $this->collect();
                $result = array_merge($result, $this->flush());
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            Log::warning('风险提醒流程失败', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * 待办列表数据源（供管理端「待处理」区块）：未处理的行，分值从高到低。
     * 不按 sent_at 过滤 —— 未发送的行同样是待办，管理员本来就该看到。
     * 刻意不带订阅 token：它在后台是需要 POST 才回显的敏感字段（见订阅溯源页），
     * 不能顺手塞进列表响应里。
     */
    public function pendingQuery()
    {
        return SubscriptionRiskNotify::whereNull('handled_at')
            ->join('v2_subscription as risk_sub', 'risk_sub.id', '=', 'v2_subscription_risk_notify.subscription_id')
            ->join('v2_user as risk_user', 'risk_user.id', '=', 'v2_subscription_risk_notify.user_id')
            ->orderByDesc('v2_subscription_risk_notify.risk_score')
            ->orderByDesc('v2_subscription_risk_notify.id')
            ->select([
                'v2_subscription_risk_notify.*',
                'risk_user.email as user_email'
            ]);
    }

    /**
     * 同一订阅只要还有未处理的待办行，就不再登记新提醒。
     */
    private function pendingExists(): \Closure
    {
        return function ($query) {
            $query->select(DB::raw(1))
                ->from('v2_subscription_risk_notify as risk_notify_pending')
                ->whereColumn('risk_notify_pending.subscription_id', 'risk_score_src.subscription_id')
                ->whereNull('risk_notify_pending.handled_at');
        };
    }

    /**
     * 能收到提醒的管理员数量。管理端「待处理」区块据此提示「提醒送不出去」，
     * 免得管理员以为通知发了、只是没人看。
     */
    public function notifiableAdminCount(): int
    {
        return $this->resolveRecipients()->count();
    }

    private function resolveRecipients()
    {
        // 与 TelegramService::sendMessageWithAdmin 的收件人口径完全一致（is_admin=1 且
        // telegram_id 非空），这样台账里的 recipients 就是真实送达人数。
        return User::where('is_admin', 1)
            ->whereNotNull('telegram_id')
            ->get(['id', 'telegram_id']);
    }

    /**
     * 摘要正文。注意 SendTelegramJob 固定用 markdown 解析模式，且服务端只转义下划线 ——
     * 正文里不能出现 * [ ] ` 这些在 markdown 里有含义的字符（「」：（）都安全）。
     */
    private function buildDigest($rows, int $pendingTotal): string
    {
        $threshold = $this->threshold();
        // 邮箱一次查完：待发行最多几十条，逐行查会变成 N+1。
        $emails = User::whereIn('id', collect($rows)->pluck('user_id')->unique()->all())
            ->pluck('email', 'id');

        $lines = [];
        $lines[] = '⚠️ 订阅清洗网关：本轮新增高风险订阅 ' . $pendingTotal . ' 条（阈值 ' . $threshold . '%）';
        $lines[] = '';

        $index = 1;
        foreach ($rows as $row) {
            $email = (string)($row->user_email ?? $emails[(int)$row->user_id] ?? ('#' . (int)$row->user_id));
            $lines[] = $index . '. ' . $email . '  风险 ' . (int)$row->risk_score . '%';
            $reasons = $this->reasonLines($row->reasons);
            if ($reasons !== '') {
                $lines[] = '   命中：' . $reasons;
            }
            $lines[] = '   窗口：' . $this->formatWindow((int)$row->window_start, (int)$row->window_end);
            $index++;
        }

        $rest = $pendingTotal - count($rows);
        if ($rest > 0) {
            $lines[] = '（另有 ' . $rest . ' 条未展开，详见后台「订阅清洗网关」）';
        }
        $lines[] = '';
        $lines[] = '后台：' . $this->adminUrl();

        $message = implode("\n", $lines);
        if (mb_strlen($message) > self::MESSAGE_MAX_LENGTH) {
            $message = mb_substr($message, 0, self::MESSAGE_MAX_LENGTH - 20) . "\n（内容过长已截断）";
        }

        return $message;
    }

    /**
     * 原因串是判定时写下的 JSON 数组，这里只取规则命中行（重复 IP 这类证据行不进摘要，
     * 否则一条消息会被证据行淹没），最多三条。
     */
    private function reasonLines($reasons): string
    {
        $decoded = json_decode((string)$reasons, true);
        if (!is_array($decoded)) {
            return '';
        }

        $picked = [];
        foreach ($decoded as $reason) {
            $reason = (string)$reason;
            if (strpos($reason, '命中') !== 0) {
                continue;
            }
            $picked[] = $this->stripRulePrefix($reason);
            if (count($picked) >= 3) {
                break;
            }
        }

        return implode('、', $picked);
    }

    /**
     * 把「命中清洗策略「X」：维度 值 运算符 阈值」压成「X（值/阈值）」，让摘要一行放得下。
     */
    private function stripRulePrefix(string $reason): string
    {
        if (preg_match('/^命中[^「]*「([^」]*)」：?(.*)$/u', $reason, $match) !== 1) {
            return $reason;
        }

        $label = $match[1];
        $detail = trim($match[2]);
        if ($detail === '') {
            return $label;
        }

        // 参数名与单位一并去掉，只留「实际值 运算符 阈值」里的两个数字。
        $numbers = [];
        if (preg_match_all('/-?\d+(?:\.\d+)?/u', $detail, $found) === 1 || !empty($found[0])) {
            $numbers = $found[0];
        }
        if (count($numbers) >= 2) {
            return $label . '（' . $numbers[count($numbers) - 2] . '/' . $numbers[count($numbers) - 1] . '）';
        }

        return $label;
    }

    private function compactReasons($reasons): string
    {
        $decoded = json_decode((string)$reasons, true);
        if (!is_array($decoded)) {
            return '[]';
        }

        return json_encode(array_slice(array_values($decoded), 0, 10), JSON_UNESCAPED_UNICODE);
    }

    private function formatWindow(int $start, int $end): string
    {
        if ($start <= 0 || $end <= 0) {
            return '—';
        }

        return date('m-d', $start) . ' ~ ' . date('m-d', $end);
    }

    private function adminUrl(): string
    {
        $base = rtrim((string)config('v2board.app_url', ''), '/');
        $securePath = trim((string)config('v2board.secure_path', config('v2board.frontend_admin_path', '')), '/');
        if ($base === '') {
            return $securePath;
        }

        return $securePath === '' ? $base : $base . '/' . $securePath . '#/risk/rule';
    }

    private function riskService(): SubscriptionRiskService
    {
        return $this->riskService ?: $this->riskService = new SubscriptionRiskService();
    }
}
