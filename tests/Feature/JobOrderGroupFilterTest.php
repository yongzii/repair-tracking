<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobOrderGroupFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_and_released_groups_separate_released_jobs_from_the_work_queue(): void
    {
        $shop = Shop::create([
            'name' => 'Job Group Test Shop',
            'slug' => 'job-group-test-shop',
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);
        $staff = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_STAFF,
            'is_active' => true,
        ]);

        foreach ([
            ['GROUP-RECEIVED', 'RECEIVED'],
            ['GROUP-READY', 'READY_FOR_PICKUP'],
            ['GROUP-RTO', 'RTO'],
            ['GROUP-RELEASED', 'PICKED_UP'],
            ['GROUP-RTO-RELEASED', 'RTO_RELEASED'],
        ] as [$number, $status]) {
            JobOrder::create([
                'shop_id' => $shop->id,
                'job_order_number' => $number,
                'customer_name' => 'Group Customer',
                'customer_phone' => '09000000000',
                'device_type' => 'Phone',
                'problem_description' => 'Needs repair.',
                'current_status' => $status,
            ]);
        }

        $this->actingAs($staff)
            ->getJson('/api/admin/job-orders?job_group=active')
            ->assertOk()
            ->assertJsonPath('jobOrders.total', 3)
            ->assertJsonCount(3, 'jobOrders.data')
            ->assertJsonMissing(['job_order_number' => 'GROUP-RELEASED'])
            ->assertJsonMissing(['job_order_number' => 'GROUP-RTO-RELEASED']);

        $this->actingAs($staff)
            ->getJson('/api/admin/job-orders?job_group=released')
            ->assertOk()
            ->assertJsonPath('jobOrders.total', 2)
            ->assertJsonCount(2, 'jobOrders.data')
            ->assertJsonFragment(['job_order_number' => 'GROUP-RELEASED'])
            ->assertJsonFragment(['job_order_number' => 'GROUP-RTO-RELEASED']);
    }

    public function test_job_order_group_filter_rejects_unknown_groups(): void
    {
        $shop = Shop::create([
            'name' => 'Invalid Group Test Shop',
            'slug' => 'invalid-group-test-shop',
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);
        $staff = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_STAFF,
            'is_active' => true,
        ]);

        $this->actingAs($staff)
            ->getJson('/api/admin/job-orders?job_group=unknown')
            ->assertUnprocessable();
    }
}
