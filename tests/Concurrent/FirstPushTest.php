<?php

declare(strict_types=1);

use HardImpact\SyncEngine\Http\SyncEndpoint;
use HardImpact\SyncEngine\SyncSequence;
use HardImpact\SyncEngine\Tests\Concurrent\ConcurrentTestCase;
use HardImpact\SyncEngine\Tests\Fixtures\Item;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::post('/sync/{scope}', fn (Request $request, int $scope) => app(SyncEndpoint::class)->respond($request, $scope));
});

/**
 * Runs `$work` in a forked process with its own `worker` connection, and
 * writes its result to `$file`.
 */
function forkWorker(string $file, Closure $work): int
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('Needs the pcntl extension.');
    }

    config()->set('database.connections.worker', config('database.connections.testing'));
    $pid = pcntl_fork();

    if ($pid === 0) {
        DB::setDefaultConnection('worker');
        file_put_contents($file, serialize($work()));

        // Skip destructors: they would close the parent's connections.
        posix_kill(posix_getpid(), SIGKILL);
    }

    return $pid;
}

function workerResult(int $pid, string $file): mixed
{
    pcntl_waitpid($pid, $status);

    return unserialize((string) file_get_contents($file));
}

/** A first push from one phone that creates one item in scope 7. */
function firstPush(int $phone): array
{
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
}

/**
 * Runs `$work(1)` here and `$work(2)` in a forked process at the same time,
 * and returns both results. The forked process uses its own connection.
 *
 * After its first write to sync_states in a transaction, each process waits up
 * to a second for the other to get as far. Both then hold what they took
 * first, which is when first writes of a scope used to deadlock on MySQL.
 *
 * @param  Closure(int): mixed  $work
 * @return array{0: mixed, 1: mixed}
 */
function inTwoProcesses(Closure $work): array
{
    $dir = sys_get_temp_dir().'/sync-engine-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $arrived = false;

    DB::listen(function (QueryExecuted $query) use (&$arrived, $dir): void {
        $sql = str_replace(['"', '`'], '', $query->sql);

        if ($arrived || $query->connectionName !== DB::getDefaultConnection() || DB::transactionLevel() === 0 || ! preg_match('/^(update|insert into) sync_states /', $sql)) {
            return;
        }

        $arrived = true;
        touch($dir.'/arrived-'.getmypid());
        $deadline = microtime(true) + 1;

        while (count(glob($dir.'/arrived-*') ?: []) < 2 && microtime(true) < $deadline) {
            usleep(10_000);
        }
    });

    $pid = forkWorker($dir.'/result', fn (): mixed => $work(2));
    $mine = $work(1);
    $theirs = workerResult($pid, $dir.'/result');

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);

    return [$mine, $theirs];
}

it('serializes two first pushes to a new scope', function (): void {
    // Scope 7 has no state row yet.
    $results = inTwoProcesses(fn (int $phone): array => firstPush($phone));

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

it('lets two first pushes through after a new-scope writer rolls back', function (): void {
    if (ConcurrentTestCase::driver() === 'sqlite') {
        $this->markTestSkipped('Needs a server: SQLite has no row locks to wait on.');
    }

    // A writer takes scope 7's first seq and keeps its transaction open.
    DB::beginTransaction();
    SyncSequence::next(DB::connection(), 7);

    $dir = sys_get_temp_dir().'/sync-engine-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $first = forkWorker($dir.'/first', fn (): array => firstPush(1));
    $second = forkWorker($dir.'/second', fn (): array => firstPush(2));

    // Both pushes wait on the writer's new row before it rolls back. Then they
    // compete for the row, which used to deadlock one of them on MySQL.
    $waits = ConcurrentTestCase::driver() === 'mysql'
        ? 'select count(*) as waits from performance_schema.data_lock_waits'
        : 'select count(*) as waits from pg_locks where not granted';
    $deadline = microtime(true) + 10;

    while ((int) DB::connection('other')->selectOne($waits)->waits < 2 && microtime(true) < $deadline) {
        usleep(10_000);
    }

    $waited = (int) DB::connection('other')->selectOne($waits)->waits;
    DB::rollBack();

    $results = [workerResult($first, $dir.'/first'), workerResult($second, $dir.'/second')];
    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);

    expect($waited)->toBeGreaterThanOrEqual(2)
        ->and($results)->toBe([[200, 'applied'], [200, 'applied']])
        ->and(Item::query()->where('scope_id', 7)->orderBy('change_seq')->pluck('change_seq')->all())->toBe([1, 2]);
});
