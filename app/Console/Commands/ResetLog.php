<?php

namespace App\Console\Commands;

use App\Models\Log;
use App\Models\NodeConnectionLog;
use App\Models\Plan;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Utils\Helper;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        // 节点连接记录按去重后的 IP 计行，活跃 IP 会一直被 last_seen_at 续期，
        // 只清掉两个月没再出现过的，保留期与上面的统计表一致。
        if (Schema::hasTable('v2_node_connection_log')) {
            NodeConnectionLog::where('last_seen_at', '<', strtotime('-2 month', time()))->delete();
        }
    }
}
