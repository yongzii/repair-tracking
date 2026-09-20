<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\JobOrderPart;
use App\Models\Shop;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobOrderPartTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_staff_cannot_mutate_another_shops_job_order_part(): void
    {
        [$shopA, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $staff = User::factory()->create([
            'shop_id' => $shopA->id,
            'role' => User::ROLE_STAFF,
            'is_active' => true,
        ]);
        [$shopB] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrderB = $this->createJobOrder($shopB);
        $part = $this->createPart($jobOrderB, $jobOrderB->shop_id);
        $original = $part->toArray();

        foreach ([$admin, $staff] as $user) {
            $this->actingAs($user)
                ->putJson("/api/admin/job-orders/{$jobOrderB->id}/parts/{$part->id}", [
                    'part_name' => 'Unauthorized update',
                    'quantity' => 2,
                    'unit_cost' => 20,
                ])
                ->assertNotFound();
        }

        $this->assertSame($shopA->id, $admin->fresh()->shop_id);
        $part->refresh();
        $this->assertSame($original['shop_id'], $part->shop_id);
        $this->assertSame($original['job_order_id'], $part->job_order_id);
        $this->assertSame($original['part_name'], $part->part_name);
        $this->assertSame($original['quantity'], $part->quantity);
        $this->assertSame($original['unit_cost'], $part->unit_cost);
        $this->assertSame($original['total_cost'], $part->total_cost);
    }

    public function test_technician_cannot_remove_another_shops_job_order_part(): void
    {
        [$shopA, $technicianUser] = $this->createShopUser(User::ROLE_TECHNICIAN);
        $technician = Technician::create([
            'shop_id' => $shopA->id,
            'name' => 'Shop A Technician',
            'email' => $technicianUser->email,
            'is_active' => true,
        ]);
        [$shopB] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrderB = $this->createJobOrder($shopB);
        $part = $this->createPart($jobOrderB, $shopB->id);

        $this->actingAs($technicianUser)
            ->deleteJson("/api/technician/job-orders/{$jobOrderB->id}/parts/{$part->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('job_order_parts', ['id' => $part->id]);
        $this->assertSame($jobOrderB->id, $part->fresh()->job_order_id);
        $this->assertDatabaseHas('technicians', [
            'id' => $technician->id,
            'shop_id' => $shopA->id,
        ]);
    }

    public function test_part_from_another_job_order_cannot_be_deleted(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $requestedJob = $this->createJobOrder($shop);
        $otherJob = $this->createJobOrder($shop);
        $part = $this->createPart($otherJob, $shop->id);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/job-orders/{$requestedJob->id}/parts/{$part->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('job_order_parts', [
            'id' => $part->id,
            'job_order_id' => $otherJob->id,
        ]);
    }

    public function test_cross_shop_part_is_rejected_when_its_job_order_relationship_is_inconsistent(): void
    {
        [$shopA, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        [$shopB] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shopA);
        $part = $this->createPart($jobOrder, $shopB->id);
        $original = $part->toArray();

        $this->actingAs($admin)
            ->putJson("/api/admin/job-orders/{$jobOrder->id}/parts/{$part->id}", [
                'part_name' => 'Cross-shop mutation',
                'quantity' => 3,
                'unit_cost' => 30,
            ])
            ->assertNotFound();

        $this->assertSame($original['shop_id'], $part->fresh()->shop_id);
        $this->assertSame($original['job_order_id'], $part->fresh()->job_order_id);
        $this->assertSame($original['part_name'], $part->fresh()->part_name);
        $this->assertSame($original['quantity'], $part->fresh()->quantity);
        $this->assertSame($original['unit_cost'], $part->fresh()->unit_cost);
        $this->assertSame($original['total_cost'], $part->fresh()->total_cost);
    }

    public function test_same_shop_part_operations_still_succeed(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop);
        $part = $this->createPart($jobOrder, $shop->id);

        $this->actingAs($admin)
            ->putJson("/api/admin/job-orders/{$jobOrder->id}/parts/{$part->id}", [
                'part_name' => 'Updated part',
                'quantity' => 2,
                'unit_cost' => 25,
            ])
            ->assertOk();

        $this->assertDatabaseHas('job_order_parts', [
            'id' => $part->id,
            'shop_id' => $shop->id,
            'part_name' => 'Updated part',
            'quantity' => 2,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/job-orders/{$jobOrder->id}/parts/{$part->id}")
            ->assertOk();

        $this->assertDatabaseMissing('job_order_parts', ['id' => $part->id]);
    }

    private function createShopUser(string $role): array
    {
        $shop = Shop::create([
            'name' => 'Part Isolation Shop ' . $role,
            'slug' => 'part-isolation-' . strtolower($role) . '-' . uniqid(),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => $role,
            'is_active' => true,
        ]);

        return [$shop, $user];
    }

    private function createJobOrder(Shop $shop): JobOrder
    {
        return JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-PART-' . $shop->id . '-' . uniqid(),
            'customer_name' => 'Part Test Customer',
            'customer_phone' => '09000000000',
            'device_type' => 'Phone',
            'problem_description' => 'Part isolation test.',
            'current_status' => 'DIAGNOSING',
        ]);
    }

    private function createPart(JobOrder $jobOrder, int $shopId): JobOrderPart
    {
        return JobOrderPart::create([
            'shop_id' => $shopId,
            'job_order_id' => $jobOrder->id,
            'part_name' => 'Test part',
            'quantity' => 1,
            'unit_cost' => 10,
            'total_cost' => 10,
        ]);
    }
}