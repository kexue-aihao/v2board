<?php

namespace App\Providers;

use App\Models\Subscription;
use App\Models\User;
use App\Observers\SubscriptionTokenObserver;
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
        $this->app['view']->addNamespace('theme', public_path() . '/theme');

        // 这里绝不能碰数据库：Schema::hasTable 会让 v2board:install、key:generate 在空库上
        // 直接炸掉。表是否存在由观察者内部的服务惰性探测，且排在 isDirty 判断之后。
        if (!self::$observersRegistered) {
            self::$observersRegistered = true;
            User::observe(SubscriptionTokenObserver::class);
            Subscription::observe(SubscriptionTokenObserver::class);
        }
    }
}
