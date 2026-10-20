<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync;

use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Hands out each household's next change_seq.
 *
 * It writes before it reads, so the write lock (a row lock on MySQL and
 * Postgres, the database lock on SQLite) is taken first and held until the
 * caller's transaction commits. Seqs therefore commit in order, and a pull
 * after seq N never misses a later commit at or below N.
 */
final class HouseholdSyncSequence
{
    public static function next(ConnectionInterface $connection, int $householdId): int
    {
        if ($connection->transactionLevel() === 0) {
            throw new LogicException('A change_seq must be taken inside the transaction that writes the row.');
        }

        $table = Config::statesTable();

        if ($connection->table($table)->where('household_id', $householdId)->increment('last_seq') === 0) {
            $connection->table($table)->insertOrIgnore(['household_id' => $householdId, 'last_seq' => 0, 'floor_seq' => 0]);
            $connection->table($table)->where('household_id', $householdId)->increment('last_seq');
        }

        return Config::int($connection->table($table)->where('household_id', $householdId)->value('last_seq'));
    }

    public static function floor(ConnectionInterface $connection, int $householdId): int
    {
        $floor = $connection->table(Config::statesTable())->where('household_id', $householdId)->value('floor_seq');

        return $floor === null ? 0 : Config::int($floor);
    }
}
