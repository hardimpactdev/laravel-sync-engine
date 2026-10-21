<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Feed;

use HardImpact\SyncEngine\Config;
use HardImpact\SyncEngine\Exceptions\SyncRequestFailed;
use HardImpact\SyncEngine\SyncEntities;
use HardImpact\SyncEngine\SyncSequence;
use HardImpact\SyncEngine\Tombstones;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Reads the scope's changes after a seq, across entities and tombstones,
 * in seq order. Each live row carries only its latest seq, so a row appears
 * once per page.
 *
 * The page reads from one snapshot: the floor, every entity, the relations
 * that `row()` loads and the tombstones all see the same committed state. A
 * write that commits during the page is not in it. Seqs commit in order (see
 * SyncSequence), so the snapshot holds every seq up to its last_seq and none
 * above it, and the next pull returns the write.
 *
 * The page also returns only seqs at or below that last_seq, and checks
 * floor_seq after it reads the tombstones. Inside a snapshot both change
 * nothing. When the caller already holds a transaction, the page cannot
 * choose its isolation. At READ COMMITTED each read sees the latest commits:
 * the bound keeps the cursor from passing a seq the page did not see, and the
 * late floor check catches a prune that removed tombstones during the page.
 * So no change is skipped. Relations that `row()` loads may then be newer
 * than the row's seq; the newer change comes in a later pull.
 *
 * Synced models and the sync tables must use the default connection.
 */
final readonly class SyncFeed
{
    public function __construct(private SyncEntities $entities) {}

    /** @param list<string> $entityNames */
    public function page(int $scopeId, array $entityNames, int $afterSeq, int $limit): FeedPage
    {
        return self::snapshot(DB::connection(), function (Connection $connection) use ($scopeId, $entityNames, $afterSeq, $limit): FeedPage {
            // The first read pins the snapshot.
            $untilSeq = SyncSequence::committed($connection, $scopeId);
            $changes = [];

            foreach ($entityNames as $name) {
                $entity = $this->entities->get($name);
                $query = $entity->query($scopeId);
                $column = $query->getModel()->qualifyColumn('change_seq');

                // row() runs here, so relations it loads come from the snapshot too.
                $query->where($column, '>', $afterSeq)->where($column, '<=', $untilSeq)->orderBy($column)->limit($limit + 1)
                    ->get()
                    ->each(function (Model $model) use (&$changes, $entity, $name): void {
                        $changes[] = new FeedChange(
                            $name,
                            self::string($model->getAttribute('sync_id')),
                            Config::int($model->getAttribute('change_seq')),
                            'upsert',
                            $entity->row($model),
                        );
                    });
            }

            // A first pull has nothing to delete.
            if ($afterSeq > 0 && $entityNames !== []) {
                $connection->table(Tombstones::table())
                    ->where('scope_id', $scopeId)
                    ->whereIn('entity', $entityNames)
                    ->where('change_seq', '>', $afterSeq)
                    ->where('change_seq', '<=', $untilSeq)
                    ->orderBy('change_seq')
                    ->limit($limit + 1)
                    ->get(['entity', 'sync_id', 'change_seq'])
                    ->each(function (object $tombstone) use (&$changes): void {
                        /** @var object{entity: string, sync_id: string, change_seq: int|string} $tombstone */
                        $changes[] = new FeedChange($tombstone->entity, $tombstone->sync_id, Config::int($tombstone->change_seq), 'delete');
                    });
            }

            // Read after the tombstones. A prune deletes tombstones and raises
            // floor_seq in one commit, so if the read above missed tombstones
            // this cursor needs, this read sees the raised floor. That holds
            // without a snapshot too, at READ COMMITTED.
            if ($afterSeq > 0 && $afterSeq < SyncSequence::floor($connection, $scopeId)) {
                throw SyncRequestFailed::resyncRequired();
            }

            usort($changes, fn (FeedChange $a, FeedChange $b): int => $a->seq <=> $b->seq);

            $hasMore = count($changes) > $limit;
            $changes = array_slice($changes, 0, $limit);
            $lastSeq = $changes === [] ? $afterSeq : $changes[array_key_last($changes)]->seq;

            return new FeedPage($changes, $lastSeq, $hasMore);
        });
    }

    /**
     * Runs `$read` in one transaction that sees one snapshot.
     *
     * - SQLite: a deferred transaction takes its snapshot at its first read and
     *   keeps it until commit. In WAL mode it blocks no writer. A connection
     *   with `transaction_mode` IMMEDIATE or EXCLUSIVE takes the write lock
     *   for the page instead: still correct, but writers wait.
     * - MySQL and MariaDB: REPEATABLE READ, set for the next transaction. The
     *   snapshot starts at the first read.
     * - PostgreSQL: REPEATABLE READ, set before the first read.
     *
     * Inside a caller's transaction the isolation is the caller's. The page
     * stays correct at READ COMMITTED or stronger (see the class comment).
     *
     * @param  \Closure(Connection): FeedPage  $read
     */
    private static function snapshot(Connection $connection, \Closure $read): FeedPage
    {
        if ($connection->transactionLevel() > 0) {
            return $read($connection);
        }

        $driver = $connection->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $connection->unprepared('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $connection->transaction(function () use ($connection, $driver, $read): FeedPage {
            if ($driver === 'pgsql') {
                $connection->unprepared('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return $read($connection);
        });
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new \UnexpectedValueException('A synced row has no sync_id.');
    }
}
