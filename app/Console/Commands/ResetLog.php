<?php

namespace App\Console\Commands;

use App\Models\Log;
use App\Models\Plan;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Utils\Helper;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResetLog extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:log';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清空日志';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        StatUser::where('record_at', '<', strtotime('-2 month', time()))->delete();
        StatServer::where('record_at', '<', strtotime('-2 month', time()))->delete();
        Log::where('created_at', '<', strtotime('-1 month', time()))->delete();
        // 订阅审计的两张表由 audit:clean 按可配置的保留期清理，不要在这里重复一套
        // 硬编码规则 —— 两个规则并存时取严者胜，配置项会静默失效。
    }
}
