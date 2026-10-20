<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync;

use HardImpact\HouseholdSync\Console\PruneTombstones;
use Illuminate\Support\ServiceProvider;

final class HouseholdSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/household-sync.php', 'household-sync');
        $this->app->singleton(SyncEntities::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/household-sync.php' => config_path('household-sync.php')], 'household-sync-config');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneTombstones::class]);
        }
    }
}
