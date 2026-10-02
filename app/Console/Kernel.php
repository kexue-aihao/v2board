<?php

namespace App\Console;

use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());
        // traffic
        $schedule->command('traffic:update')->everyMinute()->withoutOverlapping();
        // 动态倍率：采样上一分钟的每用户带宽（原始字节）并推进峰值判定。必须也是每分钟 ——
        // 原始计数是按分钟 drain 的，间隔再稀就会丢样本，判定会失真。
        $schedule->command('rate:tick')->everyMinute()->withoutOverlapping();
        // 外部订阅源：半小时抓一次。源通常几小时才变一次，抓太勤只是给别人添压力；
        // 失败不影响已有节点，所以不需要重试逻辑，管理页也可以随时手动刷新。
        $schedule->command('external:refresh')->everyThirtyMinutes()->withoutOverlapping();
        // v2board
        $schedule->command('v2board:statistics')->dailyAt('0:10');
        // 账号风险台账 + 超阈值提醒。节奏是需求定的：风险未处理就每 15 分钟重复一次，
        // 唯一的终止动作是管理员在页面上点「标记已处理」。这个命令同时负责刷新台账，
        // 所以页面上的「风险程度」最多滞后一个周期。
        $schedule->command('risk:notify')->everyFifteenMinutes()->withoutOverlapping();
        // check
        $schedule->command('check:order')->everyMinute()->withoutOverlapping();
        $schedule->command('check:commission')->everyFifteenMinutes();
        $schedule->command('check:ticket')->everyMinute();
        $schedule->command('check:renewal')->dailyAt('22:30');
        // reset
        $schedule->command('reset:traffic')->daily()->withoutOverlapping();
        $schedule->command('reset:log')->daily();
        // 每小时整点把订阅拉取日志增量聚合成「IP + 账号 + UA」累积记录。整点这个位置同时
        // 满足两件事：① 0:00 那次落在 audit:clean（0:40）之前，当天要被保留期删掉的原始行
        // 一定已经进过累积表；② 与订阅拉取写路径完全无关 —— 聚合只读已落库的日志，拉取
        // 路径上没有为本功能增加任何查询。
        $schedule->command('audit:ip-link')->hourly()->withoutOverlapping();
        // 清洗网关列表要按运营商/ASN 筛选，而归属地是 IP 库的派生结果，订阅拉取写路径上
        // 不做这个查询。这里按 location_resolved_at 增量补：新出现的 IP 十分钟内补齐，
        // 列表页自己也会把当页没解析过的行就地补上，所以这两个入口互为兜底。
        $schedule->command('access:locations')->everyTenMinutes()->withoutOverlapping();
        $schedule->command('audit:clean')->dailyAt('0:40')->withoutOverlapping();
        // 排在清理之后：本命令只读活凭证列并补历史，与保留期清理无关，但排开可以避开
        // 同一时段的 I/O。稳态下它写 0 条，非零就是 token 观察者漏写的证据。
        $schedule->command('token-history:reconcile')->dailyAt('0:50')->withoutOverlapping();
        // send
        $schedule->command('send:remindMail')->dailyAt('11:30');
        // horizon metrics
        $schedule->command('horizon:snapshot')->everyFiveMinutes();
        $telegramBindingInterval = max(60, min(3600, (int)config('v2board.telegram_binding_check_interval', 300)));
        $telegramBindingMinutes = max(1, (int)ceil($telegramBindingInterval / 60));
        $schedule->command('telegram:verify-bindings')
            ->cron('*/' . $telegramBindingMinutes . ' * * * *')
            ->withoutOverlapping();
        $schedule->command('telegram:prune-login-links')->hourly()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
