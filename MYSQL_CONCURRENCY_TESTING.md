# MySQL concurrency tests

RepairSync keeps its normal application database separate from both test databases:

- The local application uses MySQL database `repair_tracking`.
- `php artisan test` uses SQLite `:memory:` as configured in `phpunit.xml`.
- The explicitly invoked concurrency suite uses local MySQL database `repair_tracking_test`.
The guarded refresh command configures Laravel's MySQL schema grammar to create that test schema with InnoDB tables, then verifies every table engine before allowing the concurrency suite to proceed.

## Prepare the dedicated database

In WAMP/phpMyAdmin, create a new empty database named `repair_tracking_test` using `utf8mb4` (for example, collation `utf8mb4_unicode_ci`). Do not select or reuse `repair_tracking`. Prefer a MySQL account limited to this test database.

Copy `.env.mysql-testing.example` to `.env.mysql-testing`. Fill in `DB_USERNAME` and `DB_PASSWORD` locally, and set a local test-only `APP_KEY` if required by your environment. The real `.env.mysql-testing` file is ignored by Git; never commit credentials.

## Refresh and run

From PowerShell, explicitly select the dedicated environment for both commands:

```powershell
$env:APP_ENV = 'mysql-testing'
php artisan tests:mysql:refresh
if ($LASTEXITCODE -ne 0) { throw 'Dedicated MySQL test database refresh was refused or failed.' }
vendor\bin\phpunit --configuration=phpunit.mysql.xml
$env:APP_ENV = $null
```

`tests:mysql:refresh` applies the existing Laravel migrations with `migrate:fresh`; it is destructive to the selected test database. It refuses to run unless the effective environment, connection, host, port, database name, and absence of a connection URL all match the dedicated local test configuration. The MySQL PHPUnit configuration pins the same connection values and the shared test bootstrap checks them before test setup, including that all test tables use InnoDB. MySQL tests do not automatically refresh or seed the database: run the guarded refresh command explicitly first.

The MySQL suite lives under `tests/MySql`, outside the default PHPUnit test directories. It uses separate PHP processes and independent MySQL connections. Each test holds the target row lock in the parent, waits until both workers have issued their target `SELECT ... FOR UPDATE` statements, then releases the lock. Fixtures are synthetic, uniquely named test records and are removed by the tests. Worker launch failures include captured command, exit code, stdout, stderr, and result details.

The normal `php artisan test` command continues to use the unchanged `phpunit.xml` SQLite `:memory:` configuration and never selects the MySQL suite.
