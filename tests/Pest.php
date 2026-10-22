<?php

declare(strict_types=1);

use HardImpact\SyncEngine\Tests\Concurrent\ConcurrentTestCase;
use HardImpact\SyncEngine\Tests\TestCase;
use Illuminate\Testing\TestResponse;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(ConcurrentTestCase::class)->in('Concurrent');

const PHONE = '01JBC4Y0R8T5D8M4QKX3N2V6ZA';

function pull(array $overrides = [], int $scope = 1): TestResponse
{
    return test()->postJson("/sync/{$scope}", array_merge([
        'protocol' => 1,
        'client_id' => '01JBC4Y0R8T5D8M4QKX3N2V6ZA',
        'cursor' => null,
        'entities' => ['items', 'rooms'],
        'changes' => [],
    ], $overrides));
}

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
