<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine;

use HardImpact\SyncEngine\Console\PruneTombstones;
use Illuminate\Support\ServiceProvider;

final class SyncEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sync-engine.php', 'sync-engine');
        $this->app->singleton(SyncEntities::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/sync-engine.php' => config_path('sync-engine.php')], 'sync-engine-config');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneTombstones::class]);
        }
    }
}
