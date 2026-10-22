<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/** Deletes as feed entries. A tombstone carries the seq of the delete. */
final class Tombstones
{
    public static function record(ConnectionInterface $connection, int $scopeId, string $entity, string $syncId): void
    {
        $connection->transaction(function () use ($connection, $scopeId, $entity, $syncId): void {
            $seq = SyncSequence::next($connection, $scopeId);

            $connection->table(self::table())->upsert(
                [[
                    'scope_id' => $scopeId,
                    'entity' => $entity,
                    'sync_id' => $syncId,
                    'change_seq' => $seq,
                    'deleted_at' => Date::now(),
                ]],
                ['entity', 'sync_id'],
                ['scope_id', 'change_seq', 'deleted_at'],
            );
        });
    }

    /** A restored row syncs again as an upsert; its tombstone goes. */
    public static function forget(ConnectionInterface $connection, string $entity, string $syncId): void
    {
        $connection->table(self::table())->where('entity', $entity)->where('sync_id', $syncId)->delete();
    }

    public static function exists(int $scopeId, string $entity, string $syncId): bool
    {
        return DB::table(self::table())->where('scope_id', $scopeId)->where('entity', $entity)->where('sync_id', $syncId)->exists();
    }

    /** In any scope. */
    public static function recorded(string $entity, string $syncId): bool
    {
        return DB::table(self::table())->where('entity', $entity)->where('sync_id', $syncId)->exists();
    }

    public static function seqOf(string $entity, string $syncId): ?int
    {
        $seq = DB::table(self::table())->where('entity', $entity)->where('sync_id', $syncId)->value('change_seq');

        return $seq === null ? null : Config::int($seq);
    }

    public static function table(): string
    {
        return Config::tombstonesTable();
    }
}
