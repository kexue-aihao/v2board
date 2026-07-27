<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\PasswordPolicyService;
use App\Utils\Helper;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResetPassword extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:password {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重置用户密码';

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
        $user = User::where('email', $this->argument('email'))->first();
        if (!$user) abort(500, '邮箱不存在');
        // 走统一的密码策略生成器（64 位大小写字母 + 数字），并顺手清掉一直残留的
        // password_salt —— 它不影响校验，但是一条会误导人的脏数据。
        $password = PasswordPolicyService::generate();
        PasswordPolicyService::apply($user, $password);
        if (!$user->save()) abort(500, '重置失败');
        // 系统生成的密码合规，停止提醒。
        PasswordPolicyService::markSatisfied($user);
        $this->info("!!!重置成功!!!");
        $this->info("新密码为：{$password}");
        $this->info("这是系统生成的强密码，请转交本人并妥善保存，不需要再改。");
    }
}
