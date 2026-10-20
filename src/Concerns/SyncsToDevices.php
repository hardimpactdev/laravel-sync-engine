<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync\Concerns;

use HardImpact\HouseholdSync\HouseholdSyncSequence;
use HardImpact\HouseholdSync\Tombstones;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * Makes a model part of the household change feed.
 *
 * The table needs a unique `sync_id` (ULID), a nullable `change_seq` and the
 * household column. Every save that changes a synced attribute takes the next
 * change_seq, and every delete leaves a tombstone. Saves and deletes run in a
 * transaction, so the seq and the row commit together.
 *
 * Writes that skip model events (query-builder updates, saveQuietly) skip the
 * feed. Apps must not use them on synced models.
 *
 * @mixin Model
 */
trait SyncsToDevices
{
    public static function bootSyncsToDevices(): void
    {
        static::creating(function (Model $model): void {
            if (blank($model->getAttribute('sync_id'))) {
                $model->setAttribute('sync_id', (string) Str::ulid());
            }
        });

        static::saving(function (Model $model): void {
            /** @var Model&self $model */
            if ($model->exists && ! $model->hasSyncedChanges()) {
                return;
            }

            $model->setAttribute('change_seq', HouseholdSyncSequence::next($model->getConnection(), $model->syncHouseholdId()));
        });

        static::deleted(function (Model $model): void {
            /** @var Model&self $model */
            Tombstones::record($model->getConnection(), $model->syncHouseholdId(), $model->syncEntity(), $model->syncId());
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model): void {
                /** @var Model&self $model */
                Tombstones::forget($model->getConnection(), $model->syncEntity(), $model->syncId());
            });
        }
    }

    /** @param array{touch?: bool|null} $options */
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(fn (): ?bool => parent::delete());
    }

    /** The entity name on the wire and in tombstones. Defaults to the table name. */
    public function syncEntity(): string
    {
        return $this->getTable();
    }

    public function syncHouseholdId(): int
    {
        $householdId = $this->getAttribute($this->syncHouseholdColumn());

        if (! is_numeric($householdId)) {
            throw new LogicException(static::class.' has no household, so it cannot sync.');
        }

        return (int) $householdId;
    }

    public function syncHouseholdColumn(): string
    {
        return 'household_id';
    }

    public function syncId(): string
    {
        $syncId = $this->getAttribute('sync_id');

        return is_string($syncId) ? $syncId : throw new LogicException(static::class.' has no sync_id yet.');
    }

    public function changeSeq(): ?int
    {
        $seq = $this->getAttribute('change_seq');

        return is_numeric($seq) ? (int) $seq : null;
    }

    /**
     * Attributes whose change does not reach devices.
     *
     * @return list<string>
     */
    public function syncIgnoredAttributes(): array
    {
        return [];
    }

    public function hasSyncedChanges(): bool
    {
        $ignored = ['change_seq', $this->getUpdatedAtColumn(), ...$this->syncIgnoredAttributes()];

        return array_diff(array_keys($this->getDirty()), $ignored) !== [];
    }
}
