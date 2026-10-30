<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShopBrandingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_business_hours(): void
    {
        [$shop, $admin] = $this->createShopAdmin();

        $this->actingAs($admin)
            ->putJson('/api/admin/shop', [
                'name' => $shop->name,
                'slug' => $shop->slug,
                'email' => 'shop@example.com',
                'phone' => '09561234567',
                'address' => 'Main Street',
                'business_hours' => [
                    'monday' => ['open' => true, 'open_time' => '08:30', 'close_time' => '17:30'],
                    'sunday' => ['open' => false, 'open_time' => '09:00', 'close_time' => '18:00'],
                ],
                'primary_color' => '#0f766e',
                'default_warranty_days' => 30,
                'claim_terms' => null,
                'warranty_terms' => null,
                'notification_settings' => [
                    'email_enabled' => true,
                    'sms_enabled' => false,
                    'events' => ['RECEIVED' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('shop.business_hours_configured', true)
            ->assertJsonPath('shop.business_hours.monday.open', true)
            ->assertJsonPath('shop.business_hours.monday.open_time', '08:30')
            ->assertJsonPath('shop.business_hours.sunday.open', false);

        $shop->refresh();

        $this->assertSame('08:30', $shop->business_hours['monday']['open_time']);
        $this->assertFalse($shop->business_hours['sunday']['open']);
    }

    public function test_shop_settings_can_be_saved_without_default_warranty_days_and_preserve_the_stored_value(): void
    {
        [$shop, $admin] = $this->createShopAdmin();
        $shop->update(['default_warranty_days' => 45]);

        $this->actingAs($admin)
            ->putJson('/api/admin/shop', [
                'name' => $shop->name,
                'slug' => $shop->slug,
                'claim_terms' => null,
                'warranty_terms' => null,
            ])
            ->assertOk()
            ->assertJsonPath('shop.default_warranty_days', 45);

        $this->assertSame(45, $shop->fresh()->default_warranty_days);

        $settingsPage = file_get_contents(resource_path('js/spa/pages/admin/ShopSettingsPage.vue'));
        $this->assertIsString($settingsPage);
        $this->assertStringNotContainsString('Warranty Days', $settingsPage);
        $this->assertStringContainsString('Claim Stub Terms', $settingsPage);
        $printPage = file_get_contents(resource_path('js/spa/pages/admin/JobOrderPrintPage.vue'));
        $this->assertIsString($printPage);
        $this->assertStringContainsString('Claim Stub and Job Order Slip', $printPage);
        $this->assertStringContainsString('<h2>Claim Stub</h2>', $printPage);
        $this->assertStringNotContainsString('Claim Stab', $printPage);
        $jobOrdersPage = file_get_contents(resource_path('js/spa/pages/admin/JobOrdersPage.vue'));
        $this->assertIsString($jobOrdersPage);
        $this->assertStringContainsString('claim stub and job order slip', $jobOrdersPage);
        $this->assertStringContainsString(
            'Customize the terms displayed on printed Claim Stubs. Leave empty to use the system default terms.',
            $settingsPage
        );
        $this->assertStringContainsString(
            'Customize the terms displayed on printed Warranty Slips. Leave empty to use the system default terms.',
            $settingsPage
        );
        $jobOrderPage = file_get_contents(resource_path('js/spa/pages/admin/JobOrderShowPage.vue'));
        $this->assertIsString($jobOrderPage);
        $this->assertStringContainsString(
            'This warranty duration applies to this Job Order and is calculated from its pickup date. Shop Warranty Slip Terms only customize the printed document.',
            $jobOrderPage
        );
    }

    public function test_claim_stub_and_warranty_slip_terms_are_saved_and_returned_in_shop_settings(): void
    {
        [$shop, $admin] = $this->createShopAdmin();
        $claimTerms = "Claim term one.\nClaim term two.";
        $warrantyTerms = "Warranty term one.\nWarranty term two.";
        $shop->update([
            'description' => 'Keep the existing description.',
            'default_warranty_days' => 45,
        ]);

        $this->actingAs($admin)
            ->putJson('/api/admin/shop', [
                'name' => $shop->name,
                'slug' => $shop->slug,
                'email' => $shop->email,
                'phone' => $shop->phone,
                'address' => $shop->address,
                'description' => $shop->description,
                'business_hours' => null,
                'claim_terms' => $claimTerms,
                'warranty_terms' => $warrantyTerms,
                'notification_settings' => [
                    'email_enabled' => true,
                    'sms_enabled' => false,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('shop.claim_terms', $claimTerms)
            ->assertJsonPath('shop.warranty_terms', $warrantyTerms);

        $this->actingAs($admin)
            ->getJson('/api/admin/shop')
            ->assertOk()
            ->assertJsonPath('shop.claim_terms', $claimTerms)
            ->assertJsonPath('shop.warranty_terms', $warrantyTerms);

        $this->assertSame($claimTerms, $shop->fresh()->claim_terms);
        $this->assertSame($warrantyTerms, $shop->fresh()->warranty_terms);
        $this->assertSame('Keep the existing description.', $shop->fresh()->description);
        $this->assertSame(45, $shop->fresh()->default_warranty_days);
    }

    public function test_claim_stub_terms_can_be_updated_independently(): void
    {
        [$shop, $admin] = $this->createShopAdmin();
        $shop->update(['warranty_terms' => 'Existing warranty terms.']);
        $claimTerms = "First claim line.\nSecond claim line.";

        $this->actingAs($admin)
            ->putJson('/api/admin/shop', [
                'name' => $shop->name,
                'slug' => $shop->slug,
                'claim_terms' => $claimTerms,
            ])
            ->assertOk()
            ->assertJsonPath('shop.claim_terms', $claimTerms)
            ->assertJsonPath('shop.warranty_terms', 'Existing warranty terms.');

        $this->assertSame($claimTerms, $shop->fresh()->claim_terms);
        $this->assertSame('Existing warranty terms.', $shop->fresh()->warranty_terms);
    }

    public function test_warranty_slip_terms_can_be_updated_independently(): void
    {
        [$shop, $admin] = $this->createShopAdmin();
        $shop->update(['claim_terms' => 'Existing claim terms.']);
        $warrantyTerms = "First warranty line.\nSecond warranty line.";

        $this->actingAs($admin)
            ->putJson('/api/admin/shop', [
                'name' => $shop->name,
                'slug' => $shop->slug,
                'warranty_terms' => $warrantyTerms,
            ])
            ->assertOk()
            ->assertJsonPath('shop.claim_terms', 'Existing claim terms.')
            ->assertJsonPath('shop.warranty_terms', $warrantyTerms);

        $this->assertSame('Existing claim terms.', $shop->fresh()->claim_terms);
        $this->assertSame($warrantyTerms, $shop->fresh()->warranty_terms);
    }

    public function test_shop_admin_cannot_change_another_shops_claim_or_warranty_terms(): void
    {
        [$shop, $admin] = $this->createShopAdmin();
        $shop->update([
            'claim_terms' => 'Original own claim terms.',
            'warranty_terms' => 'Original own warranty terms.',
        ]);
        $otherShop = Shop::create([
            'name' => 'Other Terms Shop',
            'slug' => 'other-terms-shop',
            'claim_terms' => 'Other shop claim terms.',
            'warranty_terms' => 'Other shop warranty terms.',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->putJson('/api/admin/shop', [
                'shop_id' => $otherShop->id,
                'name' => $shop->name,
                'slug' => $shop->slug,
                'claim_terms' => 'Updated own claim terms.',
                'warranty_terms' => 'Updated own warranty terms.',
            ])
            ->assertOk()
            ->assertJsonPath('shop.id', $shop->id)
            ->assertJsonPath('shop.claim_terms', 'Updated own claim terms.')
            ->assertJsonPath('shop.warranty_terms', 'Updated own warranty terms.');

        $this->assertSame('Updated own claim terms.', $shop->fresh()->claim_terms);
        $this->assertSame('Updated own warranty terms.', $shop->fresh()->warranty_terms);
        $this->assertSame('Other shop claim terms.', $otherShop->fresh()->claim_terms);
        $this->assertSame('Other shop warranty terms.', $otherShop->fresh()->warranty_terms);
    }

    public function test_admin_can_save_description_and_read_it_in_shop_settings(): void
    {
        [$shop, $admin] = $this->createShopAdmin();

        $this->actingAs($admin)
            ->putJson('/api/admin/shop', [
                'name' => $shop->name,
                'slug' => $shop->slug,
                'description' => 'We repair phones, laptops, and other electronics.',
                'default_warranty_days' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('shop.description', 'We repair phones, laptops, and other electronics.');

        $this->assertSame(
            'We repair phones, laptops, and other electronics.',
            $shop->fresh()->description
        );

        $this->actingAs($admin)
            ->getJson('/api/admin/shop')
            ->assertOk()
            ->assertJsonPath('shop.description', 'We repair phones, laptops, and other electronics.');
    }

    public function test_shop_admin_cannot_update_another_shops_description_by_submitting_its_id(): void
    {
        [$shop, $admin] = $this->createShopAdmin();
        $otherShop = Shop::create([
            'name' => 'Other Shop',
            'slug' => 'other-shop',
            'description' => 'Original other shop description.',
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->putJson('/api/admin/shop', [
                'shop_id' => $otherShop->id,
                'name' => $shop->name,
                'slug' => $shop->slug,
                'description' => 'Updated only on the authenticated shop.',
                'default_warranty_days' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('shop.id', $shop->id)
            ->assertJsonPath('shop.description', 'Updated only on the authenticated shop.');

        $this->assertSame('Updated only on the authenticated shop.', $shop->fresh()->description);
        $this->assertSame('Original other shop description.', $otherShop->fresh()->description);
    }

    public function test_omitting_description_from_settings_update_preserves_the_saved_description(): void
    {
        [$shop, $admin] = $this->createShopAdmin();
        $shop->update(['description' => 'Keep this description.']);

        $this->actingAs($admin)
            ->putJson('/api/admin/shop', [
                'name' => $shop->name,
                'slug' => $shop->slug,
                'default_warranty_days' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('shop.description', 'Keep this description.');

        $this->assertSame('Keep this description.', $shop->fresh()->description);
    }

    public function test_empty_and_null_descriptions_are_saved_as_null_and_public_profile_stays_available(): void
    {
        [$shop, $admin] = $this->createShopAdmin();

        foreach ([null, ''] as $description) {
            $this->actingAs($admin)
                ->putJson('/api/admin/shop', [
                    'name' => $shop->name,
                    'slug' => $shop->slug,
                    'description' => $description,
                    'default_warranty_days' => 30,
                ])
                ->assertOk()
                ->assertJsonPath('shop.description', null);

            $this->getJson("/api/shops/{$shop->slug}/profile")
                ->assertOk()
                ->assertJsonPath('shop.description', null);
        }

        $this->assertNull($shop->fresh()->description);
    }

    public function test_public_shop_profile_returns_its_own_description(): void
    {
        [$shop] = $this->createShopAdmin();
        $shop->update(['description' => 'Independent shop profile description.']);
        Shop::create([
            'name' => 'Other Shop',
            'slug' => 'other-shop-description-test',
            'description' => 'Other shop description.',
            'is_active' => true,
        ]);

        $this->getJson("/api/shops/{$shop->slug}/profile")
            ->assertOk()
            ->assertJsonPath('shop.description', 'Independent shop profile description.');
    }

    public function test_shop_settings_reject_descriptions_over_the_maximum_length(): void
    {
        [$shop, $admin] = $this->createShopAdmin();

        $this->actingAs($admin)
            ->putJson('/api/admin/shop', [
                'name' => $shop->name,
                'slug' => $shop->slug,
                'description' => str_repeat('a', 5001),
                'default_warranty_days' => 30,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('description');
    }

    public function test_admin_can_upload_and_remove_shop_logo(): void
    {
        Storage::fake('public');

        [$shop, $admin] = $this->createShopAdmin();

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/shop/logo', [
                'logo' => UploadedFile::fake()->image('logo.png', 160, 160),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Shop logo uploaded successfully.')
            ->assertJsonPath('shop.logo_url', fn (?string $url) => filled($url));

        $shop->refresh();
        $this->assertNotNull($shop->logo_path);
        Storage::disk('public')->assertExists($shop->logo_path);

        $this->actingAs($admin)
            ->deleteJson('/api/admin/shop/logo')
            ->assertOk()
            ->assertJsonPath('message', 'Shop logo removed.')
            ->assertJsonPath('shop.logo_path', null)
            ->assertJsonPath('shop.logo_url', null);

        Storage::disk('public')->assertMissing($response->json('shop.logo_path'));
        $this->assertNull($shop->fresh()->logo_path);
    }

    public function test_public_shop_profile_includes_business_hours_and_logo_url(): void
    {
        [$shop] = $this->createShopAdmin();
        $shop->update([
            'logo_path' => 'shop-logos/1/logo.png',
            'business_hours' => [
                'monday' => ['label' => 'Monday', 'open' => true, 'open_time' => '08:00', 'close_time' => '17:00'],
                'sunday' => ['label' => 'Sunday', 'open' => false, 'open_time' => '09:00', 'close_time' => '18:00'],
            ],
        ]);

        $this->getJson("/api/shops/{$shop->slug}/profile")
            ->assertOk()
            ->assertJsonPath('shop.name', $shop->name)
            ->assertJsonPath('shop.logo_url', '/storage/shop-logos/1/logo.png')
            ->assertJsonPath('shop.business_hours.monday.open_time', '08:00')
            ->assertJsonPath('shop.business_hours.sunday.open', false)
            ->assertJsonPath('shop.business_hours.tuesday.open', null)
            ->assertJsonPath('shop.business_hours.tuesday.open_time', null);
    }

    public function test_unconfigured_business_hours_remain_null_when_shop_settings_are_saved(): void
    {
        [$shop, $admin] = $this->createShopAdmin();
        $shop->update(['business_hours' => null]);

        $this->actingAs($admin)
            ->putJson('/api/admin/shop', [
                'name' => $shop->name,
                'slug' => $shop->slug,
                'default_warranty_days' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('shop.business_hours', null)
            ->assertJsonPath('shop.business_hours_configured', false);

        $this->assertNull($shop->fresh()->business_hours);
    }

    public function test_admin_can_clear_configured_business_hours(): void
    {
        [$shop, $admin] = $this->createShopAdmin();
        $shop->update([
            'business_hours' => [
                'monday' => ['open' => true, 'open_time' => '08:00', 'close_time' => '17:00'],
            ],
        ]);

        $this->actingAs($admin)
            ->putJson('/api/admin/shop', [
                'name' => $shop->name,
                'slug' => $shop->slug,
                'business_hours' => null,
                'default_warranty_days' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('shop.business_hours', null)
            ->assertJsonPath('shop.business_hours_configured', false);

        $this->assertNull($shop->fresh()->business_hours);
    }

    public function test_admin_can_manage_service_catalog(): void
    {
        [, $admin] = $this->createShopAdmin();

        $categoryResponse = $this->actingAs($admin)
            ->postJson('/api/admin/shop/service-categories', [
                'name' => 'Laptop',
                'description' => 'Laptop services',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Service category added.');

        $categoryId = $categoryResponse->json('category.id');

        $serviceResponse = $this->actingAs($admin)
            ->postJson("/api/admin/shop/service-categories/{$categoryId}/services", [
                'name' => 'LCD Replacement',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Service added.');

        $this->actingAs($admin)
            ->putJson("/api/admin/shop/services/{$serviceResponse->json('service.id')}", [
                'name' => 'LCD Panel Replacement',
                'description' => null,
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('service.name', 'LCD Panel Replacement');

        $this->getJson('/api/shops/branding-test-shop/profile')
            ->assertOk()
            ->assertJsonPath('shop.service_catalog.0.name', 'Laptop')
            ->assertJsonPath('shop.service_catalog.0.services.0.name', 'LCD Panel Replacement');
    }

    public function test_job_order_can_use_service_catalog_selection(): void
    {
        [$shop, $admin] = $this->createShopAdmin();
        $category = ServiceCategory::create([
            'shop_id' => $shop->id,
            'name' => 'Cellphone',
            'is_active' => true,
        ]);
        $service = ServiceItem::create([
            'shop_id' => $shop->id,
            'service_category_id' => $category->id,
            'name' => 'Battery Replacement',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/job-orders', [
                'customer_name' => 'Catalog Customer',
                'customer_phone' => '09123456789',
                'device_type' => 'Cellphone',
                'service_category_id' => $category->id,
                'service_item_id' => $service->id,
                'brand' => 'Apple',
                'model' => 'iPhone',
                'problem_description' => 'Battery drains quickly.',
            ])
            ->assertCreated()
            ->assertJsonPath('jobOrder.device_type', 'Cellphone')
            ->assertJsonPath('jobOrder.service_category_id', $category->id)
            ->assertJsonPath('jobOrder.service_item_id', $service->id);
    }

    private function createShopAdmin(): array
    {
        $shop = Shop::create([
            'name' => 'Branding Test Shop',
            'slug' => 'branding-test-shop',
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        return [$shop, $admin];
    }
}
