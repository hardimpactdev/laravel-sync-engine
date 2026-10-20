<?php

declare(strict_types=1);

namespace HardImpact\HouseholdSync\Tests\Fixtures;

use HardImpact\HouseholdSync\Push\SyncWrite;
use HardImpact\HouseholdSync\SyncEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

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

    public function writable(): array
    {
        return ['name', 'room_id', 'notes'];
    }

    public function references(): array
    {
        return ['room_id' => 'rooms'];
    }

    public function upsert(SyncWrite $write): Model
    {
        $item = $write->existing instanceof Item ? $write->existing : new Item(['household_id' => $write->householdId, 'sync_id' => $write->syncId]);
        $values = $write->fields;

        if (array_key_exists('room_id', $values)) {
            $values['room_id'] = $values['room_id'] instanceof Room ? $values['room_id']->id : null;
        }

        Validator::make([...$item->only(['name', 'notes']), ...$values], ['name' => ['required', 'string', 'max:255']])->validate();
        $item->fill($values)->save();

        return $item;
    }
}
