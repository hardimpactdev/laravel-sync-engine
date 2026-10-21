<?php

declare(strict_types=1);

namespace HardImpact\SyncEngine\Tests\Concurrent;

use HardImpact\SyncEngine\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use PDO;

/**
 * Two connections to one database, as two requests in production. `testing`
 * serves the request under test; `other` is the second request.
 *
 * By default the database is an SQLite file in WAL mode. Set DB_DRIVER to
 * pgsql or mysql, with DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and
 * DB_PASSWORD, to run the suite on a server.
 */
abstract class ConcurrentTestCase extends TestCase
{
    private string $database = '';

    public static function driver(): string
    {
        $driver = getenv('DB_DRIVER');

        return is_string($driver) && $driver !== '' ? $driver : 'sqlite';
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.testing', $connection = $this->connection());
        $app['config']->set('database.connections.other', $connection);
    }

    protected function defineDatabaseMigrations(): void
    {
        // A server keeps its tables between tests; the SQLite file is new.
        if (self::driver() !== 'sqlite') {
            Schema::dropAllTables();
        }

        parent::defineDatabaseMigrations();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (['', '-wal', '-shm'] as $suffix) {
            if ($this->database !== '' && is_file($this->database.$suffix)) {
                unlink($this->database.$suffix);
            }
        }
    }

    /** @return array<string, mixed> */
    private function connection(): array
    {
        if (self::driver() === 'sqlite') {
            $this->database = (string) tempnam(sys_get_temp_dir(), 'sync-engine-');

            return [
                'driver' => 'sqlite',
                'database' => $this->database,
                'prefix' => '',
                'foreign_key_constraints' => false,
                'journal_mode' => 'wal',
                'busy_timeout' => 5000,
            ];
        }

        $database = getenv('DB_DATABASE') ?: 'sync_engine';
        $token = getenv('TEST_TOKEN');
        if ($token !== false) {
            $database .= '_test_'.(int) $token;
            $driver = self::driver();
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306');
            $admin = new PDO(
                "{$driver}:host={$host};port={$port};dbname=".(getenv('DB_DATABASE') ?: 'sync_engine'),
                getenv('DB_USERNAME') ?: 'root',
                getenv('DB_PASSWORD') ?: '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            if ($driver === 'pgsql') {
                $exists = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
                $exists->execute([$database]);
                if (! $exists->fetchColumn()) {
                    $admin->exec('CREATE DATABASE "'.str_replace('"', '""', $database).'"');
                }
            } else {
                $admin->exec('CREATE DATABASE IF NOT EXISTS `'.str_replace('`', '``', $database).'`');
            }
        }

        return [
            'driver' => self::driver(),
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: null,
            'database' => $database,
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => self::driver() === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => self::driver() === 'pgsql' ? null : 'utf8mb4_unicode_ci',
            'prefix' => '',
            'search_path' => 'public',
        ];
    }
}
