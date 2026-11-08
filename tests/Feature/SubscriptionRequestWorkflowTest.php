<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\SubscriptionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_shop_without_a_pending_request_can_submit_and_retrieve_its_pending_status(): void
    {
        $shop = $this->createShop();
        $admin = $this->createShopAdmin($shop);

        $this->actingAs($admin)
            ->postJson('/api/admin/subscription/requests', $this->requestPayload())
            ->assertCreated()
            ->assertJsonPath('subscription_request.status', SubscriptionRequest::STATUS_PENDING);

        $this->actingAs($admin)
            ->getJson('/api/admin/subscription/options')
            ->assertOk()
            ->assertJsonPath('pending_request.status', SubscriptionRequest::STATUS_PENDING);

        $this->assertSame(1, $shop->subscriptionRequests()->where('status', SubscriptionRequest::STATUS_PENDING)->count());
    }

    public function test_existing_pending_request_blocks_another_direct_api_submission(): void
    {
        $shop = $this->createShop();
        $admin = $this->createShopAdmin($shop);
        $pendingRequest = $this->createSubscriptionRequest($shop, SubscriptionRequest::STATUS_PENDING);

        $this->actingAs($admin)
            ->postJson('/api/admin/subscription/requests', $this->requestPayload())
            ->assertConflict()
            ->assertJsonPath('pending_request.id', $pendingRequest->id)
            ->assertJsonPath('pending_request.status', SubscriptionRequest::STATUS_PENDING);

        $this->assertSame(1, $shop->subscriptionRequests()->count());
    }

    public function test_rapid_repeated_submissions_create_only_one_pending_request(): void
    {
        $shop = $this->createShop();
        $admin = $this->createShopAdmin($shop);

        $this->actingAs($admin)
            ->postJson('/api/admin/subscription/requests', $this->requestPayload())
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/admin/subscription/requests', $this->requestPayload())
            ->assertConflict();

        $this->assertSame(1, $shop->subscriptionRequests()->where('status', SubscriptionRequest::STATUS_PENDING)->count());
        $this->assertCount(1, Storage::disk('local')->allFiles("shops/{$shop->id}/subscription-proofs"));
    }

    public function test_another_shop_can_submit_its_own_pending_request(): void
    {
        $firstShop = $this->createShop();
        $secondShop = $this->createShop();
        $firstAdmin = $this->createShopAdmin($firstShop);
        $secondAdmin = $this->createShopAdmin($secondShop);

        $this->actingAs($firstAdmin)
            ->postJson('/api/admin/subscription/requests', $this->requestPayload())
            ->assertCreated();

        $this->actingAs($secondAdmin)
            ->postJson('/api/admin/subscription/requests', $this->requestPayload())
            ->assertCreated();

        $this->assertSame(1, $firstShop->subscriptionRequests()->where('status', SubscriptionRequest::STATUS_PENDING)->count());
        $this->assertSame(1, $secondShop->subscriptionRequests()->where('status', SubscriptionRequest::STATUS_PENDING)->count());
    }

    public function test_rejected_request_does_not_prevent_resubmission(): void
    {
        $shop = $this->createShop();
        $admin = $this->createShopAdmin($shop);
        $this->createSubscriptionRequest($shop, SubscriptionRequest::STATUS_REJECTED);

        $this->actingAs($admin)
            ->postJson('/api/admin/subscription/requests', $this->requestPayload())
            ->assertCreated()
            ->assertJsonPath('subscription_request.status', SubscriptionRequest::STATUS_PENDING);

        $this->assertSame(2, $shop->subscriptionRequests()->count());
    }

    public function test_active_subscription_cannot_create_a_new_shop_admin_request(): void
    {
        $shop = $this->createShop([
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);
        $admin = $this->createShopAdmin($shop);

        $this->actingAs($admin)
            ->postJson('/api/admin/subscription/requests', $this->requestPayload())
            ->assertConflict()
            ->assertJsonPath('message', 'This shop already has active subscription access. New requests are not available while access is active.');

        $this->assertSame(0, $shop->subscriptionRequests()->count());
    }

    public function test_expired_subscription_can_submit_a_renewal_request(): void
    {
        $shop = $this->createShop([
            'trial_ends_at' => now()->subDay(),
            'is_active' => true,
        ]);
        $admin = $this->createShopAdmin($shop);

        $this->assertFalse($shop->hasActiveAccess());
        $this->assertTrue($shop->isInCloseOutMode());

        $this->actingAs($admin)
            ->postJson('/api/admin/subscription/requests', $this->requestPayload())
            ->assertCreated()
            ->assertJsonPath('subscription_request.status', SubscriptionRequest::STATUS_PENDING);

        $this->assertSame(1, $shop->subscriptionRequests()->where('status', SubscriptionRequest::STATUS_PENDING)->count());
    }

    private function requestPayload(): array
    {
        return [
            'plan_key' => 'monthly',
            'payment_method_key' => 'gcash',
            'reference_number' => 'REF-' . Str::upper(Str::random(8)),
            'proof' => UploadedFile::fake()->image('proof.jpg'),
        ];
    }

    private function createSubscriptionRequest(Shop $shop, string $status): SubscriptionRequest
    {
        return $shop->subscriptionRequests()->create([
            'plan_key' => 'monthly',
            'plan_name' => 'Monthly',
            'amount' => 1500,
            'duration_months' => 1,
            'payment_method_key' => 'gcash',
            'payment_method_name' => 'GCash',
            'status' => $status,
        ]);
    }

    private function createShop(array $attributes = []): Shop
    {
        return Shop::create(array_merge([
            'name' => 'Subscription Shop ' . Str::random(8),
            'slug' => 'subscription-' . Str::lower(Str::random(12)),
            'is_active' => true,
            'trial_ends_at' => null,
        ], $attributes));
    }

    private function createShopAdmin(Shop $shop): User
    {
        return User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_SHOP_ADMIN,
            'is_active' => true,
        ]);
    }
}
