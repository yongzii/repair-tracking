<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\SubscriptionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubscriptionProofPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_unauthenticated_user_cannot_view_a_payment_proof(): void
    {
        [$subscriptionRequest] = $this->createSubscriptionRequestWithProof();

        $this->getJson($this->proofUrl($subscriptionRequest))
            ->assertUnauthorized();
    }

    public function test_inactive_users_cannot_view_a_payment_proof(): void
    {
        [$subscriptionRequest, , $shopAdmin] = $this->createSubscriptionRequestWithProof();
        $shopAdmin->update(['is_active' => false]);

        $this->actingAs($shopAdmin)
            ->getJson($this->proofUrl($subscriptionRequest))
            ->assertForbidden();

        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);
        $platformAdmin->update(['is_active' => false]);

        $this->actingAs($platformAdmin)
            ->getJson($this->proofUrl($subscriptionRequest))
            ->assertForbidden();
    }

    public function test_platform_admin_can_view_a_private_payment_proof(): void
    {
        [$subscriptionRequest] = $this->createSubscriptionRequestWithProof();
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);

        $this->actingAs($platformAdmin)
            ->get($this->proofUrl($subscriptionRequest))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_owning_shop_admin_can_view_their_payment_proof(): void
    {
        [$subscriptionRequest, , $shopAdmin] = $this->createSubscriptionRequestWithProof();

        $this->actingAs($shopAdmin)
            ->get($this->proofUrl($subscriptionRequest))
            ->assertOk();
    }

    public function test_owning_shop_admin_can_view_proofs_for_every_request_status(): void
    {
        [$subscriptionRequest, , $shopAdmin] = $this->createSubscriptionRequestWithProof();

        foreach ([
            SubscriptionRequest::STATUS_PENDING,
            SubscriptionRequest::STATUS_APPROVED,
            SubscriptionRequest::STATUS_REJECTED,
        ] as $status) {
            $subscriptionRequest->update(['status' => $status]);

            $this->actingAs($shopAdmin)
                ->get($this->proofUrl($subscriptionRequest))
                ->assertOk();
        }
    }

    public function test_legacy_admin_alias_can_only_view_proofs_for_its_own_shop(): void
    {
        [$subscriptionRequest, $shop] = $this->createSubscriptionRequestWithProof();
        $legacyAdmin = $this->createUser(User::ROLE_ADMIN, $shop);

        $this->actingAs($legacyAdmin)
            ->get($this->proofUrl($subscriptionRequest))
            ->assertOk();

        $otherShop = $this->createShop('Legacy Admin Other Shop');
        $otherShopAdmin = $this->createUser(User::ROLE_ADMIN, $otherShop);

        $this->actingAs($otherShopAdmin)
            ->getJson($this->proofUrl($subscriptionRequest))
            ->assertNotFound();
    }

    public function test_admin_from_an_inactive_shop_cannot_view_a_payment_proof(): void
    {
        [$subscriptionRequest, $shop] = $this->createSubscriptionRequestWithProof();
        $admin = $this->createUser(User::ROLE_ADMIN, $shop);
        $shop->update(['is_active' => false]);

        $this->actingAs($admin)
            ->getJson($this->proofUrl($subscriptionRequest))
            ->assertForbidden();
    }

    public function test_shop_staff_and_technicians_cannot_view_payment_proofs(): void
    {
        [$subscriptionRequest, $shop] = $this->createSubscriptionRequestWithProof();

        foreach ([User::ROLE_STAFF, User::ROLE_TECHNICIAN] as $role) {
            $user = $this->createUser($role, $shop);

            $this->actingAs($user)
                ->getJson($this->proofUrl($subscriptionRequest))
                ->assertForbidden();
        }
    }

    public function test_another_shop_admin_cannot_view_a_payment_proof(): void
    {
        [$subscriptionRequest] = $this->createSubscriptionRequestWithProof();
        $otherShop = $this->createShop('Other Shop');
        $otherShopAdmin = $this->createUser(User::ROLE_SHOP_ADMIN, $otherShop);

        $this->actingAs($otherShopAdmin)
            ->getJson($this->proofUrl($subscriptionRequest))
            ->assertNotFound();
    }

    public function test_missing_request_and_missing_private_file_return_not_found(): void
    {
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);

        $this->actingAs($platformAdmin)
            ->getJson('/api/admin/subscription/requests/999999/proof')
            ->assertNotFound();

        [$subscriptionRequest] = $this->createSubscriptionRequestWithProof();
        Storage::disk('local')->delete($subscriptionRequest->proof_path);

        $this->getJson($this->proofUrl($subscriptionRequest))
            ->assertNotFound();
    }

    public function test_public_only_payment_proof_is_not_served_by_the_protected_endpoint(): void
    {
        [$subscriptionRequest, , $shopAdmin] = $this->createSubscriptionRequestWithProof();
        $path = $subscriptionRequest->proof_path;
        Storage::disk('local')->delete($path);
        Storage::disk('public')->put($path, 'legacy public proof');

        $this->actingAs($shopAdmin)
            ->getJson($this->proofUrl($subscriptionRequest))
            ->assertNotFound();
    }

    public function test_pdf_payment_proof_is_served_with_private_headers_and_file_content(): void
    {
        [$subscriptionRequest] = $this->createSubscriptionRequestWithProof();
        $path = "shops/{$subscriptionRequest->shop_id}/subscription-proofs/proof.pdf";
        $pdfContent = "%PDF-1.4\nPayment proof test\n%%EOF";
        Storage::disk('local')->put($path, $pdfContent);
        $subscriptionRequest->update(['proof_path' => $path]);
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);

        $response = $this->actingAs($platformAdmin)
            ->get($this->proofUrl($subscriptionRequest))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache');

        ob_start();
        $response->baseResponse->sendContent();
        $servedContent = ob_get_clean();

        $this->assertSame($pdfContent, $servedContent);
    }

    public function test_shop_and_platform_responses_use_the_protected_proof_url(): void
    {
        [$subscriptionRequest, , $shopAdmin] = $this->createSubscriptionRequestWithProof();
        $platformAdmin = $this->createUser(User::ROLE_PLATFORM_ADMIN);
        $expectedUrl = route('admin.subscription.requests.proof', [
            'subscriptionRequest' => $subscriptionRequest->id,
        ]);

        $shopResponse = $this->actingAs($shopAdmin)
            ->getJson('/api/admin/subscription/options')
            ->assertOk()
            ->assertJsonPath('pending_request.proof_url', $expectedUrl);
        $this->assertStringNotContainsString('/storage/', $shopResponse->getContent());

        $platformResponse = $this->actingAs($platformAdmin)
            ->getJson('/api/platform/subscription-requests')
            ->assertOk()
            ->assertJsonPath('subscription_requests.data.0.proof_url', $expectedUrl);
        $this->assertStringNotContainsString('/storage/', $platformResponse->getContent());
    }

    public function test_shop_admin_can_retrieve_its_latest_pending_request(): void
    {
        [$subscriptionRequest, , $shopAdmin] = $this->createSubscriptionRequestWithProof();

        $this->actingAs($shopAdmin)
            ->getJson('/api/admin/subscription/request')
            ->assertOk()
            ->assertJsonPath('latest_request.id', $subscriptionRequest->id)
            ->assertJsonPath('latest_request.status', SubscriptionRequest::STATUS_PENDING)
            ->assertJsonPath('latest_request.proof_url', $this->proofUrl($subscriptionRequest));
    }

    public function test_subscription_options_return_only_the_authenticated_shops_pending_request(): void
    {
        [$otherShopRequest] = $this->createSubscriptionRequestWithProof();
        $shop = $this->createShop('Options Scope Shop');
        $shopAdmin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);
        $ownRequest = $this->createSubscriptionRequest($shop, $shopAdmin);

        $response = $this->actingAs($shopAdmin)
            ->getJson('/api/admin/subscription/options')
            ->assertOk()
            ->assertJsonPath('pending_request.id', $ownRequest->id)
            ->assertJsonPath('pending_request.status', SubscriptionRequest::STATUS_PENDING);

        $this->assertNotSame($otherShopRequest->id, $response->json('pending_request.id'));
    }

    public function test_staff_and_technicians_cannot_load_subscription_options_or_submit_requests(): void
    {
        [, $shop] = $this->createSubscriptionRequestWithProof();

        foreach ([User::ROLE_STAFF, User::ROLE_TECHNICIAN] as $role) {
            $user = $this->createUser($role, $shop);

            $this->actingAs($user)
                ->getJson('/api/admin/subscription/options')
                ->assertForbidden();

            $this->actingAs($user)
                ->post('/api/admin/subscription/requests', [
                    'plan_key' => 'monthly',
                    'payment_method_key' => 'gcash',
                    'reference_number' => 'DENIED-' . $role,
                    'proof' => UploadedFile::fake()->image('proof.jpg'),
                ])
                ->assertForbidden();
        }
    }

    public function test_shop_admin_can_retrieve_approved_and_rejected_request_statuses(): void
    {
        foreach ([SubscriptionRequest::STATUS_APPROVED, SubscriptionRequest::STATUS_REJECTED] as $status) {
            $shop = $this->createShop("{$status} Shop");
            $shopAdmin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);
            $subscriptionRequest = $this->createSubscriptionRequest($shop, $shopAdmin, $status);

            $this->actingAs($shopAdmin)
                ->getJson('/api/admin/subscription/request')
                ->assertOk()
                ->assertJsonPath('latest_request.id', $subscriptionRequest->id)
                ->assertJsonPath('latest_request.status', $status);
        }
    }

    public function test_latest_request_is_scoped_to_the_authenticated_shop(): void
    {
        [$otherShopRequest] = $this->createSubscriptionRequestWithProof();
        $owningShop = $this->createShop('Own Subscription Shop');
        $shopAdmin = $this->createUser(User::ROLE_SHOP_ADMIN, $owningShop);
        $ownRequest = $this->createSubscriptionRequest($owningShop, $shopAdmin);

        $response = $this->actingAs($shopAdmin)
            ->getJson('/api/admin/subscription/request')
            ->assertOk()
            ->assertJsonPath('latest_request.id', $ownRequest->id);

        $this->assertNotSame($otherShopRequest->id, $response->json('latest_request.id'));
    }

    public function test_staff_and_technicians_cannot_retrieve_shop_subscription_request_details(): void
    {
        [, $shop] = $this->createSubscriptionRequestWithProof();

        foreach ([User::ROLE_STAFF, User::ROLE_TECHNICIAN] as $role) {
            $user = $this->createUser($role, $shop);

            $this->actingAs($user)
                ->getJson('/api/admin/subscription/request')
                ->assertForbidden();
        }
    }

    public function test_pending_request_remains_pending_only_when_latest_request_is_approved(): void
    {
        [$pendingRequest, $shop, $shopAdmin] = $this->createSubscriptionRequestWithProof();
        $pendingRequest->forceFill(['created_at' => now()->subDay()])->save();
        $approvedRequest = $this->createSubscriptionRequest($shop, $shopAdmin, SubscriptionRequest::STATUS_APPROVED);

        $this->actingAs($shopAdmin)
            ->getJson('/api/admin/subscription/options')
            ->assertOk()
            ->assertJsonPath('pending_request.id', $pendingRequest->id)
            ->assertJsonPath('pending_request.status', SubscriptionRequest::STATUS_PENDING);

        $this->getJson('/api/admin/subscription/request')
            ->assertOk()
            ->assertJsonPath('latest_request.id', $approvedRequest->id)
            ->assertJsonPath('latest_request.status', SubscriptionRequest::STATUS_APPROVED);
    }

    public function test_new_payment_proof_upload_is_private_and_returns_a_protected_url(): void
    {
        $shop = $this->createShop('Upload Shop');
        $shopAdmin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);

        $response = $this->actingAs($shopAdmin)
            ->post('/api/admin/subscription/requests', [
                'plan_key' => 'monthly',
                'payment_method_key' => 'gcash',
                'reference_number' => 'PRIVATE-PROOF-001',
                'proof' => UploadedFile::fake()->image('proof.jpg'),
            ])
            ->assertCreated();

        $subscriptionRequest = SubscriptionRequest::firstWhere('reference_number', 'PRIVATE-PROOF-001');
        $this->assertNotNull($subscriptionRequest);
        $this->assertSame(
            route('admin.subscription.requests.proof', [
                'subscriptionRequest' => $subscriptionRequest->id,
            ]),
            $response->json('subscription_request.proof_url')
        );
        $this->assertStringStartsWith("shops/{$shop->id}/subscription-proofs/", $subscriptionRequest->proof_path);
        Storage::disk('local')->assertExists($subscriptionRequest->proof_path);
        Storage::disk('public')->assertMissing($subscriptionRequest->proof_path);
        $this->assertStringNotContainsString('/storage/', $subscriptionRequest->proofUrl());
    }

    private function createSubscriptionRequestWithProof(): array
    {
        $shop = $this->createShop('Proof Shop');
        $shopAdmin = $this->createUser(User::ROLE_SHOP_ADMIN, $shop);
        $path = UploadedFile::fake()->image('proof.jpg')
            ->store("shops/{$shop->id}/subscription-proofs", 'local');

        $subscriptionRequest = SubscriptionRequest::create([
            'shop_id' => $shop->id,
            'requested_by_id' => $shopAdmin->id,
            'plan_key' => 'monthly',
            'plan_name' => 'Monthly',
            'amount' => 1500,
            'duration_months' => 1,
            'payment_method_key' => 'gcash',
            'payment_method_name' => 'GCash',
            'reference_number' => 'PROOF-' . str()->random(8),
            'proof_path' => $path,
            'status' => SubscriptionRequest::STATUS_PENDING,
        ]);

        return [$subscriptionRequest, $shop, $shopAdmin];
    }

    private function createSubscriptionRequest(
        Shop $shop,
        User $shopAdmin,
        string $status = SubscriptionRequest::STATUS_PENDING
    ): SubscriptionRequest {
        return SubscriptionRequest::create([
            'shop_id' => $shop->id,
            'requested_by_id' => $shopAdmin->id,
            'plan_key' => 'monthly',
            'plan_name' => 'Monthly',
            'amount' => 1500,
            'duration_months' => 1,
            'payment_method_key' => 'gcash',
            'payment_method_name' => 'GCash',
            'reference_number' => 'REQUEST-' . str()->random(8),
            'status' => $status,
            'reviewed_at' => $status === SubscriptionRequest::STATUS_PENDING ? null : now(),
        ]);
    }

    private function createShop(string $name): Shop
    {
        return Shop::create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'subscription_plan' => 'starter',
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

    private function proofUrl(SubscriptionRequest $subscriptionRequest): string
    {
        return route('admin.subscription.requests.proof', [
            'subscriptionRequest' => $subscriptionRequest->id,
        ]);
    }
}
