<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Tests;

use HardImpact\SyncEngine\SyncEngineServiceProvider;
use HardImpact\SyncEngine\SyncEntities;
use HardImpact\SyncEngine\SyncSequence;
use HardImpact\SyncEngine\Tests\Fixtures\CounterEntity;
use HardImpact\SyncEngine\Tests\Fixtures\ItemEntity;
use HardImpact\SyncEngine\Tests\Fixtures\RoomEntity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SyncEngineServiceProvider::class];
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
            $table->unsignedBigInteger('scope_id');
            $table->char('sync_id', 26)->unique();
            $table->unsignedBigInteger('change_seq')->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('counters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scope_id');
            $table->char('sync_id', 26)->unique();
            $table->unsignedBigInteger('change_seq')->nullable();
            $table->string('name');
            $table->integer('quantity')->default(0);
            $table->timestamps();
            $table->unique(['scope_id', 'name']);
        });

        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scope_id');
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

        SyncSequence::flushState();

        $this->app->make(SyncEntities::class)->register(new ItemEntity, new RoomEntity, new CounterEntity);
    }
}
