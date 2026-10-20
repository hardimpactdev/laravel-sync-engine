<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync;

use HardImpact\HouseholdSync\Push\SyncRejection;
use HardImpact\HouseholdSync\Push\SyncWrite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One synced entity, as the app defines it.
 *
 * Read side: `query()` returns the household's live rows; the feed adds the
 * change_seq filter and order. `row()` serializes one of those rows for the
 * wire: references are sync_ids, never server integer ids. The feed only
 * passes rows that `query()` returned, so `row()` may narrow the model with
 * `instanceof`.
 *
 * Write side (protocol §5): an entity accepts pushes only for the fields in
 * `writable()` and the operations in `operations()`. The handlers call the
 * app's own actions, so validation and side effects stay in the app. A
 * handler rejects a change by throwing a ValidationException or a
 * SyncRejection.
 */
abstract class SyncEntity
{
    abstract public function name(): string;

    /** @return Builder<covariant Model> */
    abstract public function query(int $householdId): Builder;

    /** @return array<string, mixed> */
    abstract public function row(Model $model): array;

    /**
     * Fields a device may set in an upsert. Empty: the entity is read-only.
     *
     * @return list<string>
     */
    public function writable(): array
    {
        return [];
    }

    /**
     * Fields and operation arguments that hold another row's sync_id, with that row's entity.
     *
     * @return array<string, string>
     */
    public function references(): array
    {
        return [];
    }

    /**
     * Named operations, such as `adjust`.
     *
     * @return list<string>
     */
    public function operations(): array
    {
        return [];
    }

    public function find(int $householdId, string $syncId): ?Model
    {
        $query = $this->query($householdId);

        return $query->where($query->getModel()->qualifyColumn('sync_id'), $syncId)->first();
    }

    /**
     * An existing row that a new row should become (protocol §5.3), such as a room
     * with the same name. With `merge`, the account-merge rules apply (§5.4).
     */
    public function match(SyncWrite $write): ?Model
    {
        return null;
    }

    /** Creates the row with the write's sync_id, or patches `$write->existing`. Returns the saved row. */
    public function upsert(SyncWrite $write): Model
    {
        throw SyncRejection::readOnly((string) array_key_first($write->fields));
    }

    public function delete(SyncWrite $write, Model $model): void
    {
        $model->delete();
    }

    /**
     * Runs a named operation. Returns the row it changed, which may be another
     * row than `$write->syncId` (the server then acks a remap).
     */
    public function operate(SyncWrite $write, string $operation): Model
    {
        throw SyncRejection::unknownOperation($operation);
    }
}
