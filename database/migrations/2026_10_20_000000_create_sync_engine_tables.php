<?php

declare(strict_types=1);

use HardImpact\SyncEngine\Config;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Config::statesTable(), function (Blueprint $table): void {
            $table->unsignedBigInteger('scope_id')->primary();
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->unsignedBigInteger('floor_seq')->default(0);
        });

        Schema::create(Config::tombstonesTable(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scope_id');
            $table->string('entity', 64);
            $table->char('sync_id', 26);
            $table->unsignedBigInteger('change_seq');
            $table->timestamp('deleted_at');

            $table->unique(['entity', 'sync_id']);
            $table->index(['scope_id', 'change_seq']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Config::tombstonesTable());
        Schema::dropIfExists(Config::statesTable());
    }
};
