<?php

declare(strict_types=1);

use HardImpact\SyncEngine\Http\SyncEndpoint;
use HardImpact\SyncEngine\Tests\Concurrent\ConcurrentTestCase;
use HardImpact\SyncEngine\Tests\Fixtures\Item;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::post('/sync/{scope}', fn (Request $request, int $scope) => app(SyncEndpoint::class)->respond($request, $scope));
});

it('makes a concurrent write wait for a push instead of failing the push', function (): void {
    // On a server the second connection would wait on the push's row lock,
    // and in one process nothing would ever release it.
    if (ConcurrentTestCase::driver() !== 'sqlite') {
        $this->markTestSkipped('Needs SQLite: a blocked second connection cannot time out quickly here.');
    }

    // The other request gives up after 100 ms instead of waiting 5 s. In
    // production it waits until the push commits.
    DB::connection('other')->unprepared('PRAGMA busy_timeout = 100');

    // Right after the push reads its receipts, another request saves an item.
    $blocked = null;
    $done = false;

    DB::listen(function (QueryExecuted $query) use (&$done, &$blocked): void {
        if ($done || $query->connectionName !== 'testing' || ! str_starts_with(str_replace('"', '', $query->sql), 'select payload_hash')) {
            return;
        }

        $done = true;

        try {
            Item::on('other')->create(['scope_id' => 1, 'name' => 'Toaster']);
            $blocked = false;
        } catch (QueryException $busy) {
            $blocked = $busy->errorInfo[1] ?? null;
        }
    });

    $response = test()->postJson('/sync/1', [
        'protocol' => 1,
        'client_id' => '01JBC4Y0R8T5D8M4QKX3N2V6ZA',
        'cursor' => null,
        'entities' => ['items'],
        'changes' => [[
            'change_id' => '01JBC5A2W4F8H0K2M4P6R00001',
            'entity' => 'items',
            'id' => '01JBC5A2W4F8H0K2M4P6R00200',
            'op' => 'upsert',
            'fields' => ['name' => 'Kettle'],
            'base_seq' => null,
            'position' => 1,
        ]],
    ]);

    // The push holds the write lock from its first statement, so the other
    // request waits (SQLITE_BUSY) instead of the push failing with
    // SQLITE_BUSY_SNAPSHOT. After the push commits, the other write goes through.
    $response->assertOk()->assertJsonPath('acks.0.status', 'applied')->assertJsonPath('acks.0.seq', 1);
    $toaster = Item::on('other')->create(['scope_id' => 1, 'name' => 'Toaster']);

    expect($done)->toBeTrue()
        ->and($blocked)->toBe(5)
        ->and($toaster->changeSeq())->toBe(2);
});
