<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\JobOrderPart;
use App\Models\JobOrderPayment;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobOrderSalesCapitalReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_and_capital_report_uses_historical_part_cost_and_actual_payment_methods(): void
    {
        $shop = Shop::create([
            'name' => 'Sales Capital Shop',
            'slug' => 'sales-capital-shop',
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);
        $admin = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $jobWithParts = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'CAPITAL-001',
            'customer_name' => 'Customer With Parts',
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'problem_description' => 'Screen repair',
            'current_status' => 'IN_PROGRESS',
            'total_cost' => 1000,
            'amount_paid' => 150,
        ]);
        JobOrderPart::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobWithParts->id,
            'part_name' => 'Historical Screen',
            'quantity' => 1,
            'unit_cost' => 275,
            'total_cost' => 275,
        ]);
        JobOrderPayment::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobWithParts->id,
            'amount' => 100,
            'payment_type' => 'PARTIAL_PAYMENT',
            'payment_method' => 'CASH',
            'received_at' => now(),
        ]);
        JobOrderPayment::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobWithParts->id,
            'amount' => 50,
            'payment_type' => 'FINAL_PAYMENT',
            'payment_method' => 'GCASH',
            'received_at' => now(),
        ]);

        $jobWithoutParts = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'CAPITAL-002',
            'customer_name' => 'Customer Without Parts',
            'customer_phone' => '09123456780',
            'device_type' => 'Tablet',
            'problem_description' => 'Diagnostics',
            'current_status' => 'RECEIVED',
            'total_cost' => 500,
            'amount_paid' => 0,
        ]);

        $response = $this->actingAs($admin)->get('/api/admin/reports');

        $response->assertOk()
            ->assertJsonPath('jobOrderSalesCapital.summary.total_gross', 1500)
            ->assertJsonPath('jobOrderSalesCapital.summary.total_capital', 275)
            ->assertJsonPath('jobOrderSalesCapital.summary.gross_less_capital', 1225);

        $rows = collect($response->json('jobOrderSalesCapital.rows'))->keyBy('tracking_number');
        $this->assertEquals(275, $rows['CAPITAL-001']['capital']);
        $this->assertSame('Cash + GCash', $rows['CAPITAL-001']['payment_method']);
        $this->assertNull($rows['CAPITAL-002']['capital']);
        $this->assertSame('Unpaid', $rows['CAPITAL-002']['payment_method']);
    }
}
