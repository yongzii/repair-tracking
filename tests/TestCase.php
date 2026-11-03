<?php

namespace Tests;

use App\Support\TestDatabaseGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $environment = $this->testEnvironment();

        if (($environment['APP_ENV'] ?? null) === 'mysql-testing') {
            TestDatabaseGuard::assertEnvironment($environment);
        } elseif (($environment['APP_ENV'] ?? null) !== 'testing') {
            throw new \RuntimeException('Refusing to run tests outside the isolated testing environments.');
        } else {
            TestDatabaseGuard::assertSqliteTestEnvironment($environment);
        }

        $this->app = $this->createApplication();
        $config = [
            'app_env' => config('app.env'),
            'default_connection' => config('database.default'),
            'mysql' => config('database.connections.mysql', []),
            'sqlite' => config('database.connections.sqlite', []),
        ];

        if (($environment['APP_ENV'] ?? null) === 'mysql-testing') {
            TestDatabaseGuard::assertConfiguration($config);
        } else {
            TestDatabaseGuard::assertSqliteTestConfiguration($config);
        }

        parent::setUp();

        if (($environment['APP_ENV'] ?? null) === 'mysql-testing') {
            $nonInnoDbTables = DB::select(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ? AND ENGINE <> ?',
                ['BASE TABLE', 'InnoDB']
            );
            if ($nonInnoDbTables !== []) {
                throw new \RuntimeException('Refusing MySQL integration tests because repair_tracking_test contains non-InnoDB tables.');
            }
        }
    }

    private function testEnvironment(): array
    {
        $environment = [];
        foreach (['APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_URL', 'DB_SOCKET'] as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
            $environment[$key] = $value === false ? null : $value;
        }

        return $environment;
    }
}
