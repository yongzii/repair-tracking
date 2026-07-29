<?php

namespace Tests\Feature;

use App\Jobs\SendCustomerNotification;
use App\Models\CustomerNotification;
use App\Models\JobOrder;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustomerNotificationHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_status_update_creates_queued_notification_records_for_customer_contacts(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $shop->update([
            'notification_settings' => [
                'email_enabled' => true,
                'sms_enabled' => true,
                'events' => ['WAITING_PARTS' => true],
            ],
        ]);
        $jobOrder = $this->createJobOrder($shop, [
            'customer_email' => 'customer@example.com',
            'customer_phone' => '09123456789',
        ]);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-status", [
                'status' => 'WAITING_PARTS',
                'note' => 'Replacement screen requested.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('customer_notifications', [
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'channel' => CustomerNotification::CHANNEL_EMAIL,
            'recipient' => 'customer@example.com',
            'status' => CustomerNotification::STATUS_QUEUED,
            'trigger' => 'status:WAITING_PARTS',
        ]);

        $this->assertDatabaseHas('customer_notifications', [
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'channel' => CustomerNotification::CHANNEL_SMS,
            'recipient' => '09123456789',
            'status' => CustomerNotification::STATUS_QUEUED,
            'trigger' => 'status:WAITING_PARTS',
        ]);

        Queue::assertPushed(SendCustomerNotification::class, 2);
    }

    public function test_shop_notification_settings_can_disable_sms_channel(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $shop->update([
            'notification_settings' => [
                'email_enabled' => true,
                'sms_enabled' => false,
                'events' => ['WAITING_PARTS' => true],
            ],
        ]);
        $jobOrder = $this->createJobOrder($shop, [
            'customer_email' => 'customer@example.com',
            'customer_phone' => '09123456789',
        ]);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-status", [
                'status' => 'WAITING_PARTS',
                'note' => 'Replacement screen requested.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('customer_notifications', [
            'job_order_id' => $jobOrder->id,
            'channel' => CustomerNotification::CHANNEL_EMAIL,
        ]);

        $this->assertDatabaseMissing('customer_notifications', [
            'job_order_id' => $jobOrder->id,
            'channel' => CustomerNotification::CHANNEL_SMS,
        ]);

        Queue::assertPushed(SendCustomerNotification::class, 1);
    }

    public function test_shop_notification_settings_can_disable_a_status_event(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $shop->update([
            'notification_settings' => [
                'email_enabled' => true,
                'sms_enabled' => true,
                'events' => ['WAITING_PARTS' => false],
            ],
        ]);
        $jobOrder = $this->createJobOrder($shop, [
            'customer_email' => 'customer@example.com',
            'customer_phone' => '09123456789',
        ]);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-status", [
                'status' => 'WAITING_PARTS',
                'note' => 'Replacement screen requested.',
            ])
            ->assertOk();

        $this->assertDatabaseCount('customer_notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_job_order_details_include_notification_history(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop);

        CustomerNotification::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'channel' => CustomerNotification::CHANNEL_EMAIL,
            'recipient' => 'customer@example.com',
            'subject' => 'Repair update',
            'message' => 'Your repair is ready for pickup.',
            'status' => CustomerNotification::STATUS_SENT,
            'trigger' => 'status:READY_FOR_PICKUP',
            'queued_at' => now()->subMinute(),
            'sent_at' => now(),
        ]);

        $this->actingAs($staff)
            ->getJson("/api/admin/job-orders/{$jobOrder->id}")
            ->assertOk()
            ->assertJsonPath('notifications.0.channel', CustomerNotification::CHANNEL_EMAIL)
            ->assertJsonPath('notifications.0.status', CustomerNotification::STATUS_SENT)
            ->assertJsonPath('notifications.0.status_label', 'SENT')
            ->assertJsonPath('notifications.0.can_retry', false)
            ->assertJsonPath('notifications.0.message_preview', 'Your repair is ready for pickup.');
    }

    public function test_staff_can_retry_failed_customer_notification(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop);
        $notification = CustomerNotification::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'channel' => CustomerNotification::CHANNEL_EMAIL,
            'recipient' => 'customer@example.com',
            'subject' => 'Repair update',
            'message' => 'Your repair is ready for pickup.',
            'status' => CustomerNotification::STATUS_FAILED,
            'trigger' => 'status:READY_FOR_PICKUP',
            'queued_at' => now()->subMinutes(10),
            'failed_at' => now()->subMinute(),
            'error_message' => 'SMTP connection failed.',
        ]);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/notifications/{$notification->id}/retry")
            ->assertOk()
            ->assertJsonPath('message', 'Notification queued for retry.')
            ->assertJsonPath('notification.status', CustomerNotification::STATUS_QUEUED)
            ->assertJsonPath('notification.can_retry', false);

        $notification->refresh();

        $this->assertSame(CustomerNotification::STATUS_QUEUED, $notification->status);
        $this->assertNull($notification->failed_at);
        $this->assertNull($notification->error_message);
        Queue::assertPushed(SendCustomerNotification::class, 1);
    }

    public function test_sent_customer_notification_cannot_be_retried(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop);
        $notification = CustomerNotification::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'channel' => CustomerNotification::CHANNEL_EMAIL,
            'recipient' => 'customer@example.com',
            'subject' => 'Repair update',
            'message' => 'Your repair is ready for pickup.',
            'status' => CustomerNotification::STATUS_SENT,
            'trigger' => 'status:READY_FOR_PICKUP',
            'queued_at' => now()->subMinutes(10),
            'sent_at' => now()->subMinute(),
        ]);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/notifications/{$notification->id}/retry")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('notification');

        Queue::assertNothingPushed();
    }

    private function createShopUser(string $role): array
    {
        $shop = Shop::create([
            'name' => 'Notification Test Shop ' . $role,
            'slug' => 'notification-test-shop-' . $role,
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => $role,
            'is_active' => true,
        ]);

        return [$shop, $user];
    }

    private function createJobOrder(Shop $shop, array $overrides = []): JobOrder
    {
        return JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-NOTIFY-' . $shop->id,
            'customer_name' => 'Customer Name',
            'customer_email' => null,
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'problem_description' => 'No display.',
            'current_status' => 'DIAGNOSING',
            ...$overrides,
        ]);
    }
}
