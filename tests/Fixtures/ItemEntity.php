<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync\Tests\Fixtures;

use HardImpact\HouseholdSync\SyncEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ItemEntity extends SyncEntity
{
    public function name(): string
    {
        return 'items';
    }

    public function query(int $householdId): Builder
    {
        return Item::query()->with('room')->where('household_id', $householdId);
    }

    public function row(Model $model): array
    {
        if (! $model instanceof Item) {
            throw new \InvalidArgumentException('Expected a Item.');
        }

        return [
            'name' => $model->name,
            'room_id' => $model->room?->syncId(),
            'notes' => $model->notes,
        ];
    }
}
