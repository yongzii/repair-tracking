# Deployment Runbook (Production)

This guide covers the minimum steps to publish the app with production settings, a real platform owner account, and reliable background jobs.

## 1) Environment File

Start from `.env.production.example`, not the local `.env.example`.

Required production differences:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
DB_CONNECTION=mysql
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
MAIL_MAILER=smtp
REPAIR_SEED_DEMO_DATA=false
```

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
php artisan key:generate --force
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

## 3) Notifications

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
