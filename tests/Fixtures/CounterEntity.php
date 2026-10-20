<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Tests\Fixtures;

use HardImpact\SyncEngine\Push\SyncWrite;
use HardImpact\SyncEngine\SyncEntity;
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

    public function query(int $scopeId): Builder
    {
        return Counter::query()->where('scope_id', $scopeId);
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
        $counter = Counter::query()->firstOrNew(['scope_id' => $write->scopeId, 'name' => $name]);

        if (! $counter->exists) {
            $counter->sync_id = $write->syncId;
        }

        $counter->quantity += $delta;
        $counter->save();

        return $counter;
    }
}
