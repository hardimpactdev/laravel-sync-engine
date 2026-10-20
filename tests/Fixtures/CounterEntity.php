<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync\Tests\Fixtures;

use HardImpact\HouseholdSync\Push\SyncWrite;
use HardImpact\HouseholdSync\SyncEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Counters change only by `adjust`, which lands on the counter with the same name. */
final class CounterEntity extends SyncEntity
{
    public function name(): string
    {
        return 'counters';
    }

    public function query(int $householdId): Builder
    {
        return Counter::query()->where('household_id', $householdId);
    }

    public function row(Model $model): array
    {
        if (! $model instanceof Counter) {
            throw new InvalidArgumentException('Expected a Counter.');
        }

        return ['name' => $model->name, 'quantity' => $model->quantity];
    }

    public function operations(): array
    {
        return ['adjust'];
    }

    public function operate(SyncWrite $write, string $operation): Model
    {
        $name = is_string($write->args['name'] ?? null) ? $write->args['name'] : '';
        $delta = is_int($write->args['delta'] ?? null) ? $write->args['delta'] : 0;
        $counter = Counter::query()->firstOrNew(['household_id' => $write->householdId, 'name' => $name]);

        if (! $counter->exists) {
            $counter->sync_id = $write->syncId;
        }

        $counter->quantity += $delta;
        $counter->save();

        return $counter;
    }
}
