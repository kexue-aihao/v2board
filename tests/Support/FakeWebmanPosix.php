<?php

namespace {
    // Advertise POSIX support on Windows without delivering any real signals.
    if (!function_exists('posix_kill')) {
        function posix_kill($pid, $signal) { return false; }
    }
    if (!function_exists('posix_getppid')) {
        function posix_getppid() { return 12345; }
    }
    if (!defined('SIGUSR2')) define('SIGUSR2', 12);
}

namespace App\Services {
    function posix_getppid() { return 12345; }
    function posix_kill($pid, $signal) {
        $GLOBALS['webman_test_signals'][] = [$pid, $signal];
        return true;
    }
}
