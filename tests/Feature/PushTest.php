<?php

declare(strict_types=1);

use HardImpact\SyncEngine\Events\ScopeChanged;
use HardImpact\SyncEngine\Http\SyncEndpoint;
use HardImpact\SyncEngine\Tests\Fixtures\Counter;
use HardImpact\SyncEngine\Tests\Fixtures\Item;
use HardImpact\SyncEngine\Tests\Fixtures\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

const PHONE = '01JBC4Y0R8T5D8M4QKX3N2V6ZA';

beforeEach(function (): void {
    Route::post('/sync/{scope}', fn (Request $request, int $scope) => app(SyncEndpoint::class)->respond($request, $scope));
});

/** A ULID made from a number, so tests read well. */
function id(int $number): string
{
    return '01JBC5A2W4F8H0K2M4P6R'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
}

/** @param list<array<string, mixed>> $changes */
function push(array $changes, ?string $cursor = null, int $scope = 1, string $client = PHONE): TestResponse
{
    return test()->postJson("/sync/{$scope}", [
        'protocol' => 1,
        'client_id' => $client,
        'cursor' => $cursor,
        'entities' => ['items', 'rooms', 'counters'],
        'changes' => $changes,
    ]);
}

/** @param array<string, mixed> $extra */
function change(int $number, string $entity, int $row, string $op, array $extra = []): array
{
    return ['change_id' => id($number), 'entity' => $entity, 'id' => id($row), 'op' => $op, 'base_seq' => null, 'position' => $number, ...$extra];
}

it('creates rows with the phone\'s ids and acks each change with its seq', function (): void {
    $response = push([
        change(1, 'rooms', 100, 'upsert', ['fields' => ['name' => 'Kitchen']]),
        change(2, 'items', 200, 'upsert', ['fields' => ['name' => 'Kettle', 'room_id' => id(100)]]),
    ])->assertOk();

    expect($response->json('acks'))->toBe([
        ['change_id' => id(1), 'status' => 'applied', 'seq' => 1],
        ['change_id' => id(2), 'status' => 'applied', 'seq' => 2],
    ])
        ->and($response->json('last_position'))->toBe(2)
        ->and(Item::query()->where('sync_id', id(200))->first()?->room?->sync_id)->toBe(id(100))
        // The pull of the same request already has both rows.
        ->and(array_column($response->json('changes'), 'id'))->toBe([id(100), id(200)]);
});

it('patches only the listed fields', function (): void {
    $item = Item::create(['scope_id' => 1, 'name' => 'Kettle', 'notes' => 'Descale']);

    push([change(1, 'items', 0, 'upsert', ['id' => $item->sync_id, 'fields' => ['name' => 'Kettle 2']])])->assertOk();

    expect($item->fresh())->name->toBe('Kettle 2')->notes->toBe('Descale');
});

it('makes a resend a duplicate with the same ack and no second write', function (): void {
    $batch = [change(1, 'counters', 300, 'adjust', ['args' => ['name' => 'Milk', 'delta' => 1]])];

    $first = push($batch)->json('acks.0');
    $again = push($batch)->json('acks.0');

    expect($again)->toBe([...$first, 'status' => 'duplicate'])
        ->and(Counter::query()->sole()->quantity)->toBe(1);
});

it('rejects a reused change id with other content', function (): void {
    push([change(1, 'items', 200, 'upsert', ['fields' => ['name' => 'Kettle']])]);

    push([change(1, 'items', 200, 'upsert', ['fields' => ['name' => 'Toaster']])])
        ->assertJsonPath('acks.0.status', 'rejected')
        ->assertJsonPath('acks.0.error.code', 'change_id_reused');
});

it('rejects invalid values with field errors and the current row, and keeps going', function (): void {
    $item = Item::create(['scope_id' => 1, 'name' => 'Kettle']);

    $response = push([
        change(1, 'items', 0, 'upsert', ['id' => $item->sync_id, 'fields' => ['name' => '']]),
        change(2, 'items', 201, 'upsert', ['fields' => ['name' => 'Toaster']]),
    ])->assertOk();

    expect($response->json('acks.0'))->toMatchArray([
        'status' => 'rejected',
        'current' => ['name' => 'Kettle', 'room_id' => null, 'notes' => null],
    ])
        ->and($response->json('acks.0.error.code'))->toBe('validation')
        ->and($response->json('acks.0.error.fields'))->toHaveKey('name')
        ->and($response->json('acks.1.status'))->toBe('applied')
        ->and($item->fresh()->name)->toBe('Kettle');
});

it('refuses fields and operations the entity does not allow', function (array $change, string $code): void {
    push([$change])->assertJsonPath('acks.0.error.code', $code);
})->with([
    'read-only field' => [change(1, 'items', 200, 'upsert', ['fields' => ['name' => 'Kettle', 'views' => 9]]), 'read_only_field'],
    'unknown entity' => [change(1, 'cats', 200, 'upsert', ['fields' => ['name' => 'Tom']]), 'unknown_entity'],
    'unknown operation' => [change(1, 'items', 200, 'explode'), 'unknown_op'],
    'reference to nothing' => [change(1, 'items', 200, 'upsert', ['fields' => ['name' => 'Kettle', 'room_id' => id(999)]]), 'parent_rejected'],
]);

it('lets a delete win over a later edit', function (): void {
    $item = Item::create(['scope_id' => 1, 'name' => 'Kettle']);
    $item->delete();

    push([change(1, 'items', 0, 'upsert', ['id' => $item->sync_id, 'fields' => ['name' => 'Kettle 2']])])
        ->assertJsonPath('acks.0.error.code', 'deleted');
});

it('deletes rows, and treats a delete of an unknown row as done', function (): void {
    $room = Room::create(['scope_id' => 1, 'name' => 'Hall']);

    $response = push([
        change(1, 'rooms', 0, 'delete', ['id' => $room->sync_id]),
        change(2, 'rooms', 404, 'delete'),
    ]);

    expect($response->json('acks.0'))->toBe(['change_id' => id(1), 'status' => 'applied', 'seq' => 2])
        ->and($response->json('acks.1'))->toBe(['change_id' => id(2), 'status' => 'applied'])
        ->and(Room::query()->count())->toBe(0);
});

it('maps a new room onto an existing one with the same name, also for later changes', function (): void {
    $kitchen = Room::create(['scope_id' => 1, 'name' => 'Kitchen']);

    $response = push([
        change(1, 'rooms', 100, 'upsert', ['fields' => ['name' => 'kitchen']]),
        change(2, 'items', 200, 'upsert', ['fields' => ['name' => 'Kettle', 'room_id' => id(100)]]),
    ]);

    expect($response->json('acks.0.remap'))->toBe(['from' => id(100), 'to' => $kitchen->sync_id])
        ->and(Room::query()->count())->toBe(1)
        ->and(Item::query()->sole()->room_id)->toBe($kitchen->id);

    // A change sent before the phone saw the remap still lands on the server's room.
    push([change(3, 'rooms', 100, 'upsert', ['fields' => ['name' => 'Kitchen']])])
        ->assertJsonPath('acks.0.status', 'applied')
        ->assertJsonPath('acks.0.remap.to', $kitchen->sync_id);
});

it('remaps an operation that lands on an existing row', function (): void {
    $milk = Counter::create(['scope_id' => 1, 'name' => 'Milk', 'quantity' => 2]);

    push([change(1, 'counters', 300, 'adjust', ['args' => ['name' => 'Milk', 'delta' => 1]])])
        ->assertJsonPath('acks.0.remap', ['from' => id(300), 'to' => $milk->sync_id]);

    expect($milk->fresh()->quantity)->toBe(3);
});

it('reports overwritten fields when the row changed after the phone saw it', function (): void {
    $item = Item::create(['scope_id' => 1, 'name' => 'Kettle']);
    $item->update(['notes' => 'Changed on the web']);

    push([change(1, 'items', 0, 'upsert', ['id' => $item->sync_id, 'base_seq' => 1, 'fields' => ['notes' => 'Phone']])])
        ->assertJsonPath('acks.0.overwrote', ['notes']);
});

it('pokes once per commit with the pushing install, and never for a rollback', function (): void {
    Event::fake([ScopeChanged::class]);

    push([
        change(1, 'rooms', 100, 'upsert', ['fields' => ['name' => 'Kitchen']]),
        change(2, 'items', 200, 'upsert', ['fields' => ['name' => '']]),
        change(3, 'items', 201, 'upsert', ['fields' => ['name' => 'Kettle']]),
    ]);
    Item::create(['scope_id' => 1, 'name' => 'Toaster']);

    $pokes = Event::dispatched(ScopeChanged::class)->map(fn (array $event): array => [$event[0]->seq, $event[0]->clientId])->all();
    expect($pokes)->toBe([[2, PHONE], [3, null]]);
});

it('keeps the highest applied position per install', function (): void {
    push([change(5, 'rooms', 100, 'upsert', ['fields' => ['name' => 'Hall']])]);

    expect(push([])->json('last_position'))->toBe(5)
        ->and(push([], client: '01JBC4Y0R8T5D8M4QKX3N2V6ZB')->json('last_position'))->toBe(0);
});

it('validates each change in the envelope', function (): void {
    push([['change_id' => 'nope', 'entity' => 'items', 'id' => id(1), 'op' => 'upsert']])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_request');
});

it('rejects an id that belongs to a row of another scope', function (): void {
    $theirs = Item::create(['scope_id' => 2, 'name' => 'Theirs']);

    push([change(1, 'items', 0, 'upsert', ['id' => $theirs->sync_id, 'fields' => ['name' => 'Mine']])])
        ->assertOk()
        ->assertJsonPath('acks.0.status', 'rejected')
        ->assertJsonPath('acks.0.error.code', 'forbidden');

    expect($theirs->fresh()->name)->toBe('Theirs');
});
