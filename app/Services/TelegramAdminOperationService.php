<?php

namespace App\Services;

use App\Jobs\SendTelegramAdminOperationJob;

class TelegramAdminOperationService
{
    private const CLEANUP_MESSAGE_LIMIT = 3800;

    private const PROTOCOL_NAMES = [
        'vmess' => 'VMess',
        'vless' => 'VLESS',
        'trojan' => 'Trojan',
        'shadowsocks' => 'Shadowsocks',
        'tuic' => 'TUIC',
        'hysteria' => 'Hysteria',
        'hysteria2' => 'Hysteria2',
        'anytls' => 'AnyTLS',
    ];

    public static function nodeCreated($node, string $protocol): void
    {
        if ((int)$node->show === 1) {
            self::dispatchNode($node, $protocol, '节点上架');
        }
    }

    public static function nodeVisibilityChanged($node, string $protocol, int $previousShow): void
    {
        $currentShow = (int)$node->show;
        if (($previousShow === 1) === ($currentShow === 1)) {
            return;
        }

        self::dispatchNode($node, $protocol, $currentShow === 1 ? '节点上架' : '节点下架');
    }

    public static function nodeDeleted($node, string $protocol): void
    {
        $show = method_exists($node, 'getOriginal') ? $node->getOriginal('show') : ($node->show ?? 0);
        if ((int)$show === 1) {
            self::dispatchNode([
                'name' => method_exists($node, 'getOriginal') ? $node->getOriginal('name') : ($node->name ?? ''),
                'rate' => method_exists($node, 'getOriginal') ? $node->getOriginal('rate') : ($node->rate ?? null),
            ], $protocol, '节点下架');
        }
    }

    public static function planCreated($plan): void
    {
        if ((int)$plan->show === 1) {
            self::dispatchPlan($plan, '套餐上架');
        }
    }

    public static function planVisibilityChanged($plan, int $previousShow): void
    {
        $currentShow = (int)$plan->show;
        if (($previousShow === 1) === ($currentShow === 1)) {
            return;
        }

        self::dispatchPlan($plan, $currentShow === 1 ? '套餐上架' : '套餐下架');
    }

    public static function planDeleted($plan): void
    {
        $show = method_exists($plan, 'getOriginal') ? $plan->getOriginal('show') : ($plan->show ?? 0);
        if ((int)$show === 1) {
            self::dispatchPlan([
                'name' => method_exists($plan, 'getOriginal') ? $plan->getOriginal('name') : ($plan->name ?? ''),
            ], '套餐下架');
        }
    }

    public static function subscriptionCleanupScanned(int $total, array $actor, int $checkedAt): void
    {
        $message = self::cleanupHeader('无效账号检测完成', $actor, $checkedAt)
            . "\n符合条件：{$total} 个账号\n本次仅检测，未删除账号。";
        self::dispatch($message, 'HTML', true);
    }

    /** 只接收已提交删除事务的账号，列表不能包含续费、资金变化等被跳过的账号。 */
    public static function subscriptionCleanupDeleted(
        array $users,
        int $attempted,
        int $processed,
        array $actor,
        int $checkedAt,
        bool $interrupted = false
    ): void {
        if (!(int)config('v2board.telegram_admin_operation_enable', 0)) return;

        $title = $interrupted ? '无效账号清理中断' : '无效账号清理完成';
        $deleted = count($users);
        $skipped = max(0, $processed - $deleted);
        $pending = max(0, $attempted - $processed);
        $header = self::cleanupHeader($title, $actor, time())
            . "\n检测时间：" . date('Y-m-d H:i:s', $checkedAt)
            . "\n本次待处理：{$attempted}；已删除：{$deleted}；跳过：{$skipped}；未完成：{$pending}";
        if ($interrupted) $header .= "\n删除操作中断，以下仅列出已经成功删除的账号。";

        if (!$users) {
            self::dispatch($header . "\n本次没有实际删除账号。", 'HTML', true);
            return;
        }

        // Telegram 不支持 HTML table，用 pre 等宽文本排出表格；每条消息保留页码。
        // 按完整转义后的文本控制长度，给 4096 字符上限留余量，避免拆断实体或一行。
        $pages = [];
        $rows = [];
        foreach ($users as $user) {
            $candidate = array_merge($rows, [$user]);
            $table = self::cleanupTable($candidate);
            $length = strlen(mb_convert_encoding($header . "\n分页：999999/999999\n" . $table, 'UTF-16LE', 'UTF-8')) / 2;
            if ($rows && $length > self::CLEANUP_MESSAGE_LIMIT) {
                $pages[] = self::cleanupTable($rows);
                $rows = [$user];
            } else {
                $rows = $candidate;
            }
        }
        $pages[] = self::cleanupTable($rows);
        $totalPages = count($pages);
        foreach ($pages as $index => $table) {
            $page = $index + 1;
            // 多段列表错开发送，给 Telegram 群组每分钟 20 条的限制留出余量。
            self::dispatch($header . "\n分页：{$page}/{$totalPages}\n" . $table, 'HTML', true, $index * 4);
        }
    }

    private static function cleanupHeader(string $title, array $actor, int $time): string
    {
        $name = self::cleanupText(config('v2board.app_name', 'V2Board'), 80);
        $operator = self::cleanupText($actor['email'] ?? '未知', 120);
        $actorId = (int)($actor['id'] ?? 0);
        $timezone = self::cleanupText(config('app.timezone', 'Asia/Shanghai'), 80);
        return '<b>' . $title . "</b>\n站点：" . self::escapeHtml($name)
            . "\n操作人：" . self::escapeHtml($operator) . "（ID {$actorId}）"
            . "\n时间：" . date('Y-m-d H:i:s', $time) . '（' . self::escapeHtml($timezone) . '）'
            . "\n条件：订阅已过期或为空，余额和佣金均为 0。";
    }

    private static function cleanupTable(array $users): string
    {
        $rows = [['ID', '账号', '原因', '到期时间', '余额', '佣金']];
        $reasons = ['empty' => '空订阅', 'expired' => '已过期', 'empty_and_expired' => '空且过期'];
        foreach ($users as $user) {
            $expiredAt = $user['expired_at'] ?? null;
            $rows[] = [
                (string)(int)$user['id'],
                self::cleanupText($user['email'] ?? '', 160),
                $reasons[$user['reason'] ?? ''] ?? '已过期',
                $expiredAt ? date('Y-m-d H:i', (int)$expiredAt) : '未设置',
                number_format((int)($user['balance'] ?? 0) / 100, 2, '.', ''),
                number_format((int)($user['commission_balance'] ?? 0) / 100, 2, '.', '')
            ];
        }
        $widths = array_fill(0, count($rows[0]), 0);
        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $widths[$index] = max($widths[$index], mb_strwidth($cell, 'UTF-8'));
            }
        }
        $lines = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $index => $cell) {
                $cells[] = $cell . str_repeat(' ', $widths[$index] - mb_strwidth($cell, 'UTF-8'));
            }
            $lines[] = implode(' | ', $cells);
        }
        return '<pre>' . self::escapeHtml(implode("\n", $lines)) . '</pre>';
    }

    private static function cleanupText($value, int $limit): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', (string)$value);
        return mb_substr(trim($value ?? ''), 0, $limit, 'UTF-8');
    }

    private static function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function dispatchNode($node, string $protocol, string $action): void
    {
        $node = (object)$node;
        $name = self::sanitizeLabel($node->name ?? '未命名');
        $protocol = self::PROTOCOL_NAMES[strtolower($protocol)] ?? '未知';
        $rate = is_numeric($node->rate ?? null)
            ? rtrim(rtrim(number_format((float)$node->rate, 4, '.', ''), '0'), '.')
            : '未知';

        self::dispatch("{$action}\n名称：{$name}\n类型：{$protocol}\n线路倍率：{$rate}x");
    }

    private static function dispatchPlan($plan, string $action): void
    {
        $plan = (object)$plan;
        $name = self::sanitizeLabel($plan->name ?? '未命名');
        self::dispatch("{$action}\n套餐：{$name}");
    }

    private static function sanitizeLabel($value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$value);
        $value = trim($value === null ? '' : $value);
        $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[已隐藏地址]', $value);
        $value = preg_replace('/(?:https?:\/\/)?(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}(?::\d{1,5})?/i', '[已隐藏地址]', $value);
        $value = preg_replace('/\b(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(?::\d{1,5})?\b/', '[已隐藏地址]', $value);
        $value = preg_replace('/\[?[a-f0-9]{0,4}(?::[a-f0-9]{0,4}){2,}\]?(?::\d{1,5})?/i', '[已隐藏地址]', $value);
        $value = trim($value === null ? '' : $value);

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 120, 'UTF-8');
        }
        if (function_exists('iconv_substr')) {
            $truncated = iconv_substr($value, 0, 120, 'UTF-8');
            if ($truncated !== false) {
                return $truncated;
            }
        }
        return substr($value, 0, 120);
    }

    private static function dispatch(string $message, string $parseMode = '', bool $groupOnly = false, int $delaySeconds = 0): void
    {
        if (!(int)config('v2board.telegram_admin_operation_enable', 0)) {
            return;
        }

        $token = trim((string)config('v2board.telegram_bot_token', ''));
        $rawChatId = trim((string)config('v2board.telegram_discuss_id', ''));
        if ($token === '' || !preg_match('/^-?\d+$/', $rawChatId)) {
            // 开关开着却发不出去，说明是配置问题。这里不能静默 return：管理员开关节点、
            // 新增节点之后什么也没发生，只会得出「通知功能没做」的结论 —— 而真正的原因
            // （没填群 ID、或者填成了 @用户名 / t.me 链接）就藏在这个 return 后面。
            \Log::warning('Admin operation notification skipped: bot token or discuss id is unusable.', [
                'bot_token_configured' => $token !== '',
                'discuss_id' => $rawChatId,
                'hint' => 'telegram_discuss_id 必须是数字群 ID（形如 -1001234567890）；@用户名与群链接都会被忽略'
            ]);
            return;
        }

        $chatId = (int)$rawChatId;
        if ($chatId === 0 || ($groupOnly && $chatId > 0)) {
            if ($groupOnly) {
                \Log::warning('Account cleanup notification skipped: discuss id must be a negative group id.');
            }
            return;
        }

        $rawThreadId = trim((string)config('v2board.telegram_admin_operation_topic_id', ''));
        $threadId = ctype_digit($rawThreadId) && (int)$rawThreadId > 0 ? (int)$rawThreadId : null;

        try {
            $job = SendTelegramAdminOperationJob::dispatch($chatId, $message, $threadId, $parseMode);
            if ($delaySeconds > 0) $job->delay($delaySeconds);
            // PendingDispatch 在析构时才真正入队，必须在 try 内触发才能捕获入队失败。
            unset($job);
        } catch (\Throwable $e) {
            // Notifications must not change the result of an admin operation.
            \Log::warning('Admin operation notification could not be queued or sent.', [
                'exception_class' => get_class($e)
            ]);
        }
    }
}
