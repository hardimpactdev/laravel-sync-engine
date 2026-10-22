<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Push;

use HardImpact\SyncEngine\SyncEntities;
use HardImpact\SyncEngine\Tombstones;
use Illuminate\Support\Str;

/**
 * The ids a device proposed for the rows an action makes, by key (protocol
 * §5.5). A handler asks `id()` for the sync_id of each row it makes, and calls
 * `used()` when it uses an existing row instead. The ack reports both in `made`.
 *
 * An action that only learns afterwards whether it made a row reads the id
 * with `adoptable()` first, and then reports the row it made or used with
 * `used()`.
 *
 * A handler that ignores it behaves as before: the device's rows are replaced
 * by the server's on the next pull.
 */
final class ProposedRows
{
    /** @var array<string, string> */
    private array $made = [];

    /**
     * The engine makes one per change. The defaults give a change without proposals.
     *
     * @param  array<string, string>  $proposed
     */
    public function __construct(
        private readonly array $proposed = [],
        private readonly int $scopeId = 0,
        private readonly SyncEntities $entities = new SyncEntities,
        private readonly ProposedIds $batch = new ProposedIds,
    ) {}

    /**
     * The sync_id for the new `$entity` row that `$key` names: the proposed id
     * when it is free, else a fresh one. A taken proposed id is never aliased,
     * since it would reach the row that has it; later changes in the batch
     * that need it are rejected.
     */
    public function id(string $key, string $entity): string
    {
        if (isset($this->made[$key])) {
            return $this->made[$key];
        }

        $adoptable = $this->adoptable($key, $entity);

        if ($adoptable !== null) {
            $this->batch->handOut($adoptable);

            return $this->made[$key] = $adoptable;
        }

        return $this->made[$key] = (string) Str::ulid();
    }

    /** The proposed id for `$key` when `id()` would adopt it, else null. It records nothing. */
    public function adoptable(string $key, string $entity): ?string
    {
        $proposed = $this->proposed[$key] ?? null;

        return $proposed !== null && $this->free($entity, $proposed) ? $proposed : null;
    }

    /**
     * The action made or used the `$entity` row `$syncId` for `$key`. A free
     * proposed id other than `$syncId` becomes its alias.
     */
    public function used(string $key, string $entity, string $syncId): void
    {
        $this->made[$key] = $syncId;
        $proposed = $this->proposed[$key] ?? null;

        if ($proposed !== null && $proposed !== $syncId && $this->free($entity, $proposed)) {
            Aliases::remember($this->scopeId, $entity, $proposed, $syncId);
        }

        $this->batch->handOut($syncId);
    }

    /**
     * The rows the action made or used, by key.
     *
     * @return array<string, string>
     */
    public function made(): array
    {
        return $this->made;
    }

    /**
     * The ids the device proposed, by key.
     *
     * @return array<string, string>
     */
    public function proposed(): array
    {
        return $this->proposed;
    }

    /**
     * Not handed out in this batch, and no row of the entity's table has it,
     * in any scope or state, and no tombstone or alias of the entity has it.
     *
     * Another scope can still claim the id between this check and the
     * insert. The unique constraint then rejects the change `forbidden`.
     */
    private function free(string $entity, string $syncId): bool
    {
        $model = $this->entities->get($entity)->query($this->scopeId)->getModel();

        return ! $this->batch->isHandedOut($syncId)
            && ! $model->newQueryWithoutScopes()->where($model->qualifyColumn('sync_id'), $syncId)->exists()
            && ! Tombstones::recorded($entity, $syncId)
            && ! Aliases::taken($entity, $syncId);
    }
}
