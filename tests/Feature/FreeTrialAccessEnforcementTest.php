<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\JobOrder;
use App\Models\Shop;
use App\Models\SparePart;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class FreeTrialAccessEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_trial_allows_job_and_inventory_writes_for_shop_staff(): void
    {
        $shop = $this->createShopWithActiveTrial(['trial_ends_at' => now()->subDay()]);
        $staff = $this->createUser($shop, User::ROLE_STAFF);

        $this->actingAs($staff)
            ->postJson('/api/admin/job-orders', [
                'customer_name' => 'Trial Customer',
                'customer_phone' => '09123456789',
                'device_type' => 'Laptop',
                'problem_description' => 'The laptop does not power on.',
            ])
            ->assertCreated();

        $this->postJson('/api/admin/inventory', [
            'name' => 'Trial Part',
            'stock_quantity' => 5,
            'reorder_level' => 1,
            'unit_cost' => 20,
        ])->assertCreated();

        $this->assertSame(1, JobOrder::query()->where('shop_id', $shop->id)->count());
        $this->assertSame(1, SparePart::query()->where('shop_id', $shop->id)->count());
        $this->assertFalse($shop->fresh()->canUsePaidCloseOut());
    }

    public function test_active_trial_allows_technicians_to_use_existing_permitted_consultation_actions(): void
    {
        $shop = $this->createShopWithActiveTrial();
        $technicianUser = $this->createUser($shop, User::ROLE_TECHNICIAN);
        Technician::create([
            'shop_id' => $shop->id,
            'name' => $technicianUser->name,
            'email' => $technicianUser->email,
            'is_active' => true,
        ]);
        $appointment = $this->createConsultation($shop);

        $this->actingAs($technicianUser)
            ->postJson("/api/admin/appointments/{$appointment->id}/status", [
                'status' => 'COMPLETED',
                'customer_response' => 'Please bring the device in for an inspection.',
            ])
            ->assertOk();

        $this->assertSame('COMPLETED', $appointment->fresh()->status);
    }

    public function test_active_trial_allows_public_consultations_and_public_directory_visibility(): void
    {
        $shop = $this->createShopWithActiveTrial();

        $directory = $this->getJson('/api/public/shops')->assertOk();
        $this->assertContains($shop->id, array_column($directory->json('shops'), 'id'));

        $this->postJson("/api/shops/{$shop->slug}/appointments", [
            'customer_name' => 'Consultation Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'CONSULTATION',
            'device_type' => 'Phone',
            'concern' => 'The phone restarts unexpectedly during use.',
        ])->assertCreated()
            ->assertJsonPath('appointment.booking_type', 'CONSULTATION');

        $this->assertSame(1, $shop->appointments()->count());
    }

    public function test_expired_trial_blocks_job_and_inventory_writes_but_preserves_historical_reads(): void
    {
        $shop = $this->createShopWithExpiredTrial(['trial_ends_at' => now()->subDay()]);
        $staff = $this->createUser($shop, User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop);
        $part = SparePart::create([
            'shop_id' => $shop->id,
            'name' => 'Historical Part',
            'stock_quantity' => 2,
            'reorder_level' => 1,
            'unit_cost' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($staff)
            ->getJson("/api/admin/job-orders/{$jobOrder->id}")
            ->assertOk();

        $this->postJson('/api/admin/job-orders', [
            'customer_name' => 'Blocked Customer',
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'problem_description' => 'This intake must be blocked after trial expiry.',
        ])->assertStatus(402)
            ->assertJsonPath('message', 'The free trial has expired. Existing records remain available in Preview Mode, but business operations are locked.');

        $this->putJson("/api/admin/job-orders/{$jobOrder->id}", [
            'customer_name' => 'Mutation Attempt',
            'customer_phone' => '09000000000',
            'device_type' => 'Phone',
            'problem_description' => 'This historical record must not be changed.',
        ])->assertStatus(402);

        $this->postJson('/api/admin/inventory', [
            'name' => 'Blocked Part',
            'stock_quantity' => 1,
            'reorder_level' => 0,
            'unit_cost' => 5,
        ])->assertStatus(402);

        $this->assertSame('Existing Customer', $jobOrder->fresh()->customer_name);
        $this->assertSame(1, SparePart::query()->where('shop_id', $shop->id)->count());
        $this->assertSame('Historical Part', $part->fresh()->name);
    }

    public function test_expired_trial_cannot_use_paid_close_out_and_is_hidden_from_public_access(): void
    {
        $shop = $this->createShopWithExpiredTrial(['trial_ends_at' => now()->subDay()]);
        $staff = $this->createUser($shop, User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-status", [
                'status' => 'DIAGNOSING',
                'note' => 'Expired free trials must not inherit paid close-out writes.',
            ])
            ->assertStatus(402);

        $directory = $this->getJson('/api/public/shops')->assertOk();
        $this->assertNotContains($shop->id, array_column($directory->json('shops'), 'id'));

        $this->postJson("/api/shops/{$shop->slug}/appointments", [
            'customer_name' => 'Consultation Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'CONSULTATION',
            'device_type' => 'Phone',
            'concern' => 'The phone battery is draining unexpectedly.',
        ])->assertStatus(402);

        $this->assertSame('RECEIVED', $jobOrder->fresh()->current_status);
        $this->assertSame(0, $shop->appointments()->count());
    }

    public function test_trial_expires_at_the_exact_end_instant(): void
    {
        $expiresAt = Carbon::parse('2026-11-09 12:00:00');
        $shop = $this->createShopWithExpiredTrial([
            'free_trial_started_at' => $expiresAt->copy()->subHours(168),
            'free_trial_ends_at' => $expiresAt,
            'free_trial_used_at' => $expiresAt->copy()->subHours(168),
        ]);

        Carbon::setTestNow($expiresAt);

        try {
            $this->assertTrue($shop->isFreeTrialExpired());
            $this->assertFalse($shop->isFreeTrialActive());
            $this->assertFalse($shop->canPerformBusinessWrites());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_incomplete_consumed_trial_history_fails_closed_for_close_out_access(): void
    {
        $shop = $this->createShop([
            'trial_ends_at' => now()->subDay(),
            'free_trial_used_at' => now()->subDays(8),
        ]);

        $this->assertTrue($shop->isFreeTrialExpired());
        $this->assertFalse($shop->canUsePaidCloseOut());
        $this->assertFalse($shop->canPerformBusinessWrites());
    }

    public function test_paid_access_and_paid_close_out_behavior_remain_unchanged(): void
    {
        $paidShop = $this->createShop(['trial_ends_at' => now()->addDays(30)]);
        $staff = $this->createUser($paidShop, User::ROLE_STAFF);

        $this->actingAs($staff)
            ->postJson('/api/admin/job-orders', [
                'customer_name' => 'Paid Customer',
                'customer_phone' => '09123456789',
                'device_type' => 'Phone',
                'problem_description' => 'Paid access permits new intake.',
            ])
            ->assertCreated();

        $expiredPaidShop = $this->createShop(['trial_ends_at' => now()->subDay()]);
        $expiredPaidStaff = $this->createUser($expiredPaidShop, User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($expiredPaidShop);

        $this->actingAs($expiredPaidStaff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-status", [
                'status' => 'DIAGNOSING',
                'note' => 'Paid close-out access remains available.',
            ])
            ->assertOk();

        $this->assertSame('DIAGNOSING', $jobOrder->fresh()->current_status);

        $paidShopWithOldTrial = $this->createShopWithExpiredTrial([
            'trial_ends_at' => now()->addDays(30),
        ]);
        $paidShopStaff = $this->createUser($paidShopWithOldTrial, User::ROLE_STAFF);
        $this->actingAs($paidShopStaff)
            ->postJson('/api/admin/job-orders', [
                'customer_name' => 'Paid Coverage Customer',
                'customer_phone' => '09123456789',
                'device_type' => 'Phone',
                'problem_description' => 'Active paid coverage takes precedence over expired trial history.',
            ])
            ->assertCreated();
    }

    public function test_inactive_shop_or_user_remains_restricted_during_an_active_trial(): void
    {
        $inactiveShop = $this->createShopWithActiveTrial(['is_active' => false]);
        $staff = $this->createUser($inactiveShop, User::ROLE_STAFF);

        $this->actingAs($staff)
            ->postJson('/api/admin/job-orders', [
                'customer_name' => 'Inactive Customer',
                'customer_phone' => '09123456789',
                'device_type' => 'Phone',
                'problem_description' => 'An inactive shop must remain restricted.',
            ])
            ->assertForbidden();

        $this->getJson('/api/public/shops')->assertOk()
            ->assertJsonMissing(['id' => $inactiveShop->id]);
        $this->postJson("/api/shops/{$inactiveShop->slug}/appointments", [
            'customer_name' => 'Inactive Consultation Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'CONSULTATION',
            'device_type' => 'Phone',
            'concern' => 'This inactive shop must not receive consultations.',
        ])->assertNotFound();

        $shop = $this->createShopWithActiveTrial();
        $inactiveUser = $this->createUser($shop, User::ROLE_STAFF, ['is_active' => false]);

        $this->actingAs($inactiveUser)
            ->postJson('/api/admin/job-orders', [
                'customer_name' => 'Inactive User',
                'customer_phone' => '09123456789',
                'device_type' => 'Phone',
                'problem_description' => 'An inactive user must remain restricted.',
            ])
            ->assertForbidden();
    }

    public function test_authentication_access_state_separates_trial_from_paid_coverage(): void
    {
        $shop = $this->createShopWithActiveTrial();
        $admin = $this->createUser($shop, User::ROLE_SHOP_ADMIN, [
            'password' => 'password123',
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('user.shop.has_active_access', false)
            ->assertJsonPath('user.shop.has_active_paid_access', false)
            ->assertJsonPath('user.shop.has_operational_access', true)
            ->assertJsonPath('user.shop.free_trial_eligible', false)
            ->assertJsonPath('user.shop.free_trial_used', true)
            ->assertJsonPath('user.shop.free_trial_active', true)
            ->assertJsonPath('user.shop.free_trial_expired', false)
            ->assertJsonPath('user.shop.paid_access_until', null);

        $this->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('user.shop.has_active_access', $loginResponse->json('user.shop.has_active_access'))
            ->assertJsonPath('user.shop.has_active_paid_access', false)
            ->assertJsonPath('user.shop.has_operational_access', true)
            ->assertJsonPath('user.shop.free_trial_active', true)
            ->assertJsonPath('user.shop.free_trial_ends_at', $loginResponse->json('user.shop.free_trial_ends_at'));
    }

    public function test_access_state_presents_active_trial_when_there_is_no_paid_coverage(): void
    {
        $shop = $this->createShopWithActiveTrial();

        $state = $shop->accessState();

        $this->assertFalse($state['has_active_paid_access']);
        $this->assertFalse($state['has_active_access']);
        $this->assertTrue($state['has_operational_access']);
        $this->assertTrue($state['free_trial_active']);
    }

    public function test_active_paid_coverage_takes_presentation_precedence_without_changing_trial_history(): void
    {
        $startedAt = now()->subHour()->startOfSecond();
        $endsAt = $startedAt->copy()->addHours(168);
        $usedAt = $startedAt->copy();
        $shop = $this->createShopWithActiveTrial([
            'trial_ends_at' => now()->addDays(30),
            'free_trial_started_at' => $startedAt,
            'free_trial_ends_at' => $endsAt,
            'free_trial_used_at' => $usedAt,
        ]);

        $state = $shop->accessState();

        $this->assertTrue($state['has_active_paid_access']);
        $this->assertTrue($state['has_active_access']);
        $this->assertTrue($state['has_operational_access']);
        $this->assertFalse($state['free_trial_active']);
        $this->assertSame($startedAt->toISOString(), $state['free_trial_started_at']->toISOString());
        $this->assertSame($endsAt->toISOString(), $state['free_trial_ends_at']->toISOString());
        $this->assertSame($usedAt->toISOString(), $state['free_trial_used_at']->toISOString());

        $shop->refresh();
        $this->assertSame($startedAt->getTimestamp(), $shop->free_trial_started_at->getTimestamp());
        $this->assertSame($endsAt->getTimestamp(), $shop->free_trial_ends_at->getTimestamp());
        $this->assertSame($usedAt->getTimestamp(), $shop->free_trial_used_at->getTimestamp());
    }

    private function createShop(array $attributes = []): Shop
    {
        return Shop::create(array_merge([
            'name' => 'Trial Access Shop ' . Str::random(8),
            'slug' => 'trial-access-' . Str::lower(Str::random(12)),
            'is_active' => true,
        ], $attributes));
    }

    private function createShopWithActiveTrial(array $attributes = []): Shop
    {
        $startedAt = now()->subHour();

        return $this->createShop(array_merge([
            'free_trial_eligible' => false,
            'free_trial_started_at' => $startedAt,
            'free_trial_ends_at' => $startedAt->copy()->addHours(168),
            'free_trial_used_at' => $startedAt,
        ], $attributes));
    }

    private function createShopWithExpiredTrial(array $attributes = []): Shop
    {
        $startedAt = now()->subHours(169);

        return $this->createShop(array_merge([
            'free_trial_eligible' => false,
            'free_trial_started_at' => $startedAt,
            'free_trial_ends_at' => $startedAt->copy()->addHours(168),
            'free_trial_used_at' => $startedAt,
        ], $attributes));
    }

    private function createUser(Shop $shop, string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'shop_id' => $shop->id,
            'role' => $role,
            'is_active' => true,
        ], $attributes));
    }

    private function createConsultation(Shop $shop): Appointment
    {
        return Appointment::create([
            'shop_id' => $shop->id,
            'confirmation_code' => 'APT-' . Str::upper(Str::random(12)),
            'customer_name' => 'Trial Consultation Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'CONSULTATION',
            'device_type' => 'Phone',
            'concern' => 'The phone does not charge.',
            'status' => 'PENDING',
        ]);
    }

    private function createJobOrder(Shop $shop): JobOrder
    {
        return JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'JO-' . Str::upper(Str::random(12)),
            'customer_name' => 'Existing Customer',
            'customer_phone' => '09000000000',
            'device_type' => 'Phone',
            'problem_description' => 'Existing repair.',
            'current_status' => 'RECEIVED',
        ]);
    }
}
