<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\JobOrderPayment;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ])
            ->assertOk()
            ->assertJsonPath('jobOrder.amount_paid', '1000.00')
            ->assertJsonPath('jobOrder.payment_status', 'PAID');

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