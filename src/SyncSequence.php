<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine;

use HardImpact\SyncEngine\Events\ScopeChanged;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Hands out each scope's next change_seq.
 *
 * It writes before it reads, so the write lock (a row lock on MySQL and
 * Postgres, the database lock on SQLite) is taken first and held until the
 * caller's transaction commits. Seqs therefore commit in order, and a pull
 * after seq N never misses a later commit at or below N.
 *
 * After the commit it fires one ScopeChanged per scope, the real-time
 * poke (protocol §4). A rolled-back write fires nothing.
 */
final class SyncSequence
{
    /** @var array<int, int> The highest seq announced per scope in this process. */
    private static array $announced = [];

    /** The install whose push is being applied, for the poke. Null for web, jobs and imports. */
    private static ?string $clientId = null;

    public static function next(ConnectionInterface $connection, int $scopeId): int
    {
        if ($connection->transactionLevel() === 0) {
            throw new LogicException('A change_seq must be taken inside the transaction that writes the row.');
        }

        $table = Config::statesTable();

        if ($connection->table($table)->where('scope_id', $scopeId)->increment('last_seq') === 0) {
            $connection->table($table)->insertOrIgnore(['scope_id' => $scopeId, 'last_seq' => 0, 'floor_seq' => 0]);
            $connection->table($table)->where('scope_id', $scopeId)->increment('last_seq');
        }

        $seq = Config::int($connection->table($table)->where('scope_id', $scopeId)->value('last_seq'));
        self::announceAfterCommit($connection, $scopeId);

        return $seq;
    }

    /**
     * Takes the scope's write lock now, before the transaction reads anything.
     *
     * On SQLite a deferred transaction that reads first and writes later fails
     * with SQLITE_BUSY_SNAPSHOT when another connection commits in between;
     * a busy timeout cannot help. A write as the first statement takes the
     * database write lock on the latest snapshot, as BEGIN IMMEDIATE does, and
     * waits for other writers through the busy timeout. On MySQL and
     * PostgreSQL it takes the row lock that next() would take later, so
     * concurrent pushes of one scope run one after the other.
     */
    public static function lock(ConnectionInterface $connection, int $scopeId): void
    {
        if ($connection->transactionLevel() === 0) {
            throw new LogicException('The scope lock must be taken inside a transaction.');
        }

        $table = Config::statesTable();
        $touch = ['last_seq' => $connection->raw('last_seq')];

        if ($connection->table($table)->where('scope_id', $scopeId)->update($touch) === 0) {
            $connection->table($table)->insertOrIgnore(['scope_id' => $scopeId, 'last_seq' => 0, 'floor_seq' => 0]);
            $connection->table($table)->where('scope_id', $scopeId)->update($touch);
        }
    }

    /** Forgets what this process announced. For tests that reset the database. */
    public static function flushState(): void
    {
        self::$announced = [];
        self::$clientId = null;
    }

    /**
     * The scope's highest committed seq. Every seq at or below it has committed,
     * because a write holds the counter's lock until it commits.
     */
    public static function committed(ConnectionInterface $connection, int $scopeId): int
    {
        $seq = $connection->table(Config::statesTable())->where('scope_id', $scopeId)->value('last_seq');

        return $seq === null ? 0 : Config::int($seq);
    }

    public static function floor(ConnectionInterface $connection, int $scopeId): int
    {
        $floor = $connection->table(Config::statesTable())->where('scope_id', $scopeId)->value('floor_seq');

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
     * scope's committed last_seq and fires once. The others find it announced.
     */
    private static function announceAfterCommit(ConnectionInterface $connection, int $scopeId): void
    {
        if (! $connection instanceof Connection) {
            return;
        }

        $clientId = self::$clientId;

        $connection->afterCommit(function () use ($connection, $scopeId, $clientId): void {
            $seq = Config::int($connection->table(Config::statesTable())->where('scope_id', $scopeId)->value('last_seq'));

            if ($seq <= (self::$announced[$scopeId] ?? 0)) {
                return;
            }

            self::$announced[$scopeId] = $seq;
            event(new ScopeChanged($scopeId, $seq, $clientId));
        });
    }
}
