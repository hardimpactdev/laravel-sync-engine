<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync;

use HardImpact\HouseholdSync\Events\HouseholdChanged;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Hands out each household's next change_seq.
 *
 * It writes before it reads, so the write lock (a row lock on MySQL and
 * Postgres, the database lock on SQLite) is taken first and held until the
 * caller's transaction commits. Seqs therefore commit in order, and a pull
 * after seq N never misses a later commit at or below N.
 *
 * After the commit it fires one HouseholdChanged per household, the real-time
 * poke (protocol §4). A rolled-back write fires nothing.
 */
final class HouseholdSyncSequence
{
    /** @var array<int, int> The highest seq announced per household in this process. */
    private static array $announced = [];

    /** The install whose push is being applied, for the poke. Null for web, jobs and imports. */
    private static ?string $clientId = null;

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

        $seq = Config::int($connection->table($table)->where('household_id', $householdId)->value('last_seq'));
        self::announceAfterCommit($connection, $householdId);

        return $seq;
    }

    /** Forgets what this process announced. For tests that reset the database. */
    public static function flushState(): void
    {
        self::$announced = [];
        self::$clientId = null;
    }

    public static function floor(ConnectionInterface $connection, int $householdId): int
    {
        $floor = $connection->table(Config::statesTable())->where('household_id', $householdId)->value('floor_seq');

        return $floor === null ? 0 : Config::int($floor);
    }

    /**
     * Runs `$work` as the given install's push, so its pokes name the install.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function asClient(string $clientId, callable $work): mixed
    {
        $previous = self::$clientId;
        self::$clientId = $clientId;

        try {
            return $work();
        } finally {
            self::$clientId = $previous;
        }
    }

    /**
     * Every write registers a callback; after the commit the first one reads the
     * household's committed last_seq and fires once. The others find it announced.
     */
    private static function announceAfterCommit(ConnectionInterface $connection, int $householdId): void
    {
        if (! $connection instanceof Connection) {
            return;
        }

        $clientId = self::$clientId;

        $connection->afterCommit(function () use ($connection, $householdId, $clientId): void {
            $seq = Config::int($connection->table(Config::statesTable())->where('household_id', $householdId)->value('last_seq'));

            if ($seq <= (self::$announced[$householdId] ?? 0)) {
                return;
            }

            self::$announced[$householdId] = $seq;
            event(new HouseholdChanged($householdId, $seq, $clientId));
        });
    }
}
