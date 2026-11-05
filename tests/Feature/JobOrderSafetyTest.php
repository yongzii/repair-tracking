<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\PartRequest;
use App\Models\JobOrderPayment;
use App\Models\SparePart;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\Shop;
use App\Models\StatusHistory;
use App\Models\StockMovement;
use App\Models\Technician;
use App\Models\User;
use Carbon\Carbon;
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

    public function test_reports_count_picked_up_repairs_separately_from_rto_and_use_pickup_time_for_turnaround(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);

        $this->createReportJobOrder($shop, 'PICKED_UP', '2025-02-01 09:00:00', '2025-02-04 09:00:00', '2025-02-20 09:00:00');
        $this->createReportJobOrder($shop, 'PICKED_UP', '2025-02-10 09:00:00', '2025-02-15 09:00:00', '2025-03-20 09:00:00');
        $this->createReportJobOrder($shop, 'PICKED_UP', '2025-02-12 09:00:00', null, '2025-03-25 09:00:00');
        $this->createReportJobOrder($shop, 'RTO', '2025-02-01 09:00:00', '2025-02-20 09:00:00', '2025-03-30 09:00:00');
        $this->createReportJobOrder($shop, 'RTO_RELEASED', '2025-02-01 09:00:00', '2025-02-25 09:00:00', '2025-03-31 09:00:00');

        $this->actingAs($admin)
            ->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonPath('totalCompleted', 3)
            ->assertJsonPath('totalRto', 2)
            ->assertJsonPath('avgTurnaround', 4)
            ->assertJsonPath('minTurnaround', 3)
            ->assertJsonPath('maxTurnaround', 5);
    }

    public function test_technician_performance_uses_current_assignment_pickup_period_and_shop_scoping(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $otherShop = Shop::create([
            'name' => 'Other Report Shop',
            'slug' => 'other-report-shop-' . $shop->id,
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Current Assignment Tech',
            'email' => 'current-assignment-' . $shop->id . '@example.test',
            'is_active' => true,
        ]);
        $secondTechnician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Second Tech',
            'email' => 'second-tech-' . $shop->id . '@example.test',
            'is_active' => true,
        ]);
        $nullPickupTechnician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Null Pickup Tech',
            'email' => 'null-pickup-tech-' . $shop->id . '@example.test',
            'is_active' => true,
        ]);
        $otherShopTechnician = Technician::create([
            'shop_id' => $otherShop->id,
            'name' => 'Other Shop Tech',
            'email' => 'other-shop-tech-' . $otherShop->id . '@example.test',
            'is_active' => true,
        ]);

        $pickedUpInRange = $this->createReportJobOrder(
            $shop,
            'PICKED_UP',
            '2025-03-01 09:00:00',
            '2025-03-11 09:00:00',
            '2025-05-01 09:00:00'
        );
        $pickedUpInRange->update(['technician_id' => $technician->id]);
        $nullPickup = $this->createReportJobOrder($shop, 'PICKED_UP', '2025-03-14 09:00:00');
        $nullPickup->update(['technician_id' => $nullPickupTechnician->id]);
        $pickedUpOutsideRange = $this->createReportJobOrder(
            $shop,
            'PICKED_UP',
            '2025-03-12 09:00:00',
            '2025-03-21 09:00:00'
        );
        $pickedUpOutsideRange->update(['technician_id' => $technician->id]);
        $warrantyCompletion = $this->createReportJobOrder(
            $shop,
            'PICKED_UP',
            '2025-03-12 09:00:00',
            '2025-03-15 09:00:00',
            null,
            'WARRANTY_SERVICE'
        );
        $warrantyCompletion->update(['technician_id' => $technician->id]);

        $rto = $this->createReportJobOrder($shop, 'RTO', '2025-03-12 09:00:00');
        $rto->update(['technician_id' => $technician->id]);
        $rtoReleased = $this->createReportJobOrder(
            $shop,
            'RTO_RELEASED',
            '2025-03-14 09:00:00',
            '2025-03-15 09:00:00'
        );
        $rtoReleased->update(['technician_id' => $technician->id]);
        $deletedCompletion = $this->createReportJobOrder(
            $shop,
            'PICKED_UP',
            '2025-03-01 09:00:00',
            '2025-03-12 09:00:00'
        );
        $deletedCompletion->update(['technician_id' => $technician->id]);
        $deletedCompletion->delete();
        $deletedWorkload = $this->createReportJobOrder($shop, 'IN_PROGRESS', '2024-01-01 09:00:00');
        $deletedWorkload->update(['technician_id' => $technician->id]);
        $deletedWorkload->delete();

        $secondCompletion = $this->createReportJobOrder(
            $shop,
            'PICKED_UP',
            '2025-03-09 09:00:00',
            '2025-03-11 09:00:00'
        );
        $secondCompletion->update(['technician_id' => $secondTechnician->id]);
        $this->createReportJobOrder(
            $shop,
            'PICKED_UP',
            '2025-03-09 09:00:00',
            '2025-03-11 09:00:00'
        );
        $crossShopAssignment = $this->createReportJobOrder(
            $shop,
            'PICKED_UP',
            '2025-03-09 09:00:00',
            '2025-03-11 09:00:00'
        );
        $crossShopAssignment->update(['technician_id' => $otherShopTechnician->id]);

        foreach ([
            ['IN_PROGRESS', '2024-01-01 09:00:00'],
            ['WAITING_PARTS', '2025-03-25 09:00:00'],
            ['PICKED_UP', '2024-01-01 09:00:00'],
            ['RTO', '2024-01-01 09:00:00'],
            ['RTO_RELEASED', '2024-01-01 09:00:00'],
        ] as [$status, $createdAt]) {
            $workloadJob = $this->createReportJobOrder(
                $shop,
                $status,
                $createdAt,
                $status === 'PICKED_UP' ? '2024-01-02 09:00:00' : null
            );
            $workloadJob->update(['technician_id' => $technician->id]);
        }
        $secondWorkloadJob = $this->createReportJobOrder($shop, 'IN_PROGRESS', '2024-01-01 09:00:00');
        $secondWorkloadJob->update(['technician_id' => $secondTechnician->id]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/reports?start_date=2025-03-10&end_date=2025-03-20&service_type=STANDARD')
            ->assertOk()
            ->assertJsonPath('technicianPerformance.0.technician_name', 'Current Assignment Tech')
            ->assertJsonPath('technicianPerformance.0.successful_jobs', 1)
            ->assertJsonPath('technicianPerformance.0.average_turnaround_days', 10)
            ->assertJsonPath('technicianPerformance.0.rto_jobs', 1)
            ->assertJsonPath('technicianPerformance.0.rto_released_jobs', 1)
            ->assertJsonPath('technicianPerformance.1.technician_name', 'Null Pickup Tech')
            ->assertJsonPath('technicianPerformance.1.successful_jobs', 0)
            ->assertJsonPath('technicianPerformance.2.technician_name', 'Second Tech')
            ->assertJsonPath('technicianPerformance.2.successful_jobs', 1)
            ->assertJsonPath('technicianPerformance.2.average_turnaround_days', 2);

        $performance = collect($response->json('technicianPerformance'));
        $this->assertCount(3, $performance);
        $this->assertSame(2, $response->json('currentTechnicianWorkload.0.current_jobs'));
        $this->assertSame(0, $response->json('currentTechnicianWorkload.1.current_jobs'));
        $this->assertSame(1, $response->json('currentTechnicianWorkload.2.current_jobs'));

        $this->actingAs($admin)
            ->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonPath('technicianPerformance.1.successful_jobs', 1)
            ->assertJsonPath('technicianPerformance.1.average_turnaround_days', 0);

        $csv = $this->actingAs($admin)
            ->get('/api/admin/reports/export/csv?report_type=operations&start_date=2025-03-10&end_date=2025-03-20&service_type=STANDARD')
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('Technician Performance (Current Assignment)', $csv);
        $this->assertStringContainsString('Current Technician Workload', $csv);
        $this->assertStringContainsString('Completed Repairs Date Basis', $csv);
        $this->assertStringContainsString('RTO Outcomes', $csv);

        $this->actingAs($admin)
            ->get('/api/admin/reports/export/pdf?report_type=operations&start_date=2025-03-10&end_date=2025-03-20&service_type=STANDARD')
            ->assertOk()
            ->assertSee('Technician Performance (Current Assignment)')
            ->assertSee('Current Technician Workload')
            ->assertSee('currently stored technician assignment')
            ->assertSee('Date basis: job creation date (created_at). Date scope: applied date range when provided; otherwise, all dates.')
            ->assertSee('Successful jobs use pickup date (picked_up_at); RTO outcomes use job creation date (created_at). Date scope: applied date range when provided; otherwise, all dates.');
    }

    public function test_technician_successful_jobs_use_inclusive_pickup_date_boundaries(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Pickup Boundary Tech',
            'email' => 'pickup-boundary-' . $shop->id . '@example.test',
            'is_active' => true,
        ]);

        foreach ([
            ['2025-03-01 09:00:00', '2025-04-09 09:00:00'],
            ['2025-03-02 09:00:00', '2025-04-10 09:00:00'],
            ['2025-03-03 09:00:00', '2025-04-12 09:00:00'],
            ['2025-03-04 09:00:00', '2025-04-13 09:00:00'],
        ] as [$createdAt, $pickedUpAt]) {
            $jobOrder = $this->createReportJobOrder($shop, 'PICKED_UP', $createdAt, $pickedUpAt);
            $jobOrder->update(['technician_id' => $technician->id]);
        }

        $this->actingAs($admin)
            ->getJson('/api/admin/reports?start_date=2025-04-10&end_date=2025-04-12')
            ->assertOk()
            ->assertJsonPath('technicianPerformance.0.successful_jobs', 2)
            ->assertJsonPath('technicianPerformance.0.average_turnaround_days', 39.5);
    }

    public function test_technician_rto_counts_use_job_creation_date_not_status_event_date(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'RTO Date Tech',
            'email' => 'rto-date-' . $shop->id . '@example.test',
            'is_active' => true,
        ]);

        foreach ([
            ['RTO', '2025-04-10 09:00:00', '2025-04-20 09:00:00'],
            ['RTO', '2025-04-09 09:00:00', '2025-04-11 09:00:00'],
            ['RTO_RELEASED', '2025-04-12 09:00:00', '2025-04-08 09:00:00'],
            ['RTO_RELEASED', '2025-04-08 09:00:00', '2025-04-11 09:00:00'],
        ] as [$status, $createdAt, $eventAt]) {
            $jobOrder = $this->createReportJobOrder($shop, $status, $createdAt);
            $jobOrder->update(['technician_id' => $technician->id]);

            $history = StatusHistory::create([
                'shop_id' => $shop->id,
                'job_order_id' => $jobOrder->id,
                'status' => $status,
                'note' => 'RTO date semantics test.',
            ]);
            $history->forceFill(['created_at' => Carbon::parse($eventAt)])->saveQuietly();
        }

        $this->actingAs($admin)
            ->getJson('/api/admin/reports?start_date=2025-04-10&end_date=2025-04-12')
            ->assertOk()
            ->assertJsonPath('technicianPerformance.0.rto_jobs', 1)
            ->assertJsonPath('technicianPerformance.0.rto_released_jobs', 1);
    }

    public function test_technician_workload_ignores_dates_and_honors_service_and_terminal_filters(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Workload Filter Tech',
            'email' => 'workload-filter-' . $shop->id . '@example.test',
            'is_active' => true,
        ]);

        foreach ([
            ['IN_PROGRESS', '2020-01-01 09:00:00', 'STANDARD'],
            ['WAITING_PARTS', '2025-04-11 09:00:00', 'STANDARD'],
            ['IN_PROGRESS', '2025-04-11 09:00:00', 'WARRANTY_SERVICE'],
            ['PICKED_UP', '2025-04-11 09:00:00', 'STANDARD'],
            ['RTO', '2025-04-11 09:00:00', 'STANDARD'],
            ['RTO_RELEASED', '2025-04-11 09:00:00', 'STANDARD'],
        ] as [$status, $createdAt, $serviceType]) {
            $jobOrder = $this->createReportJobOrder($shop, $status, $createdAt, null, null, $serviceType);
            $jobOrder->update(['technician_id' => $technician->id]);
        }

        $this->actingAs($admin)
            ->getJson('/api/admin/reports?start_date=2025-04-10&end_date=2025-04-12&service_type=STANDARD')
            ->assertOk()
            ->assertJsonPath('currentTechnicianWorkload.0.current_jobs', 2);
    }

    public function test_technician_analytics_return_empty_and_zeroed_data_with_pdf_empty_states(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);

        $emptyReport = $this->actingAs($admin)
            ->getJson('/api/admin/reports')
            ->assertOk();
        $this->assertSame([], $emptyReport->json('technicianPerformance'));
        $this->assertSame([], $emptyReport->json('currentTechnicianWorkload'));

        $this->actingAs($admin)
            ->get('/api/admin/reports/export/pdf?report_type=operations')
            ->assertOk()
            ->assertSee('No technician records are available for this shop.');

        Technician::create([
            'shop_id' => $shop->id,
            'name' => 'No Matching Jobs Tech',
            'email' => 'no-matching-jobs-' . $shop->id . '@example.test',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/reports?service_type=WARRANTY_SERVICE')
            ->assertOk()
            ->assertJsonPath('technicianPerformance.0.successful_jobs', 0)
            ->assertJsonPath('technicianPerformance.0.average_turnaround_days', 0)
            ->assertJsonPath('technicianPerformance.0.rto_jobs', 0)
            ->assertJsonPath('technicianPerformance.0.rto_released_jobs', 0)
            ->assertJsonPath('currentTechnicianWorkload.0.current_jobs', 0);

        $this->actingAs($admin)
            ->get('/api/admin/reports/export/pdf?report_type=operations&service_type=WARRANTY_SERVICE')
            ->assertOk()
            ->assertSee('No assigned jobs match the selected services and reporting period.')
            ->assertSee('No non-terminal assigned jobs match the selected services.');
    }

    public function test_unfiltered_operations_exports_explain_all_date_scope_and_metric_bases(): void
    {
        [, $admin] = $this->createShopUser(User::ROLE_ADMIN);

        $csv = $this->actingAs($admin)
            ->get('/api/admin/reports/export/csv?report_type=operations')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString(
            'Date basis: job creation date (created_at). Date scope: applied date range when provided; otherwise, all dates.',
            $csv,
        );
        $this->assertStringContainsString(
            'Successful jobs use pickup date (picked_up_at); RTO outcomes use job creation date (created_at). Date scope: applied date range when provided; otherwise, all dates.',
            $csv,
        );
        $this->assertStringContainsString(
            'Current snapshot; excludes picked-up and both RTO terminal statuses. Date filters do not apply.',
            $csv,
        );

        $pdf = $this->actingAs($admin)
            ->get('/api/admin/reports/export/pdf?report_type=operations')
            ->assertOk();

        $pdf->assertSee('Date basis: job creation date (created_at). Date scope: applied date range when provided; otherwise, all dates.');
        $pdf->assertSee('Successful jobs use pickup date (picked_up_at); RTO outcomes use job creation date (created_at). Date scope: applied date range when provided; otherwise, all dates.');
        $pdf->assertSee('Current snapshot; excludes picked-up and both RTO terminal statuses. Date filters do not apply.');
    }

    public function test_inventory_snapshot_uses_current_stock_and_existing_low_stock_rule(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $this->createInventoryPart($shop->id, 'Inactive Boundary Part', 3, 3, 10, false, 'pcs');
        $this->createInventoryPart($shop->id, 'Active Low Part', 2, 3, 25, true, 'pcs');
        $this->createInventoryPart($shop->id, 'Active In-Stock Part', 6, 5, 2, true, 'box');

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/reports?start_date=2030-01-01&end_date=2030-01-31')
            ->assertOk();

        $response
            ->assertJsonPath('inventoryReport.summary.total_items', 3)
            ->assertJsonPath('inventoryReport.summary.active_items', 2)
            ->assertJsonPath('inventoryReport.summary.inactive_items', 1)
            ->assertJsonPath('inventoryReport.summary.low_stock_items', 2)
            ->assertJsonPath('inventoryReport.summary.estimated_current_inventory_value', 92);

        $stockByUnit = collect($response->json('inventoryReport.summary.stock_quantity_by_unit'))->keyBy('unit');
        $this->assertSame(5, $stockByUnit['pcs']['quantity']);
        $this->assertSame(6, $stockByUnit['box']['quantity']);

        $items = collect($response->json('inventoryReport.items'))->keyBy('part_name');
        $this->assertSame('Low Stock', $items['Inactive Boundary Part']['stock_status']);
        $this->assertSame('Inactive', $items['Inactive Boundary Part']['active_status']);
        $this->assertEquals(30, $items['Inactive Boundary Part']['estimated_item_value']);
        $this->assertSame('In Stock', $items['Active In-Stock Part']['stock_status']);
    }

    public function test_inventory_movement_history_is_shop_scoped_date_filtered_and_classifies_recorded_out_issues(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        [$otherShop] = $this->createShopUser(User::ROLE_STAFF);
        $part = $this->createInventoryPart($shop->id, 'Movement Part', 20, 2, 4);
        $foreignPart = $this->createInventoryPart($otherShop->id, 'Foreign Part', 10, 2, 8);

        $repairJob = $this->createReportJobOrder($shop, 'IN_PROGRESS', '2025-04-01 09:00:00');
        $deletedJob = $this->createReportJobOrder($shop, 'IN_PROGRESS', '2025-04-02 09:00:00');
        $deletedJob->delete();
        $foreignJob = $this->createReportJobOrder($otherShop, 'IN_PROGRESS', '2025-04-03 09:00:00');

        $beforeRange = $this->createInventoryMovement($shop->id, $part->id, 'IN', 5, 20, null, 'Initial delivery', '2025-04-09 08:00:00');
        $repairOut = $this->createInventoryMovement($shop->id, $part->id, 'OUT', -3, 17, $repairJob->id, 'Issued for repair', '2025-04-10 09:00:00');
        $manualOut = $this->createInventoryMovement($shop->id, $part->id, 'OUT', -2, 15, null, 'Manual deduction', '2025-04-11 10:00:00');
        $reversal = $this->createInventoryMovement($shop->id, $part->id, 'IN', 1, 16, $repairJob->id, 'Correction reversal', '2025-04-12 08:00:00');
        $adjustment = $this->createInventoryMovement($shop->id, $part->id, 'ADJUSTMENT', -2, 14, null, 'Cycle count adjustment', '2025-04-12 08:30:00');
        $deletedJobOut = $this->createInventoryMovement($shop->id, $part->id, 'OUT', -1, 15, $deletedJob->id, 'Issued to retained soft-deleted job', '2025-04-12 09:00:00');
        $foreignJobOut = $this->createInventoryMovement($shop->id, $part->id, 'OUT', -4, 11, $foreignJob->id, 'Invalid cross-shop job reference', '2025-04-13 09:00:00');
        $foreignShopMovement = $this->createInventoryMovement($otherShop->id, $part->id, 'OUT', -6, 5, $repairJob->id, 'Foreign shop movement', '2025-04-11 11:00:00');
        $foreignPartMovement = $this->createInventoryMovement($shop->id, $foreignPart->id, 'OUT', -7, 3, $repairJob->id, 'Foreign part movement', '2025-04-11 12:00:00');

        $filtered = $this->actingAs($admin)
            ->getJson('/api/admin/reports?start_date=2025-04-10&end_date=2025-04-12')
            ->assertOk()
            ->assertJsonPath('inventoryReport.movement_summary.movement_count', 5)
            ->assertJsonPath('inventoryReport.movement_summary.repair_linked_out.movement_count', 2)
            ->assertJsonPath('inventoryReport.movement_summary.repair_linked_out.quantity', 4)
            ->assertJsonPath('inventoryReport.movement_summary.unlinked_manual_out.movement_count', 1)
            ->assertJsonPath('inventoryReport.movement_summary.unlinked_manual_out.quantity', 2);

        $this->assertSame(
            ['Movement Part'],
            collect($filtered->json('inventoryReport.items'))->pluck('part_name')->all(),
        );
        $filteredMovements = collect($filtered->json('inventoryReport.movements'))->keyBy('id');
        $this->assertSame(-3, $filteredMovements[$repairOut->id]['quantity_change']);
        $this->assertSame(17, $filteredMovements[$repairOut->id]['balance_after']);
        $this->assertSame('Issued for repair', $filteredMovements[$repairOut->id]['note']);
        $this->assertSame('Inventory Test User', $filteredMovements[$repairOut->id]['recorded_by']);
        $this->assertSame($repairJob->job_order_number, $filteredMovements[$repairOut->id]['job_order_reference']);
        $this->assertSame(1, $filteredMovements[$reversal->id]['quantity_change']);
        $this->assertSame('IN', $filteredMovements[$reversal->id]['type']);
        $this->assertSame(-2, $filteredMovements[$adjustment->id]['quantity_change']);
        $this->assertSame('ADJUSTMENT', $filteredMovements[$adjustment->id]['type']);
        $this->assertSame('Unlinked/manual', $filteredMovements[$manualOut->id]['out_classification']);
        $this->assertSame($deletedJob->job_order_number, $filteredMovements[$deletedJobOut->id]['job_order_reference']);
        $this->assertArrayNotHasKey($beforeRange->id, $filteredMovements->all());
        $this->assertArrayNotHasKey($foreignJobOut->id, $filteredMovements->all());
        $this->assertArrayNotHasKey($foreignShopMovement->id, $filteredMovements->all());
        $this->assertArrayNotHasKey($foreignPartMovement->id, $filteredMovements->all());

        $allDates = $this->actingAs($admin)
            ->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonPath('inventoryReport.movement_summary.movement_count', 7)
            ->assertJsonPath('inventoryReport.movement_summary.repair_linked_out.movement_count', 2)
            ->assertJsonPath('inventoryReport.movement_summary.repair_linked_out.quantity', 4)
            ->assertJsonPath('inventoryReport.movement_summary.unlinked_manual_out.movement_count', 1)
            ->assertJsonPath('inventoryReport.movement_summary.invalid_job_reference_out.movement_count', 1);
        $allMovements = collect($allDates->json('inventoryReport.movements'))->keyBy('id');
        $this->assertNull($allMovements[$foreignJobOut->id]['job_order_reference']);
        $this->assertSame('Job reference unavailable', $allMovements[$foreignJobOut->id]['out_classification']);
    }

    public function test_general_part_request_receiving_is_not_an_inventory_movement_without_a_stock_record(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $part = $this->createInventoryPart($shop->id, 'Requested Battery', 0, 2, 100);
        $request = PartRequest::create([
            'shop_id' => $shop->id,
            'inventory_spare_part_id' => $part->id,
            'part_name' => 'Requested Battery',
            'quantity' => 3,
            'status' => PartRequest::STATUS_ORDERED,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/part-requests/{$request->id}/receive")
            ->assertOk()
            ->assertJsonPath('request.status', PartRequest::STATUS_RECEIVED);

        $this->assertSame(0, (int) $part->fresh()->stock_quantity);
        $this->assertSame([], $this->getJson('/api/admin/reports')->assertOk()->json('inventoryReport.movements'));

        $actualReceipt = $this->createInventoryMovement(
            $shop->id,
            $part->id,
            'IN',
            3,
            3,
            null,
            'Separately recorded physical stock receipt',
            '2025-04-15 09:00:00',
        );
        $this->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonPath('inventoryReport.movement_summary.movement_count', 1)
            ->assertJsonPath('inventoryReport.movements.0.id', $actualReceipt->id)
            ->assertJsonPath('inventoryReport.movements.0.type', 'IN');
    }

    public function test_inventory_exports_show_clear_empty_states(): void
    {
        [, $admin] = $this->createShopUser(User::ROLE_ADMIN);

        $csv = $this->actingAs($admin)
            ->get('/api/admin/reports/export/csv?report_type=operations')
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('No inventory items found for this shop.', $csv);
        $this->assertStringContainsString('No stock movements found for the applied date scope.', $csv);
        $this->assertStringContainsString('Estimated Current Inventory Value (PHP)', $csv);

        $this->actingAs($admin)
            ->get('/api/admin/reports/export/pdf?report_type=operations')
            ->assertOk()
            ->assertSee('No inventory items found for this shop.')
            ->assertSee('No stock movements found for the applied date scope.')
            ->assertSee('Estimated Current Inventory Value');
    }

    public function test_custom_report_comparison_uses_the_preceding_equal_length_period_and_preserves_service_filter(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);

        $this->createReportJobOrder($shop, 'RECEIVED', '2025-04-10 09:00:00');
        $this->createReportJobOrder($shop, 'RECEIVED', '2025-04-12 09:00:00');
        $this->createReportJobOrder($shop, 'RECEIVED', '2025-04-06 09:00:00');
        $this->createReportJobOrder($shop, 'RECEIVED', '2025-04-06 11:00:00');
        $this->createReportJobOrder($shop, 'RECEIVED', '2025-04-07 09:00:00');
        $this->createReportJobOrder($shop, 'RECEIVED', '2025-04-09 09:00:00');
        $this->createReportJobOrder($shop, 'RECEIVED', '2025-04-08 09:00:00', null, null, 'WARRANTY_SERVICE');

        $this->actingAs($admin)
            ->getJson('/api/admin/reports?start_date=2025-04-10&end_date=2025-04-12&service_type=STANDARD')
            ->assertOk()
            ->assertJsonPath('thisMonth', 2)
            ->assertJsonPath('lastMonth', 2);
    }

    public function test_january_report_compares_with_december_of_the_previous_year(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $this->travelTo(Carbon::parse('2025-01-15 12:00:00'));

        $this->createReportJobOrder($shop, 'RECEIVED', '2025-01-05 09:00:00');
        $this->createReportJobOrder($shop, 'RECEIVED', '2024-12-05 09:00:00');
        $this->createReportJobOrder($shop, 'RECEIVED', '2024-12-20 09:00:00');
        $this->createReportJobOrder($shop, 'RECEIVED', '2023-12-15 09:00:00');

        $this->actingAs($admin)
            ->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonPath('thisMonth', 1)
            ->assertJsonPath('lastMonth', 2);

        $this->travelBack();
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

    private function createInventoryPart(
        int $shopId,
        string $name,
        int $stock,
        int $reorderLevel,
        float $unitCost,
        bool $active = true,
        string $unit = 'pcs',
    ): SparePart {
        return SparePart::create([
            'shop_id' => $shopId,
            'name' => $name,
            'category' => 'Test Parts',
            'unit' => $unit,
            'stock_quantity' => $stock,
            'reorder_level' => $reorderLevel,
            'unit_cost' => $unitCost,
            'is_active' => $active,
        ]);
    }

    private function createInventoryMovement(
        int $shopId,
        int $sparePartId,
        string $type,
        int $quantityChange,
        int $balanceAfter,
        ?int $jobOrderId,
        string $note,
        string $createdAt,
    ): StockMovement {
        $movement = StockMovement::create([
            'shop_id' => $shopId,
            'spare_part_id' => $sparePartId,
            'job_order_id' => $jobOrderId,
            'type' => $type,
            'quantity_change' => $quantityChange,
            'balance_after' => $balanceAfter,
            'note' => $note,
            'created_by' => 'Inventory Test User',
        ]);
        $movement->forceFill(['created_at' => Carbon::parse($createdAt)])->saveQuietly();

        return $movement->fresh();
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

    private function createReportJobOrder(
        Shop $shop,
        string $status,
        string $createdAt,
        ?string $pickedUpAt = null,
        ?string $updatedAt = null,
        string $serviceType = 'STANDARD',
    ): JobOrder {
        $jobOrder = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-REPORT-' . strtoupper(bin2hex(random_bytes(5))),
            'customer_name' => 'Report Customer',
            'customer_phone' => '09000000000',
            'device_type' => 'Phone',
            'problem_description' => 'Report metric test.',
            'service_type' => $serviceType,
            'current_status' => $status,
        ]);

        $jobOrder->forceFill([
            'created_at' => Carbon::parse($createdAt),
            'updated_at' => Carbon::parse($updatedAt ?? $createdAt),
            'picked_up_at' => $pickedUpAt ? Carbon::parse($pickedUpAt) : null,
        ])->saveQuietly();

        return $jobOrder;
    }
}
