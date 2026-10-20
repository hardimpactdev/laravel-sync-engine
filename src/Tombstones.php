<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;

/** Deletes as feed entries. A tombstone carries the seq of the delete. */
final class Tombstones
{
    public static function record(ConnectionInterface $connection, int $householdId, string $entity, string $syncId): void
    {
        $connection->transaction(function () use ($connection, $householdId, $entity, $syncId): void {
            $seq = HouseholdSyncSequence::next($connection, $householdId);

            $connection->table(self::table())->upsert(
                [[
                    'household_id' => $householdId,
                    'entity' => $entity,
                    'sync_id' => $syncId,
                    'change_seq' => $seq,
                    'deleted_at' => Date::now(),
                ]],
                ['entity', 'sync_id'],
                ['household_id', 'change_seq', 'deleted_at'],
            );
        });
    }

    /** A restored row syncs again as an upsert; its tombstone goes. */
    public static function forget(ConnectionInterface $connection, string $entity, string $syncId): void
    {
        $connection->table(self::table())->where('entity', $entity)->where('sync_id', $syncId)->delete();
    }

    public static function table(): string
    {
        return Config::tombstonesTable();
    }
}
