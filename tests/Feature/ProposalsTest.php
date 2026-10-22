<?php

declare(strict_types=1);

use HardImpact\SyncEngine\Http\SyncEndpoint;
use HardImpact\SyncEngine\Push\ProposedIds;
use HardImpact\SyncEngine\Push\ProposedRows;
use HardImpact\SyncEngine\SyncEntities;
use HardImpact\SyncEngine\Tests\Fixtures\Item;
use HardImpact\SyncEngine\Tests\Fixtures\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::post('/sync/{scope}', fn (Request $request, int $scope) => app(SyncEndpoint::class)->respond($request, $scope));
});

/**
 * Puts item `$item` in the room called `$room`. The action makes the room when there is none.
 *
 * @param  array<string, string>|null  $proposals
 */
function shelve(int $number, int $item, string $room, ?array $proposals = null): array
{
    return change($number, 'items', $item, 'shelve', ['args' => ['room_name' => $room], ...($proposals === null ? [] : ['proposals' => $proposals])]);
}

function proposalFixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../fixtures/v1/'.$name), true);
}

it('adopts a proposed id, so later changes in the batch can use it', function (): void {
    $response = push([
        change(1, 'items', 200, 'upsert', ['fields' => ['name' => 'Kettle']]),
        shelve(2, 200, 'Garage', ['room' => id(100)]),
        change(3, 'items', 201, 'upsert', ['fields' => ['name' => 'Ladder', 'room_id' => id(100)]]),
        change(4, 'rooms', 100, 'upsert', ['fields' => ['name' => 'Shed']]),
    ])->assertOk();

    expect($response->json('acks.1'))->toBe(['change_id' => id(2), 'status' => 'applied', 'seq' => 3, 'made' => ['room' => id(100)]])
        ->and($response->json('acks.2.status'))->toBe('applied')
        ->and($response->json('acks.3'))->toBe(['change_id' => id(4), 'status' => 'applied', 'seq' => 5])
        ->and(Room::query()->sole()->only(['sync_id', 'name']))->toBe(['sync_id' => id(100), 'name' => 'Shed'])
        ->and(Item::query()->orderBy('id')->get()->map(fn (Item $item): ?string => $item->room?->sync_id)->all())->toBe([id(100), id(100)]);
});

it('acks the existing row an action used, and reaches it through the proposed id', function (): void {
    $garage = Room::create(['scope_id' => 1, 'name' => 'Garage']);

    $response = push([
        change(1, 'items', 200, 'upsert', ['fields' => ['name' => 'Kettle']]),
        shelve(2, 200, 'Garage', ['room' => id(100)]),
        change(3, 'items', 201, 'upsert', ['fields' => ['name' => 'Ladder', 'room_id' => id(100)]]),
    ]);

    expect($response->json('acks.1.made'))->toBe(['room' => $garage->sync_id])
        ->and($response->json('acks.2.status'))->toBe('applied')
        ->and(Room::query()->count())->toBe(1)
        ->and(Item::query()->pluck('room_id')->all())->toBe([$garage->id, $garage->id]);

    // A later batch, sent before the phone saw the ack.
    $later = push([
        change(4, 'items', 202, 'upsert', ['fields' => ['name' => 'Rake', 'room_id' => id(100)]]),
        change(5, 'rooms', 100, 'upsert', ['fields' => ['name' => 'Big garage']]),
    ]);

    expect($later->json('acks.0.status'))->toBe('applied')
        ->and($later->json('acks.1.remap'))->toBe(['from' => id(100), 'to' => $garage->sync_id])
        ->and(Item::query()->where('sync_id', id(202))->sole()->room_id)->toBe($garage->id)
        ->and($garage->fresh()->name)->toBe('Big garage');
});

it('makes the row under a fresh id when the proposed id is taken, and aliases it', function (): void {
    $attic = Room::create(['scope_id' => 1, 'name' => 'Attic']);
    $attic->delete();

    $response = push([
        change(1, 'items', 200, 'upsert', ['fields' => ['name' => 'Kettle']]),
        shelve(2, 200, 'Garage', ['room' => $attic->sync_id]),
        change(3, 'items', 201, 'upsert', ['fields' => ['name' => 'Ladder', 'room_id' => $attic->sync_id]]),
    ]);

    $made = $response->json('acks.1.made.room');
    $garage = Room::query()->sole();

    expect($made)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')->not->toBe($attic->sync_id)
        ->and($garage->sync_id)->toBe($made)
        ->and(Item::query()->where('sync_id', id(201))->sole()->room_id)->toBe($garage->id);
});

it('hands a proposed id out once, and never aliases a live row away', function (): void {
    $response = push([
        change(1, 'items', 200, 'upsert', ['fields' => ['name' => 'Kettle']]),
        change(2, 'items', 201, 'upsert', ['fields' => ['name' => 'Ladder']]),
        shelve(3, 200, 'Garage', ['room' => id(100)]),
        shelve(4, 201, 'Attic', ['room' => id(100)]),
    ]);

    expect($response->json('acks.2.made.room'))->toBe(id(100))
        ->and($response->json('acks.3.made.room'))->not->toBe(id(100))
        ->and(Room::query()->count())->toBe(2);

    push([change(5, 'rooms', 100, 'upsert', ['fields' => ['name' => 'Big garage']])])
        ->assertJsonPath('acks.0', ['change_id' => id(5), 'status' => 'applied', 'seq' => 7]);

    expect(Room::query()->where('sync_id', id(100))->sole()->name)->toBe('Big garage');
});

it('lets an action read a proposal first and report its row afterwards', function (): void {
    $garage = Room::create(['scope_id' => 1, 'name' => 'Garage']);

    $response = push([
        change(1, 'items', 200, 'upsert', ['fields' => ['name' => 'Kettle']]),
        change(2, 'items', 200, 'stash', ['args' => ['room_name' => 'Attic'], 'proposals' => ['room' => id(100)]]),
        change(3, 'items', 200, 'stash', ['args' => ['room_name' => 'Garage'], 'proposals' => ['room' => id(101)]]),
        change(4, 'items', 201, 'upsert', ['fields' => ['name' => 'Ladder', 'room_id' => id(101)]]),
    ]);

    expect($response->json('acks.1.made'))->toBe(['room' => id(100)])
        ->and($response->json('acks.2.made'))->toBe(['room' => $garage->sync_id])
        ->and(Room::query()->where('sync_id', id(100))->sole()->name)->toBe('Attic')
        ->and(Item::query()->where('sync_id', id(201))->sole()->room_id)->toBe($garage->id);
});

it('reads an adoptable id without recording it, and hands it out once used', function (): void {
    $batch = new ProposedIds;
    $rows = new ProposedRows(['room' => id(100)], 1, app(SyncEntities::class), $batch);

    expect($rows->adoptable('room', 'rooms'))->toBe(id(100))
        ->and($rows->adoptable('room', 'rooms'))->toBe(id(100))
        ->and($rows->adoptable('label', 'rooms'))->toBeNull()
        ->and($rows->made())->toBe([]);

    $rows->used('room', 'rooms', id(100));

    expect($rows->made())->toBe(['room' => id(100)])
        ->and((new ProposedRows(['room' => id(100)], 1, app(SyncEntities::class), $batch))->adoptable('room', 'rooms'))->toBeNull()
        ->and(DB::table('sync_aliases')->count())->toBe(0);
});

it('leaves out a key the action did not make, and rejects the changes that need it', function (): void {
    $response = push([
        change(1, 'items', 200, 'upsert', ['fields' => ['name' => 'Kettle']]),
        shelve(2, 200, 'Garage', ['room' => id(100), 'label' => id(101)]),
        change(3, 'rooms', 101, 'upsert', ['fields' => ['name' => 'Label']]),
        change(4, 'items', 201, 'upsert', ['fields' => ['name' => 'Ladder', 'room_id' => id(101)]]),
    ]);

    expect($response->json('acks.1.made'))->toBe(['room' => id(100)])
        ->and($response->json('acks.2'))->toMatchArray(['status' => 'rejected', 'current' => null])
        ->and($response->json('acks.2.error.code'))->toBe('parent_rejected')
        ->and($response->json('acks.3.error.code'))->toBe('parent_rejected')
        ->and(Room::query()->pluck('sync_id')->all())->toBe([id(100)]);
});

it('rejects the changes that need a row of a rejected action', function (): void {
    $response = push([
        change(1, 'items', 200, 'upsert', ['fields' => ['name' => 'Kettle']]),
        shelve(2, 200, '', ['room' => id(100)]),
        change(3, 'rooms', 100, 'upsert', ['fields' => ['name' => 'Garage']]),
        change(4, 'items', 201, 'upsert', ['fields' => ['name' => 'Ladder', 'room_id' => id(100)]]),
    ]);

    expect(array_column($response->json('acks'), 'status'))->toBe(['applied', 'rejected', 'rejected', 'rejected'])
        ->and($response->json('acks.1.error.code'))->toBe('validation')
        ->and($response->json('acks.1'))->not->toHaveKey('made')
        ->and($response->json('acks.2.error.code'))->toBe('parent_rejected')
        ->and($response->json('acks.3.error.code'))->toBe('parent_rejected')
        ->and(Room::query()->count())->toBe(0);
});

it('repeats made for a resent change, and still rejects what needs a key it did not make', function (): void {
    $batch = [
        change(1, 'items', 200, 'upsert', ['fields' => ['name' => 'Kettle']]),
        shelve(2, 200, 'Garage', ['room' => id(100), 'label' => id(101)]),
    ];

    $first = push($batch)->json('acks');
    $again = push([...$batch, change(3, 'rooms', 101, 'upsert', ['fields' => ['name' => 'Label']])])->json('acks');

    expect($again[1])->toBe([...$first[1], 'status' => 'duplicate'])
        ->and($again[1]['made'])->toBe(['room' => id(100)])
        ->and($again[2]['error']['code'])->toBe('parent_rejected')
        ->and(Room::query()->count())->toBe(1);
});

it('acks made as an empty object when the action made nothing, also for a resend', function (): void {
    $batch = [change(1, 'rooms', 100, 'upsert', ['fields' => ['name' => 'Garage'], 'proposals' => ['label' => id(101)]])];

    expect(push($batch)->getContent())->toContain('"made":{}')
        ->and(push($batch)->getContent())->toContain('"status":"duplicate","seq":1,"made":{}');
});

it('acks a change without proposals as before', function (): void {
    $response = push([
        change(1, 'items', 200, 'upsert', ['fields' => ['name' => 'Kettle']]),
        shelve(2, 200, 'Garage'),
    ]);

    expect($response->json('acks'))->toBe([
        ['change_id' => id(1), 'status' => 'applied', 'seq' => 1],
        ['change_id' => id(2), 'status' => 'applied', 'seq' => 3],
    ])
        ->and(Room::query()->sole()->sync_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('accepts keys with every sign the protocol allows', function (): void {
    push([
        change(1, 'items', 200, 'upsert', ['fields' => ['name' => 'Kettle']]),
        shelve(2, 200, 'Garage', ['room' => id(100), 'pantry_item:2|a.b-c' => id(101)]),
    ])
        ->assertOk()
        ->assertJsonPath('acks.1.made', ['room' => id(100)]);
});

it('validates proposals in the envelope', function (mixed $proposals): void {
    push([change(1, 'items', 200, 'shelve', ['args' => ['room_name' => 'Garage'], 'proposals' => $proposals])])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_request');
})->with([
    'not an object' => ['room'],
    'upper-case key' => [['Room' => id(100)]],
    'key with a space' => [['pantry item' => id(100)]],
    'key too long' => [[str_repeat('k', 65) => id(100)]],
    'id not a ULID' => [['room' => 'nope']],
    'lower-case id' => [['room' => strtolower(id(100))]],
    'more than 50' => [array_combine(array_map(fn (int $n): string => "row{$n}", range(1, 51)), array_map(id(...), range(1, 51)))],
]);

it('announces proposals in every response', function (): void {
    push([])->assertOk()->assertJsonPath('features', ['proposals']);
});

it('answers the proposals fixture', function (): void {
    $request = proposalFixture('push-request-proposals.json');
    $expected = proposalFixture('push-response-proposals.json');
    Item::create(['scope_id' => 1, 'sync_id' => $request['changes'][0]['id'], 'name' => 'Dishwasher']);

    $response = test()->postJson('/sync/1', [...$request, 'cursor' => null])->assertOk();
    $withoutSeq = fn (array $rows): array => array_map(fn (array $row): array => Arr::except($row, 'seq'), $rows);

    expect(array_keys($response->json()))->toBe(array_keys($expected))
        ->and($withoutSeq($response->json('acks')))->toBe($withoutSeq($expected['acks']))
        ->and($withoutSeq($response->json('changes')))->toBe($withoutSeq($expected['changes']))
        ->and($response->json('last_position'))->toBe($expected['last_position'])
        ->and($response->json('features'))->toBe($expected['features']);
});
