<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync\Tests;

use HardImpact\HouseholdSync\HouseholdSyncServiceProvider;
use HardImpact\HouseholdSync\SyncEntities;
use HardImpact\HouseholdSync\Tests\Fixtures\ItemEntity;
use HardImpact\HouseholdSync\Tests\Fixtures\RoomEntity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [HouseholdSyncServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->char('sync_id', 26)->unique();
            $table->unsignedBigInteger('change_seq')->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->char('sync_id', 26)->unique();
            $table->unsignedBigInteger('change_seq')->nullable();
            $table->foreignId('room_id')->nullable();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(SyncEntities::class)->register(new ItemEntity, new RoomEntity);
    }
}
