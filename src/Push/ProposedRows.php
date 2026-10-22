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
     * when it is unused, else a fresh one. A proposed id that is not adopted
     * becomes an alias of the fresh one.
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

        $proposed = $this->proposed[$key] ?? null;
        $fresh = (string) Str::ulid();

        if ($proposed !== null) {
            $this->alias($entity, $proposed, $fresh);
        }

        return $this->made[$key] = $fresh;
    }

    /**
     * The proposed id for `$key` when `id()` would adopt it: unused in this
     * batch, and no row or tombstone of `$entity` in the scope has it. Else
     * null. It records nothing.
     */
    public function adoptable(string $key, string $entity): ?string
    {
        $proposed = $this->proposed[$key] ?? null;

        return $proposed !== null && ! $this->batch->isHandedOut($proposed) && ! $this->taken($entity, $proposed) ? $proposed : null;
    }

    /**
     * The action made or used the `$entity` row `$syncId` for `$key`. A
     * proposed id other than `$syncId` becomes its alias.
     */
    public function used(string $key, string $entity, string $syncId): void
    {
        $this->made[$key] = $syncId;
        $this->batch->handOut($syncId);
        $proposed = $this->proposed[$key] ?? null;

        if ($proposed !== null && $proposed !== $syncId) {
            $this->alias($entity, $proposed, $syncId);
        }
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

    private function taken(string $entity, string $syncId): bool
    {
        return $this->entities->get($entity)->find($this->scopeId, $syncId) !== null
            || Tombstones::exists($this->scopeId, $entity, $syncId)
            || Aliases::of($this->scopeId, $entity, $syncId) !== null;
    }

    /** An alias never hides a live row with the proposed id. */
    private function alias(string $entity, string $from, string $to): void
    {
        if ($this->entities->get($entity)->find($this->scopeId, $from) === null) {
            Aliases::remember($this->scopeId, $entity, $from, $to);
        }
    }
}
