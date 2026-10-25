<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshMySqlTestDatabase extends Command
{
    protected $signature = 'tests:mysql:refresh';

    protected $description = 'Safely recreate and migrate the dedicated local MySQL test database.';

    public function handle(): int
    {
        if (
            app()->environment() !== 'mysql-testing'
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'repair_tracking_test'
            || config('database.connections.mysql.host') !== '127.0.0.1'
            || (string) config('database.connections.mysql.port') !== '3306'
            || config('database.connections.mysql.url')
        ) {
            $this->components->error(
                'Refusing to refresh a database unless APP_ENV=mysql-testing and the local MySQL database is exactly repair_tracking_test.'
            );

            return self::FAILURE;
        }

        $this->components->info('Refreshing only the dedicated repair_tracking_test database.');
        config(['database.connections.mysql.engine' => 'InnoDB']);
        DB::purge('mysql');

        $status = $this->call('migrate:fresh', [
            '--database' => 'mysql',
            '--force' => true,
        ]);

        if ($status !== self::SUCCESS) {
            return $status;
        }

        $nonInnoDbTables = DB::select(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ? AND ENGINE <> ?',
            ['BASE TABLE', 'InnoDB']
        );

        if ($nonInnoDbTables !== []) {
            $this->components->error(
                'The dedicated MySQL test schema contains non-InnoDB tables; row-lock concurrency tests are unsafe.'
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
