<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\JobOrderPart;
use App\Models\Shop;
use App\Models\SparePart;
use App\Models\StockMovement;
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

    public function test_assigned_technician_can_add_a_part_without_changing_billing(): void
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

        $this->assertFinancialState($jobOrder, 0, 0, 'PENDING');
        $this->assertDatabaseHas('job_order_parts', [
            'job_order_id' => $jobOrder->id,
            'unit_cost' => 0,
            'total_cost' => 0,
        ]);
        $this->assertSame(1, $sparePart->fresh()->stock_quantity);
    }

    public function test_assigned_technician_can_add_a_part_after_payment_without_billing_safeguard(): void
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
            ->assertCreated();

        $this->assertDatabaseHas('job_order_parts', ['job_order_id' => $jobOrder->id, 'total_cost' => 0]);
        $this->assertSame(1, $sparePart->fresh()->stock_quantity);
        $this->assertFinancialState($jobOrder, 0, 150, 'PARTIAL');
    }

    public function test_assigned_technician_can_remove_a_part_without_billing_safeguard(): void
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
            ->assertOk();

        $this->assertDatabaseMissing('job_order_parts', ['id' => $part->id]);
        $this->assertSame(2, $sparePart->fresh()->stock_quantity);
        $this->assertFinancialState($jobOrder, 100, 150, 'PARTIAL');
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
                'quantity' => 3,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->assertDatabaseMissing('job_order_parts', ['job_order_id' => $jobOrder->id]);
        $this->assertDatabaseMissing('stock_movements', [
            'spare_part_id' => $sparePart->id,
            'job_order_id' => $jobOrder->id,
        ]);
        $this->assertSame(2, $sparePart->fresh()->stock_quantity);
        $this->assertSame('WAITING_PARTS', $jobOrder->fresh()->current_status);
    }

    public function test_parts_request_fulfillment_preserves_billing(): void
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

        $this->assertDatabaseHas('job_order_parts', ['job_order_id' => $jobOrder->id, 'total_cost' => 0]);
        $this->assertSame(1, $sparePart->fresh()->stock_quantity);
        $this->assertSame('IN_PROGRESS', $jobOrder->fresh()->current_status);
        $this->assertFinancialState($jobOrder, 100, 100, 'PAID');
    }

    public function test_admin_part_deletion_preserves_billing(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 100, 150, 'PARTIAL');
        $sparePart = $this->createSparePart($shop, 1, 1);
        $part = JobOrderPart::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'spare_part_id' => $sparePart->id,
            'part_name' => 'Paid test part',
            'quantity' => 1,
            'unit_cost' => 100,
            'total_cost' => 100,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/job-orders/{$jobOrder->id}/parts/{$part->id}")
            ->assertOk();

        $this->assertDatabaseMissing('job_order_parts', ['id' => $part->id]);
        $this->assertSame(2, $sparePart->fresh()->stock_quantity);
        $this->assertFinancialState($jobOrder, 100, 150, 'PARTIAL');
    }

    public function test_admin_inventory_part_mutations_are_stock_only_and_preserve_legacy_values(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 1000, 200, 'PARTIAL');
        $sparePart = $this->createSparePart($shop, 1, 5);
        $initialState = [
            'estimated_cost' => $jobOrder->estimated_cost,
            'parts_cost' => $jobOrder->parts_cost,
            'labor_cost' => $jobOrder->labor_cost,
            'total_cost' => $jobOrder->total_cost,
            'amount_paid' => $jobOrder->amount_paid,
            'payment_status' => $jobOrder->payment_status,
        ];

        $add = $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/parts", [
                'spare_part_id' => $sparePart->id,
                'quantity' => 2,
                'unit_cost' => 999,
            ])
            ->assertCreated();
        $partId = $add->json('part.id');

        $this->assertSame(3, $sparePart->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'spare_part_id' => $sparePart->id,
            'job_order_id' => $jobOrder->id,
            'job_order_part_id' => $partId,
            'type' => 'OUT',
            'quantity_change' => -2,
        ]);
        $this->assertFinancialState($jobOrder, 1000, 200, 'PARTIAL');
        $this->assertSame($initialState['estimated_cost'], $jobOrder->fresh()->estimated_cost);

        $legacyPart = JobOrderPart::findOrFail($partId);
        $legacyPart->update(['unit_cost' => 77, 'total_cost' => 154]);

        $this->actingAs($admin)
            ->putJson("/api/admin/job-orders/{$jobOrder->id}/parts/{$partId}", [
                'spare_part_id' => $sparePart->id,
                'quantity' => 3,
                'notes' => 'Updated quantity',
            ])
            ->assertOk();

        $updatedPart = $legacyPart->fresh();
        $this->assertSame(77.0, (float) $updatedPart->unit_cost);
        $this->assertSame(154.0, (float) $updatedPart->total_cost);
        $this->assertSame(3, (int) $updatedPart->quantity);
        $this->assertSame(2, $sparePart->fresh()->stock_quantity);
        $this->assertFinancialState($jobOrder, 1000, 200, 'PARTIAL');

        $this->actingAs($admin)
            ->deleteJson("/api/admin/job-orders/{$jobOrder->id}/parts/{$partId}")
            ->assertOk();

        $this->assertSame(5, $sparePart->fresh()->stock_quantity);
        $this->assertSame(3, (int) StockMovement::where('job_order_id', $jobOrder->id)
            ->where('type', 'IN')
            ->where('note', 'like', 'Returned from job order%')
            ->latest('id')
            ->value('quantity_change'));
        $this->assertFinancialState($jobOrder, 1000, 200, 'PARTIAL');
    }

    public function test_custom_used_part_does_not_deduct_inventory_stock(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 0, 0, 'PENDING');
        $sparePart = $this->createSparePart($shop, 100, 5);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/parts", [
                'part_name' => 'Unregistered custom part',
                'quantity' => 2,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('job_order_parts', [
            'id' => $response->json('part.id'),
            'job_order_id' => $jobOrder->id,
            'spare_part_id' => null,
            'part_name' => 'Unregistered custom part',
            'quantity' => 2,
        ]);
        $this->assertSame(5, $sparePart->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('job_order_id', $jobOrder->id)->count());
    }

    public function test_used_part_with_insufficient_inventory_stock_is_rejected_without_mutation(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 0, 0, 'PENDING');
        $sparePart = $this->createSparePart($shop, 100, 1);

        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/parts", [
                'spare_part_id' => $sparePart->id,
                'quantity' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->assertSame(1, $sparePart->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('job_order_id', $jobOrder->id)->count());
        $this->assertSame(0, $jobOrder->parts()->count());
    }

    public function test_staff_and_admin_cannot_add_used_parts_after_repair_completion_or_release(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_STAFF] as $role) {
            foreach (['READY_FOR_PICKUP', 'PICKED_UP', 'RTO', 'RTO_RELEASED'] as $status) {
                [$shop, $user] = $this->createShopUser($role);
                $jobOrder = $this->createJobOrder($shop, 0, 0, 'PENDING', $status);
                $sparePart = $this->createSparePart($shop, 100, 5);
                $movementCount = StockMovement::where('job_order_id', $jobOrder->id)->count();

                $this->actingAs($user)
                    ->postJson("/api/admin/job-orders/{$jobOrder->id}/parts", [
                        'spare_part_id' => $sparePart->id,
                        'quantity' => 1,
                    ])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors('job_order');

                $this->assertSame(5, $sparePart->fresh()->stock_quantity);
                $this->assertSame($movementCount, StockMovement::where('job_order_id', $jobOrder->id)->count());
                $this->assertDatabaseMissing('job_order_parts', ['job_order_id' => $jobOrder->id]);
            }
        }
    }

    public function test_staff_and_admin_cannot_update_used_parts_after_repair_completion_or_release(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_STAFF] as $role) {
            foreach (['READY_FOR_PICKUP', 'PICKED_UP', 'RTO', 'RTO_RELEASED'] as $status) {
                [$shop, $user] = $this->createShopUser($role);
                $jobOrder = $this->createJobOrder($shop, 0, 0, 'PENDING', $status);
                $sparePart = $this->createSparePart($shop, 100, 5);
                $part = JobOrderPart::create([
                    'shop_id' => $shop->id,
                    'job_order_id' => $jobOrder->id,
                    'spare_part_id' => $sparePart->id,
                    'part_name' => $sparePart->name,
                    'quantity' => 1,
                    'unit_cost' => 0,
                    'total_cost' => 0,
                ]);
                $originalPart = $part->only(['spare_part_id', 'part_name', 'quantity', 'unit_cost', 'total_cost']);
                $movementCount = StockMovement::where('job_order_id', $jobOrder->id)->count();

                $this->actingAs($user)
                    ->putJson("/api/admin/job-orders/{$jobOrder->id}/parts/{$part->id}", [
                        'spare_part_id' => $sparePart->id,
                        'quantity' => 2,
                    ])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors('job_order');

                $this->assertSame($originalPart, $part->fresh()->only(array_keys($originalPart)));
                $this->assertSame(5, $sparePart->fresh()->stock_quantity);
                $this->assertSame($movementCount, StockMovement::where('job_order_id', $jobOrder->id)->count());
                $this->assertDatabaseHas('job_order_parts', ['id' => $part->id]);
            }
        }
    }

    public function test_staff_and_admin_cannot_remove_used_parts_after_repair_completion_or_release(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_STAFF] as $role) {
            foreach (['READY_FOR_PICKUP', 'PICKED_UP', 'RTO', 'RTO_RELEASED'] as $status) {
                [$shop, $user] = $this->createShopUser($role);
                $jobOrder = $this->createJobOrder($shop, 0, 0, 'PENDING', $status);
                $sparePart = $this->createSparePart($shop, 100, 5);
                $part = JobOrderPart::create([
                    'shop_id' => $shop->id,
                    'job_order_id' => $jobOrder->id,
                    'spare_part_id' => $sparePart->id,
                    'part_name' => $sparePart->name,
                    'quantity' => 1,
                    'unit_cost' => 0,
                    'total_cost' => 0,
                ]);
                $originalPart = $part->only(['spare_part_id', 'part_name', 'quantity', 'unit_cost', 'total_cost']);
                $movementCount = StockMovement::where('job_order_id', $jobOrder->id)->count();

                $this->actingAs($user)
                    ->deleteJson("/api/admin/job-orders/{$jobOrder->id}/parts/{$part->id}")
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors('job_order');

                $this->assertSame($originalPart, $part->fresh()->only(array_keys($originalPart)));
                $this->assertSame(5, $sparePart->fresh()->stock_quantity);
                $this->assertSame($movementCount, StockMovement::where('job_order_id', $jobOrder->id)->count());
                $this->assertDatabaseHas('job_order_parts', ['id' => $part->id]);
            }
        }
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
            'unit' => 'pcs',
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