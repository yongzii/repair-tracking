<?php

namespace Tests\Feature;

use App\Models\CustomerNotification;
use App\Models\Shop;
use App\Models\SubscriptionRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTimezoneSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_subscription_request_response_formats_submitted_and_reviewed_instants_in_manila(): void
    {
        [$subscriptionRequest] = $this->createSubscriptionRequest(SubscriptionRequest::STATUS_APPROVED);

        $this->actingAs($this->createUser(User::ROLE_PLATFORM_ADMIN))
            ->getJson('/api/platform/subscription-requests?status=all')
            ->assertOk()
            ->assertJsonStructure([
                'subscription_requests' => [
                    'data' => [[
                        'created_at_formatted',
                        'reviewed_at_formatted',
                    ]],
                ],
            ])
            ->assertJsonPath('subscription_requests.data.0.created_at_formatted', 'Oct 30, 2026 11:50 PM')
            ->assertJsonPath('subscription_requests.data.0.reviewed_at_formatted', 'Oct 31, 2026 12:00 AM');

        $this->assertSame(
            '2026-10-30T15:50:33+00:00',
            $subscriptionRequest->fresh()->created_at->toIso8601String()
        );
        $this->assertSame(
            '2026-10-30T16:00:00+00:00',
            $subscriptionRequest->fresh()->reviewed_at->toIso8601String()
        );
    }

    public function test_shop_admin_subscription_status_response_keeps_compatible_fields_and_formats_nullable_review_time(): void
    {
        [$subscriptionRequest, $shopAdmin] = $this->createSubscriptionRequest(SubscriptionRequest::STATUS_PENDING);

        $this->actingAs($shopAdmin)
            ->getJson('/api/admin/subscription/request')
            ->assertOk()
            ->assertJsonStructure([
                'latest_request' => [
                    'id',
                    'plan_name',
                    'amount',
                    'payment_method_name',
                    'reference_number',
                    'proof_url',
                    'status',
                    'created_at_formatted',
                    'reviewed_at_formatted',
                ],
            ])
            ->assertJsonPath('latest_request.id', $subscriptionRequest->id)
            ->assertJsonPath('latest_request.created_at_formatted', 'Oct 30, 2026 11:50 PM')
            ->assertJsonPath('latest_request.reviewed_at_formatted', null);
    }

    public function test_platform_subscription_reminder_event_times_are_formatted_in_manila(): void
    {
        $shop = $this->createShop();
        $notification = CustomerNotification::create([
            'shop_id' => $shop->id,
            'channel' => CustomerNotification::CHANNEL_EMAIL,
            'recipient' => 'example@example.test',
            'subject' => 'Subscription reminder',
            'message' => 'Reminder message',
            'status' => CustomerNotification::STATUS_SENT,
            'trigger' => 'platform_subscription:expires_in_7_days',
            'queued_at' => CarbonImmutable::parse('2026-10-30T15:50:33+00:00'),
            'sent_at' => CarbonImmutable::parse('2026-10-30T16:00:00+00:00'),
        ]);

        $response = $this->actingAs($this->createUser(User::ROLE_PLATFORM_ADMIN))
            ->getJson('/api/platform/dashboard')
            ->assertOk()
            ->assertJsonPath('recent_reminders.0.id', $notification->id)
            ->assertJsonPath('recent_reminders.0.queued_at_formatted', 'Oct 30, 2026 11:50 PM')
            ->assertJsonPath('recent_reminders.0.sent_at_formatted', 'Oct 31, 2026 12:00 AM')
            ->assertJsonPath('recent_reminders.0.failed_at_formatted', null);

        $this->assertSame(
            '2026-10-30T15:50:33+00:00',
            $notification->fresh()->queued_at->toIso8601String()
        );
        $this->assertSame(
            '2026-10-30T16:00:00+00:00',
            $notification->fresh()->sent_at->toIso8601String()
        );
    }

    private function createSubscriptionRequest(string $status): array
    {
        $shop = $this->createShop();
        $shopAdmin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);
        $reviewer = $status === SubscriptionRequest::STATUS_PENDING
            ? null
            : $this->createUser(User::ROLE_PLATFORM_ADMIN);
        $subscriptionRequest = SubscriptionRequest::create([
            'shop_id' => $shop->id,
            'requested_by_id' => $shopAdmin->id,
            'plan_key' => 'monthly',
            'plan_name' => 'Monthly',
            'amount' => 1500,
            'duration_months' => 1,
            'payment_method_key' => 'gcash',
            'payment_method_name' => 'GCash',
            'status' => $status,
            'reviewed_by_id' => $reviewer?->id,
            'reviewed_at' => $status === SubscriptionRequest::STATUS_PENDING
                ? null
                : CarbonImmutable::parse('2026-10-30T16:00:00+00:00'),
        ]);
        $subscriptionRequest->forceFill([
            'created_at' => CarbonImmutable::parse('2026-10-30T15:50:33+00:00'),
        ])->save();

        return [$subscriptionRequest->fresh(), $shopAdmin];
    }

    private function createShop(): Shop
    {
        return Shop::create([
            'name' => 'Timezone Subscription Shop',
            'slug' => 'timezone-subscription-shop-' . str()->random(8),
            'subscription_plan' => 'starter',
            'is_active' => true,
        ]);
    }

    private function createUser(string $role, ?Shop $shop = null): User
    {
        return User::factory()->create([
            'shop_id' => $shop?->id,
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
