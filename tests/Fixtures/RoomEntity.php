<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync\Tests\Fixtures;

use HardImpact\HouseholdSync\SyncEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class RoomEntity extends SyncEntity
{
    public function name(): string
    {
        return 'rooms';
    }

    public function query(int $householdId): Builder
    {
        return Room::query()->where('household_id', $householdId);
    }

    public function row(Model $model): array
    {
        if (! $model instanceof Room) {
            throw new \InvalidArgumentException('Expected a Room.');
        }

        return ['name' => $model->name];
    }
}
