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
            ->assertJsonPath('shop.business_hours.monday.open', true)
            ->assertJsonPath('shop.business_hours.monday.open_time', '08:30')
            ->assertJsonPath('shop.business_hours.sunday.open', false);

        $shop->refresh();

        $this->assertSame('08:30', $shop->business_hours['monday']['open_time']);
        $this->assertFalse($shop->business_hours['sunday']['open']);
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
            ->assertJsonPath('shop.business_hours.sunday.open', false);
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
