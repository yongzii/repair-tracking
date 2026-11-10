<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\Shop;
use App\Models\User;
use App\Models\WarrantyAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarrantyAssessmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_covered_warranty_assessment_creates_linked_warranty_service_job(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createPickedUpWarrantyJob($shop);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/warranty-assessments", [
                'reported_issue' => 'Screen flickers again after repair.',
                'checked_by' => 'Technician Mark',
                'result' => WarrantyAssessment::RESULT_COVERED,
                'assessment_notes' => 'Same repaired part failed; no liquid or physical damage found.',
            ])
            ->assertCreated()
            ->assertJsonPath('assessment.result', WarrantyAssessment::RESULT_COVERED)
            ->assertJsonPath('assessment.warranty_job_order.current_status', 'RECEIVED');

        $assessment = WarrantyAssessment::firstOrFail();
        $warrantyJob = JobOrder::findOrFail($assessment->warranty_job_order_id);

        $this->assertSame('WARRANTY_SERVICE', $warrantyJob->service_type);
        $this->assertSame($jobOrder->id, $warrantyJob->warranty_parent_job_order_id);
        $this->assertSame('PAID', $warrantyJob->payment_status);
        $this->assertSame('Screen flickers again after repair.', $warrantyJob->problem_description);
    }

    public function test_staff_can_issue_warranty_service_directly_from_original_job_order(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createPickedUpWarrantyJob($shop);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/issue-warranty-service")
            ->assertCreated()
            ->assertJsonPath('message', 'Warranty service job created.')
            ->assertJsonPath('assessment.result', WarrantyAssessment::RESULT_COVERED)
            ->assertJsonPath('warrantyJobOrder.current_status', 'RECEIVED');

        $assessment = WarrantyAssessment::firstOrFail();
        $warrantyJob = JobOrder::findOrFail($assessment->warranty_job_order_id);

        $this->assertSame('WARRANTY_SERVICE', $warrantyJob->service_type);
        $this->assertSame($jobOrder->id, $warrantyJob->warranty_parent_job_order_id);
        $this->assertSame('PAID', $warrantyJob->payment_status);
        $this->assertSame(0.0, (float) $warrantyJob->total_cost);
        $this->assertStringStartsWith("Warranty service for {$jobOrder->job_order_number}", $warrantyJob->problem_description);
    }

    public function test_direct_warranty_service_blocks_duplicate_active_service_job(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createPickedUpWarrantyJob($shop);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/issue-warranty-service")
            ->assertCreated();

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/issue-warranty-service")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('warranty_service');

        $this->assertDatabaseCount('warranty_assessments', 1);
        $this->assertDatabaseCount('job_orders', 2);
    }

    public function test_direct_warranty_service_requires_released_active_warranty_original_job(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createPickedUpWarrantyJob($shop);
        $jobOrder->update([
            'current_status' => 'READY_FOR_PICKUP',
            'picked_up_at' => null,
            'payment_status' => 'PARTIAL',
            'amount_paid' => 500,
        ]);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/issue-warranty-service")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('warranty_service');

        $this->assertDatabaseCount('warranty_assessments', 0);
        $this->assertDatabaseCount('job_orders', 1);
    }

    public function test_direct_warranty_service_allows_released_original_job_with_outstanding_balance(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createPickedUpWarrantyJob($shop);
        $jobOrder->update([
            'payment_status' => 'PARTIAL',
            'amount_paid' => 500,
        ]);

        $original = $jobOrder->fresh();
        $financialState = [
            'payment_status' => $original->payment_status,
            'total_cost' => (float) $original->total_cost,
            'amount_paid' => (float) $original->amount_paid,
            'balance' => (float) $original->balance,
        ];

        $this->assertGreaterThan(0, $financialState['balance']);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/issue-warranty-service")
            ->assertCreated()
            ->assertJsonPath('message', 'Warranty service job created.');

        $updated = $jobOrder->fresh();
        $this->assertSame($financialState['payment_status'], $updated->payment_status);
        $this->assertSame($financialState['total_cost'], (float) $updated->total_cost);
        $this->assertSame($financialState['amount_paid'], (float) $updated->amount_paid);
        $this->assertSame($financialState['balance'], (float) $updated->balance);
    }

    public function test_warranty_setup_is_rejected_after_release(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createPickedUpWarrantyJob($shop);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-warranty", [
                'warranty_days' => 60,
                'warranty_details' => 'Attempted replacement warranty.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('warranty');

        $this->assertSame(30, $jobOrder->fresh()->warranty_days);
    }

    public function test_warranty_service_is_allowed_on_the_final_valid_warranty_date(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createPickedUpWarrantyJob($shop);
        $jobOrder->update(['picked_up_at' => today()->subDays(30)->startOfDay()]);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/issue-warranty-service")
            ->assertCreated();
    }

    public function test_warranty_service_is_rejected_after_expiration_with_clear_message(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createPickedUpWarrantyJob($shop);
        $jobOrder->update(['picked_up_at' => today()->subDays(31)->startOfDay()]);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/issue-warranty-service")
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'warranty_service' => 'Warranty period has expired. Warranty Service is no longer available for this Job Order.',
            ]);
    }

    public function test_warranty_service_is_rejected_when_no_warranty_was_issued(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createPickedUpWarrantyJob($shop);
        $jobOrder->update(['warranty_days' => 0, 'warranty_details' => null]);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/issue-warranty-service")
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'warranty_service' => 'No warranty was issued for this Job Order. Warranty Service is unavailable.',
            ]);
    }

    public function test_staff_can_cancel_newly_issued_warranty_service_job(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createPickedUpWarrantyJob($shop);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/issue-warranty-service")
            ->assertCreated();

        $warrantyJob = JobOrder::where('service_type', 'WARRANTY_SERVICE')->firstOrFail();

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$warrantyJob->id}/cancel-warranty-service")
            ->assertOk()
            ->assertJsonPath('parentJobOrder.id', $jobOrder->id);

        $this->assertSoftDeleted('job_orders', ['id' => $warrantyJob->id]);
        $this->assertDatabaseCount('warranty_assessments', 0);
    }

    public function test_not_covered_warranty_assessment_does_not_create_service_job(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createPickedUpWarrantyJob($shop);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/warranty-assessments", [
                'reported_issue' => 'Device no longer charges.',
                'checked_by' => 'Technician Mark',
                'result' => WarrantyAssessment::RESULT_NOT_COVERED,
                'assessment_notes' => 'Charging issue is unrelated to original screen repair.',
            ])
            ->assertCreated()
            ->assertJsonPath('assessment.result', WarrantyAssessment::RESULT_NOT_COVERED)
            ->assertJsonPath('assessment.warranty_job_order', null);

        $this->assertDatabaseCount('warranty_assessments', 1);
        $this->assertDatabaseCount('job_orders', 1);
    }

    public function test_tracking_page_response_includes_warranty_status_and_assessment_summary(): void
    {
        [$shop] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createPickedUpWarrantyJob($shop);

        WarrantyAssessment::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'reported_issue' => 'Screen flickers again after repair.',
            'brought_at' => now(),
            'checked_by' => 'Technician Mark',
            'result' => WarrantyAssessment::RESULT_NEEDS_DIAGNOSIS,
            'created_by' => 'Staff User',
        ]);

        $this->getJson("/api/shops/{$shop->slug}/track/{$jobOrder->job_order_number}")
            ->assertOk()
            ->assertJsonPath('jobOrder.is_under_warranty', true)
            ->assertJsonMissingPath('jobOrder.payment_status')
            ->assertJsonMissingPath('jobOrder.total_cost')
            ->assertJsonMissingPath('jobOrder.amount_paid')
            ->assertJsonMissingPath('jobOrder.balance')
            ->assertJsonMissingPath('jobOrder.is_fully_paid')
            ->assertJsonPath('warrantyAssessments.0.result', WarrantyAssessment::RESULT_NEEDS_DIAGNOSIS)
            ->assertJsonPath('warrantyAssessments.0.reported_issue', 'Screen flickers again after repair.');
    }

    public function test_print_data_uses_configured_shop_terms_for_claim_and_warranty_slips(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $shop->update([
            'claim_terms' => "Custom claim term one.\n\nCustom claim term two.",
            'warranty_terms' => "Custom warranty term one.\nCustom warranty term two.",
        ]);
        $jobOrder = $this->createPickedUpWarrantyJob($shop);

        $this->actingAs($admin)
            ->getJson("/api/admin/job-orders/{$jobOrder->id}/print-data")
            ->assertOk()
            ->assertJsonPath('claim_terms', ['Custom claim term one.', 'Custom claim term two.'])
            ->assertJsonPath('warranty_terms', ['Custom warranty term one.', 'Custom warranty term two.'])
            ->assertJsonPath('warranty.days', 30)
            ->assertJsonPath('warranty.starts_at_formatted', $jobOrder->picked_up_at->format('M d, Y'));
    }

    public function test_print_data_uses_existing_system_default_terms_when_shop_terms_are_empty(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $shop->update([
            'claim_terms' => " \n ",
            'warranty_terms' => null,
        ]);
        $jobOrder = $this->createPickedUpWarrantyJob($shop);

        $this->actingAs($admin)
            ->getJson("/api/admin/job-orders/{$jobOrder->id}/print-data")
            ->assertOk()
            ->assertJsonPath('claim_terms.0', 'Check-up and diagnosis are free of charge.')
            ->assertJsonPath('claim_terms', fn (array $terms) => count($terms) === 7)
            ->assertJsonPath('warranty_terms.0', 'Warranty covers only the specific repair or replacement listed on this slip.')
            ->assertJsonPath('warranty_terms', fn (array $terms) => count($terms) === 5);
    }

    public function test_warranty_service_invoice_is_labeled_as_service_summary(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-WARRANTY-SUMMARY-' . $shop->id,
            'service_type' => 'WARRANTY_SERVICE',
            'customer_name' => 'Customer Name',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'problem_description' => 'Warranty service.',
            'current_status' => 'RECEIVED',
            'payment_status' => 'PAID',
            'total_cost' => 0,
            'amount_paid' => 0,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/admin/job-orders/{$jobOrder->id}/invoice")
            ->assertOk()
            ->assertJsonPath('document.type', 'service_summary')
            ->assertJsonPath('document.title', 'Service Summary')
            ->assertJsonPath('document.is_warranty_service', true)
            ->assertJsonPath('invoice.invoice_number', 'WS-' . str_pad((string) $jobOrder->id, 6, '0', STR_PAD_LEFT));

        $ordinaryJobOrder = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-ORDINARY-SUMMARY-' . $shop->id,
            'service_type' => 'STANDARD',
            'customer_name' => 'Customer Name',
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'problem_description' => 'Ordinary repair.',
            'current_status' => 'IN_PROGRESS',
            'estimated_cost' => 600,
            'total_cost' => 700,
            'amount_paid' => 0,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/admin/job-orders/{$ordinaryJobOrder->id}/invoice")
            ->assertOk()
            ->assertJsonPath('document.type', 'invoice')
            ->assertJsonPath('document.is_warranty_service', false);
    }

    public function test_print_data_contains_current_financial_summary_without_customer_contact(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-PRINT-DATA-' . $shop->id,
            'customer_name' => 'Customer Name',
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'problem_description' => 'Screen issue.',
            'current_status' => 'IN_PROGRESS',
            'estimated_cost' => 600,
            'parts_cost' => 200,
            'labor_cost' => 500,
            'total_cost' => 700,
            'payment_status' => 'PENDING',
            'amount_paid' => 0,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/admin/job-orders/{$jobOrder->id}/print-data")
            ->assertOk()
            ->assertJsonPath('customer.name', 'Customer Name')
            ->assertJsonMissingPath('customer.phone')
            ->assertJsonPath('costs.estimated_cost', '600.00')
            ->assertJsonPath('costs.total_cost', '700.00')
            ->assertJsonPath('costs.amount_paid', '0.00')
            ->assertJsonPath('costs.balance', 600)
            ->assertJsonPath('costs.payment_status', 'PENDING')
            ->assertJsonPath('tracking.claim_code', $jobOrder->job_order_number);

        $this->assertSame(600.0, (float) $jobOrder->fresh()->estimated_cost);
        $this->assertSame(700.0, (float) $jobOrder->fresh()->total_cost);
        $this->assertSame(0.0, (float) $jobOrder->fresh()->amount_paid);
    }

    public function test_warranty_print_data_is_rejected_without_issued_warranty(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-NO-WARRANTY-PRINT-' . $shop->id,
            'customer_name' => 'Customer Name',
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'problem_description' => 'Screen issue.',
            'current_status' => 'IN_PROGRESS',
            'warranty_days' => 0,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/admin/job-orders/{$jobOrder->id}/print-data?warranty=1")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('warranty');
    }

    public function test_general_and_dedicated_updates_enforce_the_warranty_duration_limit(): void
    {
        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createPickedUpWarrantyJob($shop);
        $jobOrder->update([
            'current_status' => 'READY_FOR_PICKUP',
            'picked_up_at' => null,
        ]);

        $this->actingAs($staff)
            ->putJson("/api/admin/job-orders/{$jobOrder->id}", $this->jobOrderUpdatePayload(3650))
            ->assertOk()
            ->assertJsonPath('jobOrder.warranty_days', 3650);

        $this->actingAs($staff)
            ->putJson("/api/admin/job-orders/{$jobOrder->id}", $this->jobOrderUpdatePayload(3651))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('warranty_days');

        $this->assertSame(3650, $jobOrder->fresh()->warranty_days);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-warranty", [
                'warranty_days' => 3650,
            ])
            ->assertOk();

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/update-warranty", [
                'warranty_days' => 3651,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('warranty_days');

        $this->assertSame(3650, $jobOrder->fresh()->warranty_days);
    }

    private function createShopUser(string $role): array
    {
        $shop = Shop::create([
            'name' => 'Warranty Test Shop ' . $role,
            'slug' => 'warranty-test-shop-' . $role,
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

    private function createPickedUpWarrantyJob(Shop $shop): JobOrder
    {
        return JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TEST-WARRANTY-' . $shop->id,
            'customer_name' => 'Customer Name',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'brand' => 'Apple',
            'model' => 'iPhone',
            'problem_description' => 'Screen replacement.',
            'current_status' => 'PICKED_UP',
            'payment_status' => 'PAID',
            'total_cost' => 2500,
            'amount_paid' => 2500,
            'warranty_days' => 30,
            'warranty_details' => 'Screen replacement warranty only.',
            'picked_up_at' => now()->subDays(5),
            'picked_up_by' => 'Customer Name',
        ]);
    }

    private function jobOrderUpdatePayload(int $warrantyDays): array
    {
        return [
            'customer_name' => 'Customer Name',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'service_category_id' => null,
            'service_item_id' => null,
            'brand' => 'Apple',
            'model' => 'iPhone',
            'serial_number' => null,
            'problem_description' => 'Screen replacement.',
            'included_accessories' => null,
            'priority' => 'STANDARD',
            'warranty_days' => $warrantyDays,
            'estimated_completion' => null,
            'technician_id' => null,
        ];
    }
}
