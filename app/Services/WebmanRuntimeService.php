<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Reload workers only after Webman's TCP connection has sent the response. */
class WebmanRuntimeService
{
    private static $pendingPid = null;

    public static function scheduleRestart(): bool
    {
        if (!defined('isWEBMAN') || !isWEBMAN || !function_exists('posix_kill')) return false;
        try {
            $pid = function_exists('posix_getppid') ? posix_getppid() : Cache::get('WEBMANPID');
            if (!is_numeric($pid) || (int)$pid <= 1) return false;
            self::$pendingPid = (int)$pid;
            return true;
        } catch (\Throwable $exception) {
            Log::warning('Webman restart could not be scheduled.', ['error' => $exception->getMessage()]);
            return false;
        }
    }

    public static function afterResponseSent(): void
    {
        $pid = self::$pendingPid;
        self::$pendingPid = null;
        if ($pid === null || !function_exists('posix_kill')) return;
        try {
            // SIGTERM stops the master and relies on Supervisor to start it again.
            // SIGUSR2 reloads workers gracefully while keeping the master alive.
            if (!posix_kill($pid, SIGUSR2)) {
                Log::warning('Webman reload signal was not delivered.', ['pid' => $pid]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Webman restart failed after response.', ['pid' => $pid, 'error' => $exception->getMessage()]);
        }
    }
}
