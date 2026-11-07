<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\SparePart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryUnitConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_inventory_items_always_use_pcs_and_arbitrary_units_are_rejected(): void
    {
        [$shop, $admin] = $this->createShopUser();

        $created = $this->actingAs($admin)
            ->postJson('/api/admin/inventory', [
                'name' => 'Thermal Paste',
                'category' => 'Consumables',
                'stock_quantity' => 4,
                'reorder_level' => 1,
                'unit_cost' => 125,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('item.unit', 'pcs');

        $part = SparePart::findOrFail($created->json('item.id'));
        $this->assertSame('pcs', $part->unit);

        $this->actingAs($admin)
            ->postJson('/api/admin/inventory', [
                'name' => 'Unsupported Unit Part',
                'unit' => 'box',
                'stock_quantity' => 1,
                'reorder_level' => 0,
                'unit_cost' => 5,
                'is_active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit');
    }

    public function test_editing_inventory_preserves_quantity_and_fields_while_forcing_pcs(): void
    {
        [$shop, $admin] = $this->createShopUser();
        $part = SparePart::create([
            'shop_id' => $shop->id,
            'name' => 'Legacy Part',
            'part_number' => 'LEGACY-001',
            'category' => 'Test Parts',
            'unit' => 'pcs',
            'stock_quantity' => 6,
            'reorder_level' => 2,
            'unit_cost' => 5,
            'description' => 'Keep this description.',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/admin/inventory/{$part->id}", [
                'name' => 'Renamed Legacy Part',
                'part_number' => $part->part_number,
                'category' => $part->category,
                'unit' => 'box',
                'stock_quantity' => $part->stock_quantity,
                'reorder_level' => $part->reorder_level,
                'unit_cost' => $part->unit_cost,
                'description' => $part->description,
                'is_active' => $part->is_active,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit');

        $this->actingAs($admin)
            ->putJson("/api/admin/inventory/{$part->id}", [
                'name' => 'Renamed Legacy Part',
                'part_number' => $part->part_number,
                'category' => $part->category,
                'stock_quantity' => $part->stock_quantity,
                'reorder_level' => $part->reorder_level,
                'unit_cost' => $part->unit_cost,
                'description' => $part->description,
                'is_active' => $part->is_active,
            ])
            ->assertOk()
            ->assertJsonPath('item.unit', 'pcs');

        $part->refresh();
        $this->assertSame(6, (int) $part->stock_quantity);
        $this->assertSame('Renamed Legacy Part', $part->name);
        $this->assertSame('Keep this description.', $part->description);
        $this->assertSame('pcs', $part->unit);
    }

    public function test_unit_standardization_migration_preserves_existing_quantities(): void
    {
        [$shop] = $this->createShopUser();
        $part = SparePart::create([
            'shop_id' => $shop->id,
            'name' => 'Migration Part',
            'unit' => 'sachet',
            'stock_quantity' => 17,
            'reorder_level' => 4,
            'unit_cost' => 3,
            'is_active' => true,
        ]);

        $migration = require database_path('migrations/2026_11_07_000001_standardize_spare_part_units_to_pcs.php');
        $migration->up();

        $part->refresh();
        $this->assertSame('pcs', $part->unit);
        $this->assertSame(17, (int) $part->stock_quantity);
        $this->assertSame(4, (int) $part->reorder_level);
    }

    public function test_inventory_reports_group_and_display_pcs(): void
    {
        [$shop, $admin] = $this->createShopUser();
        SparePart::create([
            'shop_id' => $shop->id,
            'name' => 'Report Part',
            'unit' => 'pcs',
            'stock_quantity' => 9,
            'reorder_level' => 1,
            'unit_cost' => 3,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/reports')
            ->assertOk();

        $summary = collect($response->json('inventoryReport.summary.stock_quantity_by_unit'));
        $this->assertSame(9, (int) $summary->firstWhere('unit', 'pcs')['quantity']);
        $this->assertSame('pcs', $response->json('inventoryReport.items.0.unit'));
    }

    public function test_inventory_search_matches_name_code_category_and_specifications_with_shop_isolation(): void
    {
        [$shop, $admin] = $this->createShopUser();
        $part = SparePart::create([
            'shop_id' => $shop->id,
            'name' => 'Flex Cable Assembly',
            'part_number' => 'FLEX-4821',
            'category' => 'Display Parts',
            'unit' => 'pcs',
            'stock_quantity' => 6,
            'reorder_level' => 1,
            'unit_cost' => 0,
            'description' => 'Brand Acme compatible with Model X200 AMOLED specification',
            'is_active' => true,
        ]);
        [$otherShop] = $this->createShopUser();
        SparePart::create([
            'shop_id' => $otherShop->id,
            'name' => 'Other Shop Flex Cable',
            'part_number' => 'OTHER-FLEX-4821',
            'category' => 'Display Parts',
            'unit' => 'pcs',
            'stock_quantity' => 8,
            'reorder_level' => 1,
            'unit_cost' => 0,
            'description' => 'Brand Acme compatible with Model X200 AMOLED specification',
            'is_active' => true,
        ]);

        foreach (['Flex Cable', 'FLEX-4821', 'Display Parts', 'Acme', 'Model X200', 'AMOLED specification'] as $term) {
            $response = $this->actingAs($admin)
                ->getJson('/api/admin/inventory?' . http_build_query([
                    'search' => $term,
                    'active_only' => 1,
                    'per_page' => 10,
                ]))
                ->assertOk()
                ->assertJsonCount(1, 'items.data');

            $this->assertSame($part->id, $response->json('items.data.0.id'));
        }
    }

    private function createShopUser(): array
    {
        $shop = Shop::create([
            'name' => 'Inventory Test Shop',
            'slug' => 'inventory-test-shop-' . uniqid(),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        return [$shop, $user];
    }
}
