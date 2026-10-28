<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\JobOrderPartOrder;
use App\Models\JobOrderPayment;
use App\Models\JobOrderRefund;
use App\Models\PartRequest;
use App\Models\Shop;
use App\Models\SparePart;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_column_metadata_aliases_are_available_as_stable_object_properties(): void
    {
        $types = DB::query()
            ->selectRaw('? AS fk_column_name, ? AS fk_column_type', ['shop_id', 'bigint unsigned'])
            ->get()
            ->mapWithKeys(fn (object $metadata) => [
                $metadata->fk_column_name => $metadata->fk_column_type,
            ]);

        $this->assertSame('bigint unsigned', $types->get('shop_id'));
    }

    public function test_technician_can_create_a_general_request_without_changing_job_status(): void
    {
        [$shop, $technicianUser, $jobOrder] = $this->createTechnicianWithJob();

        $response = $this->actingAs($technicianUser)->postJson('/api/technician/part-requests', [
            'part_name' => 'LCD display',
            'quantity' => 2,
            'note' => 'Restock for upcoming repairs.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('request.part_name', 'LCD display')
            ->assertJsonPath('request.status', PartRequest::STATUS_PENDING)
            ->assertJsonPath('request.job_order', null);

        $this->assertDatabaseHas('part_requests', [
            'shop_id' => $shop->id,
            'requested_by_user_id' => $technicianUser->id,
            'job_order_id' => null,
            'part_name' => 'LCD display',
            'quantity' => 2,
            'status' => PartRequest::STATUS_PENDING,
        ]);
        $this->assertSame('IN_PROGRESS', $jobOrder->fresh()->current_status);
        $this->assertDatabaseMissing('status_histories', [
            'job_order_id' => $jobOrder->id,
            'status' => 'WAITING_PARTS',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $technicianUser->id,
            'action' => 'part_request.created',
            'record_type' => 'PartRequest',
        ]);
    }

    public function test_replaying_a_general_request_with_the_same_key_returns_the_original_request(): void
    {
        [$shop, $technicianUser] = $this->createTechnicianWithJob();
        $payload = [
            'part_name' => 'LCD display',
            'quantity' => 2,
            'note' => 'Restock for upcoming repairs.',
            'idempotency_key' => '11111111-1111-4111-8111-111111111111',
        ];

        $first = $this->actingAs($technicianUser)
            ->postJson('/api/technician/part-requests', $payload)
            ->assertCreated();
        $second = $this->actingAs($technicianUser)
            ->postJson('/api/technician/part-requests', $payload)
            ->assertOk();

        $this->assertSame($first->json('request.id'), $second->json('request.id'));
        $this->assertDatabaseCount('part_requests', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('part_requests', [
            'shop_id' => $shop->id,
            'idempotency_key' => $payload['idempotency_key'],
        ]);
    }

    public function test_reusing_a_general_request_key_with_changed_data_returns_conflict(): void
    {
        [, $technicianUser] = $this->createTechnicianWithJob();
        $key = '22222222-2222-4222-8222-222222222222';

        $this->actingAs($technicianUser)
            ->postJson('/api/technician/part-requests', [
                'part_name' => 'Battery',
                'quantity' => 1,
                'idempotency_key' => $key,
            ])
            ->assertCreated();

        $this->actingAs($technicianUser)
            ->postJson('/api/technician/part-requests', [
                'part_name' => 'Battery',
                'quantity' => 2,
                'idempotency_key' => $key,
            ])
            ->assertStatus(409);

        $this->assertDatabaseCount('part_requests', 1);
    }

    public function test_the_same_idempotency_key_can_be_used_by_different_shops(): void
    {
        [$shopA, $technicianA] = $this->createTechnicianWithJob();
        [$shopB, $technicianB] = $this->createTechnicianWithJob();
        $key = '33333333-3333-4333-8333-333333333333';

        foreach ([[$technicianA, $shopA], [$technicianB, $shopB]] as [$technician, $shop]) {
            $this->actingAs($technician)
                ->postJson('/api/technician/part-requests', [
                    'part_name' => 'Battery',
                    'quantity' => 1,
                    'idempotency_key' => $key,
                ])
                ->assertCreated();
        }

        $this->assertDatabaseCount('part_requests', 2);
        $this->assertDatabaseHas('part_requests', ['shop_id' => $shopA->id, 'idempotency_key' => $key]);
        $this->assertDatabaseHas('part_requests', ['shop_id' => $shopB->id, 'idempotency_key' => $key]);
    }

    public function test_job_blocking_part_report_is_a_distinct_explicit_job_linked_action(): void
    {
        [$shop, $technicianUser, $jobOrder] = $this->createTechnicianWithJob();

        $response = $this->actingAs($technicianUser)->postJson(
            "/api/technician/job-orders/{$jobOrder->id}/blocked-part-requests",
            [
                'part_name' => 'LCD display',
                'quantity' => 1,
                'note' => 'Repair cannot proceed without this part.',
            ]
        );

        $response->assertCreated()
            ->assertJsonPath('job.current_status', 'WAITING_PARTS');
        $this->assertDatabaseHas('status_histories', [
            'job_order_id' => $jobOrder->id,
            'status' => 'WAITING_PARTS',
            'note' => 'Parts requested: LCD display (Qty 1). Note: Repair cannot proceed without this part.',
        ]);
        $this->assertDatabaseCount('part_requests', 0);

        [, $shopAdmin] = $this->createShopUser(User::ROLE_SHOP_ADMIN, $shop);
        $this->actingAs($shopAdmin)
            ->getJson('/api/admin/blocked-job-requests')
            ->assertOk()
            ->assertJsonPath('requests.0.job_id', $jobOrder->id)
            ->assertJsonPath('requests.0.requested_part', 'LCD display');
    }

    public function test_request_remains_visible_after_unrelated_job_status_changes(): void
    {
        [$shop, $technicianUser, $jobOrder] = $this->createTechnicianWithJob();
        $partRequest = PartRequest::create([
            'shop_id' => $shop->id,
            'requested_by_user_id' => $technicianUser->id,
            'part_name' => 'LCD display',
            'quantity' => 1,
            'status' => PartRequest::STATUS_PENDING,
        ]);

        $jobOrder->update(['current_status' => 'READY_FOR_PICKUP']);

        [, $shopAdmin] = $this->createShopUser(User::ROLE_SHOP_ADMIN, $shop);
        $this->actingAs($shopAdmin)
            ->getJson('/api/admin/part-requests')
            ->assertOk()
            ->assertJsonPath('requests.0.id', $partRequest->id)
            ->assertJsonPath('requests.0.status', PartRequest::STATUS_PENDING)
            ->assertJsonPath('requests.0.job_order', null);
    }

    public function test_part_requests_are_visible_only_to_authorized_users_in_their_shop(): void
    {
        [$shopA, $adminA] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        [$shopB, $adminB] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        $requestA = PartRequest::create([
            'shop_id' => $shopA->id,
            'part_name' => 'Battery',
            'quantity' => 1,
        ]);
        $requestB = PartRequest::create([
            'shop_id' => $shopB->id,
            'part_name' => 'Charging port',
            'quantity' => 2,
        ]);

        $this->actingAs($adminA)
            ->getJson('/api/admin/part-requests')
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.id', $requestA->id);

        $this->patchJson("/api/admin/part-requests/{$requestB->id}/status", [
            'status' => PartRequest::STATUS_APPROVED,
        ])->assertNotFound();

        $this->actingAs($adminB)
            ->getJson('/api/admin/part-requests')
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.id', $requestB->id);
    }

    public function test_only_staff_and_admins_can_access_the_for_order_queue(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        PartRequest::create([
            'shop_id' => $shop->id,
            'part_name' => 'Camera module',
            'quantity' => 1,
        ]);

        $this->actingAs($staff)
            ->getJson('/api/admin/part-requests')
            ->assertOk()
            ->assertJsonCount(1, 'requests');

        [, $technicianUser] = $this->createTechnicianWithJob();
        $this->actingAs($technicianUser)
            ->getJson('/api/admin/part-requests')
            ->assertForbidden();

        $this->actingAs($technicianUser)
            ->postJson('/api/admin/part-requests', [
                'part_name' => 'Battery',
                'quantity' => 1,
            ])
            ->assertForbidden();
    }

    public function test_for_order_returns_a_deduplicated_reusable_checklist_and_allows_repeat_orders(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        SparePart::create([
            'shop_id' => $shop->id,
            'name' => 'Battery',
            'stock_quantity' => 2,
            'reorder_level' => 1,
            'is_active' => true,
        ]);
        PartRequest::create([
            'shop_id' => $shop->id,
            'part_name' => 'battery',
            'quantity' => 1,
            'status' => PartRequest::STATUS_RECEIVED,
        ]);
        PartRequest::create([
            'shop_id' => $shop->id,
            'part_name' => 'USB-C Port',
            'quantity' => 2,
            'status' => PartRequest::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/part-requests')
            ->assertOk()
            ->assertJsonPath('checklist.0.part_name', 'Battery')
            ->assertJsonPath('checklist.1.part_name', 'USB-C Port');

        $this->actingAs($admin)
            ->postJson('/api/admin/part-requests', [
                'part_name' => 'Battery',
                'quantity' => 3,
                'register_part' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('part_name');

        $repeat = $this->actingAs($admin)
            ->postJson('/api/admin/part-requests', [
                'part_name' => 'Battery',
                'quantity' => 4,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('part_requests', [
            'id' => $repeat->json('request.id'),
            'part_name' => 'Battery',
            'quantity' => 4,
            'status' => PartRequest::STATUS_PENDING,
        ]);
    }

    public function test_shop_can_create_a_checklist_order_directly_as_ordered(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/part-requests', [
                'part_name' => 'Back Glass - iPhone 11',
                'quantity' => 3,
                'status' => PartRequest::STATUS_ORDERED,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertCreated()
            ->assertJsonPath('request.status', PartRequest::STATUS_ORDERED);

        $this->assertDatabaseHas('part_requests', [
            'id' => $response->json('request.id'),
            'shop_id' => $shop->id,
            'part_name' => 'Back Glass - iPhone 11',
            'quantity' => 3,
            'status' => PartRequest::STATUS_ORDERED,
        ]);
    }

    public function test_staff_can_edit_pending_for_order_details_but_ordered_and_received_items_are_locked(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $request = PartRequest::create([
            'shop_id' => $shop->id,
            'part_name' => 'Old battery',
            'quantity' => 1,
            'note' => 'Old note',
            'status' => PartRequest::STATUS_PENDING,
        ]);

        $this->actingAs($staff)
            ->putJson("/api/admin/part-requests/{$request->id}", [
                'part_name' => 'New battery',
                'quantity' => 3,
                'note' => 'Updated note',
            ])
            ->assertOk()
            ->assertJsonPath('request.part_name', 'New battery')
            ->assertJsonPath('request.quantity', 3)
            ->assertJsonPath('request.status', PartRequest::STATUS_PENDING);

        $request->update(['status' => PartRequest::STATUS_ORDERED]);

        $this->actingAs($staff)
            ->putJson("/api/admin/part-requests/{$request->id}", [
                'part_name' => 'Unsafe update',
                'quantity' => 4,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('part');
    }

    public function test_general_request_receiving_updates_inventory_once_and_cannot_be_marked_received_directly(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        $partRequest = PartRequest::create([
            'shop_id' => $shop->id,
            'part_name' => 'LCD display',
            'quantity' => 3,
        ]);
        $inventoryPart = SparePart::create([
            'shop_id' => $shop->id,
            'name' => 'LCD display',
            'stock_quantity' => 0,
            'reorder_level' => 2,
            'unit_cost' => 100,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/part-requests/{$partRequest->id}/status", [
                'status' => PartRequest::STATUS_APPROVED,
            ])->assertOk();
        $this->patchJson("/api/admin/part-requests/{$partRequest->id}/status", [
            'status' => PartRequest::STATUS_ORDERED,
        ])->assertOk();
        $this->patchJson("/api/admin/part-requests/{$partRequest->id}/status", [
            'status' => PartRequest::STATUS_RECEIVED,
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertSame(0, (int) $inventoryPart->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('part_requests', [
            'id' => $partRequest->id,
            'status' => PartRequest::STATUS_ORDERED,
            'updated_by_user_id' => $admin->id,
        ]);

        $receivePath = "/api/admin/part-requests/{$partRequest->id}/receive";
        $this->patchJson($receivePath, ['spare_part_id' => $inventoryPart->id])->assertOk();
        $this->patchJson($receivePath, ['spare_part_id' => $inventoryPart->id])->assertOk();

        $this->assertSame(3, (int) $inventoryPart->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'shop_id' => $shop->id,
            'spare_part_id' => $inventoryPart->id,
            'type' => 'IN',
            'quantity_change' => 3,
            'balance_after' => 3,
        ]);
    }

    public function test_shop_users_can_create_general_requests_and_cannot_receive_another_shops_request(): void
    {
        [$shopA, $adminA] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        [$shopB, $adminB] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        $inventoryPart = SparePart::create([
            'shop_id' => $shopB->id,
            'name' => 'Battery',
            'stock_quantity' => 0,
            'reorder_level' => 1,
            'unit_cost' => 100,
            'is_active' => true,
        ]);
        $partRequest = PartRequest::create([
            'shop_id' => $shopB->id,
            'part_name' => 'Battery',
            'quantity' => 2,
            'status' => PartRequest::STATUS_ORDERED,
        ]);

        $created = $this->actingAs($adminA)->postJson('/api/admin/part-requests', [
            'part_name' => 'USB-C port',
            'quantity' => 4,
            'note' => 'Shop stock.',
        ])->assertCreated()
            ->assertJsonPath('request.shop_id', $shopA->id)
            ->assertJsonPath('request.job_order', null);
        [, $staffA] = $this->createShopUser(User::ROLE_STAFF, $shopA);
        $this->actingAs($staffA)->postJson('/api/admin/part-requests', [
            'part_name' => 'Charging cable',
            'quantity' => 2,
        ])->assertCreated()->assertJsonPath('request.shop_id', $shopA->id);
        $this->postJson('/api/admin/part-requests', [
            'part_name' => 'Battery',
            'quantity' => 1,
            'job_order_id' => 12345,
        ])->assertUnprocessable()->assertJsonValidationErrors('job_order_id');

        $this->assertDatabaseHas('part_requests', [
            'shop_id' => $shopA->id,
            'part_name' => 'USB-C port',
            'job_order_id' => null,
        ]);
        $this->assertDatabaseHas('part_requests', [
            'shop_id' => $shopA->id,
            'part_name' => 'Charging cable',
            'job_order_id' => null,
        ]);

        $this->patchJson("/api/admin/part-requests/{$partRequest->id}/receive", [
            'spare_part_id' => $inventoryPart->id,
        ])->assertNotFound();
        $this->assertSame(0, (int) $inventoryPart->fresh()->stock_quantity);

        $this->actingAs($adminB)->patchJson("/api/admin/part-requests/{$partRequest->id}/receive", [
            'spare_part_id' => $inventoryPart->id,
        ])->assertOk();
        $this->assertSame(2, (int) $inventoryPart->fresh()->stock_quantity);
        $this->assertNotNull($created->json('request.id'));
    }

    public function test_general_request_receiving_rejects_an_inventory_item_with_a_different_name(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        $partRequest = PartRequest::create([
            'shop_id' => $shop->id,
            'part_name' => 'LCD display',
            'quantity' => 1,
            'status' => PartRequest::STATUS_ORDERED,
        ]);
        $otherPart = SparePart::create([
            'shop_id' => $shop->id,
            'name' => 'Battery',
            'stock_quantity' => 0,
            'reorder_level' => 1,
            'unit_cost' => 100,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->patchJson("/api/admin/part-requests/{$partRequest->id}/receive", [
            'spare_part_id' => $otherPart->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('spare_part_id');

        $this->assertSame(0, (int) $otherPart->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_invalid_status_transition_is_rejected_and_audited_updates_are_scoped(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        $partRequest = PartRequest::create([
            'shop_id' => $shop->id,
            'part_name' => 'Power button',
            'quantity' => 1,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/part-requests/{$partRequest->id}/status", [
                'status' => PartRequest::STATUS_RECEIVED,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->patchJson("/api/admin/part-requests/{$partRequest->id}/status", [
            'status' => PartRequest::STATUS_APPROVED,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'part_request.status_updated',
            'record_type' => 'PartRequest',
            'record_id' => $partRequest->id,
        ]);
    }

    public function test_general_requests_can_only_be_created_by_an_active_shop_technician(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $this->actingAs($staff)
            ->postJson('/api/technician/part-requests', [
                'part_name' => 'Battery',
                'quantity' => 1,
            ])
            ->assertForbidden();

        [, $technicianUser] = $this->createTechnicianWithJob();
        $this->actingAs($technicianUser)
            ->postJson('/api/technician/part-requests', [
                'part_name' => 'Battery',
                'quantity' => 1,
                'job_order_id' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_order_id');

        $this->assertDatabaseCount('part_requests', 0);
    }

    public function test_technician_can_create_a_customer_order_linked_to_an_assigned_job_without_changing_its_status(): void
    {
        [$shop, $technician, $jobOrder] = $this->createTechnicianWithJob();

        $response = $this->actingAs($technician)
            ->postJson("/api/technician/job-orders/{$jobOrder->id}/part-orders", [
                'part_name' => 'Laptop LCD',
                'quantity' => 1,
                'reason' => 'LCD panel is damaged and no compatible stock is available.',
                'estimated_unit_price' => 8500,
            ])
            ->assertCreated()
            ->assertJsonPath('order.job_order_id', $jobOrder->id)
            ->assertJsonPath('order.customer_decision', JobOrderPartOrder::DECISION_PENDING)
            ->assertJsonPath('order.status', JobOrderPartOrder::STATUS_AWAITING_APPROVAL);
        $orderId = $response->json('order.id');

        $this->assertDatabaseHas('job_order_part_orders', [
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'requested_by_user_id' => $technician->id,
            'part_name' => 'Laptop LCD',
            'quantity' => 1,
            'status' => JobOrderPartOrder::STATUS_AWAITING_APPROVAL,
        ]);
        $this->assertSame('IN_PROGRESS', $jobOrder->fresh()->current_status);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $technician->id,
            'action' => 'job_order_part_order.created',
            'record_type' => 'JobOrderPartOrder',
        ]);
        $this->createCustomerPartOrder($shop, $jobOrder, $technician, [
            'part_name' => 'Battery',
            'reason' => 'Battery is swollen and requires replacement.',
            'estimated_unit_price' => 120,
        ]);
        $this->assertSame(2, JobOrderPartOrder::query()->where('job_order_id', $jobOrder->id)->count());

        $jobOrder->update(['current_status' => 'READY_FOR_PICKUP']);
        [, $shopAdmin] = $this->createShopUser(User::ROLE_SHOP_ADMIN, $shop);
        $this->actingAs($shopAdmin)
            ->getJson('/api/admin/job-order-part-orders')
            ->assertOk()
            ->assertJsonCount(2, 'orders')
            ->assertJsonFragment(['id' => $orderId, 'job_order_number' => $jobOrder->job_order_number]);
    }

    public function test_replaying_a_job_part_order_with_the_same_key_returns_the_original_order(): void
    {
        [$shop, $technician, $jobOrder] = $this->createTechnicianWithJob();
        $payload = [
            'part_name' => 'Laptop LCD',
            'quantity' => 1,
            'reason' => 'LCD panel replacement.',
            'estimated_unit_price' => 8500,
            'idempotency_key' => '44444444-4444-4444-8444-444444444444',
        ];

        $first = $this->actingAs($technician)
            ->postJson("/api/technician/job-orders/{$jobOrder->id}/part-orders", $payload)
            ->assertCreated();
        $second = $this->actingAs($technician)
            ->postJson("/api/technician/job-orders/{$jobOrder->id}/part-orders", $payload)
            ->assertOk();

        $this->assertSame($first->json('order.id'), $second->json('order.id'));
        $this->assertDatabaseCount('job_order_part_orders', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('job_order_part_orders', [
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'idempotency_key' => $payload['idempotency_key'],
        ]);
    }

    public function test_equivalent_price_formats_replay_the_original_job_part_order(): void
    {
        [$shop, $technician, $jobOrder] = $this->createTechnicianWithJob();
        $key = '55555555-5555-4555-8555-555555555555';
        $basePayload = [
            'part_name' => 'Laptop LCD',
            'quantity' => 1,
            'reason' => 'LCD panel replacement.',
            'idempotency_key' => $key,
        ];

        $first = $this->actingAs($technician)
            ->postJson("/api/technician/job-orders/{$jobOrder->id}/part-orders", [
                ...$basePayload,
                'estimated_unit_price' => '8500',
            ])
            ->assertCreated();
        $second = $this->actingAs($technician)
            ->postJson("/api/technician/job-orders/{$jobOrder->id}/part-orders", [
                ...$basePayload,
                'estimated_unit_price' => '8500.00',
            ])
            ->assertOk();

        $this->assertSame($first->json('order.id'), $second->json('order.id'));
        $this->assertSame(1, JobOrderPartOrder::where('shop_id', $shop->id)->where('idempotency_key', $key)->count());
    }

    public function test_shop_admin_and_staff_can_create_customer_orders_for_an_existing_job_with_decision_payment_and_location(): void
    {
        [$shop, , $jobOrder] = $this->createTechnicianWithJob();
        [, $shopAdmin] = $this->createShopUser(User::ROLE_SHOP_ADMIN, $shop);
        [, $staff] = $this->createShopUser(User::ROLE_STAFF, $shop);
        $this->recordEligiblePayment($shop, $jobOrder, 30, 'STAFF-CREATE-DOWNPAYMENT');

        $this->actingAs($shopAdmin)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/part-orders", [
                'shop_id' => $shop->id + 1000,
                'part_name' => 'Display assembly',
                'quantity' => 1,
                'reason' => 'The damaged display needs replacement.',
                'estimated_unit_price' => 100,
                'customer_decision' => JobOrderPartOrder::DECISION_PENDING,
                'device_location' => 'WITH_CUSTOMER',
                'device_location_note' => 'Customer is temporarily keeping the device.',
            ])
            ->assertCreated()
            ->assertJsonPath('order.job_order_id', $jobOrder->id)
            ->assertJsonPath('order.status', JobOrderPartOrder::STATUS_AWAITING_APPROVAL)
            ->assertJsonPath('order.device_location', 'WITH_CUSTOMER')
            ->assertJsonPath('order.device_location_updated_by', $shopAdmin->name);

        $this->assertDatabaseHas('job_order_part_orders', [
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'requested_by_user_id' => $shopAdmin->id,
            'customer_decision' => JobOrderPartOrder::DECISION_PENDING,
        ]);
        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'shop_id' => $shop->id,
            'device_location' => 'WITH_CUSTOMER',
            'device_location_updated_by_user_id' => $shopAdmin->id,
        ]);
        $this->assertNotNull($jobOrder->fresh()->device_location_updated_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $shopAdmin->id,
            'action' => 'job_order.device_location_updated',
            'record_id' => $jobOrder->id,
        ]);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/part-orders", [
                'part_name' => 'Display assembly',
                'quantity' => 1,
                'reason' => 'The customer approved this replacement.',
                'estimated_unit_price' => 100,
                'customer_decision' => JobOrderPartOrder::DECISION_APPROVED,
                'customer_decision_note' => 'Customer approved by phone; staff recorded the decision.',
                'required_downpayment_amount' => 25,
                'device_location' => 'AT_SHOP',
            ])
            ->assertCreated()
            ->assertJsonPath('order.customer_decision', JobOrderPartOrder::DECISION_APPROVED)
            ->assertJsonPath('order.customer_decision_recorded_by', $staff->name)
            ->assertJsonPath('order.status', JobOrderPartOrder::STATUS_READY_TO_ORDER)
            ->assertJsonPath('order.eligible_paid_amount', '30.00')
            ->assertJsonPath('order.remaining_downpayment_amount', '0.00');

        $approvedOrder = JobOrderPartOrder::query()
            ->where('job_order_id', $jobOrder->id)
            ->where('customer_decision', JobOrderPartOrder::DECISION_APPROVED)
            ->firstOrFail();
        $this->assertSame($staff->id, $approvedOrder->customer_decision_recorded_by_user_id);
        $this->assertSame('IN_PROGRESS', $jobOrder->fresh()->current_status);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $staff->id,
            'action' => 'job_order_part_order.customer_decision_recorded',
            'record_id' => $approvedOrder->id,
        ]);
    }

    public function test_staff_creation_requires_decision_evidence_and_downpayment_and_declines_are_terminal(): void
    {
        [$shop, , $jobOrder] = $this->createTechnicianWithJob();
        [, $staff] = $this->createShopUser(User::ROLE_STAFF, $shop);
        $url = "/api/admin/job-orders/{$jobOrder->id}/part-orders";
        $payload = [
            'part_name' => 'Charging port',
            'quantity' => 1,
            'reason' => 'The charging port is damaged.',
            'estimated_unit_price' => 50,
            'customer_decision' => JobOrderPartOrder::DECISION_APPROVED,
            'device_location' => 'AT_SHOP',
        ];

        $this->actingAs($staff)
            ->postJson($url, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['required_downpayment_amount', 'customer_decision_note']);
        $this->assertDatabaseCount('job_order_part_orders', 0);

        $this->postJson($url, [
            ...$payload,
            'customer_decision' => JobOrderPartOrder::DECISION_REJECTED,
            'customer_decision_note' => 'Customer declined the estimate by phone.',
        ])->assertCreated()
            ->assertJsonPath('order.customer_decision', JobOrderPartOrder::DECISION_REJECTED)
            ->assertJsonPath('order.display_status', 'declined')
            ->assertJsonPath('order.status', JobOrderPartOrder::STATUS_CANCELLED);

        $this->assertSame('IN_PROGRESS', $jobOrder->fresh()->current_status);
    }

    public function test_shop_staff_cannot_create_a_customer_parts_order_for_another_shop_job(): void
    {
        [, $staff] = $this->createShopUser(User::ROLE_STAFF);
        [, , $jobOrder] = $this->createTechnicianWithJob();

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/part-orders", [
                'part_name' => 'Battery',
                'quantity' => 1,
                'reason' => 'Required for the repair.',
                'estimated_unit_price' => 20,
                'customer_decision' => JobOrderPartOrder::DECISION_PENDING,
                'device_location' => 'AT_SHOP',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('job_order_part_orders', 0);
    }

    public function test_customer_parts_order_cannot_be_placed_without_explicit_approval_or_required_downpayment(): void
    {
        [$shop, $technician, $jobOrder] = $this->createTechnicianWithJob();
        [, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN, $shop);
        $order = $this->createCustomerPartOrder($shop, $jobOrder, $technician);

        $this->actingAs($admin)
            ->patchJson("/api/admin/job-order-part-orders/{$order->id}/place-order", [
                'supplier_name' => 'Panel Supplier',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->patchJson("/api/admin/job-order-part-orders/{$order->id}/customer-decision", [
            'decision' => JobOrderPartOrder::DECISION_APPROVED,
            'required_downpayment_amount' => 50,
            'customer_decision_note' => 'Customer approved by phone.',
        ])->assertOk()
            ->assertJsonPath('order.status', JobOrderPartOrder::STATUS_AWAITING_DOWNPAYMENT)
            ->assertJsonPath('order.remaining_downpayment_amount', '50.00');

        $this->patchJson("/api/admin/job-order-part-orders/{$order->id}/place-order", [
            'supplier_name' => 'Panel Supplier',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('required_downpayment_amount');

        $this->assertSame(JobOrderPartOrder::STATUS_AWAITING_DOWNPAYMENT, $order->fresh()->status);
    }

    public function test_unrecorded_and_refunded_payments_do_not_satisfy_the_parts_order_downpayment(): void
    {
        [$shop, $technician, $jobOrder] = $this->createTechnicianWithJob();
        [, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN, $shop);
        $jobOrder->update(['amount_paid' => 1000]);
        $order = $this->createCustomerPartOrder($shop, $jobOrder, $technician, [
            'estimated_unit_price' => 100,
        ]);

        $this->actingAs($admin)->patchJson("/api/admin/job-order-part-orders/{$order->id}/customer-decision", [
            'decision' => JobOrderPartOrder::DECISION_APPROVED,
            'required_downpayment_amount' => 50,
            'customer_decision_note' => 'Customer approval noted by staff.',
        ])->assertOk()
            ->assertJsonPath('order.eligible_paid_amount', '0.00');

        foreach ([
            ['amount' => 25, 'status' => JobOrderRefund::STATUS_PENDING, 'key' => 'PARTS-ORDER-PENDING-REFUND'],
            ['amount' => 50, 'status' => JobOrderRefund::STATUS_ISSUED, 'key' => 'PARTS-ORDER-ISSUED-REFUND'],
        ] as $refundData) {
            $payment = $this->recordEligiblePayment($shop, $jobOrder, $refundData['amount'], $refundData['key']);
            JobOrderRefund::create([
                'shop_id' => $shop->id,
                'job_order_id' => $jobOrder->id,
                'job_order_payment_id' => $payment->id,
                'amount' => $refundData['amount'],
                'status' => $refundData['status'],
                'reason' => 'Refund obligation',
                'initiated_at' => now(),
                'issued_at' => $refundData['status'] === JobOrderRefund::STATUS_ISSUED ? now() : null,
            ]);
        }

        $this->patchJson("/api/admin/job-order-part-orders/{$order->id}/place-order", [
            'supplier_name' => 'Panel Supplier',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('required_downpayment_amount');

        $this->assertSame('IN_PROGRESS', $jobOrder->fresh()->current_status);
    }

    public function test_customer_approval_and_sufficient_payment_are_both_required_before_supplier_order(): void
    {
        [$shop, $technician, $jobOrder] = $this->createTechnicianWithJob();
        [, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN, $shop);
        $order = $this->createCustomerPartOrder($shop, $jobOrder, $technician);
        $this->recordEligiblePayment($shop, $jobOrder, 75, 'PARTS-ORDER-ELIGIBLE');

        $this->actingAs($admin)->patchJson("/api/admin/job-order-part-orders/{$order->id}/customer-decision", [
            'decision' => JobOrderPartOrder::DECISION_APPROVED,
            'required_downpayment_amount' => 50,
            'customer_decision_note' => 'Customer approval noted by staff.',
        ])->assertOk()
            ->assertJsonPath('order.status', JobOrderPartOrder::STATUS_READY_TO_ORDER)
            ->assertJsonPath('order.can_order', true);

        $this->patchJson("/api/admin/job-order-part-orders/{$order->id}/place-order", [
            'supplier_name' => 'Panel Supplier',
            'purchase_reference' => 'PO-1001',
        ])->assertOk()
            ->assertJsonPath('order.status', JobOrderPartOrder::STATUS_ORDERED);

        $this->assertSame('IN_PROGRESS', $jobOrder->fresh()->current_status);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'job_order_part_order.ordered',
            'record_id' => $order->id,
        ]);
    }

    public function test_receiving_is_shop_scoped_idempotent_and_does_not_complete_the_repair(): void
    {
        [$shop, $technician, $jobOrder] = $this->createTechnicianWithJob();
        [, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN, $shop);
        $order = $this->createOrderReadyToReceive($shop, $jobOrder, $technician, $admin);
        $inventoryPart = SparePart::create([
            'shop_id' => $shop->id,
            'name' => 'Laptop LCD',
            'stock_quantity' => 0,
            'reorder_level' => 1,
            'unit_cost' => 7000,
            'is_active' => true,
        ]);
        $wrongInventoryPart = SparePart::create([
            'shop_id' => $shop->id,
            'name' => 'Wrong display assembly',
            'stock_quantity' => 0,
            'reorder_level' => 1,
            'unit_cost' => 4000,
            'is_active' => true,
        ]);

        $receiveUrl = "/api/admin/job-order-part-orders/{$order->id}/receive";
        $this->actingAs($admin)->patchJson($receiveUrl, [
            'spare_part_id' => $wrongInventoryPart->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('spare_part_id');
        $this->patchJson($receiveUrl, [
            'spare_part_id' => $inventoryPart->id,
        ])->assertOk()
            ->assertJsonPath('order.status', JobOrderPartOrder::STATUS_RECEIVED);

        $this->patchJson($receiveUrl, [
            'spare_part_id' => $inventoryPart->id,
        ])->assertOk();

        $this->assertSame(1, (int) $inventoryPart->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('IN_PROGRESS', $jobOrder->fresh()->current_status);

        $this->patchJson("/api/technician/job-orders/{$jobOrder->id}/device-location", [
            'device_location' => 'WITH_CUSTOMER',
        ])->assertOk();
        $this->actingAs($technician)
            ->patchJson("/api/technician/job-orders/{$jobOrder->id}/part-orders/{$order->id}/issue")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('device_location');

        $this->patchJson("/api/technician/job-orders/{$jobOrder->id}/device-location", [
            'device_location' => 'AT_SHOP',
        ])->assertOk();
        $issueResponse = $this->patchJson("/api/technician/job-orders/{$jobOrder->id}/part-orders/{$order->id}/issue")
            ->assertCreated();
        $this->patchJson("/api/technician/job-orders/{$jobOrder->id}/part-orders/{$order->id}/issue")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('part_order');

        $this->deleteJson("/api/technician/job-orders/{$jobOrder->id}/parts/{$issueResponse->json('part.id')}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('part');
        $this->actingAs($admin)
            ->putJson("/api/admin/job-orders/{$jobOrder->id}/parts/{$issueResponse->json('part.id')}", [
                'part_name' => 'Edited LCD',
                'quantity' => 1,
                'unit_cost' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('part');
        $this->deleteJson("/api/admin/job-orders/{$jobOrder->id}/parts/{$issueResponse->json('part.id')}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('part');
        $this->assertDatabaseHas('job_order_parts', [
            'job_order_id' => $jobOrder->id,
            'job_order_part_order_id' => $order->id,
        ]);
        $this->assertSame(0, (int) $inventoryPart->fresh()->stock_quantity);
        $this->assertSame('IN_PROGRESS', $jobOrder->fresh()->current_status);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->actingAs($admin)
            ->getJson('/api/admin/job-order-part-orders')
            ->assertOk()
            ->assertJsonPath('orders.0.display_status', 'issued_to_job_order');
    }

    public function test_rejected_orders_are_not_placeable_and_cannot_be_cancelled_after_supplier_order(): void
    {
        [$shop, $technician, $jobOrder] = $this->createTechnicianWithJob();
        [, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN, $shop);
        $rejected = $this->createCustomerPartOrder($shop, $jobOrder, $technician);
        $this->actingAs($admin)->patchJson("/api/admin/job-order-part-orders/{$rejected->id}/customer-decision", [
            'decision' => JobOrderPartOrder::DECISION_REJECTED,
            'customer_decision_note' => 'Customer declined by phone.',
        ])->assertOk()
            ->assertJsonPath('order.status', JobOrderPartOrder::STATUS_CANCELLED);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'job_order_part_order.customer_decision_recorded',
            'record_id' => $rejected->id,
        ]);
        $this->patchJson("/api/admin/job-order-part-orders/{$rejected->id}/place-order", [
            'supplier_name' => 'Supplier',
        ])->assertUnprocessable();

        $ready = $this->createOrderReadyToReceive($shop, $jobOrder, $technician, $admin);
        $this->patchJson("/api/admin/job-order-part-orders/{$ready->id}/cancel", [
            'cancellation_reason' => 'Changed customer plan.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_customer_parts_queue_and_mutations_are_isolated_by_shop_and_role(): void
    {
        [$shopA, $technicianA, $jobA] = $this->createTechnicianWithJob();
        [$shopB, $technicianB, $jobB] = $this->createTechnicianWithJob();
        [, $adminA] = $this->createShopUser(User::ROLE_SHOP_ADMIN, $shopA);
        $orderA = $this->createCustomerPartOrder($shopA, $jobA, $technicianA);
        $orderB = $this->createCustomerPartOrder($shopB, $jobB, $technicianB);

        $this->actingAs($adminA)
            ->getJson('/api/admin/job-order-part-orders')
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.id', $orderA->id);
        $this->patchJson("/api/admin/job-order-part-orders/{$orderB->id}/customer-decision", [
            'decision' => JobOrderPartOrder::DECISION_APPROVED,
            'required_downpayment_amount' => 20,
            'customer_decision_note' => 'Unauthorized cross-shop access.',
        ])->assertNotFound();
        $this->actingAs($technicianA)
            ->postJson("/api/technician/job-orders/{$jobB->id}/part-orders", [
                'part_name' => 'Battery',
                'quantity' => 1,
                'reason' => 'Required for the repair.',
                'estimated_unit_price' => 20,
            ])
            ->assertNotFound();
        $this->actingAs($technicianA)
            ->getJson('/api/admin/job-order-part-orders')
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.id', $orderA->id);

        $platformAdmin = User::factory()->create([
            'shop_id' => null,
            'role' => User::ROLE_PLATFORM_ADMIN,
            'is_active' => true,
        ]);
        $this->actingAs($platformAdmin)
            ->getJson('/api/admin/job-order-part-orders')
            ->assertForbidden();
    }

    public function test_technician_parts_order_listing_is_assigned_job_scoped_and_keeps_admin_staff_access(): void
    {
        [$shop, $technicianUser, $assignedJob] = $this->createTechnicianWithJob();
        $assignedOrder = $this->createCustomerPartOrder($shop, $assignedJob, $technicianUser, [
            'customer_decision_note' => 'Private decision note.',
            'required_downpayment_amount' => 50,
            'supplier_name' => 'Private supplier',
            'purchase_reference' => 'SUP-123',
        ]);
        $assignedJob->update(['customer_phone' => '09123456789']);

        $otherTechnician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Another Technician',
            'email' => 'other-parts-tech-' . uniqid() . '@example.test',
            'is_active' => true,
        ]);
        $otherTechnicianJob = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'OTHER-TECH-' . strtoupper(uniqid()),
            'customer_name' => 'Other Technician Customer',
            'customer_phone' => '09222222222',
            'device_type' => 'Phone',
            'problem_description' => 'Other assigned repair.',
            'technician_id' => $otherTechnician->id,
            'current_status' => 'IN_PROGRESS',
        ]);
        $otherTechnicianOrder = $this->createCustomerPartOrder($shop, $otherTechnicianJob, $technicianUser);

        $unassignedJob = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'UNASSIGNED-' . strtoupper(uniqid()),
            'customer_name' => 'Unassigned Customer',
            'device_type' => 'Tablet',
            'problem_description' => 'Unassigned repair.',
            'current_status' => 'IN_PROGRESS',
        ]);
        $unassignedOrder = $this->createCustomerPartOrder($shop, $unassignedJob, $technicianUser);

        [$otherShop, $otherShopTechnician, $otherShopJob] = $this->createTechnicianWithJob();
        $otherShopOrder = $this->createCustomerPartOrder($otherShop, $otherShopJob, $otherShopTechnician);

        $this->actingAs($technicianUser)
            ->getJson('/api/admin/job-order-part-orders')
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.id', $assignedOrder->id)
            ->assertJsonMissingPath('orders.0.customer_contact')
            ->assertJsonMissingPath('orders.0.customer_phone')
            ->assertJsonMissingPath('orders.0.estimated_unit_price')
            ->assertJsonMissingPath('orders.0.customer_decision_note')
            ->assertJsonMissingPath('orders.0.supplier_name')
            ->assertJsonMissingPath('orders.0.required_downpayment_amount')
            ->assertJsonMissingPath('orders.0.eligible_paid_amount')
            ->assertJsonMissingPath('orders.0.can_order')
            ->assertJsonMissingPath('orders.0.purchase_reference')
            ->assertJsonMissing(['id' => $otherTechnicianOrder->id])
            ->assertJsonMissing(['id' => $unassignedOrder->id])
            ->assertJsonMissing(['id' => $otherShopOrder->id]);

        [, $staff] = $this->createShopUser(User::ROLE_STAFF, $shop);
        [, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN, $shop);
        foreach ([$staff, $admin] as $shopUser) {
            $this->actingAs($shopUser)
                ->getJson('/api/admin/job-order-part-orders')
                ->assertOk()
                ->assertJsonCount(3, 'orders')
                ->assertJsonFragment(['id' => $assignedOrder->id])
                ->assertJsonFragment(['id' => $otherTechnicianOrder->id])
                ->assertJsonFragment(['id' => $unassignedOrder->id])
                ->assertJsonMissing(['id' => $otherShopOrder->id])
                ->assertJsonFragment(['customer_phone' => '09123456789'])
                ->assertJsonFragment(['customer_decision_note' => 'Private decision note.'])
                ->assertJsonFragment(['supplier_name' => 'Private supplier']);
        }
    }

    public function test_technician_without_an_active_profile_cannot_list_parts_orders(): void
    {
        [, $technicianUser] = $this->createShopUser(User::ROLE_TECHNICIAN);

        $this->actingAs($technicianUser)
            ->getJson('/api/admin/job-order-part-orders')
            ->assertForbidden()
            ->assertJsonPath('message', 'No active technician profile is linked to this account.');
    }

    public function test_device_location_is_audited_and_existing_public_tracking_does_not_include_parts_orders(): void
    {
        [$shop, $technician, $jobOrder] = $this->createTechnicianWithJob();
        $order = $this->createCustomerPartOrder($shop, $jobOrder, $technician);

        $this->actingAs($technician)->patchJson("/api/technician/job-orders/{$jobOrder->id}/device-location", [
            'device_location' => 'WITH_CUSTOMER',
        ])->assertOk();

        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'device_location' => 'WITH_CUSTOMER',
            'device_location_updated_by_user_id' => $technician->id,
        ]);
        $this->assertNotNull($jobOrder->fresh()->device_location_updated_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $technician->id,
            'action' => 'job_order.device_location_updated',
            'record_id' => $jobOrder->id,
        ]);

        $this->getJson("/api/shops/{$shop->slug}/track/{$jobOrder->job_order_number}")
            ->assertOk()
            ->assertJsonMissing(['part_name' => $order->part_name])
            ->assertJsonMissing(['customer_decision' => JobOrderPartOrder::DECISION_PENDING]);
    }

    private function createCustomerPartOrder(Shop $shop, JobOrder $jobOrder, User $requester, array $overrides = []): JobOrderPartOrder
    {
        return JobOrderPartOrder::create(array_merge([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'requested_by_user_id' => $requester->id,
            'part_name' => 'Laptop LCD',
            'quantity' => 1,
            'reason' => 'Required to complete the repair.',
            'estimated_unit_price' => 100,
            'customer_decision' => JobOrderPartOrder::DECISION_PENDING,
            'status' => JobOrderPartOrder::STATUS_AWAITING_APPROVAL,
        ], $overrides));
    }

    private function recordEligiblePayment(Shop $shop, JobOrder $jobOrder, float $amount, string $idempotencyKey): JobOrderPayment
    {
        return JobOrderPayment::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'idempotency_key' => $idempotencyKey,
            'amount' => $amount,
            'payment_type' => 'PARTIAL_PAYMENT',
            'payment_method' => 'CASH',
            'received_at' => now(),
            'received_by' => 'Test Staff',
        ]);
    }

    private function createOrderReadyToReceive(Shop $shop, JobOrder $jobOrder, User $technician, User $admin): JobOrderPartOrder
    {
        $order = $this->createCustomerPartOrder($shop, $jobOrder, $technician);
        $this->recordEligiblePayment($shop, $jobOrder, 100, 'READY-' . uniqid());
        $this->actingAs($admin)->patchJson("/api/admin/job-order-part-orders/{$order->id}/customer-decision", [
            'decision' => JobOrderPartOrder::DECISION_APPROVED,
            'required_downpayment_amount' => 50,
            'customer_decision_note' => 'Customer approved by phone.',
        ])->assertOk();
        $this->patchJson("/api/admin/job-order-part-orders/{$order->id}/place-order", [
            'supplier_name' => 'Panel Supplier',
        ])->assertOk();

        return $order->fresh();
    }

    private function createShopUser(string $role, ?Shop $shop = null): array
    {
        $shop ??= Shop::create([
            'name' => 'Part Request Shop ' . uniqid(),
            'slug' => 'part-request-' . uniqid(),
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

    private function createTechnicianWithJob(): array
    {
        $shop = Shop::create([
            'name' => 'Part Request Technician Shop ' . uniqid(),
            'slug' => 'part-request-tech-' . uniqid(),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Parts Request Technician',
            'email' => 'parts-tech-' . uniqid() . '@example.test',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'shop_id' => $shop->id,
            'technician_id' => $technician->id,
            'role' => User::ROLE_TECHNICIAN,
            'is_active' => true,
        ]);
        $jobOrder = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'PART-REQUEST-' . strtoupper(uniqid()),
            'customer_name' => 'Internal Test Customer',
            'device_type' => 'Phone',
            'problem_description' => 'Test repair.',
            'technician_id' => $technician->id,
            'current_status' => 'IN_PROGRESS',
        ]);

        return [$shop, $user, $jobOrder];
    }
}
