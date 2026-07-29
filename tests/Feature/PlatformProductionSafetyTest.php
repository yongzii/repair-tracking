<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\JobOrder;
use App\Models\PlatformSubscriptionPayment;
use App\Models\Shop;
use App\Models\SubscriptionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformProductionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_platform_admin_can_access_platform_dashboard(): void
    {
        $shopAdmin = $this->createUser(User::ROLE_SHOP_ADMIN, $this->createShop('Shop Admin Test'));

        $this->actingAs($shopAdmin)
            ->getJson('/api/platform/dashboard')
            ->assertForbidden();

        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);

        $this->actingAs($platformAdmin)
            ->getJson('/api/platform/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'summary' => [
                    'total_shops',
                    'active_shops',
                    'inactive_shops',
                    'portal_users',
                ],
                'recent_shops',
            ]);
    }

    public function test_platform_admin_cannot_access_shop_admin_modules(): void
    {
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);

        $this->actingAs($platformAdmin)
            ->getJson('/api/admin/dashboard')
            ->assertForbidden();
    }

    public function test_shop_admin_can_still_access_shop_admin_modules(): void
    {
        $shop = $this->createShop('Shop Dashboard Test');
        $shop->update(['trial_ends_at' => now()->addDays(30)]);
        $shopAdmin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);

        $this->actingAs($shopAdmin)
            ->getJson('/api/admin/dashboard')
            ->assertOk();
    }

    public function test_public_shop_launch_allows_preview_but_blocks_transactions_until_subscription(): void
    {
        $response = $this->postJson('/api/onboarding/register-shop', [
            'shop_name' => 'Pending Payment Repair',
            'shop_slug' => 'pending-payment-repair',
            'shop_email' => 'pending-shop@example.com',
            'shop_phone' => '09123456789',
            'shop_address' => 'Main Street',
            'owner_name' => 'Pending Owner',
            'owner_email' => 'pending-owner@example.com',
            'owner_password' => 'password123',
            'owner_password_confirmation' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('message', 'Shop workspace created successfully. You can sign in to explore preview mode, but subscription payment is required before saving records or transactions.');

        $shop = Shop::firstWhere('slug', 'pending-payment-repair');

        $this->assertNotNull($shop);
        $this->assertNull($shop->trial_ends_at);
        $this->assertSame('pending-payment-repair', $response->json('shop.slug'));
        $this->assertSame('pending-owner@example.com', $response->json('user.email'));
        $this->assertFalse($response->json('user.shop.has_active_access'));

        $owner = User::firstWhere('email', 'pending-owner@example.com');
        $this->assertAuthenticatedAs($owner);

        $this->actingAs($owner)
            ->getJson('/api/admin/dashboard')
            ->assertOk();

        $this->actingAs($owner)
            ->postJson('/api/admin/job-orders', [
                'customer_name' => 'Preview Customer',
                'customer_phone' => '09123456789',
                'device_type' => 'Laptop',
                'problem_description' => 'Preview only.',
            ])
            ->assertPaymentRequired()
            ->assertJsonPath('message', 'Subscription required. Preview mode lets you explore the system, but saving records and transactions are locked until payment is activated.');

        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);

        $this->actingAs($platformAdmin)
            ->postJson("/api/platform/shops/{$shop->id}/subscription-payments", [
                'amount' => 1500,
                'payment_method' => 'CASH',
                'covered_from' => now()->toDateString(),
                'covered_until' => now()->addMonth()->toDateString(),
                'received_at' => now()->toDateString(),
            ])
            ->assertCreated();

        $this->postJson('/api/auth/login', [
            'email' => 'pending-owner@example.com',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('user.shop.has_active_access', true);
    }

    public function test_platform_shop_updates_are_audited(): void
    {
        $shop = $this->createShop('Audit Target Shop');
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);

        $this->actingAs($platformAdmin)
            ->putJson("/api/platform/shops/{$shop->id}", [
                'subscription_plan' => 'growth',
                'trial_ends_at' => now()->addDays(30)->toDateString(),
                'is_active' => false,
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $platformAdmin->id,
            'user_role' => User::ROLE_PLATFORM_ADMIN,
            'action' => 'platform.shop.updated',
            'module' => 'platform',
            'record_type' => 'Shop',
            'record_id' => $shop->id,
        ]);

        $auditLog = AuditLog::firstWhere('action', 'platform.shop.updated');

        $this->assertSame('starter', $auditLog->old_values['subscription_plan']);
        $this->assertSame('growth', $auditLog->new_values['subscription_plan']);
        $this->assertFalse($auditLog->new_values['is_active']);
    }

    public function test_platform_shop_toggle_is_audited(): void
    {
        $shop = $this->createShop('Toggle Target Shop');
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);

        $this->actingAs($platformAdmin)
            ->postJson("/api/platform/shops/{$shop->id}/toggle", [
                'is_active' => false,
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $platformAdmin->id,
            'action' => 'platform.shop.deactivated',
            'module' => 'platform',
            'record_type' => 'Shop',
            'record_id' => $shop->id,
        ]);
    }

    public function test_platform_dashboard_includes_trial_alert_counts(): void
    {
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);
        $this->createShop('Expiring Trial Shop')->update(['trial_ends_at' => now()->addDays(3)]);
        $this->createShop('Expired Trial Shop')->update(['trial_ends_at' => now()->subDay()]);

        $this->actingAs($platformAdmin)
            ->getJson('/api/platform/dashboard')
            ->assertOk()
            ->assertJsonPath('summary.trial_expiring_soon', 1)
            ->assertJsonPath('summary.expired_trials', 1);
    }

    public function test_platform_dashboard_includes_billing_summary(): void
    {
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);
        $shopWithNoAccessDate = $this->createShop('No Access Date Shop');
        $paidShop = $this->createShop('Monthly Paid Shop');

        PlatformSubscriptionPayment::create([
            'shop_id' => $paidShop->id,
            'amount' => 2500,
            'payment_method' => 'CASH',
            'covered_from' => now()->startOfMonth(),
            'covered_until' => now()->endOfMonth(),
            'received_at' => now(),
            'received_by' => 'Platform Admin',
        ]);

        $expectedNoAccessDateShops = Shop::query()
            ->where('is_active', true)
            ->whereNull('trial_ends_at')
            ->count();

        $this->actingAs($platformAdmin)
            ->getJson('/api/platform/dashboard')
            ->assertOk()
            ->assertJsonPath('summary.no_access_date_shops', $expectedNoAccessDateShops)
            ->assertJsonPath('summary.platform_payments_this_month', 1)
            ->assertJsonPath('summary.platform_collected_this_month', 2500);

        $this->assertNull($shopWithNoAccessDate->trial_ends_at);
    }

    public function test_platform_shop_list_exposes_and_filters_access_status(): void
    {
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);
        $this->createShop('Healthy Access Shop')->update(['trial_ends_at' => now()->addDays(30)]);
        $expiredShop = $this->createShop('Expired Access Shop');
        $expiredShop->update(['trial_ends_at' => now()->subDay()]);
        $this->createShop('Needs Setup Shop');
        $this->createShop('Inactive Access Shop', false);
        $expectedNeedsSetupShops = Shop::query()
            ->where('is_active', true)
            ->whereNull('trial_ends_at')
            ->count();

        $this->actingAs($platformAdmin)
            ->getJson('/api/platform/shops?access_status=close_out')
            ->assertOk()
            ->assertJsonPath('shops.total', 1)
            ->assertJsonPath('shops.data.0.name', 'Expired Access Shop')
            ->assertJsonPath('shops.data.0.access_status', 'close_out')
            ->assertJsonPath('shops.data.0.access_status_label', 'Close-out')
            ->assertJsonPath('shops.data.0.can_accept_new_work', false)
            ->assertJsonPath('shops.data.0.is_close_out_mode', true);

        $this->actingAs($platformAdmin)
            ->getJson('/api/platform/shops?access_status=needs_setup')
            ->assertOk()
            ->assertJsonPath('shops.total', $expectedNeedsSetupShops)
            ->assertJsonPath('shops.data.0.name', 'Needs Setup Shop')
            ->assertJsonPath('shops.data.0.access_status', 'needs_setup')
            ->assertJsonPath('shops.data.0.access_status_label', 'Needs Setup')
            ->assertJsonPath('shops.data.0.can_accept_new_work', false);
    }

    public function test_platform_admin_can_reset_shop_admin_password(): void
    {
        $shop = $this->createShop('Password Reset Shop');
        $shopAdmin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);

        $response = $this->actingAs($platformAdmin)
            ->postJson("/api/platform/shops/{$shop->id}/reset-admin-password")
            ->assertOk()
            ->assertJsonPath('message', 'Shop admin password reset successfully.')
            ->assertJsonPath('admin.email', $shopAdmin->email);

        $this->assertNotEmpty($response->json('temporary_password'));
        $this->assertTrue($shopAdmin->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $platformAdmin->id,
            'action' => 'platform.shop_admin.password_reset',
            'module' => 'platform',
            'record_type' => 'User',
            'record_id' => $shopAdmin->id,
        ]);
    }

    public function test_platform_admin_can_record_subscription_payment(): void
    {
        $shop = $this->createShop('Billing Target Shop', false);
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);
        $coveredUntil = now()->endOfMonth();

        $this->actingAs($platformAdmin)
            ->postJson("/api/platform/shops/{$shop->id}/subscription-payments", [
                'amount' => 1500,
                'payment_method' => 'GCASH',
                'reference_number' => 'GC-123',
                'covered_from' => now()->startOfMonth()->toDateString(),
                'covered_until' => $coveredUntil->toDateString(),
                'notes' => 'Monthly subscription.',
                'received_at' => now()->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Subscription payment recorded.')
            ->assertJsonPath('payment.amount', 1500)
            ->assertJsonPath('payment.reference_number', 'GC-123')
            ->assertJsonPath('shop.is_active', true)
            ->assertJsonPath('shop.trial_ends_at_formatted', $coveredUntil->format('M d, Y'))
            ->assertJsonPath('shop.subscription_payments.0.reference_number', 'GC-123');

        $this->assertTrue($shop->fresh()->trial_ends_at->isSameDay($coveredUntil));

        $this->assertDatabaseHas('platform_subscription_payments', [
            'shop_id' => $shop->id,
            'payment_method' => 'GCASH',
            'reference_number' => 'GC-123',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $platformAdmin->id,
            'action' => 'platform.subscription_payment.recorded',
            'module' => 'platform_billing',
            'record_type' => 'PlatformSubscriptionPayment',
        ]);
    }

    public function test_subscription_request_payment_proof_can_be_approved_to_activate_access(): void
    {
        Storage::fake('public');

        $shop = $this->createShop('Proof Payment Shop');
        $shop->update(['trial_ends_at' => null]);
        $shopAdmin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);

        $this->actingAs($platformAdmin)
            ->putJson('/api/platform/subscription-settings', [
                'instructions' => 'Pay and upload proof.',
                'plans' => [
                    ['key' => 'monthly', 'name' => 'Monthly', 'amount' => 1500, 'duration_months' => 1],
                ],
                'payment_methods' => [
                    ['key' => 'gcash', 'name' => 'GCash', 'account_name' => 'Platform Admin', 'account_number' => '09999999999', 'instructions' => 'Send to GCash.'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('settings.payment_methods.0.account_number', '09999999999');

        $this->actingAs($shopAdmin)
            ->getJson('/api/admin/subscription/options')
            ->assertOk()
            ->assertJsonPath('settings.plans.0.name', 'Monthly');

        $this->actingAs($shopAdmin)
            ->post('/api/admin/subscription/requests', [
                'plan_key' => 'monthly',
                'payment_method_key' => 'gcash',
                'reference_number' => 'GCASH-123',
                'proof' => UploadedFile::fake()->image('proof.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('subscription_request.status', SubscriptionRequest::STATUS_PENDING)
            ->assertJsonPath('subscription_request.reference_number', 'GCASH-123');

        $subscriptionRequest = SubscriptionRequest::firstWhere('reference_number', 'GCASH-123');
        $this->assertNotNull($subscriptionRequest);
        Storage::disk('public')->assertExists($subscriptionRequest->proof_path);

        $this->actingAs($platformAdmin)
            ->postJson("/api/platform/subscription-requests/{$subscriptionRequest->id}/approve", [
                'admin_notes' => 'Verified payment.',
            ])
            ->assertOk()
            ->assertJsonPath('subscription_request.status', SubscriptionRequest::STATUS_APPROVED);

        $this->assertTrue($shop->fresh()->hasActiveAccess());
        $this->assertDatabaseHas('platform_subscription_payments', [
            'shop_id' => $shop->id,
            'payment_method' => 'GCash',
            'reference_number' => 'GCASH-123',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $platformAdmin->id,
            'action' => 'platform.subscription_request.approved',
            'module' => 'platform_billing',
        ]);
    }

    public function test_inactive_shop_tracking_pages_are_not_publicly_readable(): void
    {
        $shop = $this->createShop('Inactive Tracking Shop', false);
        $jobOrder = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TRACK-INACTIVE-001',
            'customer_name' => 'Customer Name',
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'problem_description' => 'No power.',
            'current_status' => 'RECEIVED',
        ]);

        $this->getJson("/api/shops/{$shop->slug}/track/{$jobOrder->job_order_number}")
            ->assertNotFound();

        $this->getJson('/api/track?' . http_build_query([
            'shop_slug' => $shop->slug,
            'job_order_number' => $jobOrder->job_order_number,
        ]))->assertNotFound();

        $this->getJson("/api/track/{$jobOrder->job_order_number}")
            ->assertNotFound();

        $this->getJson('/api/track?' . http_build_query([
            'job_order_number' => $jobOrder->job_order_number,
        ]))->assertNotFound();
    }

    private function createShop(string $name, bool $isActive = true): Shop
    {
        return Shop::create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'subscription_plan' => 'starter',
            'is_active' => $isActive,
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
