<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PasswordPolicyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 手工把用户标记为「待重置密码」。
 *
 * 迁移时已经一次性标记过全部现存普通用户，本命令用于事后补：比如新导入了一批账号，或者
 * 某个用户的密码疑似泄漏但你不想直接替他改掉（改掉他就登不进去了，标记只是开始提醒）。
 *
 * 不排计划任务 —— 定期把所有人重新标记一遍会让已经合规的用户被无休止地提醒。
 */
class RequirePasswordReset extends Command
{
    protected $signature = 'password:require-reset {--all : 标记全部普通用户} {--email= : 只标记指定邮箱}';

    protected $description = '把用户标记为待重置密码（提醒其改用系统生成的强密码）';

    public function handle()
    {
        if (!PasswordPolicyService::available()) {
            $this->error('v2_user.password_reset_required 列不存在，请先执行 php artisan v2board:update');
            return 1;
        }

        $email = $this->option('email');
        if (!$email && !$this->option('all')) {
            $this->error('请指定 --all 或 --email=someone@example.com');
            return 1;
        }

        if ($email) {
            $user = User::where('email', $email)->first();
            if (!$user) {
                $this->error("邮箱 {$email} 不存在");
                return 1;
            }
            if ($user->is_admin || $user->is_staff) {
                // 旗标照样写，但提醒只在用户端出现且对管理员/员工被 requiresReset 排除，
                // 所以这里如实说明「写了但不会提醒」，而不是假装成功。
                $this->warn('该账号是管理员或员工，旗标会写入但不会产生提醒。');
            }
            PasswordPolicyService::markRequired($user);
            $this->info("已标记 {$email}");
            return 0;
        }

        // 管理员和员工不在提醒范围内，不去写他们的行，免得留下一堆永远不生效的旗标。
        $affected = DB::table('v2_user')
            ->where('is_admin', 0)
            ->where('is_staff', 0)
            ->where('password_reset_required', 0)
            ->update([
                'password_reset_required' => 1,
                'updated_at' => time()
            ]);
        $this->info("已标记 {$affected} 个普通用户为待重置密码。");
        return 0;
    }
}
