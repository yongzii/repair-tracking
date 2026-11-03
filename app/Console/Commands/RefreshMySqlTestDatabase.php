<?php

namespace App\Console\Commands;

use App\Support\TestDatabaseGuard;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

class RefreshMySqlTestDatabase extends Command
{
    protected $signature = 'tests:mysql:refresh';

    protected $description = 'Safely recreate and migrate the dedicated local MySQL test database.';

    public function handle(): int
    {
        try {
            TestDatabaseGuard::assertEnvironment($this->testEnvironment());
            config(['database.connections.mysql.engine' => 'InnoDB']);
            TestDatabaseGuard::assertConfiguration([
                'app_env' => app()->environment(),
                'default_connection' => config('database.default'),
                'mysql' => config('database.connections.mysql', []),
            ]);
        } catch (Throwable) {
            $this->components->error('Refusing to refresh: test environment or effective MySQL configuration is unsafe.');
            return self::FAILURE;
        }

        try {
            DB::purge('mysql');
            $connection = DB::connection('mysql');
            $approvedConfiguration = config('database.connections.mysql');
        } catch (Throwable) {
            $this->components->error('Refusing to refresh: the approved MySQL connection could not be established.');
            return self::FAILURE;
        }

        try {
            $targetIsApproved = $this->configurationIsUnchanged($approvedConfiguration)
                && DB::connection('mysql') === $connection
                && $this->hasApprovedDatabaseIdentity($connection);
        } catch (Throwable) {
            $targetIsApproved = false;
        }

        if (!$targetIsApproved) {
            $this->components->error('Refusing to refresh: the target MySQL connection failed validation.');
            return self::FAILURE;
        }

        $validatedPdo = $connection->getRawPdo();
        if (!$validatedPdo instanceof PDO) {
            $this->components->error('Refusing to refresh: the validated MySQL connection is unavailable.');
            return self::FAILURE;
        }

        $connection->setReconnector(static function (Connection $connection): void {
            throw new \RuntimeException('MySQL test refresh cannot reconnect after identity validation.');
        });

        $this->components->info('Verified the live MySQL target is repair_tracking_test on port 3306.');
        $this->components->info('Dropping tables only through the validated MySQL connection.');

        try {
            if (
                !$this->configurationIsUnchanged($approvedConfiguration)
                || DB::connection('mysql') !== $connection
                || !$this->hasApprovedDatabaseIdentity($connection)
                || $connection->getRawPdo() !== $validatedPdo
            ) {
                $this->components->error('Refusing to wipe: the validated MySQL connection identity changed.');
                return self::FAILURE;
            }

            $connection->getSchemaBuilder()->dropAllTables();
        } catch (Throwable) {
            $this->components->error('The schema wipe failed on the validated MySQL connection; refusing to continue.');
            return self::FAILURE;
        }

        try {
            $identityIsUnchanged = DB::connection('mysql') === $connection
                && $this->configurationIsUnchanged($approvedConfiguration)
                && $this->hasApprovedDatabaseIdentity($connection)
                && $connection->getRawPdo() === $validatedPdo;
        } catch (Throwable) {
            $identityIsUnchanged = false;
        }

        if (!$identityIsUnchanged) {
            $this->components->error('Refusing to migrate: the validated MySQL connection identity could not be reverified.');
            return self::FAILURE;
        }

        try {
            $status = $this->call('migrate', [
                '--database' => 'mysql',
                '--force' => true,
            ]);
        } catch (Throwable) {
            $this->components->error('Migrations failed on the validated MySQL connection.');
            return self::FAILURE;
        }

        if ($status !== self::SUCCESS) {
            return $status;
        }

        try {
            $nonInnoDbTables = $connection->select(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ? AND ENGINE <> ?',
                ['BASE TABLE', 'InnoDB'],
                false
            );
        } catch (Throwable) {
            $this->components->error('Could not verify table engines on the validated MySQL connection.');
            return self::FAILURE;
        }

        if ($nonInnoDbTables !== []) {
            $this->components->error(
                'The dedicated MySQL test schema contains non-InnoDB tables; row-lock concurrency tests are unsafe.'
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function configurationIsUnchanged(array $approvedConfiguration): bool
    {
        try {
            TestDatabaseGuard::assertEnvironment($this->testEnvironment());
            TestDatabaseGuard::assertConfiguration([
                'app_env' => app()->environment(),
                'default_connection' => config('database.default'),
                'mysql' => config('database.connections.mysql', []),
            ]);
        } catch (Throwable) {
            return false;
        }

        return config('database.connections.mysql') === $approvedConfiguration;
    }

    private function hasApprovedDatabaseIdentity(Connection $connection): bool
    {
        if ($connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'repair_tracking_test') {
            return false;
        }

        try {
            $identity = $connection->selectOne(
                'SELECT DATABASE() AS database_name, @@port AS server_port',
                [],
                false
            );
            TestDatabaseGuard::assertLiveIdentity($identity === null ? null : [
                'driver' => $connection->getDriverName(),
                'database_name' => $identity->database_name,
                'server_port' => $identity->server_port,
            ]);
        } catch (Throwable) {
            return false;
        }

        return true;
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
