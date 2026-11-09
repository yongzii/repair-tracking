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
use Illuminate\Support\Carbon;
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

    public function test_public_shop_launch_preview_and_free_trial_access_then_subscription_payment(): void
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
            ->assertJsonPath('message', 'Shop workspace created successfully. You can explore Preview Mode or manually activate the available 7-day free trial. Subscription payment is required for full access after the trial ends.')
            ->assertJsonPath('user.shop.free_trial_eligible', true)
            ->assertJsonPath('user.shop.free_trial_active', false)
            ->assertJsonPath('user.shop.has_active_paid_access', false);

        $shop = Shop::firstWhere('slug', 'pending-payment-repair');

        $this->assertNotNull($shop);
        $this->assertNull($shop->trial_ends_at);
        $this->assertNull($shop->description);
        $this->assertSame('pending-payment-repair', $response->json('shop.slug'));
        $this->assertSame('pending-owner@example.com', $response->json('user.email'));
        $this->assertFalse($response->json('user.shop.has_active_access'));

        $this->getJson('/api/shops/pending-payment-repair/profile')
            ->assertNotFound();

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

        $this->actingAs($owner)
            ->postJson('/api/shop/free-trial/activate')
            ->assertOk()
            ->assertJsonPath('trial.active', true)
            ->assertJsonPath('trial.eligible', false)
            ->assertJsonPath('trial.used', true);

        $this->getJson('/api/shops/pending-payment-repair/profile')
            ->assertOk()
            ->assertJsonPath('shop.slug', 'pending-payment-repair');

        $this->actingAs($owner)
            ->postJson('/api/admin/job-orders', [
                'customer_name' => 'Trial Customer',
                'customer_phone' => '09123456789',
                'device_type' => 'Laptop',
                'problem_description' => 'Operational work during the active free trial.',
            ])
            ->assertCreated();

        $this->postJson('/api/admin/inventory', [
            'name' => 'Trial Part',
            'stock_quantity' => 5,
            'reorder_level' => 1,
            'unit_cost' => 20,
        ])->assertCreated();

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

    public function test_platform_dashboard_compares_approved_requests_with_payment_records(): void
    {
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);
        $requestShop = $this->createShop('Approved Request Shop');
        $additionalPaymentShop = $this->createShop('Additional Payment Shop');
        $reviewedAt = now()->subMinutes(5);

        SubscriptionRequest::create([
            'shop_id' => $requestShop->id,
            'plan_key' => 'starter',
            'plan_name' => 'Monthly',
            'amount' => 1500,
            'duration_months' => 1,
            'payment_method_key' => 'gcash',
            'payment_method_name' => 'GCash',
            'reference_number' => 'REQ-123',
            'status' => SubscriptionRequest::STATUS_APPROVED,
            'reviewed_at' => $reviewedAt,
        ]);

        PlatformSubscriptionPayment::create([
            'shop_id' => $requestShop->id,
            'amount' => 1500,
            'payment_method' => 'GCASH',
            'reference_number' => 'REQ-123',
            'notes' => 'Approved subscription request.',
            'received_at' => $reviewedAt,
        ]);

        PlatformSubscriptionPayment::create([
            'shop_id' => $additionalPaymentShop->id,
            'amount' => 4500,
            'payment_method' => 'CASH',
            'received_at' => now(),
        ]);

        $this->actingAs($platformAdmin)
            ->getJson('/api/platform/dashboard')
            ->assertOk()
            ->assertJsonPath('summary.platform_collected_this_month', 6000)
            ->assertJsonPath('summary.platform_payments_this_month', 2)
            ->assertJsonPath('summary.platform_approved_requests_this_month', 1)
            ->assertJsonPath('summary.platform_approved_request_amount_this_month', 1500)
            ->assertJsonPath('summary.platform_additional_payment_records_this_month', 1)
            ->assertJsonPath('summary.platform_payment_difference_from_requests_this_month', 4500)
            ->assertJsonPath('platform_month_payments.total', 2)
            ->assertJsonPath('platform_month_payments.data.0.shop_name', $additionalPaymentShop->name)
            ->assertJsonPath('platform_month_payments.data.0.amount', 4500);
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

    public function test_subscription_approval_during_a_free_trial_starts_paid_coverage_immediately_and_preserves_history(): void
    {
        $now = Carbon::parse('2026-11-09 14:30:00');
        Carbon::setTestNow($now);

        try {
            $startedAt = $now->copy()->subHour();
            $trialEndsAt = $startedAt->copy()->addHours(168);
            $shop = $this->createShop('Trial Approval Shop');
            $shop->forceFill([
                'free_trial_eligible' => false,
                'free_trial_started_at' => $startedAt,
                'free_trial_ends_at' => $trialEndsAt,
                'free_trial_used_at' => $startedAt,
            ])->save();
            $request = $this->createPendingSubscriptionRequest($shop);
            $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);

            $this->actingAs($platformAdmin)
                ->postJson("/api/platform/subscription-requests/{$request->id}/approve")
                ->assertOk();

            $shop->refresh();
            $payment = PlatformSubscriptionPayment::where('shop_id', $shop->id)->sole();
            $this->assertSame($now->copy()->startOfDay()->toDateTimeString(), $payment->covered_from->toDateTimeString());
            $this->assertTrue($shop->trial_ends_at->isSameDay($now->copy()->addMonth()->subDay()));
            $this->assertTrue($shop->hasActivePaidAccess());
            $this->assertFalse($shop->accessState()['free_trial_active']);
            $this->assertFalse($shop->free_trial_eligible);
            $this->assertSame($startedAt->getTimestamp(), $shop->free_trial_started_at->getTimestamp());
            $this->assertSame($trialEndsAt->getTimestamp(), $shop->free_trial_ends_at->getTimestamp());
            $this->assertSame($startedAt->getTimestamp(), $shop->free_trial_used_at->getTimestamp());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_direct_subscription_approval_forfeits_unused_trial_without_creating_trial_history(): void
    {
        $shop = $this->createShop('Direct Approval Shop');
        $shop->free_trial_eligible = true;
        $shop->save();
        $shopAdmin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);
        $request = $this->createPendingSubscriptionRequest($shop);
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);

        $this->actingAs($platformAdmin)
            ->postJson("/api/platform/subscription-requests/{$request->id}/approve")
            ->assertOk();

        $shop->refresh();
        $this->assertFalse($shop->free_trial_eligible);
        $this->assertFalse($shop->hasUsedFreeTrial());
        $this->assertNull($shop->free_trial_started_at);
        $this->assertNull($shop->free_trial_ends_at);
        $this->assertNull($shop->free_trial_used_at);

        Carbon::setTestNow($shop->trial_ends_at->copy()->addSecond());
        try {
            $this->actingAs($shopAdmin)
                ->postJson('/api/shop/free-trial/activate')
                ->assertConflict()
                ->assertJsonPath('message', 'This shop is not eligible to activate a free trial.');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_subscription_approval_chains_after_existing_future_paid_coverage(): void
    {
        $existingPaidEnd = now()->addDays(10)->endOfDay();
        $shop = $this->createShop('Paid Renewal Approval Shop');
        $shop->trial_ends_at = $existingPaidEnd;
        $shop->save();
        $request = $this->createPendingSubscriptionRequest($shop);
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);

        $this->actingAs($platformAdmin)
            ->postJson("/api/platform/subscription-requests/{$request->id}/approve")
            ->assertOk();

        $payment = PlatformSubscriptionPayment::where('shop_id', $shop->id)->sole();
        $expectedStart = $existingPaidEnd->copy()->addDay()->startOfDay();
        $this->assertSame($expectedStart->toDateTimeString(), $payment->covered_from->toDateTimeString());
        $this->assertSame(
            $expectedStart->copy()->addMonth()->subDay()->endOfDay()->getTimestamp(),
            $shop->fresh()->trial_ends_at->getTimestamp()
        );
    }

    public function test_payment_that_extends_coverage_forfeits_unused_trial_eligibility(): void
    {
        $shop = $this->createShop('Payment Forfeiture Shop', false);
        $shop->free_trial_eligible = true;
        $shop->save();
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);

        $this->actingAs($platformAdmin)
            ->postJson("/api/platform/shops/{$shop->id}/subscription-payments", [
                'amount' => 1000,
                'covered_until' => now()->addMonth()->toDateString(),
            ])
            ->assertCreated();

        $this->assertFalse($shop->fresh()->free_trial_eligible);
        $this->assertNull($shop->fresh()->free_trial_started_at);
        $this->assertNull($shop->fresh()->free_trial_ends_at);
        $this->assertNull($shop->fresh()->free_trial_used_at);
    }

    public function test_payment_without_coverage_extension_does_not_forfeit_trial_eligibility(): void
    {
        $shop = $this->createShop('Non-extending Payment Shop');
        $shop->forceFill([
            'free_trial_eligible' => true,
            'trial_ends_at' => now()->addMonth(),
        ])->save();
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);

        $this->actingAs($platformAdmin)
            ->postJson("/api/platform/shops/{$shop->id}/subscription-payments", [
                'amount' => 1000,
                'covered_until' => now()->addDays(10)->toDateString(),
            ])
            ->assertCreated();

        $this->assertTrue($shop->fresh()->free_trial_eligible);

        $noEffectiveCoverageShop = $this->createShop('Past Coverage Payment Shop');
        $noEffectiveCoverageShop->free_trial_eligible = true;
        $noEffectiveCoverageShop->save();
        $this->actingAs($platformAdmin)
            ->postJson("/api/platform/shops/{$noEffectiveCoverageShop->id}/subscription-payments", [
                'amount' => 1000,
                'covered_until' => now()->subDay()->toDateString(),
            ])
            ->assertCreated();

        $this->assertFalse($noEffectiveCoverageShop->fresh()->hasActivePaidAccess());
        $this->assertTrue($noEffectiveCoverageShop->fresh()->free_trial_eligible);
    }

    public function test_repeated_payments_keep_furthest_paid_coverage_and_preserve_trial_history(): void
    {
        $startedAt = now()->subDay()->startOfSecond();
        $shop = $this->createShop('Repeated Payment Shop');
        $shop->forceFill([
            'free_trial_eligible' => false,
            'free_trial_started_at' => $startedAt,
            'free_trial_ends_at' => $startedAt->copy()->addHours(168),
            'free_trial_used_at' => $startedAt,
        ])->save();
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);
        $furthestCoverage = now()->addMonths(3)->toDateString();

        foreach ([$furthestCoverage, now()->addMonth()->toDateString()] as $coveredUntil) {
            $this->actingAs($platformAdmin)
                ->postJson("/api/platform/shops/{$shop->id}/subscription-payments", [
                    'amount' => 1000,
                    'covered_until' => $coveredUntil,
                ])
                ->assertCreated();
        }

        $shop->refresh();
        $this->assertSame(Carbon::parse($furthestCoverage)->endOfDay()->getTimestamp(), $shop->trial_ends_at->getTimestamp());
        $this->assertFalse($shop->free_trial_eligible);
        $this->assertSame($startedAt->getTimestamp(), $shop->free_trial_started_at->getTimestamp());
        $this->assertSame($startedAt->copy()->addHours(168)->getTimestamp(), $shop->free_trial_ends_at->getTimestamp());
        $this->assertSame($startedAt->getTimestamp(), $shop->free_trial_used_at->getTimestamp());
    }

    public function test_platform_shop_update_and_reactivation_only_forfeit_when_paid_access_is_effective(): void
    {
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);
        $eligibleShop = $this->createShop('Admin Update Forfeiture Shop');
        $eligibleShop->free_trial_eligible = true;
        $eligibleShop->save();

        $this->actingAs($platformAdmin)
            ->putJson("/api/platform/shops/{$eligibleShop->id}", [
                'subscription_plan' => 'monthly',
                'trial_ends_at' => now()->addMonth()->toISOString(),
                'is_active' => true,
            ])
            ->assertOk();
        $this->assertFalse($eligibleShop->fresh()->free_trial_eligible);

        $noAccessShop = $this->createShop('Admin Update No Access Shop');
        $noAccessShop->free_trial_eligible = true;
        $noAccessShop->save();
        $this->actingAs($platformAdmin)
            ->putJson("/api/platform/shops/{$noAccessShop->id}", [
                'subscription_plan' => 'monthly',
                'trial_ends_at' => now()->subDay()->toISOString(),
                'is_active' => true,
            ])
            ->assertOk();
        $this->assertTrue($noAccessShop->fresh()->free_trial_eligible);

        $reactivatedShop = $this->createShop('Admin Reactivation Paid Shop', false);
        $reactivatedShop->forceFill([
            'free_trial_eligible' => true,
            'trial_ends_at' => now()->addMonth(),
        ])->save();
        $this->actingAs($platformAdmin)
            ->postJson("/api/platform/shops/{$reactivatedShop->id}/toggle", ['is_active' => true])
            ->assertOk();
        $this->assertFalse($reactivatedShop->fresh()->free_trial_eligible);

        $inactiveWithoutCoverage = $this->createShop('Admin Reactivation Preview Shop', false);
        $inactiveWithoutCoverage->free_trial_eligible = true;
        $inactiveWithoutCoverage->save();
        $this->actingAs($platformAdmin)
            ->postJson("/api/platform/shops/{$inactiveWithoutCoverage->id}/toggle", ['is_active' => true])
            ->assertOk();
        $this->assertTrue($inactiveWithoutCoverage->fresh()->free_trial_eligible);
    }

    public function test_subscription_request_payment_proof_can_be_approved_to_activate_access(): void
    {
        Storage::fake('local');
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
        $this->assertSame(
            route('admin.subscription.requests.proof', ['subscriptionRequest' => $subscriptionRequest->id]),
            $subscriptionRequest->proofUrl()
        );
        Storage::disk('local')->assertExists($subscriptionRequest->proof_path);
        Storage::disk('public')->assertMissing($subscriptionRequest->proof_path);

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
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Shop code is required for tracking.');

        $this->getJson('/api/track?' . http_build_query([
            'job_order_number' => $jobOrder->job_order_number,
        ]))->assertUnprocessable()
            ->assertJsonPath('message', 'Shop code is required for tracking.');
    }

    public function test_public_tracking_requires_shop_code_and_matches_the_same_shop(): void
    {
        $shopA = $this->createShop('Tracking Shop A');
        $shopA->update([
            'address' => 'Shop A Address',
            'business_hours' => ['monday' => ['open' => true, 'open_time' => '08:00', 'close_time' => '17:00']],
        ]);
        $shopB = $this->createShop('Tracking Shop B');

        $jobA = JobOrder::create([
            'shop_id' => $shopA->id,
            'job_order_number' => 'TRACK-SHOP-A-001',
            'customer_name' => 'Customer A',
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'problem_description' => 'No power.',
            'current_status' => 'RECEIVED',
        ]);
        $jobB = JobOrder::create([
            'shop_id' => $shopB->id,
            'job_order_number' => 'TRACK-SHOP-B-001',
            'customer_name' => 'Customer B',
            'customer_phone' => '09123456780',
            'device_type' => 'Laptop',
            'problem_description' => 'No display.',
            'current_status' => 'RECEIVED',
        ]);

        $this->getJson('/api/track?' . http_build_query([
            'job_order_number' => $jobA->job_order_number,
        ]))->assertUnprocessable()
            ->assertJsonPath('message', 'Shop code is required for tracking.')
            ->assertJsonPath('errors.shop_slug.0', 'The shop code field is required.');

        $this->getJson('/api/track/' . $jobA->job_order_number)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Shop code is required for tracking.');

        $this->getJson('/api/track?' . http_build_query([
            'shop_slug' => $shopA->slug,
            'job_order_number' => $jobA->job_order_number,
        ]))->assertOk()
            ->assertJsonPath('shop.name', $shopA->name)
            ->assertJsonPath('shop.address', 'Shop A Address')
            ->assertJsonPath('shop.business_hours.monday.open_time', '08:00');

        $this->getJson("/api/shops/{$shopA->slug}/track/{$jobA->job_order_number}")
            ->assertOk();

        $this->getJson("/api/shops/{$shopB->slug}/track/{$jobA->job_order_number}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Job Order not found for this shop.');

        $this->getJson("/api/shops/{$shopA->slug}/track/does-not-exist")
            ->assertNotFound()
            ->assertJsonPath('message', 'Job Order not found for this shop.');

        $this->getJson("/api/shops/{$shopA->slug}/track/{$jobB->job_order_number}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Job Order not found for this shop.');
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

    private function createPendingSubscriptionRequest(Shop $shop): SubscriptionRequest
    {
        return $shop->subscriptionRequests()->create([
            'plan_key' => 'monthly',
            'plan_name' => 'Monthly',
            'amount' => 1500,
            'duration_months' => 1,
            'payment_method_key' => 'gcash',
            'payment_method_name' => 'GCash',
            'status' => SubscriptionRequest::STATUS_PENDING,
        ]);
    }
}
