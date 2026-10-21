<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Tests\Concurrent;

use HardImpact\SyncEngine\Tests\TestCase;

/**
 * Two connections to one SQLite file in WAL mode, as two requests in
 * production. `testing` serves the pull; `other` is the second request.
 */
abstract class ConcurrentTestCase extends TestCase
{
    private string $database = '';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->database = (string) tempnam(sys_get_temp_dir(), 'sync-engine-');

        $connection = [
            'driver' => 'sqlite',
            'database' => $this->database,
            'prefix' => '',
            'foreign_key_constraints' => false,
            'journal_mode' => 'wal',
            'busy_timeout' => 5000,
        ];

        $app['config']->set('database.connections.testing', $connection);
        $app['config']->set('database.connections.other', $connection);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->database.$suffix)) {
                unlink($this->database.$suffix);
            }
        }
    }
}
