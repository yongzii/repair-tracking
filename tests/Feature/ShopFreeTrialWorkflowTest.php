<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\SubscriptionRequest;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShopFreeTrialWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIVATE_URL = '/api/shop/free-trial/activate';

    public function test_newly_registered_shop_has_an_unused_trial_without_activating_it(): void
    {
        $this->postJson('/api/onboarding/register-shop', [
            'shop_name' => 'Free Trial Registration Shop',
            'shop_slug' => 'free-trial-registration-' . Str::lower(Str::random(8)),
            'owner_name' => 'Trial Owner',
            'owner_email' => Str::lower(Str::random(12)) . '@example.com',
            'owner_password' => 'password123',
            'owner_password_confirmation' => 'password123',
        ])->assertCreated();

        $shop = Shop::query()->latest('id')->firstOrFail();

        $this->assertTrue($shop->free_trial_eligible);
        $this->assertNull($shop->free_trial_started_at);
        $this->assertNull($shop->free_trial_ends_at);
        $this->assertNull($shop->free_trial_used_at);
        $this->assertNull($shop->trial_ends_at);
        $this->assertSame(0, $shop->subscriptionRequests()->count());
    }

    public function test_shop_trial_eligibility_defaults_to_false_for_existing_records(): void
    {
        $shop = Shop::create([
            'name' => 'Existing Shop Without Trial',
            'slug' => 'existing-shop-' . Str::lower(Str::random(8)),
        ]);

        $this->assertFalse($shop->fresh()->free_trial_eligible);
        $this->assertFalse($shop->fresh()->isEligibleForFreeTrial());
    }

    public function test_shop_admin_can_activate_a_trial_for_exactly_168_hours_once(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);
        $startedAt = Carbon::parse('2026-11-09 12:34:56');
        Carbon::setTestNow($startedAt);

        try {
            $response = $this->actingAs($admin)
                ->postJson(self::ACTIVATE_URL)
                ->assertOk()
                ->assertJsonPath('trial.eligible', false)
                ->assertJsonPath('trial.used', true)
                ->assertJsonPath('trial.active', true)
                ->assertJsonPath('trial.started_at', $startedAt->toISOString())
                ->assertJsonPath('trial.ends_at', $startedAt->copy()->addHours(168)->toISOString())
                ->assertJsonPath('trial.used_at', $startedAt->toISOString());

            $shop->refresh();
            $this->assertTrue($shop->hasUsedFreeTrial());
            $this->assertTrue($shop->isFreeTrialActive());
            $this->assertSame(168 * 60 * 60, $shop->free_trial_ends_at->getTimestamp() - $shop->free_trial_started_at->getTimestamp());
            $this->assertSame(
                $shop->free_trial_started_at->getTimestamp(),
                $shop->free_trial_used_at->getTimestamp()
            );
            $this->assertSame($startedAt->toISOString(), $response->json('trial.started_at'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_trial_expiration_is_detected_at_the_exact_end_instant(): void
    {
        $shop = $this->createShop([
            'free_trial_eligible' => false,
            'free_trial_started_at' => now()->subHours(168),
            'free_trial_ends_at' => now(),
            'free_trial_used_at' => now()->subHours(168),
        ]);

        $this->assertTrue($shop->hasUsedFreeTrial());
        $this->assertTrue($shop->isFreeTrialExpired());
        $this->assertFalse($shop->isFreeTrialActive());
        $this->assertSame(
            $shop->free_trial_ends_at->getTimestamp(),
            $shop->freeTrialExpirationAt()->getTimestamp()
        );
    }

    public function test_second_activation_is_rejected_without_resetting_trial_timestamps(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);
        Carbon::setTestNow();

        try {
            $this->actingAs($admin)->postJson(self::ACTIVATE_URL)->assertOk();
            $shop->refresh();
            $startedAt = $shop->free_trial_started_at->toISOString();
            $endsAt = $shop->free_trial_ends_at->toISOString();
            Carbon::setTestNow($shop->free_trial_ends_at->copy());

            $this->postJson(self::ACTIVATE_URL)
                ->assertConflict()
                ->assertJsonPath('message', 'This shop has already used its free trial.');

            $shop->refresh();
            $this->assertSame($startedAt, $shop->free_trial_started_at->toISOString());
            $this->assertSame($endsAt, $shop->free_trial_ends_at->toISOString());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_used_trial_remains_ineligible_after_paid_coverage_expires(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);

        $this->actingAs($admin)->postJson(self::ACTIVATE_URL)->assertOk();
        $shop->refresh();
        $startedAt = $shop->free_trial_started_at->getTimestamp();
        $trialEndsAt = $shop->free_trial_ends_at->getTimestamp();
        $usedAt = $shop->free_trial_used_at->getTimestamp();

        $paidCoverageEndsAt = $shop->free_trial_ends_at->copy()->addDay();
        $shop->forceFill(['trial_ends_at' => $paidCoverageEndsAt])->save();
        Carbon::setTestNow($paidCoverageEndsAt->copy()->addSecond());

        try {
            $this->actingAs($admin)
                ->postJson(self::ACTIVATE_URL)
                ->assertConflict()
                ->assertJsonPath('message', 'This shop has already used its free trial.');

            $shop->refresh();
            $this->assertFalse($shop->free_trial_eligible);
            $this->assertSame($startedAt, $shop->free_trial_started_at->getTimestamp());
            $this->assertSame($trialEndsAt, $shop->free_trial_ends_at->getTimestamp());
            $this->assertSame($usedAt, $shop->free_trial_used_at->getTimestamp());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_only_shop_admin_can_activate_a_trial(): void
    {
        foreach ([User::ROLE_STAFF, User::ROLE_TECHNICIAN, 'customer', User::ROLE_ADMIN] as $role) {
            $shop = $this->createShop();
            $user = $this->createUser($role, $shop);

            if ($role === User::ROLE_TECHNICIAN) {
                Technician::create([
                    'shop_id' => $shop->id,
                    'name' => 'Trial Technician',
                    'email' => $user->email,
                    'is_active' => true,
                ]);
            }

            $this->actingAs($user)
                ->postJson(self::ACTIVATE_URL)
                ->assertForbidden();

            $this->assertNull($shop->fresh()->free_trial_started_at);
        }
    }

    public function test_user_cannot_activate_a_different_shops_trial(): void
    {
        $userShop = $this->createShop();
        $otherShop = $this->createShop();
        $admin = $this->createUser(User::ROLE_SHOP_ADMIN, $userShop);

        $this->actingAs($admin)
            ->postJson(self::ACTIVATE_URL, ['shop_id' => $otherShop->id])
            ->assertOk();

        $this->assertTrue($userShop->fresh()->hasUsedFreeTrial());
        $this->assertFalse($otherShop->fresh()->hasUsedFreeTrial());
        $this->assertTrue($otherShop->fresh()->free_trial_eligible);
    }

    public function test_inactive_shop_cannot_activate_a_trial(): void
    {
        $shop = $this->createShop(['is_active' => false]);
        $admin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);

        $this->actingAs($admin)
            ->postJson(self::ACTIVATE_URL)
            ->assertForbidden();

        $this->assertTrue($shop->fresh()->free_trial_eligible);
        $this->assertNull($shop->fresh()->free_trial_started_at);
    }

    public function test_shop_with_active_paid_coverage_cannot_activate_a_trial(): void
    {
        $paidCoverageEndsAt = now()->addDays(30)->startOfSecond();
        $shop = $this->createShop(['trial_ends_at' => $paidCoverageEndsAt]);
        $admin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);

        $this->actingAs($admin)
            ->postJson(self::ACTIVATE_URL)
            ->assertConflict()
            ->assertJsonPath('message', 'This shop already has active subscription coverage.');

        $shop->refresh();
        $this->assertTrue($shop->free_trial_eligible);
        $this->assertNull($shop->free_trial_started_at);
        $this->assertSame($paidCoverageEndsAt->getTimestamp(), $shop->trial_ends_at->getTimestamp());
    }

    public function test_pending_subscription_request_does_not_prevent_trial_activation(): void
    {
        $shop = $this->createShop();
        $shop->subscriptionRequests()->create([
            'plan_key' => 'monthly',
            'plan_name' => 'Monthly',
            'amount' => 1500,
            'duration_months' => 1,
            'payment_method_key' => 'gcash',
            'payment_method_name' => 'GCash',
            'status' => SubscriptionRequest::STATUS_PENDING,
        ]);
        $admin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);

        $this->actingAs($admin)
            ->postJson(self::ACTIVATE_URL)
            ->assertOk()
            ->assertJsonPath('trial.active', true);

        $this->assertSame(1, $shop->subscriptionRequests()->where('status', SubscriptionRequest::STATUS_PENDING)->count());
    }

    public function test_ineligible_shop_cannot_activate_a_trial(): void
    {
        $shop = $this->createShop(['free_trial_eligible' => false]);
        $admin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);

        $this->actingAs($admin)
            ->postJson(self::ACTIVATE_URL)
            ->assertConflict()
            ->assertJsonPath('message', 'This shop is not eligible to activate a free trial.');

        $this->assertNull($shop->fresh()->free_trial_started_at);
    }

    public function test_activation_does_not_modify_paid_subscription_coverage(): void
    {
        $shop = $this->createShop(['trial_ends_at' => now()->subDays(10)]);
        $paidCoverageEndsAt = $shop->trial_ends_at->getTimestamp();
        $admin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);

        $this->actingAs($admin)->postJson(self::ACTIVATE_URL)->assertOk();

        $this->assertSame($paidCoverageEndsAt, $shop->fresh()->trial_ends_at->getTimestamp());
    }

    private function createShop(array $attributes = []): Shop
    {
        return Shop::create(array_merge([
            'name' => 'Free Trial Shop ' . Str::random(8),
            'slug' => 'free-trial-' . Str::lower(Str::random(12)),
            'free_trial_eligible' => true,
            'is_active' => true,
        ], $attributes));
    }

    private function createUser(string $role, Shop $shop): User
    {
        return User::factory()->create([
            'shop_id' => $shop->id,
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
