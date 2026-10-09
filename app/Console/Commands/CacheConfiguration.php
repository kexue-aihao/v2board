<?php

namespace App\Console\Commands;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Console\ConfigCacheCommand;

class CacheConfiguration extends ConfigCacheCommand
{
    public function handle()
    {
        try {
            return parent::handle();
        } finally {
            // Reloaded workers must not reuse the previously compiled cache file
            // when OPcache timestamp checks are delayed or disabled.
            if (function_exists('opcache_invalidate')) @opcache_invalidate($this->laravel->getCachedConfigPath(), true);
        }
    }

    protected function getFreshConfiguration()
    {
        $running = Container::getInstance();
        $timezone = date_default_timezone_get();
        try {
            $fresh = new Application($this->laravel->basePath());
            $fresh->useStoragePath($this->laravel->storagePath());
            $fresh->useEnvironmentPath($this->laravel->environmentPath());
            $fresh->loadEnvironmentFrom($this->laravel->environmentFile());
            // Only read configuration. Booting providers here replaces the live
            // request, facades and Eloquent connection during an audit transaction.
            $fresh->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);
            // Providers have already merged package defaults into the running
            // repository (e.g. ignition). Retain those namespaces, while replacing
            // every on-disk namespace with its freshly read values.
            return array_replace($this->laravel['config']->all(), $fresh['config']->all());
        } finally {
            Container::setInstance($running);
            date_default_timezone_set($timezone);
        }
    }
}
