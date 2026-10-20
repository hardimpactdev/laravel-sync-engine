<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Tests\Fixtures;

use HardImpact\SyncEngine\Push\SyncWrite;
use HardImpact\SyncEngine\SyncEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class RoomEntity extends SyncEntity
{
    public function name(): string
    {
        return 'rooms';
    }

    public function query(int $scopeId): Builder
    {
        return Room::query()->where('scope_id', $scopeId);
    }

    public function row(Model $model): array
    {
        if (! $model instanceof Room) {
            throw new \InvalidArgumentException('Expected a Room.');
        }

        return ['name' => $model->name];
    }

    public function writable(): array
    {
        return ['name'];
    }

    /** Two phones that both add "Kitchen" end up with one room. */
    public function match(SyncWrite $write): ?Model
    {
        $name = $write->fields['name'] ?? null;

        return is_string($name) ? Room::query()->where('scope_id', $write->scopeId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first() : null;
    }

    public function upsert(SyncWrite $write): Model
    {
        $room = $write->existing instanceof Room ? $write->existing : new Room(['scope_id' => $write->scopeId, 'sync_id' => $write->syncId]);
        $room->fill($write->fields)->save();

        return $room;
    }
}
