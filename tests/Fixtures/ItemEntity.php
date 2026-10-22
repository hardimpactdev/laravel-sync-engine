<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Tests\Fixtures;

use HardImpact\SyncEngine\Push\SyncRejection;
use HardImpact\SyncEngine\Push\SyncWrite;
use HardImpact\SyncEngine\SyncEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

/**
 * `shelve {room_name}` puts an item in the room with that name, and makes the
 * room when there is none. `stash` does the same, as an action that learns
 * only afterwards whether it made the room.
 */
final class ItemEntity extends SyncEntity
{
    public function name(): string
    {
        return 'items';
    }

    public function query(int $scopeId): Builder
    {
        return Item::query()->with('room')->where('scope_id', $scopeId);
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

    public function operations(): array
    {
        return ['shelve', 'stash'];
    }

    public function upsert(SyncWrite $write): Model
    {
        $item = $write->existing instanceof Item ? $write->existing : new Item(['scope_id' => $write->scopeId, 'sync_id' => $write->syncId]);
        $values = $write->fields;

        if (array_key_exists('room_id', $values)) {
            $values['room_id'] = $values['room_id'] instanceof Room ? $values['room_id']->id : null;
        }

        Validator::make([...$item->only(['name', 'notes']), ...$values], ['name' => ['required', 'string', 'max:255']])->validate();
        $item->fill($values)->save();

        return $item;
    }

    public function operate(SyncWrite $write, string $operation): Model
    {
        $item = $write->existing instanceof Item ? $write->existing : throw SyncRejection::deleted();
        $args = Validator::make($write->args, ['room_name' => ['required', 'string', 'max:255']])->validate();
        $name = is_string($args['room_name']) ? $args['room_name'] : '';
        $room = Room::query()->where('scope_id', $write->scopeId)->where('name', $name)->first();

        if ($operation === 'stash') {
            // A null id gets a fresh one.
            $room ??= Room::create(['scope_id' => $write->scopeId, 'sync_id' => $write->proposals->adoptable('room', 'rooms'), 'name' => $name]);
            $write->proposals->used('room', 'rooms', $room->syncId());
        } elseif ($room !== null) {
            $write->proposals->used('room', 'rooms', $room->syncId());
        } else {
            $room = Room::create(['scope_id' => $write->scopeId, 'sync_id' => $write->proposals->id('room', 'rooms'), 'name' => $name]);
        }

        $item->update(['room_id' => $room->id]);

        return $item;
    }
}
