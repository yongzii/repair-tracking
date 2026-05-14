# Deployment Runbook (Production)

This guide covers the minimum steps to run notifications and queues reliably in production.

## 1) Environment Variables

Ensure these are set in `.env`:

```
QUEUE_CONNECTION=database
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

## 2) Queue Worker (Required)

Run the worker for the notifications queue:

```
php artisan queue:work --queue=notifications,default --tries=5 --backoff=60
```

## 3) Supervisor (Linux example)

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

## 4) Logs

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

## 5) Health Check

Trigger a status update and confirm:
- Email delivered
- SMS delivered
- No errors in `storage/logs/laravel.log`
