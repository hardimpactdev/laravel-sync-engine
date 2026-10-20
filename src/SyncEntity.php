<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One synced entity, as the app defines it.
 *
 * `query()` returns the household's live rows; the feed adds the change_seq
 * filter and order. `row()` serializes one of those rows for the wire:
 * references are sync_ids, never server integer ids. The feed only passes rows
 * that `query()` returned, so `row()` may narrow the model with `instanceof`.
 */
abstract class SyncEntity
{
    abstract public function name(): string;

    /** @return Builder<covariant Model> */
    abstract public function query(int $householdId): Builder;

    /** @return array<string, mixed> */
    abstract public function row(Model $model): array;
}
