<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\JobOrderPart;
use App\Models\Shop;
use App\Models\SparePart;
use App\Models\StatusHistory;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialIntegrityCostMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_paid_cost_reduction_succeeds(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 5000, 0);

        $this->updateCosts($staff, $jobOrder, 4000, 0)->assertOk();

        $this->assertFinancialState($jobOrder, 4000, 0, 'PENDING');
    }

    public function test_partial_payment_cost_reduction_above_paid_succeeds(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 5000, 3000);

        $this->updateCosts($staff, $jobOrder, 4000, 0)->assertOk();

        $this->assertFinancialState($jobOrder, 4000, 3000, 'PARTIAL');
    }

    public function test_partial_payment_cost_reduction_to_paid_amount_succeeds(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 5000, 3000);

        $this->updateCosts($staff, $jobOrder, 3000, 0)->assertOk();

        $this->assertFinancialState($jobOrder, 3000, 3000, 'PAID');
    }

    public function test_cost_reduction_below_partial_payment_is_rejected_without_audit(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 5000, 3000);

        $this->updateCosts($staff, $jobOrder, 2000, 0)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('total_cost');

        $this->assertFinancialState($jobOrder, 5000, 3000, 'PARTIAL');
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'job_order.costs_updated',
            'record_id' => $jobOrder->id,
        ]);
    }

    public function test_cost_reduction_below_full_payment_is_rejected(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 5000, 5000, 'PAID');

        $this->updateCosts($staff, $jobOrder, 4000, 0)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('total_cost');

        $this->assertFinancialState($jobOrder, 5000, 5000, 'PAID');
    }

    public function test_cost_increase_after_payment_remains_allowed(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 5000, 3000);

        $this->updateCosts($staff, $jobOrder, 7000, 0)->assertOk();

        $this->assertFinancialState($jobOrder, 7000, 3000, 'PARTIAL');
    }

    public function test_cross_shop_cost_update_is_rejected(): void
    {
        [$shopA, $staffA] = $this->createShopUser(User::ROLE_STAFF);
        [$shopB] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shopB, 5000, 3000);

        $this->updateCosts($staffA, $jobOrder, 4000, 0)->assertNotFound();

        $this->assertFinancialState($jobOrder, 5000, 3000, 'PARTIAL');
    }

    public function test_technician_cannot_call_admin_cost_update(): void
    {
        [$shop, $technicianUser] = $this->createShopUser(User::ROLE_TECHNICIAN);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Financial Test Technician',
            'email' => $technicianUser->email,
            'is_active' => true,
        ]);
        $jobOrder = $this->createJobOrder($shop, 5000, 3000);
        $jobOrder->update(['technician_id' => $technician->id]);

        $this->updateCosts($technicianUser, $jobOrder, 4000, 0)->assertForbidden();
        $this->assertFinancialState($jobOrder, 5000, 3000, 'PARTIAL');
    }

    public function test_assigned_technician_can_add_a_part_when_total_covers_paid_amount(): void
    {
        $shop = $this->createShop();
        [$technicianUser, $technician] = $this->createTechnician($shop);
        $jobOrder = $this->createJobOrder($shop, 0, 0, 'PENDING');
        $jobOrder->update(['technician_id' => $technician->id]);
        $sparePart = $this->createSparePart($shop, 100, 2);

        $this->actingAs($technicianUser)
            ->postJson("/api/technician/job-orders/{$jobOrder->id}/parts", [
                'spare_part_id' => $sparePart->id,
                'quantity' => 1,
            ])
            ->assertCreated();

        $this->assertFinancialState($jobOrder, 100, 0, 'PENDING');
        $this->assertDatabaseHas('job_order_parts', ['job_order_id' => $jobOrder->id]);
    }

    public function test_assigned_technician_cannot_add_a_part_when_total_would_fall_below_paid_amount(): void
    {
        $shop = $this->createShop();
        [$technicianUser, $technician] = $this->createTechnician($shop);
        $jobOrder = $this->createJobOrder($shop, 0, 150, 'PARTIAL');
        $jobOrder->update(['technician_id' => $technician->id]);
        $sparePart = $this->createSparePart($shop, 100, 2);

        $this->actingAs($technicianUser)
            ->postJson("/api/technician/job-orders/{$jobOrder->id}/parts", [
                'spare_part_id' => $sparePart->id,
                'quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('total_cost');

        $this->assertDatabaseMissing('job_order_parts', ['job_order_id' => $jobOrder->id]);
        $this->assertSame(2, $sparePart->fresh()->stock_quantity);
        $this->assertFinancialState($jobOrder, 0, 150, 'PARTIAL');
    }

    public function test_assigned_technician_cannot_remove_a_part_when_total_would_fall_below_paid_amount(): void
    {
        $shop = $this->createShop();
        [$technicianUser, $technician] = $this->createTechnician($shop);
        $jobOrder = $this->createJobOrder($shop, 100, 150, 'PARTIAL');
        $jobOrder->update(['technician_id' => $technician->id, 'parts_cost' => 100]);
        $sparePart = $this->createSparePart($shop, 100, 1);
        $part = JobOrderPart::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'spare_part_id' => $sparePart->id,
            'part_name' => $sparePart->name,
            'quantity' => 1,
            'unit_cost' => 100,
            'total_cost' => 100,
        ]);

        $this->actingAs($technicianUser)
            ->deleteJson("/api/technician/job-orders/{$jobOrder->id}/parts/{$part->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('total_cost');

        $this->assertDatabaseHas('job_order_parts', ['id' => $part->id]);
        $this->assertSame(1, $sparePart->fresh()->stock_quantity);
    }

    public function test_parts_request_fulfillment_rejects_without_partial_stock_or_job_changes(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 500, 600, 'PARTIAL', 'WAITING_PARTS');
        StatusHistory::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'status' => 'WAITING_PARTS',
            'note' => 'Parts requested: Charging IC (Qty 1)',
        ]);
        $sparePart = $this->createSparePart($shop, 0, 2);

        $this->actingAs($staff)
            ->postJson("/api/admin/blocked-job-orders/{$jobOrder->id}/fulfill-parts-request", [
                'spare_part_id' => $sparePart->id,
                'quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('total_cost');

        $this->assertDatabaseMissing('job_order_parts', ['job_order_id' => $jobOrder->id]);
        $this->assertSame(2, $sparePart->fresh()->stock_quantity);
        $this->assertSame('WAITING_PARTS', $jobOrder->fresh()->current_status);
    }

    public function test_parts_request_fulfillment_succeeds_when_resulting_total_covers_paid_amount(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 100, 100, 'PAID', 'WAITING_PARTS');
        StatusHistory::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'status' => 'WAITING_PARTS',
            'note' => 'Parts requested: Charging IC (Qty 1)',
        ]);
        $sparePart = $this->createSparePart($shop, 100, 2);

        $this->actingAs($staff)
            ->postJson("/api/admin/blocked-job-orders/{$jobOrder->id}/fulfill-parts-request", [
                'spare_part_id' => $sparePart->id,
                'quantity' => 1,
            ])
            ->assertOk();

        $this->assertDatabaseHas('job_order_parts', ['job_order_id' => $jobOrder->id, 'total_cost' => 100]);
        $this->assertSame(1, $sparePart->fresh()->stock_quantity);
        $this->assertSame('IN_PROGRESS', $jobOrder->fresh()->current_status);
        $this->assertFinancialState($jobOrder, 100, 100, 'PAID');
    }

    public function test_admin_part_deletion_cannot_reduce_total_below_paid_amount(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 100, 150, 'PARTIAL');
        $part = JobOrderPart::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'part_name' => 'Paid test part',
            'quantity' => 1,
            'unit_cost' => 100,
            'total_cost' => 100,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/job-orders/{$jobOrder->id}/parts/{$part->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('total_cost');

        $this->assertDatabaseHas('job_order_parts', ['id' => $part->id]);
        $this->assertFinancialState($jobOrder, 100, 150, 'PARTIAL');
    }

    private function updateCosts(User $user, JobOrder $jobOrder, int $partsCost, int $laborCost)
    {
        return $this->actingAs($user)->postJson("/api/admin/job-orders/{$jobOrder->id}/update-costs", [
            'parts_cost' => $partsCost,
            'labor_cost' => $laborCost,
        ]);
    }

    private function assertFinancialState(JobOrder $jobOrder, int $total, int $paid, string $status): void
    {
        $jobOrder->refresh();
        $this->assertSame((float) $total, (float) $jobOrder->total_cost);
        $this->assertSame((float) $paid, (float) $jobOrder->amount_paid);
        $this->assertSame($status, $jobOrder->payment_status);
        $this->assertSame((float) max($total - $paid, 0), (float) $jobOrder->balance);
    }

    private function createTechnician(Shop $shop): array
    {
        $user = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_TECHNICIAN,
            'is_active' => true,
        ]);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Assigned Technician',
            'email' => $user->email,
            'is_active' => true,
        ]);

        return [$user, $technician];
    }

    private function createSparePart(Shop $shop, int $unitCost, int $stock): SparePart
    {
        return SparePart::create([
            'shop_id' => $shop->id,
            'name' => 'Test Spare Part ' . uniqid(),
            'part_number' => 'PART-' . uniqid(),
            'unit' => 'piece',
            'stock_quantity' => $stock,
            'reorder_level' => 1,
            'unit_cost' => $unitCost,
            'is_active' => true,
        ]);
    }

    private function createShop(): Shop
    {
        return Shop::create([
            'name' => 'Financial Integrity Shop ' . uniqid(),
            'slug' => 'financial-integrity-' . uniqid(),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);
    }

    private function createShopUser(string $role): array
    {
        $shop = $this->createShop();
        $user = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => $role,
            'is_active' => true,
        ]);

        return [$shop, $user];
    }

    private function createJobOrder(Shop $shop, int $total, int $paid, string $status = 'PARTIAL', string $currentStatus = 'DIAGNOSING'): JobOrder
    {
        return JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-FINANCIAL-' . uniqid(),
            'customer_name' => 'Financial Test Customer',
            'customer_phone' => '09000000000',
            'device_type' => 'Phone',
            'problem_description' => 'Financial integrity test.',
            'current_status' => $currentStatus,
            'parts_cost' => $total,
            'labor_cost' => 0,
            'total_cost' => $total,
            'amount_paid' => $paid,
            'payment_status' => $status,
        ]);
    }
}