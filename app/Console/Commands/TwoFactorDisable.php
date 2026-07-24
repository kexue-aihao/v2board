<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Console\Command;

class TwoFactorDisable extends Command
{
    protected $signature = 'two-factor:disable {user : User id or email}';
    protected $description = 'Disable two-factor protection for an account in an emergency';

    public function handle()
    {
        $value = $this->argument('user');
        $user = is_numeric($value) ? User::find((int)$value) : User::where('email', $value)->first();
        if (!$user) {
            $this->error('User not found.');
            return 1;
        }
        $service = new TwoFactorService();
        if (!$service->isEnabled($user->id)) {
            $this->info('Two-factor protection is already disabled.');
            return 0;
        }
        $service->emergencyDisable($user);
        $this->info('Two-factor protection disabled and sessions revoked.');
        return 0;
    }
}
