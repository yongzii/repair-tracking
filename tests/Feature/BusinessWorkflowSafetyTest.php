<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\JobOrderPart;
use App\Models\JobOrderPayment;
use App\Models\PlatformSubscriptionPayment;
use App\Models\Shop;
use App\Models\SparePart;
use App\Models\StatusHistory;
use App\Models\SubscriptionRequest;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessWorkflowSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_request_can_only_be_approved_once(): void
    {
        $shop = $this->createShop();
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);
        $request = $this->createSubscriptionRequest($shop);
        $endpoint = "/api/platform/subscription-requests/{$request->id}/approve";

        $this->actingAs($platformAdmin)->postJson($endpoint)->assertOk();
        $this->actingAs($platformAdmin)->postJson($endpoint)->assertUnprocessable();

        $this->assertSame(1, PlatformSubscriptionPayment::where('shop_id', $shop->id)->count());
        $this->assertSame(SubscriptionRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'platform.subscription_request.approved')
            ->where('record_id', $request->id)
            ->count());
    }

    public function test_subscription_request_can_only_be_rejected_once(): void
    {
        $shop = $this->createShop();
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);
        $request = $this->createSubscriptionRequest($shop);
        $endpoint = "/api/platform/subscription-requests/{$request->id}/reject";

        $this->actingAs($platformAdmin)->postJson($endpoint)->assertOk();
        $this->actingAs($platformAdmin)->postJson($endpoint)->assertUnprocessable();

        $this->assertSame(SubscriptionRequest::STATUS_REJECTED, $request->fresh()->status);
        $this->assertSame(0, PlatformSubscriptionPayment::where('shop_id', $shop->id)->count());
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'platform.subscription_request.rejected')
            ->where('record_id', $request->id)
            ->count());
    }

    public function test_initial_payment_type_reflects_zero_partial_and_full_payment_state(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);

        $zero = $this->createJobOrderThroughIntake($staff, $shop, 100, 0);
        $partial = $this->createJobOrderThroughIntake($staff, $shop, 100, 40);
        $full = $this->createJobOrderThroughIntake($staff, $shop, 100, 100);

        $this->assertSame('PENDING', $zero->payment_status);
        $this->assertSame(0, JobOrderPayment::where('job_order_id', $zero->id)->count());
        $this->assertSame('PARTIAL', $partial->payment_status);
        $this->assertSame('PARTIAL_PAYMENT', $partial->payments()->sole()->payment_type);
        $this->assertSame('PAID', $full->payment_status);
        $this->assertSame('FINAL_PAYMENT', $full->payments()->sole()->payment_type);

        $this->actingAs($staff)
            ->postJson('/api/admin/job-orders', $this->intakePayload($shop, 100, 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('partial_payment');
    }

    public function test_ready_job_order_can_be_picked_up_only_once_and_accept_later_payment(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 'READY_FOR_PICKUP', 100, 0);
        $endpoint = "/api/admin/job-orders/{$jobOrder->id}/mark-picked-up";
        $payload = [
            'picked_up_by' => 'Customer',
            'override_unpaid_balance' => true,
            'override_reason' => 'Approved for collection.',
        ];

        $this->actingAs($admin)->postJson($endpoint, $payload)->assertOk();
        $this->actingAs($admin)->postJson($endpoint, $payload)->assertUnprocessable();

        $this->assertSame('PICKED_UP', $jobOrder->fresh()->current_status);
        $this->assertNotNull($jobOrder->fresh()->picked_up_at);
        $this->assertSame(1, StatusHistory::where('job_order_id', $jobOrder->id)->where('status', 'PICKED_UP')->count());

        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/record-payment", [
                'amount_paid' => 100,
                'idempotency_key' => (string) Str::uuid(),
                'payment_method' => 'CASH',
            ])
            ->assertOk();

        $this->assertSame(100.0, (float) $jobOrder->fresh()->amount_paid);
    }

    public function test_picked_up_job_order_cannot_return_to_repair_status(): void
    {
        [, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($staff->shop, 'PICKED_UP');

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-status", ['status' => 'IN_PROGRESS'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('PICKED_UP', $jobOrder->fresh()->current_status);
    }

    public function test_rto_can_be_released_once_and_cannot_reopen_or_activate_warranty(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 'IN_PROGRESS');

        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-status", ['status' => 'RTO'])
            ->assertOk();
        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-status", ['status' => 'DIAGNOSING'])
            ->assertUnprocessable();

        $endpoint = "/api/admin/job-orders/{$jobOrder->id}/release-rto";
        $payload = ['picked_up_by' => 'Customer'];
        $this->actingAs($admin)->postJson($endpoint, $payload)->assertOk();
        $this->actingAs($admin)->postJson($endpoint, $payload)->assertUnprocessable();

        $jobOrder->refresh();
        $this->assertSame('RTO_RELEASED', $jobOrder->current_status);
        $this->assertNotNull($jobOrder->picked_up_at);
        $this->assertFalse($jobOrder->isUnderWarranty());
        $this->assertSame(1, StatusHistory::where('job_order_id', $jobOrder->id)->where('status', 'RTO_RELEASED')->count());
    }

    public function test_rto_release_does_not_require_an_unpaid_balance_override(): void
    {
        $shop = $this->createShop();
        $staff = $this->createUser(User::ROLE_STAFF, $shop);
        $jobOrder = $this->createJobOrder($shop, 'RTO', 100, 0);
        $endpoint = "/api/admin/job-orders/{$jobOrder->id}/release-rto";
        $payload = ['picked_up_by' => 'Customer'];

        $this->actingAs($staff)->postJson($endpoint, $payload)->assertOk();

        $this->assertSame('RTO_RELEASED', $jobOrder->fresh()->current_status);
        $this->assertNull($jobOrder->fresh()->pickup_override_reason);
        $this->assertSame(0.0, (float) $jobOrder->fresh()->balance);
    }

    public function test_rto_preserves_estimate_and_receipt_while_creating_pending_refund_for_partial_payment(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 'IN_PROGRESS', 1000, 0);
        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/record-payment", [
                'amount_paid' => 300,
                'idempotency_key' => (string) Str::uuid(),
                'payment_method' => 'CASH',
            ])
            ->assertOk();
        $payment = $jobOrder->payments()->sole();

        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-status", ['status' => 'RTO'])
            ->assertOk();

        $this->assertSame(1000.0, (float) $jobOrder->fresh()->total_cost);
        $this->assertSame(300.0, (float) $jobOrder->fresh()->amount_paid);
        $this->assertSame(0.0, (float) $jobOrder->fresh()->balance);
        $this->assertSame(300.0, (float) $payment->fresh()->amount);
        $this->assertDatabaseHas('job_order_refunds', [
            'job_order_id' => $jobOrder->id,
            'job_order_payment_id' => $payment->id,
            'amount' => 300,
            'status' => 'PENDING',
        ]);

        $refund = $jobOrder->refunds()->sole();
        $issueEndpoint = "/api/admin/job-orders/{$jobOrder->id}/refunds/{$refund->id}/issue";
        $this->actingAs($admin)->postJson($issueEndpoint, ['refund_method' => 'CASH'])->assertOk();
        $this->actingAs($admin)->postJson($issueEndpoint, ['refund_method' => 'CASH'])->assertUnprocessable();
        $this->assertSame('ISSUED', $refund->fresh()->status);
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'job_order.rto_refund_issued')
            ->where('record_id', $jobOrder->id)
            ->count());
    }

    public function test_rto_creates_one_refund_per_multiple_receipts_and_zero_payment_creates_none(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $partiallyPaid = $this->createJobOrder($shop, 'IN_PROGRESS', 100, 0);

        foreach ([20, 30] as $amount) {
            $this->actingAs($admin)
                ->postJson("/api/admin/job-orders/{$partiallyPaid->id}/record-payment", [
                    'amount_paid' => $amount,
                    'idempotency_key' => (string) Str::uuid(),
                    'payment_method' => 'CASH',
                ])
                ->assertOk();
        }

        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$partiallyPaid->id}/update-status", ['status' => 'RTO'])
            ->assertOk();
        $this->assertSame(2, $partiallyPaid->refunds()->count());
        $this->assertSame(50.0, (float) $partiallyPaid->refunds()->sum('amount'));

        $unpaid = $this->createJobOrder($shop, 'IN_PROGRESS', 100, 0);
        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$unpaid->id}/update-status", ['status' => 'RTO'])
            ->assertOk();
        $this->assertSame(0, $unpaid->refunds()->count());
        $this->assertSame(0.0, (float) $unpaid->fresh()->balance);
    }

    public function test_legacy_rto_refund_reconciliation_is_audited_and_cannot_exceed_unmatched_paid_total(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 'RTO', 1000, 300);
        $endpoint = "/api/admin/job-orders/{$jobOrder->id}/legacy-rto-refunds";

        $this->actingAs($admin)->postJson($endpoint, [
            'amount' => 301,
            'reconciliation_reason' => 'Verified against cash receipt book.',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->actingAs($admin)->postJson($endpoint, [
            'amount' => 300,
            'reconciliation_reason' => 'Verified against cash receipt book.',
        ])->assertCreated();

        $this->assertSame(0.0, (float) $jobOrder->fresh()->balance);
        $this->assertSame(0.0, (float) $jobOrder->refunds()->whereNull('job_order_payment_id')->value('amount') - 300);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'job_order.legacy_rto_refund_reconciled',
            'record_id' => $jobOrder->id,
        ]);
    }

    public function test_rto_rejects_new_payments_and_cost_or_part_changes(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 'RTO', 100, 0);
        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/record-payment", [
                'amount_paid' => 10,
                'idempotency_key' => (string) Str::uuid(),
                'payment_method' => 'CASH',
            ])
            ->assertUnprocessable();
        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-costs", [
                'parts_cost' => 0,
                'labor_cost' => 50,
            ])
            ->assertUnprocessable();
        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/parts", [
                'part_name' => 'Replacement screen',
                'quantity' => 1,
                'unit_cost' => 10,
            ])
            ->assertUnprocessable();

        $this->assertSame(0, $jobOrder->payments()->count());
        $this->assertSame(0, $jobOrder->parts()->count());
        $this->assertSame(100.0, (float) $jobOrder->fresh()->total_cost);
    }

    public function test_original_payment_history_cannot_be_permanently_deleted_with_its_job_order(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 'RECEIVED', 100, 0);
        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/record-payment", [
                'amount_paid' => 20,
                'idempotency_key' => (string) Str::uuid(),
                'payment_method' => 'CASH',
            ])
            ->assertOk();

        $this->actingAs($admin)->deleteJson("/api/admin/job-orders/{$jobOrder->id}")->assertOk();
        $this->actingAs($admin)->deleteJson("/api/admin/job-orders/{$jobOrder->id}/force-delete")
            ->assertUnprocessable();

        $this->assertSame(20.0, (float) JobOrder::withTrashed()->findOrFail($jobOrder->id)->amount_paid);
        $this->assertSame(1, JobOrderPayment::where('job_order_id', $jobOrder->id)->count());
    }

    public function test_reports_separate_original_financial_history_from_rto_refunds(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 'IN_PROGRESS', 1000, 0);
        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/record-payment", [
                'amount_paid' => 300,
                'idempotency_key' => (string) Str::uuid(),
                'payment_method' => 'CASH',
            ])
            ->assertOk();
        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-status", ['status' => 'RTO'])
            ->assertOk();

        $report = $this->actingAs($admin)->getJson('/api/admin/reports')->assertOk();
        $report->assertJsonPath('totalRevenue', 1000);
        $report->assertJsonPath('totalCollected', 300);
        $report->assertJsonPath('pendingReceivables', 0);
        $report->assertJsonPath('pendingRtoRefunds', 300);
        $report->assertJsonPath('issuedRtoRefunds', 0);
        $report->assertJsonPath('netRetained', 300);

        $refund = $jobOrder->refunds()->sole();
        $this->actingAs($admin)->postJson(
            "/api/admin/job-orders/{$jobOrder->id}/refunds/{$refund->id}/issue",
            ['refund_method' => 'CASH']
        )->assertOk();

        $afterRefund = $this->actingAs($admin)->getJson('/api/admin/reports')->assertOk();
        $afterRefund->assertJsonPath('totalCollected', 300);
        $afterRefund->assertJsonPath('pendingRtoRefunds', 0);
        $afterRefund->assertJsonPath('issuedRtoRefunds', 300);
        $afterRefund->assertJsonPath('netRetained', 0);
    }

    public function test_technician_cannot_mutate_parts_for_terminal_or_rto_jobs(): void
    {
        $shop = $this->createShop();
        [$technicianUser, $technician] = $this->createTechnician($shop);
        $sparePart = SparePart::create([
            'shop_id' => $shop->id,
            'name' => 'Workflow Test Part',
            'part_number' => 'WF-' . Str::upper(Str::random(8)),
            'stock_quantity' => 5,
            'unit_cost' => 10,
            'is_active' => true,
        ]);

        foreach (['READY_FOR_PICKUP', 'PICKED_UP', 'RTO', 'RTO_RELEASED'] as $status) {
            $jobOrder = $this->createJobOrder($shop, $status);
            $jobOrder->update(['technician_id' => $technician->id]);

            $this->actingAs($technicianUser)
                ->postJson("/api/technician/job-orders/{$jobOrder->id}/parts", [
                    'spare_part_id' => $sparePart->id,
                    'quantity' => 1,
                ])
                ->assertUnprocessable();

            $this->actingAs($technicianUser)
                ->postJson("/api/technician/job-orders/{$jobOrder->id}/blocked-part-requests", [
                    'part_name' => 'Replacement screen',
                    'quantity' => 1,
                ])
                ->assertUnprocessable();

            $part = JobOrderPart::create([
                'shop_id' => $shop->id,
                'job_order_id' => $jobOrder->id,
                'spare_part_id' => $sparePart->id,
                'part_name' => $sparePart->name,
                'quantity' => 1,
                'unit_cost' => 10,
                'total_cost' => 10,
            ]);

            $this->actingAs($technicianUser)
                ->deleteJson("/api/technician/job-orders/{$jobOrder->id}/parts/{$part->id}")
                ->assertUnprocessable();
        }

        $this->assertSame(5, $sparePart->fresh()->stock_quantity);
        $this->assertSame(4, JobOrderPart::count());
    }

    public function test_technician_part_mutation_still_requires_same_shop_assignment(): void
    {
        $shopA = $this->createShop();
        $shopB = $this->createShop();
        [$technicianUser, $technician] = $this->createTechnician($shopA);
        $unassigned = $this->createJobOrder($shopA, 'DIAGNOSING');
        $crossShop = $this->createJobOrder($shopB, 'DIAGNOSING');
        $crossShop->update(['technician_id' => $technician->id]);
        $sparePart = SparePart::create([
            'shop_id' => $shopA->id,
            'name' => 'Assigned Part',
            'part_number' => 'AS-' . Str::upper(Str::random(8)),
            'stock_quantity' => 2,
            'unit_cost' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($technicianUser)
            ->postJson("/api/technician/job-orders/{$unassigned->id}/parts", [
                'spare_part_id' => $sparePart->id,
                'quantity' => 1,
            ])
            ->assertForbidden();
        $this->actingAs($technicianUser)
            ->postJson("/api/technician/job-orders/{$crossShop->id}/parts", [
                'spare_part_id' => $sparePart->id,
                'quantity' => 1,
            ])
            ->assertNotFound();

        $this->assertSame(2, $sparePart->fresh()->stock_quantity);
    }

    private function createSubscriptionRequest(Shop $shop): SubscriptionRequest
    {
        return SubscriptionRequest::create([
            'shop_id' => $shop->id,
            'plan_key' => 'monthly',
            'plan_name' => 'Monthly',
            'amount' => 1500,
            'duration_months' => 1,
            'payment_method_key' => 'gcash',
            'payment_method_name' => 'GCash',
            'reference_number' => 'REF-' . Str::upper(Str::random(8)),
            'status' => SubscriptionRequest::STATUS_PENDING,
        ]);
    }

    private function createJobOrderThroughIntake(User $staff, Shop $shop, int $estimatedCost, int $payment): JobOrder
    {
        $response = $this->actingAs($staff)
            ->postJson('/api/admin/job-orders', $this->intakePayload($shop, $estimatedCost, $payment))
            ->assertCreated();

        return JobOrder::findOrFail($response->json('jobOrder.id'));
    }

    private function intakePayload(Shop $shop, int $estimatedCost, int $payment): array
    {
        $suffix = Str::upper(Str::random(8));

        return [
            'customer_name' => 'Workflow Customer ' . $suffix,
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'problem_description' => 'Workflow regression test.',
            'estimated_cost' => $estimatedCost,
            'partial_payment' => $payment,
        ];
    }

    private function createJobOrder(
        Shop $shop,
        string $status,
        int $total = 0,
        int $paid = 0,
    ): JobOrder {
        return JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'WORKFLOW-' . Str::upper(Str::random(10)),
            'customer_name' => 'Workflow Customer',
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'problem_description' => 'Workflow transaction test.',
            'current_status' => $status,
            'parts_cost' => $total,
            'labor_cost' => 0,
            'total_cost' => $total,
            'amount_paid' => $paid,
            'payment_status' => $paid <= 0 ? 'PENDING' : ($paid >= $total ? 'PAID' : 'PARTIAL'),
        ]);
    }

    private function createTechnician(Shop $shop): array
    {
        $user = $this->createUser(User::ROLE_TECHNICIAN, $shop);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Workflow Technician',
            'email' => $user->email,
            'is_active' => true,
        ]);

        return [$user, $technician];
    }

    private function createShopUser(string $role): array
    {
        $shop = $this->createShop();

        return [$shop, $this->createUser($role, $shop)];
    }

    private function createShop(): Shop
    {
        return Shop::create([
            'name' => 'Workflow Shop ' . Str::random(8),
            'slug' => 'workflow-' . Str::lower(Str::random(12)),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);
    }

    private function createUser(string $role, ?Shop $shop = null): User
    {
        return User::factory()->create([
            'shop_id' => $shop?->id,
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
