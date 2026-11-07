<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PartRequest;
use App\Models\Shop;
use App\Models\SparePart;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryManagementAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_manage_inventory_and_each_operation_is_audited(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);

        $created = $this->actingAs($staff)
            ->postJson('/api/admin/inventory', $this->partPayload([
                'name' => 'Staff-created Part',
                'stock_quantity' => 10,
                'description' => 'Original description',
            ]))
            ->assertCreated()
            ->assertJsonPath('item.stock_quantity', 10);
        $partId = $created->json('item.id');

        $this->assertAudit('inventory.created', $staff, $shop, $partId);
        $createAudit = AuditLog::where('action', 'inventory.created')->firstOrFail();
        $this->assertSame(0, $createAudit->new_values['stock_quantity_before']);
        $this->assertSame(10, $createAudit->new_values['stock_quantity_change']);

        $this->actingAs($staff)
            ->putJson("/api/admin/inventory/{$partId}", $this->partPayload([
                'name' => 'Staff-updated Part',
                'stock_quantity' => 10,
            ]))
            ->assertOk()
            ->assertJsonPath('item.name', 'Staff-updated Part')
            ->assertJsonPath('item.description', 'Original description');
        $this->assertAudit('inventory.updated', $staff, $shop, $partId);
        $detailsEditAudit = AuditLog::where('action', 'inventory.updated')->oldest('id')->firstOrFail();
        $this->assertSame('Staff-created Part', $detailsEditAudit->old_values['name']);
        $this->assertSame('Staff-updated Part', $detailsEditAudit->new_values['name']);
        $this->assertSame('Original description', $detailsEditAudit->old_values['description']);

        $this->actingAs($staff)
            ->putJson("/api/admin/inventory/{$partId}", $this->partPayload([
                'name' => 'Staff-updated Part',
                'stock_quantity' => 11,
                'stock_note' => 'Count correction',
            ]))
            ->assertOk()
            ->assertJsonPath('item.stock_quantity', 11);
        $stockEditAudit = AuditLog::where('action', 'inventory.updated')->latest('id')->firstOrFail();
        $this->assertSame(10, $stockEditAudit->old_values['stock_quantity']);
        $this->assertSame(11, $stockEditAudit->new_values['stock_quantity']);
        $this->assertSame(1, $stockEditAudit->new_values['stock_quantity_change']);
        $this->assertSame('Count correction', $stockEditAudit->new_values['stock_change_reason']);

        $this->actingAs($staff)
            ->postJson("/api/admin/inventory/{$partId}/adjust", [
                'action' => 'in',
                'quantity' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('item.stock_quantity', 16);
        $this->assertAudit('inventory.restocked', $staff, $shop, $partId);

        $this->actingAs($staff)
            ->postJson("/api/admin/inventory/{$partId}/adjust", [
                'action' => 'out',
                'quantity' => 3,
                'note' => 'Issued for repair',
            ])
            ->assertOk()
            ->assertJsonPath('item.stock_quantity', 13);
        $this->assertAudit('inventory.deducted', $staff, $shop, $partId);

        $this->actingAs($staff)
            ->postJson("/api/admin/inventory/{$partId}/adjust", [
                'action' => 'set',
                'quantity' => 7,
                'note' => 'Cycle count',
            ])
            ->assertOk()
            ->assertJsonPath('item.stock_quantity', 7);
        $this->assertAudit('inventory.quantity_set', $staff, $shop, $partId);

        $this->assertSame(7, (int) SparePart::findOrFail($partId)->stock_quantity);
        $this->assertSame(
            ['IN', 'ADJUSTMENT', 'IN', 'OUT', 'ADJUSTMENT'],
            StockMovement::where('spare_part_id', $partId)->orderBy('id')->pluck('type')->all()
        );
        $deductionAudit = AuditLog::where('action', 'inventory.deducted')->firstOrFail();
        $this->assertSame(16, $deductionAudit->old_values['stock_quantity']);
        $this->assertSame(13, $deductionAudit->new_values['stock_quantity']);
        $this->assertSame(3, $deductionAudit->new_values['quantity_deducted']);
        $this->assertSame('Issued for repair', $deductionAudit->new_values['reason']);
        $this->assertDatabaseHas('stock_movements', [
            'spare_part_id' => $partId,
            'type' => 'OUT',
            'quantity_change' => -3,
            'balance_after' => 13,
            'note' => 'Issued for repair',
        ]);

        $safePart = SparePart::create([
            'shop_id' => $shop->id,
            'name' => 'Unused Part',
            'unit' => 'pcs',
            'stock_quantity' => 0,
            'reorder_level' => 0,
            'unit_cost' => 0,
            'is_active' => true,
        ]);
        $this->actingAs($staff)
            ->deleteJson("/api/admin/inventory/{$safePart->id}")
            ->assertOk();
        $this->assertNull(SparePart::find($safePart->id));
        $this->assertAudit('inventory.deleted', $staff, $shop, $safePart->id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory.deleted',
            'record_id' => $safePart->id,
        ]);
    }

    public function test_staff_stock_failures_require_reasons_and_do_not_change_stock_or_write_audits(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $part = $this->createPart($shop, 4);

        $this->actingAs($staff)
            ->postJson("/api/admin/inventory/{$part->id}/adjust", [
                'action' => 'out',
                'quantity' => 0,
                'note' => 'Invalid zero deduction',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->actingAs($staff)
            ->postJson("/api/admin/inventory/{$part->id}/adjust", [
                'action' => 'out',
                'quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $this->actingAs($staff)
            ->postJson("/api/admin/inventory/{$part->id}/adjust", [
                'action' => 'out',
                'quantity' => 5,
                'note' => 'Too much',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->actingAs($staff)
            ->postJson("/api/admin/inventory/{$part->id}/adjust", [
                'action' => 'set',
                'quantity' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $this->actingAs($staff)
            ->putJson("/api/admin/inventory/{$part->id}", $this->partPayload([
                'stock_quantity' => 3,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock_note');

        $this->assertSame(4, (int) $part->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('spare_part_id', $part->id)->count());
        $this->assertSame(0, AuditLog::where('record_id', $part->id)->count());
    }

    public function test_staff_cannot_access_or_modify_another_shops_inventory(): void
    {
        [, $staff] = $this->createShopUser(User::ROLE_STAFF);
        [$otherShop] = $this->createShopUser(User::ROLE_ADMIN);
        $foreignPart = $this->createPart($otherShop, 8);

        $this->actingAs($staff)
            ->getJson("/api/admin/inventory/{$foreignPart->id}")
            ->assertNotFound();
        $this->actingAs($staff)
            ->putJson("/api/admin/inventory/{$foreignPart->id}", $this->partPayload())
            ->assertNotFound();
        $this->actingAs($staff)
            ->postJson("/api/admin/inventory/{$foreignPart->id}/adjust", [
                'action' => 'in',
                'quantity' => 2,
            ])
            ->assertNotFound();
        $this->actingAs($staff)
            ->deleteJson("/api/admin/inventory/{$foreignPart->id}")
            ->assertNotFound();

        $this->assertSame(8, (int) $foreignPart->fresh()->stock_quantity);
        $this->assertSame(0, AuditLog::where('record_id', $foreignPart->id)->count());
    }

    public function test_admin_retains_inventory_access_and_technicians_and_platform_admins_remain_restricted(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $part = $this->createPart($shop, 4);

        $this->actingAs($admin)
            ->postJson("/api/admin/inventory/{$part->id}/adjust", [
                'action' => 'out',
                'quantity' => 1,
                'note' => 'Admin deduction',
            ])
            ->assertOk()
            ->assertJsonPath('item.stock_quantity', 3);

        [, $technician] = $this->createShopUser(User::ROLE_TECHNICIAN);
        $this->actingAs($technician)
            ->postJson("/api/admin/inventory/{$part->id}/adjust", [
                'action' => 'in',
                'quantity' => 1,
            ])
            ->assertForbidden();

        $platformAdmin = User::factory()->create([
            'shop_id' => null,
            'role' => User::ROLE_PLATFORM_ADMIN,
            'is_active' => true,
        ]);
        $this->actingAs($platformAdmin)
            ->getJson('/api/admin/inventory')
            ->assertForbidden();
    }

    public function test_items_with_stock_or_historical_references_cannot_be_deleted(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $part = $this->createPart($shop, 1);
        StockMovement::create([
            'shop_id' => $shop->id,
            'spare_part_id' => $part->id,
            'type' => 'IN',
            'quantity_change' => 1,
            'balance_after' => 1,
            'note' => 'Historical delivery',
        ]);

        $this->actingAs($staff)
            ->deleteJson("/api/admin/inventory/{$part->id}")
            ->assertUnprocessable();

        $this->assertNotNull($part->fresh());
        $this->assertSame(1, StockMovement::where('spare_part_id', $part->id)->count());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'inventory.deleted',
            'record_id' => $part->id,
        ]);

        $requestedPart = $this->createPart($shop, 0);
        $partRequest = PartRequest::create([
            'shop_id' => $shop->id,
            'inventory_spare_part_id' => $requestedPart->id,
            'part_name' => $requestedPart->name,
            'quantity' => 1,
            'status' => PartRequest::STATUS_PENDING,
        ]);
        $this->actingAs($staff)
            ->deleteJson("/api/admin/inventory/{$requestedPart->id}")
            ->assertUnprocessable();
        $this->assertNotNull($requestedPart->fresh());
        $this->assertSame($requestedPart->id, $partRequest->fresh()->inventory_spare_part_id);
    }

    public function test_staff_cannot_view_or_mutate_audit_logs(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $auditLog = AuditLog::create([
            'shop_id' => $shop->id,
            'user_id' => $staff->id,
            'user_name' => $staff->name,
            'user_role' => $staff->role,
            'action' => 'inventory.created',
            'module' => 'inventory',
            'record_type' => 'SparePart',
            'record_id' => 999,
            'description' => 'Existing inventory audit entry.',
        ]);

        $this->actingAs($staff)
            ->getJson('/api/admin/audit-logs')
            ->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'id' => $auditLog->id,
            'action' => 'inventory.created',
            'description' => 'Existing inventory audit entry.',
        ]);
    }

    private function partPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Inventory Test Part',
            'part_number' => 'PART-' . uniqid(),
            'category' => 'Test Parts',
            'unit' => 'pcs',
            'stock_quantity' => 0,
            'reorder_level' => 1,
            'unit_cost' => 0,
            'is_active' => true,
        ], $overrides);
    }

    private function createPart(Shop $shop, int $stock): SparePart
    {
        return SparePart::create([
            'shop_id' => $shop->id,
            'name' => 'Existing Inventory Part ' . uniqid(),
            'part_number' => 'EXISTING-' . uniqid(),
            'unit' => 'pcs',
            'stock_quantity' => $stock,
            'reorder_level' => 1,
            'unit_cost' => 0,
            'is_active' => true,
        ]);
    }

    private function createShopUser(string $role): array
    {
        $shop = Shop::create([
            'name' => 'Inventory Access Shop ' . uniqid(),
            'slug' => 'inventory-access-' . uniqid(),
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

    private function assertAudit(string $action, User $user, Shop $shop, int $partId): void
    {
        $this->assertDatabaseHas('audit_logs', [
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_role' => $user->role,
            'action' => $action,
            'module' => 'inventory',
            'record_type' => 'SparePart',
            'record_id' => $partId,
        ]);
    }
}
