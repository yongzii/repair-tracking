<?php

use App\Jobs\SendCustomerNotification;
use App\Models\CustomerNotification;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('repair:production-check {--allow-local : Do not fail only because APP_ENV is not production}', function () {
    $failures = [];
    $warnings = [];

    $fail = function (string $message) use (&$failures): void {
        $failures[] = $message;
    };

    $warn = function (string $message) use (&$warnings): void {
        $warnings[] = $message;
    };

    $isBlank = fn ($value): bool => trim((string) $value) === '';
    $isPlaceholder = fn ($value): bool => Str::contains((string) $value, ['example.com', 'your-domain.com', 'change-this', 'replace-with']);

    if (!app()->environment('production') && !$this->option('allow-local')) {
        $fail('APP_ENV must be production for a publish deployment.');
    }

    if (config('app.debug')) {
        $fail('APP_DEBUG must be false.');
    }

    if ($isBlank(config('app.key'))) {
        $fail('APP_KEY is missing. Run php artisan key:generate --force on the server.');
    }

    $appUrl = (string) config('app.url');
    if (!Str::startsWith($appUrl, 'https://') || $isPlaceholder($appUrl) || Str::contains($appUrl, ['localhost', '127.0.0.1'])) {
        $fail('APP_URL must be the real HTTPS production domain.');
    }

    if (config('database.default') === 'sqlite') {
        $fail('DB_CONNECTION should use a production database such as mysql or pgsql, not sqlite.');
    }

    if (in_array(config('queue.default'), ['sync', 'null'], true)) {
        $fail('QUEUE_CONNECTION must use a real queue backend such as database, redis, or sqs.');
    }

    if (in_array(config('session.driver'), ['array', 'cookie', 'file'], true)) {
        $fail('SESSION_DRIVER should be database or redis for production.');
    }

    if (!config('session.encrypt')) {
        $fail('SESSION_ENCRYPT must be true.');
    }

    if (!config('session.secure')) {
        $fail('SESSION_SECURE_COOKIE must be true.');
    }

    if (in_array(config('mail.default'), ['log', 'array'], true)) {
        $fail('MAIL_MAILER must use a real delivery provider, not log or array.');
    }

    if ($isBlank(config('mail.from.address')) || $isPlaceholder(config('mail.from.address'))) {
        $fail('MAIL_FROM_ADDRESS must be a real sender address.');
    }

    $platformEmail = config('repair.platform_admin.email');
    $platformPassword = config('repair.platform_admin.password');
    if ($platformEmail === 'platform@example.com' || $platformPassword === 'password' || $isPlaceholder($platformEmail) || $isPlaceholder($platformPassword)) {
        $fail('PLATFORM_ADMIN_EMAIL and PLATFORM_ADMIN_PASSWORD must be real production credentials.');
    }

    if (filter_var(config('repair.seed_demo_data'), FILTER_VALIDATE_BOOL)) {
        $fail('REPAIR_SEED_DEMO_DATA must be false in production.');
    }

    if (config('repair.admin_login_hint')) {
        $warn('ADMIN_LOGIN_HINT is visible on the login page. Leave it empty in production.');
    }

    if (config('repair.sms_required')) {
        foreach (['sid', 'token', 'from'] as $key) {
            if ($isBlank(config("services.twilio.{$key}"))) {
                $fail('Twilio credentials are required because REPAIR_SMS_REQUIRED is true.');
                break;
            }
        }
    } elseif ($isBlank(config('services.twilio.sid')) || $isBlank(config('services.twilio.token')) || $isBlank(config('services.twilio.from'))) {
        $warn('Twilio credentials are incomplete, so SMS delivery will not work.');
    }

    if (config('filesystems.default') === 'public' && !is_link(public_path('storage')) && !is_dir(public_path('storage'))) {
        $warn('Public storage link is missing. Run php artisan storage:link.');
    }

    if ($failures) {
        $this->error('Production readiness check failed.');
        foreach ($failures as $message) {
            $this->line("  [fail] {$message}");
        }
    } else {
        $this->info('Production readiness check passed.');
    }

    foreach ($warnings as $message) {
        $this->warn("  [warn] {$message}");
    }

    return $failures ? self::FAILURE : self::SUCCESS;
})->purpose('Check whether the app configuration is safe enough for production publishing');

Artisan::command('platform:subscription-reminders', function () {
    $reminders = [
        7 => [
            'trigger' => 'platform_subscription:expires_in_7_days',
            'label' => '7 days',
        ],
        3 => [
            'trigger' => 'platform_subscription:expires_in_3_days',
            'label' => '3 days',
        ],
        0 => [
            'trigger' => 'platform_subscription:expires_today',
            'label' => 'today',
        ],
    ];

    $queued = 0;

    foreach ($reminders as $daysUntilExpiry => $reminder) {
        $targetDate = today()->addDays($daysUntilExpiry);

        Shop::query()
            ->where('is_active', true)
            ->whereNotNull('trial_ends_at')
            ->whereDate('trial_ends_at', $targetDate)
            ->with(['users' => fn ($query) => $query
                ->whereIn('role', User::adminRoles())
                ->where('is_active', true)
                ->whereNotNull('email')
                ->orderByDesc('role')])
            ->chunkById(100, function ($shops) use ($reminder, $daysUntilExpiry, &$queued) {
                foreach ($shops as $shop) {
                    $admin = $shop->users->first();

                    if (!$admin || !filter_var($admin->email, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }

                    if (CustomerNotification::query()
                        ->where('shop_id', $shop->id)
                        ->where('recipient', $admin->email)
                        ->where('trigger', $reminder['trigger'])
                        ->exists()) {
                        continue;
                    }

                    $accessDate = $shop->trial_ends_at?->format('M d, Y');
                    $subject = $daysUntilExpiry === 0
                        ? "Subscription access expires today: {$shop->name}"
                        : "Subscription access expires in {$reminder['label']}: {$shop->name}";
                    $message = $daysUntilExpiry === 0
                        ? "Hello {$admin->name}, your {$shop->name} access expires today ({$accessDate}). After expiry, your shop will enter close-out mode: existing job orders can still be completed, but new intake will be locked until access is renewed."
                        : "Hello {$admin->name}, your {$shop->name} access will expire on {$accessDate}. Please renew before then to keep new job order intake available.";

                    $notification = CustomerNotification::create([
                        'shop_id' => $shop->id,
                        'job_order_id' => null,
                        'channel' => CustomerNotification::CHANNEL_EMAIL,
                        'recipient' => $admin->email,
                        'subject' => $subject,
                        'message' => $message,
                        'status' => CustomerNotification::STATUS_QUEUED,
                        'trigger' => $reminder['trigger'],
                        'queued_at' => now(),
                    ]);

                    SendCustomerNotification::dispatch(
                        $admin->email,
                        null,
                        $subject,
                        $message,
                        $notification->id
                    );

                    $queued++;
                }
            });
    }

    $this->info("Queued {$queued} subscription reminder notification(s).");

    return self::SUCCESS;
})->purpose('Queue subscription expiry reminder emails for shop admins');

Schedule::command('platform:subscription-reminders')->dailyAt('08:00');
