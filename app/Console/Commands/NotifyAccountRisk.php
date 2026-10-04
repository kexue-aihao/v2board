<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SubscribeAccountRiskService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 账号风险提醒。每 15 分钟一次（Kernel 里调度）。
 *
 * 节奏是需求定的：**风险未处理就一直发**。所以这个命令没有「只提醒一次」的闸门，
 * 唯一的终止动作是管理员在页面上点「标记已处理」。
 *
 * 台账里的 notified_at / notify_count 只用于展示与排查，不参与「发不发」的判断 ——
 * 判断只看 handled_at 是否为空。
 */
class NotifyAccountRisk extends Command
{
    protected $signature = 'risk:notify
        {--dry-run : 只重算并打印待办，不发消息、不写提醒记录}
        {--refresh-only : 只重算台账，不做任何提醒（部署与排障用）}
        {--limit=200 : 单次最多处理多少个待办账号}';

    protected $description = '按阻断比例重算账号风险，并把未处理的超阈值账号提醒给管理员';

    public function handle(): int
    {
        $service = new SubscribeAccountRiskService();
        if (!$service->available()) {
            $this->info('风险台账或拉取聚合表尚未安装，跳过。');
            return self::SUCCESS;
        }

        $accounts = $service->refresh();
        $threshold = $service->threshold();

        if ($this->option('refresh-only')) {
            $this->info(sprintf('风险台账已刷新：%d 个账号。', $accounts));
            return self::SUCCESS;
        }

        $pending = $service->pending(max(1, (int)$this->option('limit')));

        if (!$pending) {
            $this->info(sprintf(
                '台账已刷新（%d 个账号），当前没有未处理且风险 ≥ %s%% 的账号。',
                $accounts,
                $this->percentText($threshold)
            ));
            return self::SUCCESS;
        }

        $emails = User::whereIn('id', array_column($pending, 'user_id'))->pluck('email', 'id');
        $message = $this->compose($pending, $emails, $threshold);

        if ($this->option('dry-run')) {
            $this->line($message);
            $this->info(sprintf('[dry-run] %d 个待办账号，未发送、未写记录。', count($pending)));
            return self::SUCCESS;
        }

        // 发不出去时**不写提醒记录**：这一轮没提醒成功，下一轮还得算数。
        // 与「发送失败保留待发状态」是同一个道理，只是这里连发送动作都没发生。
        if (!config('v2board.telegram_bot_enable', 0)) {
            $this->warn(sprintf(
                '%d 个账号待办，但 telegram_bot_enable 未开启，本轮不发送。',
                count($pending)
            ));
            return self::SUCCESS;
        }

        $recipients = (new TelegramService())->administratorRecipients(false, ['super', 'operations'])->count();
        if ($recipients <= 0) {
            $this->warn(sprintf(
                '%d 个账号待办，但没有任何绑定了 Telegram 的管理员，本轮不发送。',
                count($pending)
            ));
            Log::warning('Subscription account risk notice has no recipient', [
                'pending' => count($pending)
            ]);
            return self::SUCCESS;
        }

        (new TelegramService())->sendMessageWithAdmin($message, false, ['super', 'operations']);
        $service->markNotified(array_column($pending, 'user_id'));

        $this->info(sprintf(
            '已向 %d 个管理员提醒 %d 个待办账号（阈值 %s%%）。',
            $recipients,
            count($pending),
            $this->percentText($threshold)
        ));

        return self::SUCCESS;
    }

    /**
     * 摘要在前、明细在后：账号多的时候一眼看到「有几个」，再决定要不要翻。
     */
    private function compose(array $pending, $emails, float $threshold): string
    {
        $lines = [];
        $lines[] = '*订阅清洗网关 · 风险提醒*';
        $lines[] = '';
        $lines[] = sprintf(
            '以下 %d 个账号的订阅拉取被阻断比例达到 %s%%，且尚未处理：',
            count($pending),
            $this->percentText($threshold)
        );
        $lines[] = '';

        $shown = array_slice($pending, 0, SubscribeAccountRiskService::NOTIFY_DETAIL_LIMIT);
        foreach ($shown as $index => $row) {
            $userId = (int)$row->user_id;
            $email = (string)($emails[$userId] ?? '');
            $lines[] = sprintf(
                '%d. %s — %s%%（阻断 %d / 总 %d）',
                $index + 1,
                $this->escape($email !== '' ? $email : '#' . $userId),
                $this->percentText((float)$row->risk_percent),
                (int)$row->blocked_count,
                (int)$row->total_count
            );
        }
        if (count($pending) > count($shown)) {
            $lines[] = sprintf('…另有 %d 个账号未列出。', count($pending) - count($shown));
        }

        $lines[] = '';
        $lines[] = '处理完请在管理端「订阅清洗网关」页面的待处理区块点「标记已处理」；';
        $lines[] = '在那之前每 15 分钟会重复提醒一次。';

        return implode("\n", $lines);
    }

    /**
     * 去掉无意义的尾零：60.00% 读起来像精确值，60% 才是人写的。
     */
    private function percentText(float $percent): string
    {
        $rounded = round($percent, 2);
        $text = number_format($rounded, 2, '.', '');
        $text = rtrim(rtrim($text, '0'), '.');

        return $text === '' ? '0' : $text;
    }

    /**
     * SendTelegramJob 固定用 markdown 解析，邮箱里的下划线会被吃掉成斜体。
     * 只转义 markdown 的元字符，不做 HTML 处理。
     */
    private function escape(string $text): string
    {
        return str_replace(
            ['_', '*', '[', ']', '`'],
            ['\_', '\*', '\[', '\]', '\`'],
            $text
        );
    }
}
