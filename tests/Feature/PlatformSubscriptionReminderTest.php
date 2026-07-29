<?php

namespace Tests\Feature;

use App\Jobs\SendCustomerNotification;
use App\Models\CustomerNotification;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlatformSubscriptionReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_subscription_reminder_command_queues_expiring_shop_admin_email(): void
    {
        $shop = $this->createShop('Expiring Reminder Shop', now()->addDays(7));
        $admin = $this->createShopAdmin($shop, 'owner@example.test');

        $exitCode = Artisan::call('platform:subscription-reminders');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('customer_notifications', [
            'shop_id' => $shop->id,
            'job_order_id' => null,
            'channel' => CustomerNotification::CHANNEL_EMAIL,
            'recipient' => $admin->email,
            'status' => CustomerNotification::STATUS_QUEUED,
            'trigger' => 'platform_subscription:expires_in_7_days',
        ]);
        Queue::assertPushed(SendCustomerNotification::class, 1);
    }

    public function test_subscription_reminder_command_does_not_duplicate_same_reminder(): void
    {
        $shop = $this->createShop('Duplicate Reminder Shop', now()->addDays(3));
        $admin = $this->createShopAdmin($shop, 'duplicate@example.test');

        Artisan::call('platform:subscription-reminders');
        Artisan::call('platform:subscription-reminders');

        $this->assertDatabaseCount('customer_notifications', 1);
        $this->assertDatabaseHas('customer_notifications', [
            'shop_id' => $shop->id,
            'recipient' => $admin->email,
            'trigger' => 'platform_subscription:expires_in_3_days',
        ]);
        Queue::assertPushed(SendCustomerNotification::class, 1);
    }

    public function test_subscription_reminder_command_ignores_needs_setup_and_inactive_shops(): void
    {
        $needsSetup = Shop::create([
            'name' => 'Needs Setup Reminder Shop',
            'slug' => 'needs-setup-reminder-shop',
            'is_active' => true,
        ]);
        $inactive = $this->createShop('Inactive Reminder Shop', now()->addDays(7), false);
        $this->createShopAdmin($needsSetup, 'setup@example.test');
        $this->createShopAdmin($inactive, 'inactive@example.test');

        Artisan::call('platform:subscription-reminders');

        $this->assertDatabaseCount('customer_notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_platform_dashboard_includes_recent_subscription_reminders(): void
    {
        $platformAdmin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'is_active' => true,
        ]);
        $shop = $this->createShop('History Reminder Shop', now()->addDays(7));
        $admin = $this->createShopAdmin($shop, 'history@example.test');
        CustomerNotification::create([
            'shop_id' => $shop->id,
            'job_order_id' => null,
            'channel' => CustomerNotification::CHANNEL_EMAIL,
            'recipient' => $admin->email,
            'subject' => 'Subscription access expires in 7 days',
            'message' => 'Please renew before expiry.',
            'status' => CustomerNotification::STATUS_QUEUED,
            'trigger' => 'platform_subscription:expires_in_7_days',
            'queued_at' => now(),
        ]);

        $this->actingAs($platformAdmin)
            ->getJson('/api/platform/dashboard')
            ->assertOk()
            ->assertJsonPath('recent_reminders.0.shop_name', 'History Reminder Shop')
            ->assertJsonPath('recent_reminders.0.recipient', 'history@example.test')
            ->assertJsonPath('recent_reminders.0.trigger_label', 'Expires in 7 days')
            ->assertJsonPath('recent_reminders.0.status_label', 'QUEUED');
    }

    private function createShop(string $name, \DateTimeInterface $accessUntil, bool $active = true): Shop
    {
        return Shop::create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'trial_ends_at' => $accessUntil,
            'is_active' => $active,
        ]);
    }

    private function createShopAdmin(Shop $shop, string $email): User
    {
        return User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_SHOP_ADMIN,
            'email' => $email,
            'is_active' => true,
        ]);
    }
}
