<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Utils\Helper;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResetUser extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重置所有用户信息';

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
        if (!$this->confirm("确定要重置所有用户安全信息吗？")) {
            return;
        }
        ini_set('memory_limit', -1);
        $users = User::all();
        // 注意本命令只写 v2_user.token，不写 v2_subscription.token；Client 中间件先按订阅
        // 的 token 解析，所以对有订阅行的用户旧 token 仍然有效，随后 ensurePrimary→syncUser
        // 还会把旧值写回来。token 历史会如实记录这个事实，而不是假装泄漏已经止住。
        \App\Utils\TokenRotationContext::using('cli_reset_all', function () use ($users) {
            foreach ($users as $user)
            {
                $user->token = Helper::guid();
                $user->uuid = Helper::guid(true);
                $user->save();
                $this->info("已重置用户{$user->email}的安全信息");
            }
        });
    }
}
