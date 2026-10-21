<?php

declare(strict_types=1);

use HardImpact\SyncEngine\Tests\Concurrent\ConcurrentTestCase;
use HardImpact\SyncEngine\Tests\TestCase;
use Illuminate\Testing\TestResponse;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(ConcurrentTestCase::class)->in('Concurrent');

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
