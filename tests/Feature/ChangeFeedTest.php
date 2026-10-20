<?php

declare(strict_types=1);

use HardImpact\HouseholdSync\Backfill;
use HardImpact\HouseholdSync\HouseholdSyncSequence;
use HardImpact\HouseholdSync\Http\SyncEndpoint;
use HardImpact\HouseholdSync\Tests\Fixtures\Item;
use HardImpact\HouseholdSync\Tests\Fixtures\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

function pull(array $overrides = [], int $household = 1): TestResponse
{
    return test()->postJson("/sync/{$household}", array_merge([
        'protocol' => 1,
        'client_id' => '01JBC4Y0R8T5D8M4QKX3N2V6ZA',
        'cursor' => null,
        'entities' => ['items', 'rooms'],
        'changes' => [],
    ], $overrides));
}

beforeEach(function (): void {
    Route::post('/sync/{household}', fn (Request $request, int $household) => app(SyncEndpoint::class)->respond($request, $household));
});

it('gives new rows an upper-case ULID and the next seq of their household', function (): void {
    $kitchen = Room::create(['household_id' => 1, 'name' => 'Kitchen']);
    $other = Room::create(['household_id' => 2, 'name' => 'Attic']);
    $item = Item::create(['household_id' => 1, 'name' => 'Kettle', 'room_id' => $kitchen->id]);

    expect($kitchen->sync_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($kitchen->changeSeq())->toBe(1)
        ->and($item->changeSeq())->toBe(2)
        ->and($other->changeSeq())->toBe(1);
});

it('takes a new seq only when a synced attribute changes', function (): void {
    $item = Item::create(['household_id' => 1, 'name' => 'Kettle']);

    $item->update(['views' => 5]);
    $item->touch();
    expect($item->fresh()->changeSeq())->toBe(1);

    $item->update(['notes' => 'Descale monthly']);
    expect($item->fresh()->changeSeq())->toBe(2);
});

it('refuses to hand out a seq outside a transaction', function (): void {
    HouseholdSyncSequence::next(DB::connection(), 1);
})->throws(LogicException::class);

it('rolls the seq back with the write', function (): void {
    try {
        DB::transaction(function (): void {
            Item::create(['household_id' => 1, 'name' => 'Kettle']);

            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
    }

    expect(Item::create(['household_id' => 1, 'name' => 'Toaster'])->changeSeq())->toBe(1);
});

it('serves a first pull of every live row in seq order', function (): void {
    $kitchen = Room::create(['household_id' => 1, 'name' => 'Kitchen']);
    $kettle = Item::create(['household_id' => 1, 'name' => 'Kettle', 'room_id' => $kitchen->id]);
    Item::create(['household_id' => 2, 'name' => 'Not ours']);

    pull()
        ->assertOk()
        ->assertJsonPath('protocol', 1)
        ->assertJsonPath('acks', [])
        ->assertJsonPath('has_more', false)
        ->assertJsonPath('changes', [
            ['entity' => 'rooms', 'id' => $kitchen->sync_id, 'seq' => 1, 'op' => 'upsert', 'row' => ['name' => 'Kitchen']],
            ['entity' => 'items', 'id' => $kettle->sync_id, 'seq' => 2, 'op' => 'upsert', 'row' => ['name' => 'Kettle', 'room_id' => $kitchen->sync_id, 'notes' => null]],
        ]);
});

it('pulls only what changed after the cursor, with each row once', function (): void {
    $kettle = Item::create(['household_id' => 1, 'name' => 'Kettle']);
    $cursor = pull()->json('cursor');

    $kettle->update(['name' => 'Kettle 2']);
    $kettle->update(['name' => 'Kettle 3']);
    $toaster = Item::create(['household_id' => 1, 'name' => 'Toaster']);

    $changes = pull(['cursor' => $cursor])->assertOk()->json('changes');

    expect(collect($changes)->map(fn (array $change): array => [$change['id'], $change['seq'], $change['row']['name']])->all())
        ->toBe([[$kettle->sync_id, 3, 'Kettle 3'], [$toaster->sync_id, 4, 'Toaster']]);
});

it('pages through the feed without losing rows', function (): void {
    foreach (range(1, 7) as $number) {
        $number % 2 === 0
            ? Room::create(['household_id' => 1, 'name' => "Room {$number}"])
            : Item::create(['household_id' => 1, 'name' => "Item {$number}"]);
    }

    $seen = [];
    $cursor = null;

    do {
        $response = pull(['cursor' => $cursor, 'limit' => 3])->assertOk();
        array_push($seen, ...array_column($response->json('changes'), 'seq'));
        $cursor = $response->json('cursor');
    } while ($response->json('has_more'));

    expect($seen)->toBe(range(1, 7));
});

it('sends deletes as tombstones and soft deletes the same way', function (): void {
    $kitchen = Room::create(['household_id' => 1, 'name' => 'Kitchen']);
    $kettle = Item::create(['household_id' => 1, 'name' => 'Kettle']);
    $cursor = pull()->json('cursor');

    $kitchen->delete();
    $kettle->delete();

    pull(['cursor' => $cursor])->assertOk()->assertJsonPath('changes', [
        ['entity' => 'rooms', 'id' => $kitchen->sync_id, 'seq' => 3, 'op' => 'delete'],
        ['entity' => 'items', 'id' => $kettle->sync_id, 'seq' => 4, 'op' => 'delete'],
    ]);

    // A first pull has nothing to delete, and a deleted row is gone.
    expect(pull()->json('changes'))->toBe([]);
});

it('syncs a restored row again as an upsert', function (): void {
    $kettle = Item::create(['household_id' => 1, 'name' => 'Kettle']);
    $kettle->delete();
    $cursor = pull()->json('cursor');

    $kettle->restore();

    pull(['cursor' => $cursor])->assertOk()
        ->assertJsonPath('changes.0.op', 'upsert')
        ->assertJsonPath('changes.0.seq', 3)
        ->assertJsonCount(1, 'changes');
});

it('returns only the entities the app asks for', function (): void {
    Room::create(['household_id' => 1, 'name' => 'Kitchen']);
    Item::create(['household_id' => 1, 'name' => 'Kettle']);

    expect(array_column(pull(['entities' => ['items']])->json('changes'), 'entity'))->toBe(['items']);
});

it('backfills existing rows into the feed', function (): void {
    DB::table('items')->insert([
        ['household_id' => 1, 'sync_id' => '01JBC59ZQ0B2D4F6H8K0M2P4R6', 'change_seq' => null, 'name' => 'Old kettle'],
    ]);
    DB::statement('CREATE TABLE legacy (id integer primary key, household_id integer, sync_id char(26), change_seq integer)');
    DB::table('legacy')->insert([['household_id' => 1], ['household_id' => 1]]);

    Backfill::run('items');
    Backfill::run('legacy');

    expect(DB::table('items')->value('change_seq'))->toBe(1)
        ->and(DB::table('items')->value('sync_id'))->toBe('01JBC59ZQ0B2D4F6H8K0M2P4R6')
        ->and(DB::table('legacy')->pluck('change_seq')->all())->toBe([2, 3])
        ->and(DB::table('legacy')->whereNull('sync_id')->count())->toBe(0);
});

it('asks for a resync when the cursor is older than the pruned history', function (): void {
    $kettle = Item::create(['household_id' => 1, 'name' => 'Kettle']);
    $cursor = pull()->json('cursor');
    $kettle->delete();
    Item::create(['household_id' => 1, 'name' => 'Toaster']);

    $this->travel(200)->days();
    $this->artisan('household-sync:prune-tombstones')->assertSuccessful();

    pull(['cursor' => $cursor])->assertStatus(409)->assertJsonPath('error.code', 'resync_required');
    pull()->assertOk()->assertJsonCount(1, 'changes');
});

it('rejects a cursor of another household', function (): void {
    Item::create(['household_id' => 2, 'name' => 'Kettle']);
    $cursor = pull(household: 2)->json('cursor');

    pull(['cursor' => $cursor])->assertStatus(422)->assertJsonPath('error.code', 'invalid_request');
});

it('validates the envelope', function (array $body, int $status, string $code): void {
    pull($body)->assertStatus($status)->assertJsonPath('error.code', $code);
})->with([
    'newer protocol' => [['protocol' => 2], 426, 'upgrade_required'],
    'protocol as text' => [['protocol' => '1'], 422, 'invalid_request'],
    'unknown entity' => [['entities' => ['cats']], 422, 'invalid_request'],
    'bad client id' => [['client_id' => 'abc'], 422, 'invalid_request'],
    'garbled cursor' => [['cursor' => 'nope'], 422, 'invalid_request'],
    'pushed changes' => [['changes' => [['change_id' => 'x']]], 422, 'invalid_request'],
]);

it('answers in the shape of the protocol fixture', function (): void {
    Item::create(['household_id' => 1, 'name' => 'Kettle']);

    $fixture = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/v1/push-response.json'), true);

    expect(array_keys(pull()->json()))->toBe(array_keys($fixture))
        ->and(array_keys(pull()->json('changes.0')))->toBe(array_keys($fixture['changes'][0]));
});
