<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ProductionReadinessCommandTest extends TestCase
{
    public function test_production_check_fails_for_local_debug_demo_configuration(): void
    {
        Config::set('app.debug', true);
        Config::set('app.key', '');
        Config::set('app.url', 'http://localhost');
        Config::set('database.default', 'sqlite');
        Config::set('queue.default', 'sync');
        Config::set('session.driver', 'array');
        Config::set('session.encrypt', false);
        Config::set('session.secure', false);
        Config::set('mail.default', 'log');
        Config::set('mail.from.address', 'hello@example.com');
        Config::set('repair.platform_admin.email', 'platform@example.com');
        Config::set('repair.platform_admin.password', 'password');
        Config::set('repair.seed_demo_data', true);
        Config::set('repair.sms_required', true);
        Config::set('services.twilio.sid', null);
        Config::set('services.twilio.token', null);
        Config::set('services.twilio.from', null);

        $exitCode = Artisan::call('repair:production-check --allow-local');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Production readiness check failed.', $output);
        $this->assertStringContainsString('APP_DEBUG must be false.', $output);
        $this->assertStringContainsString('REPAIR_SEED_DEMO_DATA must be false in production.', $output);
    }

    public function test_production_check_passes_for_publish_ready_configuration(): void
    {
        Config::set('app.env', 'production');
        Config::set('app.debug', false);
        Config::set('app.key', 'base64:' . str_repeat('a', 43) . '=');
        Config::set('app.url', 'https://repairs.example.test');
        Config::set('database.default', 'mysql');
        Config::set('queue.default', 'database');
        Config::set('session.driver', 'database');
        Config::set('session.encrypt', true);
        Config::set('session.secure', true);
        Config::set('mail.default', 'smtp');
        Config::set('mail.from.address', 'no-reply@repairs.example.test');
        Config::set('repair.admin_login_hint', '');
        Config::set('repair.platform_admin.email', 'owner@repairs.example.test');
        Config::set('repair.platform_admin.password', 'very-long-random-production-password');
        Config::set('repair.seed_demo_data', false);
        Config::set('repair.sms_required', true);
        Config::set('services.twilio.sid', 'AC123456789');
        Config::set('services.twilio.token', 'token');
        Config::set('services.twilio.from', '+15551234567');
        Config::set('filesystems.default', 'local');

        $exitCode = Artisan::call('repair:production-check --allow-local');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Production readiness check passed.', $output);
    }
}
