<?php

namespace Tests\Unit;

use App\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TestDatabaseGuardTest extends TestCase
{
    public function test_guards_accept_the_dedicated_test_configuration(): void
    {
        TestDatabaseGuard::assertEnvironment([
            'APP_ENV' => 'mysql-testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'repair_tracking_test',
            'DB_URL' => '',
            'DB_SOCKET' => '',
        ]);

        TestDatabaseGuard::assertConfiguration([
            'app_env' => 'mysql-testing',
            'default_connection' => 'mysql',
            'mysql' => [
                'driver' => 'mysql',
                'database' => 'repair_tracking_test',
                'host' => '127.0.0.1',
                'port' => '3306',
                'url' => null,
                'unix_socket' => '',
            ],
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_environment_guard_rejects_unsafe_values(): void
    {
        $valid = [
            'APP_ENV' => 'mysql-testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'repair_tracking_test',
            'DB_URL' => '',
            'DB_SOCKET' => '',
        ];

        foreach ([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_HOST' => 'localhost',
            'DB_PORT' => '3307',
            'DB_DATABASE' => 'repair_tracking',
            'DB_URL' => 'mysql://unexpected',
            'DB_SOCKET' => 'unexpected.sock',
        ] as $key => $unsafeValue) {
            $environment = $valid;
            $environment[$key] = $unsafeValue;
            $rejected = false;

            try {
                TestDatabaseGuard::assertEnvironment($environment);
            } catch (RuntimeException) {
                $rejected = true;
            }

            $this->assertTrue($rejected, "The environment guard must reject an unsafe {$key} value.");
        }
    }

    public function test_configuration_guard_rejects_unsafe_effective_values(): void
    {
        $valid = [
            'app_env' => 'mysql-testing',
            'default_connection' => 'mysql',
            'mysql' => [
                'driver' => 'mysql',
                'database' => 'repair_tracking_test',
                'host' => '127.0.0.1',
                'port' => '3306',
                'url' => null,
                'unix_socket' => '',
            ],
        ];

        $invalidConfigurations = [
            ['driver' => 'pgsql'],
            ['database' => 'repair_tracking'],
            ['host' => 'localhost'],
            ['port' => '3307'],
            ['url' => 'mysql://unexpected'],
            ['unix_socket' => 'unexpected.sock'],
            ['read' => ['host' => 'unexpected']],
            ['write' => ['host' => 'unexpected']],
        ];

        foreach ($invalidConfigurations as $overrides) {
            $configuration = $valid;
            $configuration['mysql'] = array_merge($configuration['mysql'], $overrides);
            $rejected = false;

            try {
                TestDatabaseGuard::assertConfiguration($configuration);
            } catch (RuntimeException) {
                $rejected = true;
            }

            $this->assertTrue($rejected, 'The configuration guard must reject an unsafe effective MySQL configuration.');
        }

        $missingDriverConfiguration = $valid;
        unset($missingDriverConfiguration['mysql']['driver']);
        try {
            TestDatabaseGuard::assertConfiguration($missingDriverConfiguration);
            $this->fail('The configuration guard must reject a missing MySQL driver.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_configuration_guard_rejects_wrong_environment_or_default_connection(): void
    {
        $valid = [
            'app_env' => 'mysql-testing',
            'default_connection' => 'mysql',
            'mysql' => [
                'driver' => 'mysql',
                'database' => 'repair_tracking_test',
                'host' => '127.0.0.1',
                'port' => '3306',
                'url' => null,
                'unix_socket' => '',
            ],
        ];

        foreach ([
            ['app_env' => 'testing'],
            ['default_connection' => 'sqlite'],
        ] as $overrides) {
            $configuration = array_merge($valid, $overrides);
            $rejected = false;

            try {
                TestDatabaseGuard::assertConfiguration($configuration);
            } catch (RuntimeException) {
                $rejected = true;
            }

            $this->assertTrue($rejected, 'The configuration guard must reject a wrong app environment or default connection.');
        }
    }

    public function test_live_identity_guard_accepts_only_the_approved_mysql_target(): void
    {
        TestDatabaseGuard::assertLiveIdentity([
            'driver' => 'mysql',
            'database_name' => 'repair_tracking_test',
            'server_port' => '3306',
        ]);
        $this->addToAssertionCount(1);

        foreach ([
            null,
            [],
            ['driver' => 'mysql', 'database_name' => 'repair_tracking_test'],
            ['driver' => 'pgsql', 'database_name' => 'repair_tracking_test', 'server_port' => '3306'],
            ['driver' => 'mysql', 'database_name' => 'repair_tracking', 'server_port' => '3306'],
            ['driver' => 'mysql', 'database_name' => 'repair_tracking_test', 'server_port' => '3307'],
        ] as $identity) {
            try {
                TestDatabaseGuard::assertLiveIdentity($identity);
                $this->fail('The live identity guard must reject an unapproved or unverifiable target.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_sqlite_test_guards_reject_connection_urls_and_overrides(): void
    {
        $environmentRejected = false;
        try {
            TestDatabaseGuard::assertSqliteTestEnvironment(['DB_URL' => 'mysql://unexpected']);
        } catch (RuntimeException) {
            $environmentRejected = true;
        }
        $this->assertTrue($environmentRejected, 'The SQLite test environment guard must reject DB_URL.');

        $valid = [
            'app_env' => 'testing',
            'default_connection' => 'sqlite',
            'sqlite' => [
                'database' => ':memory:',
                'url' => null,
            ],
        ];

        foreach ([
            ['url' => 'mysql://unexpected'],
            ['read' => ['database' => 'repair_tracking']],
            ['write' => ['database' => 'repair_tracking']],
            ['database' => 'repair_tracking'],
        ] as $overrides) {
            $configuration = $valid;
            $configuration['sqlite'] = array_merge($configuration['sqlite'], $overrides);
            $rejected = false;

            try {
                TestDatabaseGuard::assertSqliteTestConfiguration($configuration);
            } catch (RuntimeException) {
                $rejected = true;
            }

            $this->assertTrue($rejected, 'The SQLite test configuration guard must reject an unsafe connection override.');
        }
    }
}
