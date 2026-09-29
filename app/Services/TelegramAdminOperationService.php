<?php

namespace App\Services;

use App\Jobs\SendTelegramAdminOperationJob;

class TelegramAdminOperationService
{
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

    private static function dispatchNode($node, string $protocol, string $action): void
    {
        $name = self::sanitizeLabel($node->name ?? '未命名');
        $protocol = self::PROTOCOL_NAMES[strtolower($protocol)] ?? '未知';
        $rate = is_numeric($node->rate ?? null)
            ? rtrim(rtrim(number_format((float)$node->rate, 4, '.', ''), '0'), '.')
            : '未知';

        self::dispatch("{$action}\n名称：{$name}\n类型：{$protocol}\n线路倍率：{$rate}x");
    }

    private static function dispatchPlan($plan, string $action): void
    {
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

    private static function dispatch(string $message): void
    {
        if (!(int)config('v2board.telegram_admin_operation_enable', 0)) {
            return;
        }

        $token = trim((string)config('v2board.telegram_bot_token', ''));
        $rawChatId = trim((string)config('v2board.telegram_discuss_id', ''));
        if ($token === '' || !preg_match('/^-?\d+$/', $rawChatId)) {
            return;
        }

        $chatId = (int)$rawChatId;
        if ($chatId === 0) {
            return;
        }

        $rawThreadId = trim((string)config('v2board.telegram_admin_operation_topic_id', ''));
        $threadId = ctype_digit($rawThreadId) && (int)$rawThreadId > 0 ? (int)$rawThreadId : null;

        try {
            SendTelegramAdminOperationJob::dispatch($chatId, $message, $threadId);
        } catch (\Throwable $e) {
            // Notifications must not change the result of an admin operation.
        }
    }
}
