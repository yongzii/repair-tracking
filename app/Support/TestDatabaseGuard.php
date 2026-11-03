<?php

namespace App\Support;

use RuntimeException;

final class TestDatabaseGuard
{
    public static function assertEnvironment(array $environment): void
    {
        foreach ([
            'APP_ENV' => 'mysql-testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'repair_tracking_test',
        ] as $key => $expected) {
            if (($environment[$key] ?? null) !== $expected) {
                throw new RuntimeException("Refusing MySQL test database use: {$key} must match the dedicated test configuration.");
            }
        }

        foreach (['DB_URL', 'DB_SOCKET'] as $key) {
            if (!self::isEmpty($environment[$key] ?? null)) {
                throw new RuntimeException("Refusing MySQL test database use: {$key} must be empty.");
            }
        }
    }

    public static function assertConfiguration(array $configuration): void
    {
        $connection = $configuration['mysql'] ?? [];

        if (($configuration['app_env'] ?? null) !== 'mysql-testing') {
            throw new RuntimeException('Refusing MySQL test database use: effective APP_ENV must be mysql-testing.');
        }

        if (($configuration['default_connection'] ?? null) !== 'mysql') {
            throw new RuntimeException('Refusing MySQL test database use: effective default connection must be mysql.');
        }

        if (($connection['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('Refusing MySQL test database use: effective MySQL driver must be mysql.');
        }

        if (($connection['database'] ?? null) !== 'repair_tracking_test') {
            throw new RuntimeException('Refusing MySQL test database use: effective database must be repair_tracking_test.');
        }

        if (($connection['host'] ?? null) !== '127.0.0.1') {
            throw new RuntimeException('Refusing MySQL test database use: effective host must be 127.0.0.1.');
        }

        if ((string) ($connection['port'] ?? '') !== '3306') {
            throw new RuntimeException('Refusing MySQL test database use: effective port must be 3306.');
        }

        if (!self::isEmpty($connection['url'] ?? null)) {
            throw new RuntimeException('Refusing MySQL test database use: the MySQL connection URL must be empty.');
        }

        if (!self::isEmpty($connection['unix_socket'] ?? null)) {
            throw new RuntimeException('Refusing MySQL test database use: the MySQL connection socket must be empty.');
        }

        foreach (['read', 'write'] as $override) {
            if (!empty($connection[$override])) {
                throw new RuntimeException("Refusing MySQL test database use: unexpected MySQL {$override} connection override is configured.");
            }
        }
    }

    public static function assertLiveIdentity(?array $identity): void
    {
        if (
            ($identity['driver'] ?? null) !== 'mysql'
            || ($identity['database_name'] ?? null) !== 'repair_tracking_test'
            || (string) ($identity['server_port'] ?? '') !== '3306'
        ) {
            throw new RuntimeException('Refusing MySQL test database use: live database identity is not approved.');
        }
    }

    public static function assertSqliteTestEnvironment(array $environment): void
    {
        if (!self::isEmpty($environment['DB_URL'] ?? null)) {
            throw new RuntimeException('Refusing SQLite tests: DB_URL must be empty.');
        }
    }

    public static function assertSqliteTestConfiguration(array $configuration): void
    {
        $connection = $configuration['sqlite'] ?? [];

        if (($configuration['app_env'] ?? null) !== 'testing') {
            throw new RuntimeException('Refusing SQLite tests: effective APP_ENV must be testing.');
        }

        if (($configuration['default_connection'] ?? null) !== 'sqlite') {
            throw new RuntimeException('Refusing SQLite tests: effective default connection must be sqlite.');
        }

        if (($connection['database'] ?? null) !== ':memory:') {
            throw new RuntimeException('Refusing SQLite tests: effective database must be :memory:.');
        }

        if (!self::isEmpty($connection['url'] ?? null)) {
            throw new RuntimeException('Refusing SQLite tests: the SQLite connection URL must be empty.');
        }

        foreach (['read', 'write'] as $override) {
            if (!empty($connection[$override])) {
                throw new RuntimeException("Refusing SQLite tests: unexpected SQLite {$override} connection override is configured.");
            }
        }
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === false || $value === '';
    }
}
