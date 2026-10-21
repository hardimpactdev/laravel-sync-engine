<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Feed;

use HardImpact\SyncEngine\Config;
use HardImpact\SyncEngine\SyncEntities;
use HardImpact\SyncEngine\SyncSequence;
use HardImpact\SyncEngine\Tombstones;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Reads the scope's changes after a seq, across entities and tombstones,
 * in seq order. Each live row carries only its latest seq, so a row appears
 * once per page.
 *
 * Each entity is a separate query, so a write can commit between two of them.
 * The page therefore reads the scope's committed last_seq first and returns
 * only seqs at or below it. Seqs commit in order (see SyncSequence), so every
 * seq up to that bound is already visible, and a later write gets a seq above
 * it and comes in the next pull.
 */
final readonly class SyncFeed
{
    public function __construct(private SyncEntities $entities) {}

    /** @param list<string> $entityNames */
    public function page(int $scopeId, array $entityNames, int $afterSeq, int $limit): FeedPage
    {
        $changes = [];
        $untilSeq = SyncSequence::committed(DB::connection(), $scopeId);

        foreach ($entityNames as $name) {
            $entity = $this->entities->get($name);
            $query = $entity->query($scopeId);
            $column = $query->getModel()->qualifyColumn('change_seq');

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
            DB::table(Tombstones::table())
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

        usort($changes, fn (FeedChange $a, FeedChange $b): int => $a->seq <=> $b->seq);

        $hasMore = count($changes) > $limit;
        $changes = array_slice($changes, 0, $limit);
        $lastSeq = $changes === [] ? $afterSeq : $changes[array_key_last($changes)]->seq;

        return new FeedPage($changes, $lastSeq, $hasMore);
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new \UnexpectedValueException('A synced row has no sync_id.');
    }
}
