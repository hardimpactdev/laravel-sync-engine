<?php

declare(strict_types=1);

use HardImpact\SyncEngine\Http\SyncEndpoint;
use HardImpact\SyncEngine\Tests\Fixtures\Item;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::post('/sync/{scope}', fn (Request $request, int $scope) => app(SyncEndpoint::class)->respond($request, $scope));
});

/**
 * Runs `$work(1)` here and `$work(2)` in a forked process at the same time,
 * and returns both results. The forked process uses its own connection.
 *
 * After its first write to sync_states, each process waits up to a second for
 * the other to get as far. Both then hold what they took first, which is when
 * first writes of a scope used to deadlock on MySQL.
 *
 * @param  Closure(int): mixed  $work
 * @return array{0: mixed, 1: mixed}
 */
function inTwoProcesses(Closure $work): array
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('Needs the pcntl extension.');
    }

    $dir = sys_get_temp_dir().'/sync-engine-'.bin2hex(random_bytes(6));
    mkdir($dir);
    config()->set('database.connections.worker', config('database.connections.testing'));
    $arrived = false;

    DB::listen(function (QueryExecuted $query) use (&$arrived, $dir): void {
        $sql = str_replace(['"', '`'], '', $query->sql);

        if ($arrived || $query->connectionName !== DB::getDefaultConnection() || ! preg_match('/^(update|insert into) sync_states /', $sql)) {
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
        file_put_contents($dir.'/result', serialize($work(2)));

        // Skip destructors: they would close the parent's connections.
        posix_kill(posix_getpid(), SIGKILL);
    }

    $mine = $work(1);
    pcntl_waitpid($pid, $status);
    $theirs = unserialize((string) file_get_contents($dir.'/result'));

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);

    return [$mine, $theirs];
}

it('serializes two first pushes to a new scope', function (): void {
    // Scope 7 has no state row yet.
    $results = inTwoProcesses(function (int $phone): array {
        $response = test()->postJson('/sync/7', [
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

        return [$response->status(), $response->json('acks.0.status')];
    });

    expect($results)->toBe([[200, 'applied'], [200, 'applied']])
        ->and(Item::query()->where('scope_id', 7)->orderBy('change_seq')->pluck('change_seq')->all())->toBe([1, 2]);
});

it('lets two first writes of new scopes commit side by side', function (int $first, int $second, array $seqs): void {
    // Neither scope has a state row yet.
    $results = inTwoProcesses(function (int $process) use ($first, $second): string {
        try {
            $item = DB::transaction(fn (): Item => Item::create(['scope_id' => $process === 1 ? $first : $second, 'name' => "Kettle {$process}"]));

            return 'seq '.$item->changeSeq();
        } catch (Throwable $failure) {
            return $failure->getMessage();
        }
    });

    sort($results);

    expect($results)->toBe($seqs);
})->with([
    'one scope' => [7, 7, ['seq 1', 'seq 2']],
    'two scopes' => [7, 8, ['seq 1', 'seq 1']],
]);
