# Deployment Runbook (Production)

This guide covers production configuration. The generic Linux and self-managed background-process instructions below are not a Render Free deployment recipe. For the Docker web service, follow the Render section first and observe its limitations.

## Render Free Docker Web Service (Capstone Demonstration)

The repository Dockerfile builds the Vue assets with Node.js 22, installs production PHP dependencies on PHP 8.3 with Apache, and serves Laravel from `public/`. Apache binds to `0.0.0.0` on Render's runtime `PORT` (defaulting to 10000 if it is not supplied). Configure the Render health-check path as `/up`. The image defaults to `APP_ENV=production`, `APP_DEBUG=false`, and `LOG_CHANNEL=stderr` so application logs go to the container log stream. The image does not run Laravel's development server.

The Docker build excludes local environment files, certificates, dependencies, storage contents, local databases, and test artifacts. It does not bake production credentials or a TiDB CA certificate into the image. Configure the production environment in Render, preserve the same `APP_KEY` across deployments, and provide a verified TiDB CA certificate at the path set by `MYSQL_ATTR_SSL_CA`. Confirm that the chosen Render secret-file provisioning method makes the file readable at that path; if it cannot, do not deploy until a safe provisioning method is established. Keep `DB_URL` and `DB_SOCKET` empty, and confirm the effective endpoint, port 4000, and `repairsync_cloud` database before any operator-initiated database change.

The image prepares writable Laravel runtime directories and creates the `public/storage` symlink for public shop logos. This does not make uploads durable. Repair photos, consultation photos, subscription proofs, and logos use local storage in the current application; Render Free's filesystem is ephemeral. Use external object storage for durable files. The current S3 configuration does not by itself install the optional Flysystem S3 adapter, so object-storage support needs separate dependency/configuration work and verification.

Do not run migrations or seeders during Docker build or web-service startup. Treat production migrations and seeding as separate, explicit operations after verifying the Render database environment and intended target.

Render Free does not provide a Free background-worker service or automatic Laravel scheduling. Customer email notification jobs use the database queue and may remain pending without a separate running worker. The daily `platform:subscription-reminders` task will not run automatically without an external scheduler. Do not embed either process in the web-server container as a purported reliable solution. SMTP ports 25, 465, and 587 are blocked on Render Free; use of an HTTPS email API would avoid that port restriction, but it does not solve the missing queue worker.

This deployment is for a capstone demonstration only. It does not provide durable uploaded files or reliable queued notifications/reminders on Render Free.

## 1) Environment File

Start from `.env.production.example`, not the local `.env.example`.

Required production differences:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
DB_CONNECTION=mysql
DB_HOST=your-tidb-cloud-endpoint
DB_PORT=4000
DB_DATABASE=repairsync_cloud
DB_USERNAME=configure-through-hosting-secrets
DB_PASSWORD=configure-through-hosting-secrets
DB_URL=
DB_SOCKET=
MYSQL_ATTR_SSL_CA=/path/provided/by-host/tidb-ca.pem
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
MAIL_MAILER=smtp
REPAIR_SEED_DEMO_DATA=false
```

These database values are examples, not deployable credentials or a real endpoint. Replace `DB_HOST` with the production TiDB endpoint and supply the database username and password using the hosting provider's secret manager. Set `DB_DATABASE` to `repairsync_cloud`; do not use the local or schema-rehearsal database names. Keep `DB_URL` empty because a nonempty URL takes precedence over the individual MySQL host, port, database, username, and password values. Do not configure a database URL through the host environment either.

The default MySQL connection reads `MYSQL_ATTR_SSL_CA` and enables `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT`. Before the first production migration, obtain the CA certificate from the TiDB provider's official source and verify it independently. Have the hosting platform mount/provision it at the configured path (the path above is only a placeholder), outside the public web root. Ensure the PHP runtime can read the file and its parent directories with least-privilege permissions; do not make it writable by the web process unless the hosting platform requires that. Do not copy the existing untracked workspace PEM into production or commit any CA file unless it has been verified as the correct provider certificate and its inclusion is explicitly approved. The certificate contents are not application credentials, but an incorrect or substituted CA undermines server-certificate verification.

Before the first production migration, configure the production environment variables and persistent `APP_KEY` through the hosting provider, replace every placeholder, confirm the effective database endpoint and `repairsync_cloud` target in the provider configuration, and verify the CA file is readable. Keep `DB_URL` and `DB_SOCKET` empty. Do not proceed with migration if any target or TLS setting is uncertain. Preserve the same `APP_KEY` across deployments; do not regenerate it on an existing production installation.

Before production seeding, set a real platform owner:

```
PLATFORM_ADMIN_EMAIL=owner@your-domain.com
PLATFORM_ADMIN_PASSWORD=replace-with-a-long-random-password
```

The production seeder refuses the default `platform@example.com / password` pair.

## 2) Install and Build

```
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan db:seed --class=PlatformAdminSeeder --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Use full `php artisan db:seed --force` only when `REPAIR_SEED_DEMO_DATA=false` is already set.

### Repair photo storage migration

New repair photos are stored on Laravel's private local disk. Existing files under the old public path must be copied manually after taking a backup and before removing the old copies. The database `photo_path` values remain relative to `repair-photos/...`.

Linux/macOS:

```bash
mkdir -p storage/app/private/repair-photos
cp -a storage/app/public/repair-photos/. storage/app/private/repair-photos/
find storage/app/private/repair-photos -type f | wc -l
```

Windows PowerShell:

```powershell
New-Item -ItemType Directory -Force storage/app/private/repair-photos
Copy-Item storage/app/public/repair-photos/* storage/app/private/repair-photos -Recurse -Force
Get-ChildItem storage/app/private/repair-photos -Recurse -File | Measure-Object
```

Verify the private copy and application photo access before deleting the old public directory. Only after verification, remove `storage/app/public/repair-photos` from the server. Do not remove the global `public/storage` link because shop logos and other public assets still use it.

## 3) Notifications (Generic SMTP — NOT APPLICABLE to Render Free)

**These SMTP settings are not applicable to Render Free.** Render Free blocks outbound SMTP ports 25, 465, and 587, including the port 587 example below. Do not treat these values as a working Render email configuration. The project does not have a verified Render-compatible email API configuration documented here, and email notifications are not confirmed operational on Render Free.

```
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=...
MAIL_FROM_NAME="Repair Service Management System"

TWILIO_SID=...
TWILIO_TOKEN=...
TWILIO_FROM=+1XXXXXXXXXX
TWILIO_DEFAULT_COUNTRY=+63
```

## 4) Queue Worker (Required)

Run the worker for the notifications queue:

```
php artisan queue:work --queue=notifications,default --tries=5 --backoff=60
```

## 5) Supervisor (Linux example)

Create `/etc/supervisor/conf.d/repair-system-queue.conf`:

```
[program:repair-system-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/repair-system/artisan queue:work --queue=notifications,default --tries=5 --backoff=60
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/repair-system/queue.log
stopwaitsecs=3600
```

Then:
```
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start repair-system-queue:*
```

## 6) Logs

Laravel log file:
```
storage/logs/laravel.log
```

Failed jobs:
```
php artisan queue:failed
```

Retry:
```
php artisan queue:retry all
```

## 7) Health Check

After deployment:

```
php artisan about
php artisan queue:failed
```

Then sign in with the platform owner account, create or verify one shop workspace, trigger a job-order status update, and confirm:

- Platform owner can access `/platform`
- Shop owner can access `/admin`
- Inactive shops cannot sign in
- Inactive shop public tracking and booking links return not found
- Email delivered
- SMS delivered
- No errors in `storage/logs/laravel.log`
