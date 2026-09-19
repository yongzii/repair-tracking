<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicTrackingRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_tracking_is_rate_limited_after_thirty_requests(): void
    {
        $this->createTrackingFixture();

        for ($request = 1; $request <= 30; $request++) {
            $this->getJson('/api/track?job_order_number=missing-tracking-number')
                ->assertUnprocessable();
        }

        $this->getJson('/api/track?job_order_number=missing-tracking-number')
            ->assertTooManyRequests();
    }

    public function test_shop_specific_tracking_is_rate_limited_after_thirty_requests(): void
    {
        $shop = $this->createTrackingFixture();

        for ($request = 1; $request <= 30; $request++) {
            $this->getJson("/api/shops/{$shop->slug}/track/missing-tracking-number")
                ->assertNotFound();
        }

        $this->getJson("/api/shops/{$shop->slug}/track/missing-tracking-number")
            ->assertTooManyRequests();
    }

    public function test_legacy_generic_tracking_is_rate_limited_after_thirty_requests(): void
    {
        $this->createTrackingFixture();

        for ($request = 1; $request <= 30; $request++) {
            $this->getJson('/api/track/missing-tracking-number')
                ->assertUnprocessable();
        }

        $this->getJson('/api/track/missing-tracking-number')
            ->assertTooManyRequests();
    }

    public function test_legacy_shop_tracking_is_rate_limited_after_thirty_requests(): void
    {
        $shop = $this->createTrackingFixture();

        for ($request = 1; $request <= 30; $request++) {
            $this->getJson("/api/shops/{$shop->slug}/track?job_order_number=missing-tracking-number")
                ->assertNotFound();
        }

        $this->getJson("/api/shops/{$shop->slug}/track?job_order_number=missing-tracking-number")
            ->assertTooManyRequests();
    }

    private function createTrackingFixture(): Shop
    {
        $shop = Shop::create([
            'name' => 'Tracking Rate Limit Shop',
            'slug' => 'tracking-rate-limit-shop-' . uniqid(),
            'subscription_plan' => 'starter',
            'is_active' => true,
        ]);

        JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'TRACK-RATE-LIMIT-' . uniqid(),
            'customer_name' => 'Tracking Customer',
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'problem_description' => 'No power.',
            'current_status' => 'RECEIVED',
        ]);

        return $shop;
    }
}
