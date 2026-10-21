<?php

declare(strict_types=1);

use HardImpact\SyncEngine\Http\SyncEndpoint;
use HardImpact\SyncEngine\Tests\Fixtures\Item;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Route::post('/sync/{scope}', fn (Request $request, int $scope) => app(SyncEndpoint::class)->respond($request, $scope));
});

/** A push from one phone that creates one item in scope 7, which has no state row yet. */
function firstPush(int $phone): TestResponse
{
    return test()->postJson('/sync/7', [
        'protocol' => 1,
        'client_id' => "01JBC4Y0R8T5D8M4QKX3N2V6Z{$phone}",
        'cursor' => null,
        'entities' => ['items'],
        'changes' => [[
            'change_id' => "01JBC5A2W4F8H0K2M4P6R0000{$phone}",
            'entity' => 'items',
            'id' => "01JBC5A2W4F8H0K2M4P6R0020{$phone}",
            'op' => 'upsert',
            'fields' => ['name' => "Kettle {$phone}"],
            'base_seq' => null,
            'position' => 1,
        ]],
    ]);
}

it('serializes two first pushes to a new scope from two processes', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Needs the pcntl extension.');
    }

    $dir = sys_get_temp_dir().'/sync-engine-'.bin2hex(random_bytes(6));
    mkdir($dir);

    // The forked process gets its own connection; the parent's stays untouched.
    config()->set('database.connections.worker', config('database.connections.testing'));

    // After its first update of sync_states, each push waits up to a second for
    // the other push to get as far. Both then hold what they took first, which
    // is when two first pushes used to deadlock on MySQL.
    $arrived = false;

    DB::listen(function (QueryExecuted $query) use (&$arrived, $dir): void {
        if ($arrived || $query->connectionName !== DB::getDefaultConnection() || ! str_starts_with(str_replace(['"', '`'], '', $query->sql), 'update sync_states')) {
            return;
        }

        $arrived = true;
        touch($dir.'/arrived-'.getmypid());
        $deadline = microtime(true) + 1;

        while (count(glob($dir.'/arrived-*') ?: []) < 2 && microtime(true) < $deadline) {
            usleep(10_000);
        }
    });

    $pid = pcntl_fork();

    if ($pid === 0) {
        DB::setDefaultConnection('worker');
        $response = firstPush(2);
        file_put_contents($dir.'/result', (string) json_encode([$response->status(), $response->json('acks.0.status')]));

        // Skip destructors: they would close the parent's connections.
        posix_kill(posix_getpid(), SIGKILL);
    }

    $mine = firstPush(1);
    pcntl_waitpid($pid, $status);
    $theirs = json_decode((string) file_get_contents($dir.'/result'), true);

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);

    expect([$mine->status(), $mine->json('acks.0.status')])->toBe([200, 'applied'])
        ->and($theirs)->toBe([200, 'applied'])
        ->and(Item::query()->where('scope_id', 7)->orderBy('change_seq')->pluck('change_seq')->all())->toBe([1, 2]);
});
