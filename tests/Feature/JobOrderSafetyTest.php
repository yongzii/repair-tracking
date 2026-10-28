<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\JobOrderPayment;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\Shop;
use App\Models\StatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class JobOrderSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_staff_cannot_release_a_job_order_with_an_unpaid_balance(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createReadyJobOrder($shop);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/mark-picked-up", [
                'picked_up_by' => 'Customer Name',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('picked_up_by');

        $this->assertNull($jobOrder->fresh()->picked_up_at);
    }

    public function test_admin_can_release_an_unpaid_job_order_with_a_recorded_override_reason(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createReadyJobOrder($shop);

        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/mark-picked-up", [
                'picked_up_by' => 'Customer Name',
                'override_unpaid_balance' => true,
                'override_reason' => 'Approved payment arrangement.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Device released.');

        $jobOrder->refresh();
        $this->assertSame('PICKED_UP', $jobOrder->current_status);
        $this->assertSame($admin->name, $jobOrder->pickup_override_by);
        $this->assertSame('Approved payment arrangement.', $jobOrder->pickup_override_reason);
        $this->assertDatabaseHas('status_histories', [
            'job_order_id' => $jobOrder->id,
            'status' => 'PICKED_UP',
            'note' => 'Device released to Customer Name',
        ]);
    }

    public function test_staff_and_admin_can_update_repair_status_without_recorded_customer_approval(): void
    {
        foreach ([User::ROLE_STAFF, User::ROLE_ADMIN] as $role) {
            [$shop, $user] = $this->createShopUser($role);
            $jobOrder = JobOrder::create([
                'shop_id' => $shop->id,
                'job_order_number' => 'TEST-APPROVAL-' . strtoupper($role),
                'customer_name' => 'Customer Name',
                'customer_phone' => '09000000000',
                'device_type' => 'Phone',
                'problem_description' => 'Does not power on.',
                'current_status' => 'DIAGNOSING',
            ]);

            $this->actingAs($user)
                ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-status", [
                    'status' => 'IN_PROGRESS',
                ])
                ->assertOk();

            $this->assertSame('IN_PROGRESS', $jobOrder->fresh()->current_status);

            $this->actingAs($user)
                ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-status", [
                    'status' => 'READY_FOR_PICKUP',
                ])
                ->assertOk();

            $this->assertSame('READY_FOR_PICKUP', $jobOrder->fresh()->current_status);
        }
    }

    public function test_assigned_technician_can_start_repair_without_approval_and_record_it_later(): void
    {
        [$shop, $technicianUser] = $this->createShopUser(User::ROLE_TECHNICIAN);
        $technician = \App\Models\Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Approval Technician',
            'email' => $technicianUser->email,
            'is_active' => true,
        ]);
        $jobOrder = JobOrder::create([
            'shop_id' => $shop->id,
            'technician_id' => $technician->id,
            'job_order_number' => 'TEST-TECH-APPROVAL-001',
            'customer_name' => 'Customer Name',
            'customer_phone' => '09000000000',
            'customer_email' => 'customer@example.test',
            'device_type' => 'Phone',
            'problem_description' => 'Does not power on.',
            'current_status' => 'DIAGNOSING',
            'serial_number' => 'SERIAL-SECRET',
            'internal_notes' => 'Staff-only secret note.',
            'amount_paid' => 500,
            'payment_status' => 'PARTIAL',
            'payment_notes' => 'Private payment note.',
        ]);

        $this->actingAs($technicianUser)
            ->postJson("/api/technician/job-orders/{$jobOrder->id}/update-status", [
                'status' => 'IN_PROGRESS',
                'note' => 'Repair started without an approval record.',
            ])
            ->assertOk();

        $this->assertSame('IN_PROGRESS', $jobOrder->fresh()->current_status);

        $response = $this->actingAs($technicianUser)
            ->postJson("/api/technician/job-orders/{$jobOrder->id}/record-repair-approval", [
                'repair_approved_by_customer' => 'Customer Name',
                'repair_approval_notes' => 'Phone approval; approved estimate PHP 2500.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Customer repair approval recorded.')
            ->assertJsonPath('jobOrder.repair_approved_by_customer', 'Customer Name')
            ->assertJsonPath('jobOrder.repair_approval_recorded_by', $technicianUser->name)
            ->assertJsonStructure([
                'message',
                'jobOrder' => [
                    'repair_approved_at',
                    'repair_approved_by_customer',
                    'repair_approval_recorded_by',
                ],
            ])
            ->assertJsonMissingPath('jobOrder.customer_email')
            ->assertJsonMissingPath('jobOrder.customer_phone')
            ->assertJsonMissingPath('jobOrder.internal_notes')
            ->assertJsonMissingPath('jobOrder.amount_paid')
            ->assertJsonMissingPath('jobOrder.payment_status')
            ->assertJsonMissingPath('jobOrder.payment_notes')
            ->assertJsonMissingPath('jobOrder.serial_number');

        $this->assertNotEmpty($response->json('jobOrder.repair_approved_at'));

        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'repair_approved_by_customer' => 'Customer Name',
            'repair_approval_recorded_by' => $technicianUser->name,
            'repair_approval_notes' => 'Phone approval; approved estimate PHP 2500.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'shop_id' => $shop->id,
            'user_id' => $technicianUser->id,
            'user_role' => User::ROLE_TECHNICIAN,
            'action' => 'job_order.repair_approved',
            'record_id' => $jobOrder->id,
        ]);
    }

    public function test_unassigned_technician_cannot_record_approval_for_another_job(): void
    {
        [$shop, $technicianUser] = $this->createShopUser(User::ROLE_TECHNICIAN);
        $technician = \App\Models\Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Unassigned Technician',
            'email' => $technicianUser->email,
            'is_active' => true,
        ]);
        $jobOrder = JobOrder::create([
            'shop_id' => $shop->id,
            'technician_id' => null,
            'job_order_number' => 'TEST-TECH-UNASSIGNED-001',
            'customer_name' => 'Customer Name',
            'customer_phone' => '09000000000',
            'device_type' => 'Phone',
            'problem_description' => 'Does not power on.',
            'current_status' => 'DIAGNOSING',
        ]);

        $this->actingAs($technicianUser)
            ->postJson("/api/technician/job-orders/{$jobOrder->id}/record-repair-approval", [
                'repair_approved_by_customer' => 'Customer Name',
            ])
            ->assertForbidden();
    }

    public function test_job_order_can_be_created_without_email_or_accessories(): void
    {
        [, $staff] = $this->createShopUser(User::ROLE_STAFF);

        $this->actingAs($staff)
            ->postJson('/api/admin/job-orders', [
                'customer_name' => 'Walk-in Customer',
                'customer_phone' => '09123456789',
                'device_type' => 'Phone',
                'problem_description' => 'Does not power on.',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('job_orders', [
            'customer_name' => 'Walk-in Customer',
            'customer_email' => null,
            'customer_phone' => '09123456789',
            'included_accessories' => null,
        ]);
    }

    public function test_job_order_cannot_be_created_without_a_customer_phone_number(): void
    {
        [, $staff] = $this->createShopUser(User::ROLE_STAFF);

        $this->actingAs($staff)
            ->postJson('/api/admin/job-orders', [
                'customer_name' => 'Walk-in Customer',
                'device_type' => 'Phone',
                'problem_description' => 'Does not power on.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_phone');
    }

    public function test_expired_shop_access_blocks_new_job_order_intake(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $shop->update(['trial_ends_at' => now()->subDay()]);

        $this->actingAs($staff)
            ->postJson('/api/admin/job-orders', [
                'customer_name' => 'Walk-in Customer',
                'customer_phone' => '09123456789',
                'device_type' => 'Phone',
                'problem_description' => 'Does not power on.',
            ])
            ->assertStatus(402)
            ->assertJsonPath('message', 'Shop access has expired. Existing job orders can still be completed, but new intake is locked until the subscription is renewed.');

        $this->assertDatabaseMissing('job_orders', [
            'shop_id' => $shop->id,
            'customer_name' => 'Walk-in Customer',
        ]);
    }

    public function test_needs_setup_shop_access_blocks_new_job_order_intake(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $shop->update(['trial_ends_at' => null]);

        $this->actingAs($staff)
            ->postJson('/api/admin/job-orders', [
                'customer_name' => 'Walk-in Customer',
                'customer_phone' => '09123456789',
                'device_type' => 'Phone',
                'problem_description' => 'Does not power on.',
            ])
            ->assertStatus(402)
            ->assertJsonPath('message', 'Subscription required. Preview mode lets you explore the system, but saving records and transactions are locked until payment is activated.');

        $this->assertDatabaseMissing('job_orders', [
            'shop_id' => $shop->id,
            'customer_name' => 'Walk-in Customer',
        ]);
    }

    public function test_expired_shop_access_can_still_update_existing_job_orders(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $shop->update(['trial_ends_at' => now()->subDay()]);
        $jobOrder = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-CLOSEOUT-001',
            'customer_name' => 'Existing Customer',
            'customer_phone' => '09000000000',
            'device_type' => 'Phone',
            'problem_description' => 'Existing repair.',
            'current_status' => 'RECEIVED',
        ]);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-status", [
                'status' => 'DIAGNOSING',
                'note' => 'Continued close-out work after access expiry.',
            ])
            ->assertOk()
            ->assertJsonPath('jobOrder.current_status', 'DIAGNOSING');

        $this->assertDatabaseHas('status_histories', [
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'status' => 'DIAGNOSING',
        ]);
    }

    public function test_job_order_cannot_be_deleted_after_approved_repair_has_started(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-NO-CANCEL-001',
            'customer_name' => 'Customer Name',
            'customer_phone' => '09000000000',
            'device_type' => 'Phone',
            'problem_description' => 'Does not power on.',
            'repair_approved_at' => now(),
            'repair_approved_by_customer' => 'Customer Name',
            'current_status' => 'WAITING_PARTS',
        ]);

        StatusHistory::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'status' => 'IN_PROGRESS',
            'note' => 'Approved repair work started.',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/job-orders/{$jobOrder->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_order');

        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'deleted_at' => null,
        ]);
    }

    public function test_job_orders_can_be_filtered_to_warranty_service_units(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);

        $originalJob = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-STANDARD-001',
            'customer_name' => 'Standard Customer',
            'customer_phone' => '09000000000',
            'device_type' => 'Phone',
            'problem_description' => 'Standard repair.',
            'service_type' => 'STANDARD',
            'current_status' => 'RECEIVED',
        ]);

        JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-WTY-001',
            'warranty_parent_job_order_id' => $originalJob->id,
            'customer_name' => 'Warranty Customer',
            'customer_phone' => '09111111111',
            'device_type' => 'Laptop',
            'problem_description' => 'Warranty service.',
            'service_type' => 'WARRANTY_SERVICE',
            'current_status' => 'RECEIVED',
        ]);

        $this->actingAs($staff)
            ->getJson('/api/admin/job-orders?service_type=WARRANTY_SERVICE')
            ->assertOk()
            ->assertJsonPath('jobOrders.total', 1)
            ->assertJsonPath('jobOrders.data.0.job_order_number', 'TEST-WTY-001')
            ->assertJsonPath('jobOrders.data.0.warranty_parent_job_order_number', 'TEST-STANDARD-001')
            ->assertJsonPath('jobOrders.data.0.is_warranty_service', true);
    }

    public function test_job_orders_can_be_filtered_by_received_date_range(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);

        $oldJob = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-DATE-OLD',
            'customer_name' => 'Old Customer',
            'customer_phone' => '09000000000',
            'device_type' => 'Phone',
            'problem_description' => 'Old repair.',
            'current_status' => 'RECEIVED',
        ]);
        $oldJob->forceFill([
            'created_at' => '2026-07-01 09:00:00',
            'updated_at' => '2026-07-01 09:00:00',
        ])->save();

        $newJob = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-DATE-NEW',
            'customer_name' => 'New Customer',
            'customer_phone' => '09111111111',
            'device_type' => 'Laptop',
            'problem_description' => 'New repair.',
            'current_status' => 'RECEIVED',
        ]);
        $newJob->forceFill([
            'created_at' => '2026-07-20 09:00:00',
            'updated_at' => '2026-07-20 09:00:00',
        ])->save();

        $this->actingAs($staff)
            ->getJson('/api/admin/job-orders?received_from=2026-07-01&received_to=2026-07-01')
            ->assertOk()
            ->assertJsonPath('jobOrders.total', 1)
            ->assertJsonPath('jobOrders.data.0.job_order_number', 'TEST-DATE-OLD');
    }

    public function test_dashboard_counts_warranty_service_jobs(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);

        JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-DASH-STANDARD-001',
            'customer_name' => 'Standard Customer',
            'customer_phone' => '09000000000',
            'device_type' => 'Phone',
            'problem_description' => 'Standard repair.',
            'service_type' => 'STANDARD',
            'current_status' => 'RECEIVED',
        ]);

        JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-DASH-WTY-001',
            'customer_name' => 'Warranty Customer',
            'customer_phone' => '09111111111',
            'device_type' => 'Laptop',
            'problem_description' => 'Warranty service.',
            'service_type' => 'WARRANTY_SERVICE',
            'current_status' => 'RECEIVED',
        ]);

        $this->actingAs($staff)
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('warrantyServiceJobs', 1);
    }

    public function test_reports_can_be_filtered_to_warranty_service_jobs(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);

        JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-REPORT-STANDARD-001',
            'customer_name' => 'Standard Customer',
            'customer_phone' => '09000000000',
            'device_type' => 'Phone',
            'problem_description' => 'Standard repair.',
            'service_type' => 'STANDARD',
            'current_status' => 'RECEIVED',
        ]);

        JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-REPORT-WTY-001',
            'customer_name' => 'Warranty Customer',
            'customer_phone' => '09111111111',
            'device_type' => 'Laptop',
            'problem_description' => 'Warranty service.',
            'service_type' => 'WARRANTY_SERVICE',
            'current_status' => 'RECEIVED',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/reports?service_type=WARRANTY_SERVICE')
            ->assertOk()
            ->assertJsonPath('filters.service_type_label', 'Under Warranty')
            ->assertJsonPath('filteredJobOrders.0.job_order_number', 'TEST-REPORT-WTY-001')
            ->assertJsonPath('filteredJobOrders.0.service_type_label', 'Under Warranty');
    }

    public function test_reports_can_filter_and_summarize_service_catalog(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $laptop = ServiceCategory::create([
            'shop_id' => $shop->id,
            'name' => 'Laptop',
            'is_active' => true,
        ]);
        $cellphone = ServiceCategory::create([
            'shop_id' => $shop->id,
            'name' => 'Cellphone',
            'is_active' => true,
        ]);
        $lcd = ServiceItem::create([
            'shop_id' => $shop->id,
            'service_category_id' => $laptop->id,
            'name' => 'LCD Replacement',
            'is_active' => true,
        ]);
        $battery = ServiceItem::create([
            'shop_id' => $shop->id,
            'service_category_id' => $cellphone->id,
            'name' => 'Battery Replacement',
            'is_active' => true,
        ]);

        JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-REPORT-LCD-001',
            'customer_name' => 'LCD Customer',
            'customer_phone' => '09000000000',
            'device_type' => 'Laptop',
            'service_category_id' => $laptop->id,
            'service_item_id' => $lcd->id,
            'problem_description' => 'Broken panel.',
            'current_status' => 'RECEIVED',
        ]);

        JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-REPORT-BATT-001',
            'customer_name' => 'Battery Customer',
            'customer_phone' => '09111111111',
            'device_type' => 'Cellphone',
            'service_category_id' => $cellphone->id,
            'service_item_id' => $battery->id,
            'problem_description' => 'Weak battery.',
            'current_status' => 'RECEIVED',
        ]);

        $this->actingAs($admin)
            ->getJson("/api/admin/reports?service_category_id={$laptop->id}&service_item_id={$lcd->id}")
            ->assertOk()
            ->assertJsonPath('filters.service_category_label', 'Laptop')
            ->assertJsonPath('filters.service_item_label', 'LCD Replacement')
            ->assertJsonPath('filteredJobOrders.0.job_order_number', 'TEST-REPORT-LCD-001')
            ->assertJsonPath('filteredJobOrders.0.service_category_name', 'Laptop')
            ->assertJsonPath('filteredJobOrders.0.service_item_name', 'LCD Replacement')
            ->assertJsonPath('topServices.0.service_item_name', 'LCD Replacement')
            ->assertJsonPath('topServices.0.total', 1);
    }

    public function test_payment_csv_export_includes_partial_payment_watchlist_only_for_payment_report(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-PAYMENT-CSV-001',
            'customer_name' => 'Payment Customer',
            'customer_phone' => '09000000000',
            'device_type' => 'Phone',
            'problem_description' => 'Payment report repair.',
            'current_status' => 'READY_FOR_PICKUP',
            'total_cost' => 2000,
            'amount_paid' => 500,
        ]);
        JobOrderPayment::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'amount' => 500,
            'payment_type' => 'PARTIAL_PAYMENT',
            'payment_method' => 'CASH',
            'received_at' => now(),
            'received_by' => 'Admin User',
        ]);

        $response = $this->actingAs($admin)
            ->get('/api/admin/reports/export/csv?report_type=payments')
            ->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Partial Payment Watchlist', $csv);
        $this->assertStringContainsString('TEST-PAYMENT-CSV-001', $csv);
        $this->assertStringContainsString('HELD', $csv);
        $this->assertStringNotContainsString('Operations Summary', $csv);
    }

    private function createShopUser(string $role): array
    {
        $shop = Shop::create([
            'name' => 'Safety Test Shop',
            'slug' => 'safety-test-shop-' . strtolower($role),
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

    private function createReadyJobOrder(Shop $shop): JobOrder
    {
        return JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-PICKUP-' . $shop->id,
            'customer_name' => 'Customer Name',
            'customer_phone' => '09000000000',
            'device_type' => 'Laptop',
            'problem_description' => 'Screen issue.',
            'current_status' => 'READY_FOR_PICKUP',
            'repair_approved_at' => now(),
            'repair_approved_by_customer' => 'Customer Name',
            'total_cost' => 1000,
            'amount_paid' => 0,
        ]);
    }
}
