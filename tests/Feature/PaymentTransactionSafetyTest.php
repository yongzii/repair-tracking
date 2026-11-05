<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\JobOrderPayment;
use App\Models\JobOrderRefund;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentTransactionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_record_a_full_payment_atomically(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 1000);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/record-payment", [
                'amount_paid' => 1000,
                'idempotency_key' => (string) Str::uuid(),
                'payment_type' => 'FINAL_PAYMENT',
            ])
            ->assertOk()
            ->assertJsonPath('jobOrder.amount_paid', '1000.00')
            ->assertJsonPath('jobOrder.payment_status', 'PAID')
            ->assertJsonPath('payment.payment_type', 'FINAL_PAYMENT');

        $this->assertDatabaseCount('job_order_payments', 1);
        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'amount_paid' => 1000,
            'payment_status' => 'PAID',
        ]);
    }

    public function test_staff_can_record_a_partial_payment(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 1000);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/record-payment", [
                'amount_paid' => 300,
                'idempotency_key' => (string) Str::uuid(),
                'payment_type' => 'PARTIAL_PAYMENT',
            ])
            ->assertOk()
            ->assertJsonPath('jobOrder.amount_paid', '300.00')
            ->assertJsonPath('jobOrder.payment_status', 'PARTIAL');

        $this->assertDatabaseCount('job_order_payments', 1);
        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'amount_paid' => 300,
            'payment_status' => 'PARTIAL',
        ]);
    }

    public function test_partial_payment_cannot_be_labeled_as_final(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 1000);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/record-payment", [
                'amount_paid' => 300,
                'idempotency_key' => (string) Str::uuid(),
                'payment_type' => 'FINAL_PAYMENT',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_type');

        $this->assertDatabaseCount('job_order_payments', 0);
        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'amount_paid' => 0,
            'payment_status' => 'PENDING',
        ]);
    }

    public function test_payment_cannot_exceed_the_locked_balance(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 1000, 700);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/record-payment", [
                'amount_paid' => 400,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_paid');

        $this->assertDatabaseCount('job_order_payments', 0);
        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'amount_paid' => 700,
            'payment_status' => 'PARTIAL',
        ]);
    }

    public function test_payment_and_aggregate_roll_back_together_when_payment_creation_fails(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 1000);
        $listener = function () {
            throw new \RuntimeException('Simulated payment creation failure.');
        };
        JobOrderPayment::creating($listener);

        try {
            $this->withoutExceptionHandling();

            $this->actingAs($staff)
                ->postJson("/api/admin/job-orders/{$jobOrder->id}/record-payment", [
                    'amount_paid' => 1000,
                    'idempotency_key' => (string) Str::uuid(),
                ]);
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated payment creation failure.', $exception->getMessage());
        } finally {
            JobOrderPayment::flushEventListeners();
        }

        $this->assertDatabaseCount('job_order_payments', 0);
        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'amount_paid' => 0,
            'payment_status' => 'PENDING',
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'job_order.payment_recorded',
            'record_id' => $jobOrder->id,
        ]);
    }

    public function test_payment_recording_preserves_authorization_and_tenant_isolation(): void
    {
        [$shopA, $staffA] = $this->createShopUser(User::ROLE_STAFF);
        [$shopB] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrderB = $this->createJobOrder($shopB, 1000);

        $this->actingAs($staffA)
            ->postJson("/api/admin/job-orders/{$jobOrderB->id}/record-payment", [
                'amount_paid' => 100,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('job_order_payments', 0);
        $this->assertSame($shopA->id, $staffA->fresh()->shop_id);
        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrderB->id,
            'amount_paid' => 0,
            'payment_status' => 'PENDING',
        ]);
    }

    private function createShopUser(string $role): array
    {
        $shop = Shop::create([
            'name' => 'Payment Test Shop ' . uniqid(),
            'slug' => 'payment-test-shop-' . uniqid(),
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

    private function createJobOrder(Shop $shop, int $totalCost, int $amountPaid = 0): JobOrder
    {
        return JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-PAYMENT-' . uniqid(),
            'customer_name' => 'Payment Test Customer',
            'customer_phone' => '09000000000',
            'device_type' => 'Phone',
            'problem_description' => 'Payment transaction test.',
            'current_status' => 'DIAGNOSING',
            'total_cost' => $totalCost,
            'amount_paid' => $amountPaid,
            'payment_status' => $amountPaid > 0 ? 'PARTIAL' : 'PENDING',
        ]);
    }

    private function createPaymentRecord(
        ?int $shopId,
        JobOrder $jobOrder,
        float $amount,
        ?string $receivedAt = null,
        array $attributes = [],
    ): JobOrderPayment {
        return JobOrderPayment::create(array_replace([
            'shop_id' => $shopId,
            'job_order_id' => $jobOrder->id,
            'amount' => $amount,
            'payment_type' => 'PARTIAL_PAYMENT',
            'payment_method' => 'CASH',
            'received_at' => $receivedAt ?? now(),
        ], $attributes));
    }

    private function createRefundRecord(
        int $shopId,
        JobOrder $jobOrder,
        float $amount,
        string $status,
        string $initiatedAt,
        ?string $issuedAt = null,
    ): JobOrderRefund {
        return JobOrderRefund::create([
            'shop_id' => $shopId,
            'job_order_id' => $jobOrder->id,
            'amount' => $amount,
            'status' => $status,
            'reason' => 'Report test refund',
            'refund_method' => $status === JobOrderRefund::STATUS_ISSUED ? 'CASH' : null,
            'initiated_at' => $initiatedAt,
            'issued_at' => $issuedAt,
        ]);
    }

    public function test_payment_report_excludes_cross_shop_job_relationships_and_preserves_owned_totals(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        [$otherShop] = $this->createShopUser(User::ROLE_STAFF);
        $ownedJob = $this->createJobOrder($shop, 1000);
        $foreignJob = $this->createJobOrder($otherShop, 1000);
        $foreignJob->update(['customer_name' => 'Foreign Payment Customer']);

        $this->createPaymentRecord($shop->id, $ownedJob, 100);
        $this->createPaymentRecord($shop->id, $foreignJob, 900);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/reports?report_type=payments')
            ->assertOk()
            ->assertJsonPath('paymentSummary.total_records', 1)
            ->assertJsonPath('paymentSummary.total_amount', 100)
            ->assertJsonPath('paymentMethodBreakdown.0.amount', 100)
            ->assertJsonPath('paymentRecords.0.job_order_number', $ownedJob->job_order_number);

        $response->assertJsonMissing(['job_order_number' => $foreignJob->job_order_number]);
        $response->assertJsonMissing(['customer_name' => $foreignJob->customer_name]);
    }

    public function test_refund_report_excludes_cross_shop_jobs_and_keeps_owned_pending_and_issued_totals(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        [$otherShop] = $this->createShopUser(User::ROLE_STAFF);
        $ownedJob = $this->createJobOrder($shop, 1000);
        $foreignJob = $this->createJobOrder($otherShop, 1000);
        $foreignJob->update(['customer_name' => 'Foreign Refund Customer']);

        $this->createRefundRecord($shop->id, $ownedJob, 20, JobOrderRefund::STATUS_PENDING, '2025-04-10 09:00:00');
        $this->createRefundRecord($shop->id, $ownedJob, 30, JobOrderRefund::STATUS_ISSUED, '2025-04-10 10:00:00', '2025-04-11 11:00:00');
        $this->createRefundRecord($shop->id, $foreignJob, 900, JobOrderRefund::STATUS_PENDING, '2025-04-10 12:00:00');
        $this->createRefundRecord($otherShop->id, $ownedJob, 700, JobOrderRefund::STATUS_PENDING, '2025-04-10 13:00:00');

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/reports?report_type=payments')
            ->assertOk()
            ->assertJsonPath('paymentSummary.pending_refund_obligations', 20)
            ->assertJsonPath('paymentSummary.issued_refunds', 30)
            ->assertJsonPath('filteredJobOrders.0.refunds_pending', 20)
            ->assertJsonPath('filteredJobOrders.0.refunds_issued', 30)
            ->assertJsonCount(2, 'refundRecords');

        $response->assertJsonMissing(['job_order_number' => $foreignJob->job_order_number]);
        $response->assertJsonMissing(['customer_name' => $foreignJob->customer_name]);
    }

    public function test_refund_details_use_the_matching_pending_or_issued_event_date_and_blank_dates_include_all(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 1000);

        $pendingInRange = $this->createRefundRecord(
            $shop->id,
            $jobOrder,
            20,
            JobOrderRefund::STATUS_PENDING,
            '2025-04-10 09:00:00',
        );
        $pendingOutsideRange = $this->createRefundRecord(
            $shop->id,
            $jobOrder,
            30,
            JobOrderRefund::STATUS_PENDING,
            '2025-04-13 09:00:00',
        );
        $issuedInRange = $this->createRefundRecord(
            $shop->id,
            $jobOrder,
            40,
            JobOrderRefund::STATUS_ISSUED,
            '2025-04-01 09:00:00',
            '2025-04-11 09:00:00',
        );
        $issuedOutsideRange = $this->createRefundRecord(
            $shop->id,
            $jobOrder,
            50,
            JobOrderRefund::STATUS_ISSUED,
            '2025-04-10 10:00:00',
            '2025-04-13 10:00:00',
        );

        $filtered = $this->actingAs($admin)
            ->getJson('/api/admin/reports?start_date=2025-04-10&end_date=2025-04-12&report_type=payments')
            ->assertOk()
            ->assertJsonPath('paymentSummary.pending_refund_obligations', 20)
            ->assertJsonPath('paymentSummary.issued_refunds', 40)
            ->assertJsonCount(2, 'refundRecords');
        $filteredRefunds = collect($filtered->json('refundRecords'))->keyBy('id');
        $this->assertArrayHasKey($pendingInRange->id, $filteredRefunds);
        $this->assertArrayHasKey($issuedInRange->id, $filteredRefunds);
        $this->assertArrayNotHasKey($pendingOutsideRange->id, $filteredRefunds);
        $this->assertArrayNotHasKey($issuedOutsideRange->id, $filteredRefunds);
        $this->assertStringContainsString('Apr 01, 2025', $filteredRefunds[$issuedInRange->id]['initiated_at']);
        $this->assertStringContainsString('Apr 11, 2025', $filteredRefunds[$issuedInRange->id]['issued_at']);

        $dateQuery = 'start_date=2025-04-10&end_date=2025-04-12&report_type=payments';
        $csv = $this->actingAs($admin)
            ->get("/api/admin/reports/export/csv?{$dateQuery}")
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('Pending refunds are filtered by initiated_at; issued refunds are filtered by issued_at.', $csv);
        $this->assertStringContainsString('Apr 01, 2025', $csv);

        $this->actingAs($admin)
            ->get("/api/admin/reports/export/pdf?{$dateQuery}")
            ->assertOk()
            ->assertSee('Pending refunds are filtered by initiated_at; issued refunds are filtered by issued_at.')
            ->assertSee('Apr 01, 2025');

        $allDates = $this->actingAs($admin)
            ->getJson('/api/admin/reports?report_type=payments')
            ->assertOk()
            ->assertJsonPath('paymentSummary.pending_refund_obligations', 50)
            ->assertJsonPath('paymentSummary.issued_refunds', 90)
            ->assertJsonCount(4, 'refundRecords');
        $allRefunds = collect($allDates->json('refundRecords'))->keyBy('id');
        $this->assertArrayHasKey($pendingOutsideRange->id, $allRefunds);
        $this->assertArrayHasKey($issuedOutsideRange->id, $allRefunds);
    }

    public function test_payment_report_does_not_attribute_legacy_null_shop_payments_to_the_job_shop(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 1000);

        $this->createPaymentRecord($shop->id, $jobOrder, 100);
        $this->createPaymentRecord(null, $jobOrder, 900);

        $this->actingAs($admin)
            ->getJson('/api/admin/reports?report_type=payments')
            ->assertOk()
            ->assertJsonPath('paymentSummary.total_records', 1)
            ->assertJsonPath('paymentSummary.total_amount', 100)
            ->assertJsonCount(1, 'paymentRecords');
    }

    public function test_payment_report_uses_transaction_amounts_and_preserves_method_and_type_groups(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 5000, 7);

        $this->createPaymentRecord($shop->id, $jobOrder, 10, '2025-04-10 09:00:00', [
            'payment_method' => 'CASH',
            'payment_type' => 'PARTIAL_PAYMENT',
            'reference_number' => 'TXN-CASH-10',
            'notes' => 'Counter payment',
            'received_by' => 'Shop Cashier',
        ]);
        $this->createPaymentRecord($shop->id, $jobOrder, 20, '2025-04-10 10:00:00', [
            'payment_method' => 'GCASH',
            'payment_type' => 'FINAL_PAYMENT',
        ]);
        $this->createPaymentRecord($shop->id, $jobOrder, 30, '2025-04-10 11:00:00', [
            'payment_method' => null,
            'payment_type' => '',
        ]);
        $this->createPaymentRecord($shop->id, $jobOrder, 40, '2025-04-10 12:00:00', [
            'payment_method' => '   ',
            'payment_type' => '   ',
        ]);
        $unknown = $this->createPaymentRecord($shop->id, $jobOrder, 50, '2025-04-10 13:00:00', [
            'payment_method' => 'QRPH_CUSTOM',
        ]);
        $legacyType = $this->createPaymentRecord($shop->id, $jobOrder, 60, '2025-04-10 14:00:00');
        DB::table('job_order_payments')->where('id', $legacyType->id)->update(['payment_type' => 'DOWN_PAYMENT']);
        DB::table('job_order_payments')->where('id', $unknown->id)->update(['payment_type' => 'CUSTOM_LEGACY_TYPE']);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/reports?report_type=payments')
            ->assertOk()
            ->assertJsonPath('paymentSummary.total_amount', 210)
            ->assertJsonPath('paymentSummary.total_records', 6);

        $methods = collect($response->json('paymentMethodBreakdown'))->keyBy('payment_method');
        $this->assertEquals(70, $methods['CASH']['amount']);
        $this->assertEquals(20, $methods['GCASH']['amount']);
        $this->assertEquals(70, $methods['UNSPECIFIED']['amount']);
        $this->assertEquals(50, $methods['QRPH_CUSTOM']['amount']);

        $types = collect($response->json('paymentTypeBreakdown'))->keyBy('payment_type');
        $this->assertEquals(70, $types['PARTIAL_PAYMENT']['amount']);
        $this->assertEquals(20, $types['FINAL_PAYMENT']['amount']);
        $this->assertEquals(70, $types['UNSPECIFIED']['amount']);
        $this->assertEquals(50, $types['CUSTOM_LEGACY_TYPE']['amount']);
        $this->assertSame(7.0, (float) $jobOrder->fresh()->amount_paid);
        $cashPayment = collect($response->json('paymentRecords'))->firstWhere('amount', 10);
        $this->assertSame('received_at', $cashPayment['payment_date_basis']);
        $this->assertSame('TXN-CASH-10', $cashPayment['reference_number']);
        $this->assertSame('Counter payment', $cashPayment['notes']);
        $this->assertSame('Shop Cashier', $cashPayment['received_by']);
    }

    public function test_current_pending_balance_snapshot_includes_picked_up_jobs_and_ignores_report_dates(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $this->createJobOrder($shop, 1000, 250);
        $pickedUp = $this->createJobOrder($shop, 500, 100);
        $pickedUp->update(['current_status' => 'PICKED_UP']);
        $rto = $this->createJobOrder($shop, 200);
        $rto->update(['current_status' => 'RTO']);
        $rtoReleased = $this->createJobOrder($shop, 300);
        $rtoReleased->update(['current_status' => 'RTO_RELEASED']);
        $this->createJobOrder($shop, 100, 150);

        $this->actingAs($admin)
            ->getJson('/api/admin/reports?report_type=payments&start_date=2099-01-01&end_date=2099-01-31')
            ->assertOk()
            ->assertJsonPath('paymentSummary.total_records', 0)
            ->assertJsonPath('paymentSummary.total_amount', 0)
            ->assertJsonPath('paymentSummary.current_pending_balances', 1150)
            ->assertJsonPath('paymentSummary.current_pending_balance_jobs', 2);
    }

    public function test_payment_history_filters_inclusively_by_received_at_and_shows_created_at_fallback(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 1000);
        $this->createPaymentRecord($shop->id, $jobOrder, 10, '2025-04-10 00:00:00');
        $this->createPaymentRecord($shop->id, $jobOrder, 20, '2025-04-12 23:59:59');
        $this->createPaymentRecord($shop->id, $jobOrder, 30, '2025-04-09 23:59:59');
        $this->createPaymentRecord($shop->id, $jobOrder, 40, '2025-04-13 00:00:00');
        $this->createPaymentRecord($shop->id, $jobOrder, 50, null, ['received_at' => null]);

        $filtered = $this->actingAs($admin)
            ->getJson('/api/admin/reports?report_type=payments&start_date=2025-04-10&end_date=2025-04-12')
            ->assertOk()
            ->assertJsonPath('paymentSummary.total_amount', 30)
            ->assertJsonPath('paymentSummary.total_records', 2)
            ->assertJsonCount(2, 'paymentRecords');

        $allDates = $this->actingAs($admin)
            ->getJson('/api/admin/reports?report_type=payments')
            ->assertOk()
            ->assertJsonPath('paymentSummary.total_amount', 150)
            ->assertJsonPath('paymentSummary.total_records', 5)
            ->assertJsonCount(5, 'paymentRecords');
        $fallbackPayment = collect($allDates->json('paymentRecords'))->firstWhere('amount', 50);
        $this->assertSame('created_at (display fallback)', $fallbackPayment['payment_date_basis']);
        $this->assertTrue($fallbackPayment['received_at_is_fallback']);
        $this->assertNotEmpty($filtered->json('paymentRecords.0.id'));
    }

    public function test_payment_history_is_limited_to_latest_thirty_while_summary_covers_all_transactions(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop, 1000);
        $payments = collect();

        for ($index = 1; $index <= 31; $index++) {
            $payments->push($this->createPaymentRecord($shop->id, $jobOrder, 1, '2025-04-10 09:00:00'));
        }

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/reports?report_type=payments')
            ->assertOk()
            ->assertJsonPath('paymentSummary.total_records', 31)
            ->assertJsonPath('paymentSummary.total_amount', 31)
            ->assertJsonCount(30, 'paymentRecords');

        $listedIds = collect($response->json('paymentRecords'))->pluck('id');
        $this->assertNotContains($payments->first()->id, $listedIds);
        $this->assertContains($payments->last()->id, $listedIds);

        $csv = $this->actingAs($admin)
            ->get('/api/admin/reports/export/csv?report_type=payments')
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('Customer Collections', $csv);
        $this->assertStringContainsString('Payment Transaction History (Latest 30)', $csv);
        $this->assertStringContainsString('summary totals include all matching transactions', strtolower($csv));

        $pdf = $this->actingAs($admin)
            ->get('/api/admin/reports/export/pdf?report_type=payments')
            ->assertOk();
        $pdf->assertSee('Payment Transaction History (Latest 30)')
            ->assertSee('Customer Collections')
            ->assertSee('Current Pending Customer Balances')
            ->assertSee('not verified bank settlements');
    }

    public function test_same_key_retries_return_the_original_payment_without_duplicate_rows_or_audits(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 1000);
        $key = (string) Str::uuid();
        $payload = [
            'amount_paid' => 300,
            'idempotency_key' => $key,
            'payment_method' => 'CASH',
            'payment_notes' => 'First installment',
        ];

        $first = $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/record-payment", $payload)
            ->assertOk();
        $paymentId = $first->json('payment.id');

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/record-payment", $payload)
            ->assertOk()
            ->assertJsonPath('payment.id', $paymentId)
            ->assertJsonPath('jobOrder.amount_paid', '300.00');

        $this->assertDatabaseCount('job_order_payments', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'job_order.payment_recorded',
            'record_id' => $jobOrder->id,
        ]);
        $audit = \App\Models\AuditLog::firstOrFail();
        $this->assertSame($paymentId, $audit->new_values['payment_id']);
        $this->assertSame($key, $audit->new_values['idempotency_key']);
    }

    public function test_same_key_with_different_amount_is_rejected_without_modifying_original_payment(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 1000);
        $key = (string) Str::uuid();
        $payload = ['amount_paid' => 300, 'idempotency_key' => $key];

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/record-payment", $payload)
            ->assertOk();

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/record-payment", [
                'amount_paid' => 500,
                'idempotency_key' => $key,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('job_order_payments', 1);
        $this->assertDatabaseHas('job_orders', ['id' => $jobOrder->id, 'amount_paid' => 300]);
    }

    public function test_same_key_cannot_be_reused_for_another_job_order(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $firstJob = $this->createJobOrder($shop, 1000);
        $secondJob = $this->createJobOrder($shop, 1000);
        $key = (string) Str::uuid();

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$firstJob->id}/record-payment", [
                'amount_paid' => 300,
                'idempotency_key' => $key,
            ])
            ->assertOk();

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$secondJob->id}/record-payment", [
                'amount_paid' => 300,
                'idempotency_key' => $key,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('job_order_payments', 1);
        $this->assertDatabaseHas('job_orders', ['id' => $secondJob->id, 'amount_paid' => 0]);
    }

    public function test_different_keys_allow_legitimate_same_amount_payments(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 1000);

        foreach ([Str::uuid(), Str::uuid()] as $key) {
            $this->actingAs($staff)
                ->postJson("/api/admin/job-orders/{$jobOrder->id}/record-payment", [
                    'amount_paid' => 300,
                    'idempotency_key' => (string) $key,
                ])
                ->assertOk();
        }

        $this->assertDatabaseCount('job_order_payments', 2);
        $this->assertDatabaseHas('job_orders', ['id' => $jobOrder->id, 'amount_paid' => 600]);
    }

    public function test_legacy_payment_without_an_idempotency_key_remains_valid(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop, 1000, 300);
        JobOrderPayment::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'amount' => 300,
            'payment_type' => 'PARTIAL_PAYMENT',
        ]);

        $this->assertDatabaseHas('job_order_payments', [
            'job_order_id' => $jobOrder->id,
            'idempotency_key' => null,
        ]);
    }
}