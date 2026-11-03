# MySQL concurrency tests

RepairSync keeps the explicitly invoked concurrency-test database separate from the default PHPUnit database:

- The base Laravel database configuration defaults to SQLite. A local `.env` may override that selection, so check the effective environment instead of assuming which database the application uses.
- `php artisan test` uses SQLite `:memory:` as configured in `phpunit.xml`.
- The explicitly invoked concurrency suite uses local MySQL database `repair_tracking_test`.
The guarded refresh command configures Laravel's MySQL schema grammar to create that test schema with InnoDB tables and checks table engines after migration. The shared test bootstrap independently checks existing table engines during test setup, after Laravel's `parent::setUp()` has run.

The production environment example selects MySQL database `repair_tracking`. Neither that example nor this test guide proves which database any running deployment or developer environment currently uses.

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

`tests:mysql:refresh` is destructive to the selected test database. It validates the effective environment and connection settings, checks the live database name and port on the write connection, disables automatic reconnects, and drops tables through that same validated Laravel connection before running migrations through the same named connection. The migrations resolve to that cached connection in the current project; automatic reconnection is blocked, so a lost connection fails the operation rather than silently opening a replacement. The shared test bootstrap validates connection settings before `parent::setUp()`, then independently checks table engines after `parent::setUp()` and before each test method proceeds. The configured loopback endpoint and database name/port do not independently authenticate the MySQL server; a local proxy or port-forward could route the connection to another server. Manually verify the endpoint and database before running the refresh.

MySQL DDL is not reliably transactional. If table deletion or migration fails, the schema can be left partially dropped or partially migrated; the command stops and does not restore prior objects. Inspect the dedicated test database manually and re-verify its target before attempting another refresh. MySQL tests do not automatically refresh or seed the database: run the guarded refresh command explicitly first.

The MySQL suite lives under `tests/MySql`, outside the default PHPUnit test directories. It uses separate PHP processes and independent MySQL connections. Each test holds the target row lock in the parent, waits until both workers have issued their target `SELECT ... FOR UPDATE` statements, then releases the lock. Fixtures are synthetic, uniquely named test records and are removed by the tests. Worker launch failures include captured command, exit code, stdout, stderr, and result details.

The normal `php artisan test` command continues to use the unchanged `phpunit.xml` SQLite `:memory:` configuration and never selects the MySQL suite.
