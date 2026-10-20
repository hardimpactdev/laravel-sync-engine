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
        // One per applied or rejected change: a resend gets the same ack.
        Schema::create(Config::receiptsTable(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scope_id');
            $table->char('change_id', 26);
            $table->char('client_id', 26);
            $table->char('payload_hash', 64);
            $table->json('ack');
            $table->timestamp('created_at');

            $table->unique(['scope_id', 'change_id']);
            $table->index('created_at');
        });

        // A device's id for a row the server matched to an existing row (protocol §5.3).
        Schema::create(Config::aliasesTable(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scope_id');
            $table->string('entity', 64);
            $table->char('from_sync_id', 26);
            $table->char('to_sync_id', 26);
            $table->timestamp('created_at');

            $table->unique(['scope_id', 'entity', 'from_sync_id']);
        });

        // Each install per scope: the last outbox position the server applied.
        Schema::create(Config::clientsTable(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scope_id');
            $table->char('client_id', 26);
            $table->unsignedBigInteger('last_position')->default(0);
            $table->timestamp('last_seen_at');

            $table->unique(['scope_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Config::clientsTable());
        Schema::dropIfExists(Config::aliasesTable());
        Schema::dropIfExists(Config::receiptsTable());
    }
};
