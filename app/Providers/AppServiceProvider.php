<?php

namespace App\Providers;

use App\Models\Subscription;
use App\Models\User;
use App\Observers\SubscriptionTokenObserver;
use App\Observers\RatePolicyNodeObserver;
use App\Services\ServerIdService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * boot() 在每个 Webman worker 启动时跑一次、每次 artisan 调用也跑一次。worker 重启是
     * 新进程所以重注册天然幂等，但重复注册会让每次写入变成两次，所以还是加个守卫。
     */
    private static $observersRegistered = false;

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        \App\Services\SecurityAuditQueue::register();
        foreach (['created', 'updated', 'deleted'] as $event) {
            \Illuminate\Support\Facades\Event::listen('eloquent.' . $event . ': *', function ($name, $payload) use ($event) {
                \App\Services\SecurityAuditService::modelChange($event, $payload[0]);
            });
        }
        \Illuminate\Support\Facades\DB::listen(function ($query) {
            if (!app()->bound('request')) return;
            $request = request();
            $context = $request->attributes->get('security_audit_context');
            if (!$context || !preg_match('/^\s*(insert(?:\s+or\s+ignore)?\s+into|update|delete\s+from)\s+[`"]?(\w+)/i', $query->sql, $matches)) return;
            if (strpos($matches[2], 'v2_admin_audit') === 0) return;
            // Never record SQL bindings: they can contain credentials or payment secrets.
            $context['statements'][] = ['operation' => strtolower($matches[1]), 'table' => $matches[2]];
            $request->attributes->set('security_audit_context', $context);
        });
        User::saving(function ($user) {
            if (!$user->exists || (int)$user->getOriginal('id') !== 1 || !(int)$user->getOriginal('is_admin')) return;
            if ($user->isDirty('id') || !$user->is_admin || $user->banned || $user->admin_role) {
                throw new \RuntimeException('不能删除、停用或降级唯一超级管理员');
            }
        });
        User::deleting(function ($user) {
            if ((int)$user->id === 1 && $user->is_admin) throw new \RuntimeException('不能删除唯一超级管理员');
        });
        $this->app['view']->addNamespace('theme', public_path() . '/theme');
        foreach (ServerIdService::TYPES as $entry) {
            $entry[0]::observe(RatePolicyNodeObserver::class);
        }

        // 这里绝不能碰数据库：Schema::hasTable 会让 v2board:install、key:generate 在空库上
        // 直接炸掉。表是否存在由观察者内部的服务惰性探测，且排在 isDirty 判断之后。
        if (!self::$observersRegistered) {
            self::$observersRegistered = true;
            User::observe(SubscriptionTokenObserver::class);
            Subscription::observe(SubscriptionTokenObserver::class);
        }
    }
}
