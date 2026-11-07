<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPendingPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_payments_matches_current_report_balance_rules(): void
    {
        [$shop, $staff] = $this->createShopUser('dashboard-pending-shop', User::ROLE_STAFF);
        [$otherShop] = $this->createShopUser('dashboard-other-shop', User::ROLE_STAFF);
        $admin = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->createJobOrder($shop, 'DASH-NORMAL', 'READY_FOR_PICKUP', 1000, 0);
        $this->createJobOrder($shop, 'DASH-PARTIAL', 'IN_PROGRESS', 2500, 500);
        $this->createJobOrder($shop, 'DASH-FULL', 'PICKED_UP', 1000, 1000);
        $this->createJobOrder($shop, 'DASH-OVERPAID', 'PICKED_UP', 500, 700);
        $this->createJobOrder($shop, 'DASH-RTO', 'RTO', 6000, 0);
        $this->createJobOrder($shop, 'DASH-RTO-RELEASED', 'RTO_RELEASED', 6000, 0);
        $deletedJob = $this->createJobOrder($shop, 'DASH-DELETED', 'READY_FOR_PICKUP', 4000, 0);
        $deletedJob->delete();
        $this->createJobOrder($otherShop, 'DASH-OTHER-SHOP', 'READY_FOR_PICKUP', 3000, 0);

        $this->actingAs($staff)
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('pendingPayments', 3000);

        $this->actingAs($admin)
            ->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonPath('paymentSummary.current_pending_balances', 3000)
            ->assertJsonPath('paymentSummary.current_pending_balance_jobs', 2);
    }

    private function createShopUser(string $slug, string $role): array
    {
        $shop = Shop::create([
            'name' => $slug,
            'slug' => $slug,
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

    private function createJobOrder(
        Shop $shop,
        string $number,
        string $status,
        int $totalCost,
        int $amountPaid,
    ): JobOrder {
        return JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => $number,
            'customer_name' => 'Dashboard Test Customer',
            'customer_phone' => '09000000000',
            'device_type' => 'Phone',
            'problem_description' => 'Dashboard pending payment test.',
            'current_status' => $status,
            'total_cost' => $totalCost,
            'amount_paid' => $amountPaid,
        ]);
    }
}
