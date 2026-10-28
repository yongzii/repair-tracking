<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\JobOrder;
use App\Models\Shop;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAlertCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_admin_and_staff_receive_shop_scoped_pending_request_and_overdue_counts(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        [$otherShop] = $this->createShopUser(User::ROLE_STAFF);

        Appointment::create([
            'shop_id' => $shop->id,
            'confirmation_code' => 'ALERT-PENDING-001',
            'customer_name' => 'Pending Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'CONSULTATION',
            'device_type' => 'Phone',
            'concern' => 'Needs repair.',
            'preferred_at' => now()->addDay(),
            'status' => 'PENDING',
        ]);
        Appointment::create([
            'shop_id' => $otherShop->id,
            'confirmation_code' => 'ALERT-PENDING-002',
            'customer_name' => 'Other Shop Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'CONSULTATION',
            'device_type' => 'Phone',
            'concern' => 'Needs repair.',
            'preferred_at' => now()->addDay(),
            'status' => 'PENDING',
        ]);
        JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'ALERT-OVERDUE-001',
            'customer_name' => 'Overdue Customer',
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'problem_description' => 'Does not power on.',
            'current_status' => 'IN_PROGRESS',
            'estimated_completion' => now()->subHour(),
        ]);
        JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'ALERT-NOT-OVERDUE-001',
            'customer_name' => 'Future Customer',
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'problem_description' => 'Does not power on.',
            'current_status' => 'IN_PROGRESS',
            'estimated_completion' => now()->addDay(),
        ]);

        foreach ([$admin, $this->createShopUserForShop($shop, User::ROLE_STAFF)] as $user) {
            $this->actingAs($user)
                ->getJson('/api/admin/alerts')
                ->assertOk()
                ->assertExactJson([
                    'pending_customer_requests' => 1,
                    'overdue_jobs' => 1,
                ]);
        }
    }

    public function test_technician_alert_count_includes_only_open_jobs_assigned_to_that_technician(): void
    {
        [$shop, $user] = $this->createShopUser(User::ROLE_TECHNICIAN);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Alert Technician',
            'email' => $user->email,
            'is_active' => true,
        ]);

        foreach ([
            ['ALERT-TECH-OPEN-001', 'IN_PROGRESS'],
            ['ALERT-TECH-OPEN-002', 'READY_FOR_PICKUP'],
            ['ALERT-TECH-DONE-001', 'PICKED_UP'],
        ] as [$number, $status]) {
            JobOrder::create([
                'shop_id' => $shop->id,
                'technician_id' => $technician->id,
                'job_order_number' => $number,
                'customer_name' => 'Technician Customer',
                'customer_phone' => '09123456789',
                'device_type' => 'Phone',
                'problem_description' => 'Does not power on.',
                'current_status' => $status,
            ]);
        }

        $this->actingAs($user)
            ->getJson('/api/technician/alerts')
            ->assertOk()
            ->assertExactJson(['open_assigned_jobs' => 2]);
    }

    public function test_shop_staff_cannot_access_technician_alert_counts(): void
    {
        [, $staff] = $this->createShopUser(User::ROLE_STAFF);

        $this->actingAs($staff)
            ->getJson('/api/technician/alerts')
            ->assertForbidden();
    }

    private function createShopUser(string $role): array
    {
        $shop = Shop::create([
            'name' => 'Alert Test Shop ' . uniqid(),
            'slug' => 'alert-test-shop-' . uniqid(),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);

        return [$shop, $this->createShopUserForShop($shop, $role)];
    }

    private function createShopUserForShop(Shop $shop, string $role): User
    {
        return User::factory()->create([
            'shop_id' => $shop->id,
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
