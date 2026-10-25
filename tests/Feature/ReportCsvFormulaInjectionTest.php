<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportCsvFormulaInjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_export_neutralizes_formula_prefixes_and_preserves_normal_text(): void
    {
        $shop = Shop::create([
            'name' => 'CSV Test Shop',
            'slug' => 'csv-test-shop',
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);
        $admin = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $customerNames = [
            '=1+1',
            '+SUM(1,1)',
            '-1+1',
            '@SUM(1,1)',
            " \t=1+1",
            "\x01@SUM(1,1)",
            'Normal Customer',
        ];

        foreach ($customerNames as $index => $customerName) {
            JobOrder::create([
                'shop_id' => $shop->id,
                'job_order_number' => 'TEST-CSV-FORMULA-' . $index,
                'customer_name' => $customerName,
                'customer_phone' => '09123456789',
                'device_type' => 'Phone',
                'problem_description' => 'CSV formula test.',
                'current_status' => 'RECEIVED',
                'total_cost' => 0,
                'amount_paid' => 0,
            ]);
        }

        $csv = $this->actingAs($admin)
            ->get('/api/admin/reports/export/csv?report_type=operations')
            ->assertOk()
            ->streamedContent();

        $rows = array_map(
            fn (string $line) => str_getcsv($line),
            explode("\n", trim($csv))
        );
        $jobRows = array_values(array_filter(
            $rows,
            fn (array $row) => isset($row[1]) && str_starts_with($row[1], 'TEST-CSV-FORMULA-')
        ));
        $exportedNames = array_column($jobRows, 2);

        $this->assertCount(count($customerNames), $jobRows);
        $this->assertEqualsCanonicalizing([
            "'=1+1",
            "'+SUM(1,1)",
            "'-1+1",
            "'@SUM(1,1)",
            "' \t=1+1",
            "'\x01@SUM(1,1)",
            'Normal Customer',
        ], $exportedNames);
        $this->assertStringNotContainsString(',"=1+1",', $csv);
        $this->assertStringContainsString(',"Normal Customer",', $csv);
    }
}
