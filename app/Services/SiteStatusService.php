<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * 站点维护状态的运行时快照。
 *
 * Webman worker 会长期复用 Laravel 的 config 仓库，后台写完
 * config/v2board.php 后，已经启动的 worker 不会自动看到新值。状态放进
 * 共享缓存后，所有 worker 都能在下一次请求中读到管理员刚保存的结果，
 * 维护状态切换就不再依赖重启进程。
 */
class SiteStatusService
{
    public const CACHE_KEY = 'v2board.site_status.runtime';

    public const CONFIG_KEYS = [
        'site_status',
        'site_status_title',
        'site_status_message',
        'site_status_recovery_at',
    ];

    private const MODES = ['normal', 'maintenance', 'shutdown'];

    public static function current(): array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return self::normalize($cached);
            }
        } catch (\Throwable $exception) {
            // 配置读取必须继续工作，即使缓存驱动暂时不可用。
        }

        return self::fromConfig((array)config('v2board', []));
    }

    /**
     * 将刚写入磁盘的配置同步到当前 worker 和共享缓存。
     */
    public static function sync(array $config): array
    {
        $status = self::fromConfig($config);
        config([
            'v2board.site_status' => $status['site_status'],
            'v2board.site_status_title' => $status['site_status_title'],
            'v2board.site_status_message' => $status['site_status_message'],
            'v2board.site_status_recovery_at' => $status['site_status_recovery_at'],
        ]);

        try {
            Cache::forever(self::CACHE_KEY, $status);
        } catch (\Throwable $exception) {
            // 当前 worker 已经同步；其它 worker 会在缓存恢复后从下一次保存开始同步。
        }

        return $status;
    }

    public static function onlyStatusChanges(array $before, array $after): bool
    {
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if (in_array($key, self::CONFIG_KEYS, true)) {
                continue;
            }
            if (!self::sameValue($before[$key] ?? null, $after[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private static function sameValue($left, $right): bool
    {
        if (is_array($left) || is_array($right)) {
            $left = is_array($left) ? $left : [];
            $right = is_array($right) ? $right : [];
            ksort($left);
            ksort($right);

            return self::sameValueList($left, $right);
        }

        // ConfigSave receives strings from JSON forms while the persisted
        // config often contains integers. Compare scalar values by their
        // effective text representation so unchanged fields do not trigger a
        // needless Webman reload.
        return (string)($left ?? '') === (string)($right ?? '');
    }

    private static function sameValueList(array $left, array $right): bool
    {
        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $key => $value) {
            if (!array_key_exists($key, $right) || !self::sameValue($value, $right[$key])) {
                return false;
            }
        }

        return true;
    }

    private static function fromConfig(array $config): array
    {
        return self::normalize([
            'site_status' => $config['site_status'] ?? 'normal',
            'site_status_title' => $config['site_status_title'] ?? null,
            'site_status_message' => $config['site_status_message'] ?? null,
            'site_status_recovery_at' => $config['site_status_recovery_at'] ?? null,
        ]);
    }

    private static function normalize(array $values): array
    {
        $mode = (string)($values['site_status'] ?? 'normal');
        if (!in_array($mode, self::MODES, true)) {
            $mode = 'normal';
        }

        $recoveryAt = $values['site_status_recovery_at'] ?? null;
        if ($recoveryAt === null || $recoveryAt === '') {
            $recoveryAt = null;
        } else {
            $recoveryAt = (int)$recoveryAt;
        }

        return [
            'site_status' => $mode,
            'site_status_title' => (string)($values['site_status_title'] ?? ''),
            'site_status_message' => (string)($values['site_status_message'] ?? ''),
            'site_status_recovery_at' => $recoveryAt,
        ];
    }
}
