<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        if ($this->environmentValue('APP_ENV') === 'mysql-testing') {
            $this->assertEnvironmentValue('DB_CONNECTION', 'mysql');
            $this->assertEnvironmentValue('DB_HOST', '127.0.0.1');
            $this->assertEnvironmentValue('DB_PORT', '3306');
            $this->assertEnvironmentValue('DB_DATABASE', 'repair_tracking_test');
            $this->assertEnvironmentValue('DB_URL', '');
        } elseif ($this->environmentValue('APP_ENV') !== 'testing') {
            throw new \RuntimeException('Refusing to run tests outside the isolated testing environments.');
        }

        parent::setUp();

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if (config('app.env') === 'mysql-testing') {
            if (
                $connection !== 'mysql'
                || $database !== 'repair_tracking_test'
                || config('database.connections.mysql.host') !== '127.0.0.1'
                || (string) config('database.connections.mysql.port') !== '3306'
                || config('database.connections.mysql.url')
            ) {
                throw new \RuntimeException('Refusing MySQL integration tests unless they use the dedicated local repair_tracking_test database.');
            }

            $nonInnoDbTables = \Illuminate\Support\Facades\DB::select(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ? AND ENGINE <> ?',
                ['BASE TABLE', 'InnoDB']
            );
            if ($nonInnoDbTables !== []) {
                throw new \RuntimeException('Refusing MySQL integration tests because repair_tracking_test contains non-InnoDB tables.');
            }

            return;
        }

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException(sprintf(
                'Refusing to run tests against [%s/%s]. Tests must use sqlite :memory: to protect local data.',
                $connection,
                $database
            ));
        }
    }

    private function environmentValue(string $key): string|false
    {
        return $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
    }

    private function assertEnvironmentValue(string $key, string $expected): void
    {
        if ($this->environmentValue($key) !== $expected) {
            throw new \RuntimeException("Refusing MySQL integration tests: {$key} must be explicitly set to the dedicated test configuration.");
        }
    }
}
