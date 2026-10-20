<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync\Tests\Fixtures;

use HardImpact\HouseholdSync\SyncEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends SyncEntity<Item> */
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
        return [
            'name' => $model->getAttribute('name'),
            'room_id' => $model->room?->syncId(),
            'notes' => $model->getAttribute('notes'),
        ];
    }
}
