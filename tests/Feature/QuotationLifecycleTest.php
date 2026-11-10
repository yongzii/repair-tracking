<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\JobOrder;
use App\Models\JobOrderPayment;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuotationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_intake_preserves_null_zero_and_positive_quotes_and_system_diagnosis_option(): void
    {
        $shop = $this->createShop();
        $staff = $this->createUser(User::ROLE_STAFF, $shop);
        $laptop = $this->createCategory($shop, 'Laptop');
        $phone = $this->createCategory($shop, 'Cellphone');
        $laptopService = $this->createService($shop, $laptop, 'Screen repair');
        $phoneService = $this->createService($shop, $phone, 'Charging repair');
        $otherShop = $this->createShop();
        $otherCategory = $this->createCategory($otherShop, 'Laptop');
        $otherShopService = $this->createService($otherShop, $otherCategory, 'Other shop service');

        $meta = $this->actingAs($staff)->getJson('/api/admin/job-orders/meta')->assertOk();
        $catalog = collect($meta->json('serviceCatalog'))->keyBy('id');
        $this->assertSame(
            [$laptopService->id],
            collect($catalog[$laptop->id]['services'])->pluck('id')->all()
        );
        $this->assertSame(
            [$phoneService->id],
            collect($catalog[$phone->id]['services'])->pluck('id')->all()
        );
        $this->assertDatabaseMissing('service_items', ['name' => 'To Be Diagnosed']);
        $this->actingAs($staff)
            ->postJson('/api/admin/job-orders', $this->intakePayload(
                $laptop,
                'to_be_diagnosed',
                null,
                10
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('estimated_cost');

        $unquoted = $this->createThroughIntake($staff, $this->intakePayload(
            $laptop,
            'to_be_diagnosed',
            null
        ));
        $zero = $this->createThroughIntake($staff, $this->intakePayload(
            $laptop,
            $laptopService->id,
            0
        ));
        $positive = $this->createThroughIntake($staff, $this->intakePayload(
            $phone,
            $phoneService->id,
            875
        ));

        $this->assertNull($unquoted->estimated_cost);
        $this->assertSame(0.0, (float) $unquoted->total_cost);
        $this->assertSame(0.0, (float) $zero->estimated_cost);
        $this->assertSame(0.0, (float) $zero->total_cost);
        $this->assertSame(875.0, (float) $positive->estimated_cost);
        $this->assertSame(875.0, (float) $positive->total_cost);
        $this->assertSame(0, $unquoted->payments()->count());
        $this->assertSame(0, $zero->payments()->count());
        $this->actingAs($staff)
            ->getJson("/api/admin/job-orders/{$unquoted->id}/print-data")
            ->assertOk()
            ->assertJsonPath('costs.estimated_cost', null);

        $this->actingAs($staff)
            ->postJson('/api/admin/job-orders', $this->intakePayload($laptop, $otherShopService->id, 100))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_item_id');
    }

    public function test_shop_staff_can_quote_existing_diagnosis_without_creating_a_duplicate_and_reason_is_audited(): void
    {
        $shop = $this->createShop();
        $staff = $this->createUser(User::ROLE_STAFF, $shop);
        $category = $this->createCategory($shop, 'Laptop');
        $service = $this->createService($shop, $category, 'Board repair');
        $jobOrder = $this->createThroughIntake($staff, $this->intakePayload(
            $category,
            'to_be_diagnosed',
            null
        ));

        $this->actingAs($staff)
            ->putJson("/api/admin/job-orders/{$jobOrder->id}", $this->updatePayload(
                $category,
                $service,
                750,
                'Diagnosis completed; approved repair price.'
            ))
            ->assertOk()
            ->assertJsonPath('jobOrder.estimated_cost', '750.00')
            ->assertJsonPath('jobOrder.total_cost', '750.00');

        $jobOrder->refresh();
        $this->assertSame(1, JobOrder::where('id', $jobOrder->id)->count());
        $this->assertSame($service->id, $jobOrder->service_item_id);
        $this->assertSame(750.0, (float) $jobOrder->estimated_cost);
        $this->assertSame(750.0, (float) $jobOrder->total_cost);

        $audit = AuditLog::query()
            ->where('action', 'job_order.updated')
            ->where('record_id', $jobOrder->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($staff->id, $audit->user_id);
        $this->assertNull($audit->old_values['estimated_cost']);
        $this->assertSame('750.00', (string) $audit->new_values['estimated_cost']);
        $this->assertStringContainsString('Diagnosis completed; approved repair price.', $audit->description);
        $this->assertNotNull($audit->created_at);
    }

    public function test_quotation_edits_follow_existing_shop_authorization_and_isolation(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);
        $staff = $this->createUser(User::ROLE_STAFF, $shop);
        $technician = $this->createUser(User::ROLE_TECHNICIAN, $shop);
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);
        $category = $this->createCategory($shop, 'Laptop');
        $service = $this->createService($shop, $category, 'Keyboard repair');
        $jobOrder = $this->createThroughIntake($admin, $this->intakePayload(
            $category,
            'to_be_diagnosed',
            null
        ));
        $payload = $this->updatePayload($category, $service, 500);

        $this->actingAs($staff)
            ->putJson("/api/admin/job-orders/{$jobOrder->id}", $payload)
            ->assertOk();
        $this->actingAs($technician)
            ->putJson("/api/admin/job-orders/{$jobOrder->id}", $this->updatePayload($category, $service, 600))
            ->assertForbidden();
        $this->actingAs($platformAdmin)
            ->putJson("/api/admin/job-orders/{$jobOrder->id}", $this->updatePayload($category, $service, 600))
            ->assertForbidden();
        $this->actingAs($admin)
            ->putJson("/api/admin/job-orders/{$jobOrder->id}", $this->updatePayload($category, $service, 600))
            ->assertOk();

        $otherShop = $this->createShop();
        $otherAdmin = $this->createUser(User::ROLE_SHOP_ADMIN, $otherShop);
        $this->actingAs($otherAdmin)
            ->putJson("/api/admin/job-orders/{$jobOrder->id}", $this->updatePayload($category, $service, 700))
            ->assertNotFound();

        $this->assertSame(600.0, (float) $jobOrder->fresh()->estimated_cost);
        $this->assertSame(600.0, (float) $jobOrder->fresh()->total_cost);
    }

    public function test_existing_inactive_service_can_be_preserved_while_updating_a_quotation(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);
        $category = $this->createCategory($shop, 'Laptop');
        $service = $this->createService($shop, $category, 'Legacy service');
        $jobOrder = $this->createThroughIntake($admin, $this->intakePayload($category, $service->id, 400));
        $service->update(['is_active' => false]);

        $this->actingAs($admin)
            ->putJson("/api/admin/job-orders/{$jobOrder->id}", $this->updatePayload($category, $service, 500))
            ->assertOk();

        $this->assertSame($service->id, $jobOrder->fresh()->service_item_id);
        $this->assertSame(500.0, (float) $jobOrder->fresh()->estimated_cost);
    }

    public function test_unquoted_orders_block_payment_and_release_but_zero_quoted_orders_are_valid(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);
        $category = $this->createCategory($shop, 'Laptop');
        $service = $this->createService($shop, $category, 'Inspection');
        $unquoted = $this->createThroughIntake($admin, $this->intakePayload(
            $category,
            'to_be_diagnosed',
            null
        ));

        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$unquoted->id}/record-payment", [
                'amount_paid' => 1,
                'idempotency_key' => (string) Str::uuid(),
                'payment_method' => 'CASH',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_paid');

        $unquoted->update(['current_status' => 'READY_FOR_PICKUP']);
        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$unquoted->id}/mark-picked-up", [
                'picked_up_by' => 'Customer',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('picked_up_by');
        $this->assertNull($unquoted->fresh()->picked_up_at);

        $zeroQuote = $this->createThroughIntake($admin, $this->intakePayload($category, $service->id, 0));
        $this->assertSame(0.0, (float) $zeroQuote->estimated_cost);
        $zeroQuote->update(['current_status' => 'READY_FOR_PICKUP']);
        $this->actingAs($admin)
            ->postJson("/api/admin/job-orders/{$zeroQuote->id}/mark-picked-up", [
                'picked_up_by' => 'Customer',
            ])
            ->assertOk();
    }

    public function test_positive_quotes_support_initial_and_succeeding_payments_and_idempotent_retries(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);
        $category = $this->createCategory($shop, 'Laptop');
        $service = $this->createService($shop, $category, 'Screen repair');
        $jobOrder = $this->createThroughIntake($admin, $this->intakePayload(
            $category,
            $service->id,
            1000,
            200
        ));
        $this->assertSame(1000.0, (float) $jobOrder->total_cost);
        $this->assertSame(800.0, (float) $jobOrder->fresh()->balance);
        $this->assertSame(1, $jobOrder->payments()->count());

        $key = (string) Str::uuid();
        $paymentUrl = "/api/admin/job-orders/{$jobOrder->id}/record-payment";
        $payload = [
            'amount_paid' => 300,
            'idempotency_key' => $key,
            'payment_method' => 'CASH',
        ];
        $first = $this->actingAs($admin)->postJson($paymentUrl, $payload)->assertOk();
        $this->assertSame(500.0, (float) $jobOrder->fresh()->balance);
        $this->actingAs($admin)->postJson($paymentUrl, $payload)
            ->assertOk()
            ->assertJsonPath('payment.id', $first->json('payment.id'));
        $this->assertSame(2, $jobOrder->payments()->count());

        $this->actingAs($admin)->postJson($paymentUrl, [
            'amount_paid' => 500,
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => 'CASH',
        ])->assertOk();
        $this->assertSame(0.0, (float) $jobOrder->fresh()->balance);
        $this->assertSame(3, $jobOrder->payments()->count());

        $this->actingAs($admin)
            ->putJson("/api/admin/job-orders/{$jobOrder->id}", $this->updatePayload($category, $service, 999))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('estimated_cost');
        $this->assertSame(1000.0, (float) $jobOrder->fresh()->estimated_cost);
        $this->assertSame(3, $jobOrder->payments()->count());
    }

    private function createThroughIntake(User $user, array $payload): JobOrder
    {
        $response = $this->actingAs($user)
            ->postJson('/api/admin/job-orders', $payload)
            ->assertCreated();

        return JobOrder::findOrFail($response->json('jobOrder.id'));
    }

    private function intakePayload(
        ServiceCategory $category,
        int|string $serviceId,
        ?float $estimate,
        ?float $initialPayment = null,
    ): array {
        return [
            'customer_name' => 'Quotation Customer ' . Str::random(8),
            'customer_phone' => '09123456789',
            'device_type' => $category->name,
            'service_category_id' => $category->id,
            'service_item_id' => $serviceId,
            'problem_description' => 'Quotation lifecycle test.',
            'estimated_cost' => $estimate,
            'partial_payment' => $initialPayment,
        ];
    }

    private function updatePayload(
        ServiceCategory $category,
        ServiceItem $service,
        float $estimate,
        ?string $reason = null,
    ): array {
        return [
            'customer_name' => 'Updated quotation customer',
            'customer_phone' => '09123456789',
            'device_type' => $category->name,
            'service_category_id' => $category->id,
            'service_item_id' => $service->id,
            'problem_description' => 'Diagnosis complete.',
            'estimated_cost' => $estimate,
            'quotation_change_reason' => $reason,
        ];
    }

    private function createCategory(Shop $shop, string $name): ServiceCategory
    {
        return ServiceCategory::create([
            'shop_id' => $shop->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function createService(Shop $shop, ServiceCategory $category, string $name): ServiceItem
    {
        return ServiceItem::create([
            'shop_id' => $shop->id,
            'service_category_id' => $category->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function createShop(): Shop
    {
        return Shop::create([
            'name' => 'Quotation Shop ' . Str::random(8),
            'slug' => 'quotation-' . Str::lower(Str::random(12)),
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
