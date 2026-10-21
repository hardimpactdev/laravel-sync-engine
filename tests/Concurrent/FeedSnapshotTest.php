<?php

declare(strict_types=1);

use HardImpact\SyncEngine\Exceptions\SyncRequestFailed;
use HardImpact\SyncEngine\Feed\SyncFeed;
use HardImpact\SyncEngine\Http\SyncEndpoint;
use HardImpact\SyncEngine\Tests\Concurrent\ConcurrentTestCase;
use HardImpact\SyncEngine\Tests\Fixtures\Item;
use HardImpact\SyncEngine\Tests\Fixtures\Room;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::post('/sync/{scope}', fn (Request $request, int $scope) => app(SyncEndpoint::class)->respond($request, $scope));
});

/**
 * Runs `$write` once, right after the first query on `testing` that starts
 * with `$sql` (without identifier quotes). The write commits on the other connection, as another request would.
 */
function afterPullReads(string $sql, Closure $write): void
{
    $done = false;

    DB::listen(function (QueryExecuted $query) use (&$done, $sql, $write): void {
        // Without identifier quotes, so one prefix matches every driver.
        $text = str_replace(['"', '`'], '', $query->sql);

        if ($done || $query->connectionName !== 'testing' || ! str_starts_with($text, $sql)) {
            return;
        }

        $done = true;
        $write();
    });
}

/** Runs the prune command on the other connection. */
function pruneElsewhere(): void
{
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('other');

    try {
        Artisan::call('sync-engine:prune-tombstones');
    } finally {
        DB::setDefaultConnection($default);
    }
}

it('delivers a write that commits between two entity reads on the next pull', function (): void {
    Item::create(['scope_id' => 1, 'name' => 'Kettle']);
    $kitchen = Room::create(['scope_id' => 1, 'name' => 'Kitchen']);
    $cursor = pull()->json('cursor');

    // Right after the page reads items, another request saves an item (seq 3)
    // and then a room (seq 4). The page reads rooms after both have committed.
    afterPullReads('select * from items', function () use ($kitchen): void {
        DB::connection('other')->transaction(function () use ($kitchen): void {
            Item::on('other')->create(['scope_id' => 1, 'name' => 'Toaster']);
            Room::on('other')->findOrFail($kitchen->id)->update(['name' => 'Kitchen 2']);
        });
    });

    $page = pull(['cursor' => $cursor])->assertOk()->assertJsonPath('has_more', false);
    $next = pull(['cursor' => $page->json('cursor')])->assertOk();

    expect(array_column($page->json('changes'), 'seq'))->toBe([])
        ->and(array_column($next->json('changes'), 'seq'))->toBe([3, 4]);
});

it('pages through one snapshot while another request writes', function (): void {
    $kitchen = Room::create(['scope_id' => 1, 'name' => 'Kitchen']);
    $hall = Room::create(['scope_id' => 1, 'name' => 'Hall']);
    Item::create(['scope_id' => 1, 'name' => 'Kettle', 'room_id' => $kitchen->id]);
    Item::create(['scope_id' => 1, 'name' => 'Mug']);

    // During the first page, another request adds an item (seq 5) and renames
    // the hall, which moves it from seq 2 to seq 6.
    afterPullReads('select * from items', function () use ($hall): void {
        Item::on('other')->create(['scope_id' => 1, 'name' => 'Toaster']);
        Room::on('other')->findOrFail($hall->id)->update(['name' => 'Hallway']);
    });

    $pages = [];
    $rows = [];
    $cursor = null;

    do {
        $response = pull(['cursor' => $cursor, 'limit' => 3])->assertOk();
        $changes = $response->json('changes');
        $pages[] = array_column($changes, 'seq');
        $cursor = $response->json('cursor');

        foreach ($changes as $change) {
            $rows[$change['id']] = $change['row']['name'];
        }
    } while ($changes !== []);

    // The first page is the state before the write: the hall at seq 2 with its
    // old name. The next page brings the write.
    expect($pages)->toBe([[1, 2, 3], [4, 5, 6], []])
        ->and($response->json('has_more'))->toBeFalse()
        ->and($rows[$hall->sync_id])->toBe('Hallway')
        ->and(count($rows))->toBe(5);
});

it('serializes a row with its relations as they were in the snapshot', function (): void {
    $kitchen = Room::create(['scope_id' => 1, 'name' => 'Kitchen']);
    $kettle = Item::create(['scope_id' => 1, 'name' => 'Kettle', 'room_id' => $kitchen->id]);

    // Between the items read and the eager load of their rooms, another
    // request deletes the kitchen (seq 3).
    afterPullReads('select * from items', function () use ($kitchen): void {
        Room::on('other')->findOrFail($kitchen->id)->delete();
    });

    $page = pull()->assertOk()->assertJsonPath('changes', [
        ['entity' => 'rooms', 'id' => $kitchen->sync_id, 'seq' => 1, 'op' => 'upsert', 'row' => ['name' => 'Kitchen']],
        ['entity' => 'items', 'id' => $kettle->sync_id, 'seq' => 2, 'op' => 'upsert', 'row' => ['name' => 'Kettle', 'room_id' => $kitchen->sync_id, 'notes' => null]],
    ]);

    pull(['cursor' => $page->json('cursor')])->assertOk()->assertJsonPath('changes', [
        ['entity' => 'rooms', 'id' => $kitchen->sync_id, 'seq' => 3, 'op' => 'delete'],
    ]);
});

it('never pages past a delete that a prune removes during the pull', function (string $sql, int $status, ?array $seqs): void {
    $kettle = Item::create(['scope_id' => 1, 'name' => 'Kettle']);
    Item::create(['scope_id' => 1, 'name' => 'Mug']);
    $cursor = pull()->json('cursor');

    $kettle->delete();
    Item::create(['scope_id' => 1, 'name' => 'Toaster']);
    $this->travel(200)->days();

    // The prune deletes the kettle's tombstone (seq 3) and raises floor_seq to 3.
    afterPullReads($sql, fn () => pruneElsewhere());

    $response = pull(['cursor' => $cursor])->assertStatus($status);

    if ($seqs === null) {
        $response->assertJsonPath('error.code', 'resync_required');
    } else {
        expect(array_map(fn (array $change): array => [$change['op'], $change['seq']], $response->json('changes')))->toBe($seqs);
    }
})->with([
    // The prune commits before the page starts: the page sees the raised floor.
    'after the floor check' => ['select floor_seq', 409, null],
    // The prune commits during the page: the page still sees the tombstone.
    'between entity reads' => ['select * from items', 200, [['delete', 3], ['upsert', 4]]],
]);

it('never pages past a pruned delete inside a caller\'s READ COMMITTED transaction', function (): void {
    $kettle = Item::create(['scope_id' => 1, 'name' => 'Kettle']);
    Item::create(['scope_id' => 1, 'name' => 'Mug']);
    $kettle->delete();
    Item::create(['scope_id' => 1, 'name' => 'Toaster']);
    $this->travel(200)->days();

    // After the page reads last_seq and before it reads tombstones, a prune
    // deletes the kettle's tombstone (seq 3) and raises floor_seq to 3.
    afterPullReads('select * from items', fn () => pruneElsewhere());

    $driver = ConcurrentTestCase::driver();

    if ($driver === 'mysql') {
        DB::unprepared('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }

    $result = DB::transaction(function () use ($driver): array {
        if ($driver === 'pgsql') {
            DB::unprepared('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        try {
            $page = app(SyncFeed::class)->page(1, ['items', 'rooms'], 2, 500);
        } catch (SyncRequestFailed $failure) {
            return ['resync', $failure->errorCode];
        }

        return array_map(fn ($change): array => [$change->op, $change->seq], $page->changes);
    });

    // SQLite's transaction is a snapshot, so the page still has the tombstone.
    // At READ COMMITTED the tombstone is gone, so the page must ask for a resync.
    expect($result)->toBe($driver === 'sqlite' ? [['delete', 3], ['upsert', 4]] : ['resync', 'resync_required']);
});
