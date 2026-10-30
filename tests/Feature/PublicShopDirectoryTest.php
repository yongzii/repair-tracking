<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicShopDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_only_returns_active_shops_with_future_access(): void
    {
        $eligibleShop = $this->createShop('Eligible Shop');
        $this->createShop('Inactive Shop', ['is_active' => false]);
        $this->createShop('Expired Shop', ['trial_ends_at' => now()->subDay()]);
        $this->createShop('No Access Date Shop', ['trial_ends_at' => null]);

        $this->getJson('/api/public/shops')
            ->assertOk()
            ->assertJsonCount(1, 'shops')
            ->assertJsonPath('shops.0.id', $eligibleShop->id)
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_eligible_sample_shop_uses_the_same_directory_access_rule(): void
    {
        $sampleShop = $this->createShop('Sample shop');

        $this->getJson('/api/public/shops')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $sampleShop->id,
                'name' => 'Sample shop',
                'slug' => $sampleShop->slug,
            ]);
    }

    public function test_directory_returns_only_approved_public_fields_and_limited_active_services(): void
    {
        $shop = $this->createShop('Public Shop', [
            'email' => 'private@example.test',
            'subscription_plan' => 'private-plan',
            'notification_settings' => ['private' => 'setting'],
            'logo_path' => 'shop-logos/public-shop/logo.png',
        ]);
        $category = $this->createCategory($shop, 'Repairs');
        $inactiveCategory = $this->createCategory($shop, 'Inactive Category', ['is_active' => false]);

        foreach (['Battery Repair', 'Board Repair', 'Display Repair', 'Port Repair', 'Speaker Repair'] as $name) {
            $this->createService($shop, $category, $name);
        }
        $this->createService($shop, $category, 'Hidden Service', ['is_active' => false]);
        $this->createService($shop, $inactiveCategory, 'Hidden Category Service');

        $response = $this->getJson('/api/public/shops')->assertOk();
        $shopData = $response->json('shops.0');

        $this->assertSame(['id', 'name', 'slug', 'address', 'logo_url', 'services', 'services_more_count'], array_keys($shopData));
        $this->assertSame('/storage/shop-logos/public-shop/logo.png', $shopData['logo_url']);
        $this->assertCount(4, $shopData['services']);
        $this->assertSame(1, $shopData['services_more_count']);
        $this->assertNotContains('Hidden Service', $shopData['services']);
        $this->assertNotContains('Hidden Category Service', $shopData['services']);
        $response
            ->assertJsonMissingPath('shops.0.email')
            ->assertJsonMissingPath('shops.0.subscription_plan')
            ->assertJsonMissingPath('shops.0.trial_ends_at')
            ->assertJsonMissingPath('shops.0.notification_settings');
    }

    public function test_directory_search_matches_shop_name_address_and_active_services(): void
    {
        $nameMatch = $this->createShop('Cobalt Electronics');
        $addressMatch = $this->createShop('Address Repair', ['address' => 'North Harbor']);
        $serviceMatch = $this->createShop('Service Repair');
        $category = $this->createCategory($serviceMatch, 'Phone');
        $this->createService($serviceMatch, $category, 'Screen Fix', ['description' => 'Quartz display replacement']);

        $this->getJson('/api/public/shops?search=Cobalt')
            ->assertOk()
            ->assertJsonCount(1, 'shops')
            ->assertJsonPath('shops.0.id', $nameMatch->id);

        $this->getJson('/api/public/shops?search=Harbor')
            ->assertOk()
            ->assertJsonCount(1, 'shops')
            ->assertJsonPath('shops.0.id', $addressMatch->id);

        $this->getJson('/api/public/shops?search=Quartz')
            ->assertOk()
            ->assertJsonCount(1, 'shops')
            ->assertJsonPath('shops.0.id', $serviceMatch->id);
    }

    public function test_directory_service_search_ignores_inactive_services_and_categories(): void
    {
        $shop = $this->createShop('Service Visibility Shop');
        $activeCategory = $this->createCategory($shop, 'Active Category');
        $inactiveCategory = $this->createCategory($shop, 'Inactive Category', ['is_active' => false]);
        $this->createService($shop, $activeCategory, 'Inactive Service', ['is_active' => false]);
        $this->createService($shop, $inactiveCategory, 'Hidden Category Service');

        $this->getJson('/api/public/shops?search=Inactive%20Service')
            ->assertOk()
            ->assertJsonCount(0, 'shops');

        $this->getJson('/api/public/shops?search=Hidden%20Category')
            ->assertOk()
            ->assertJsonCount(0, 'shops');
    }

    public function test_directory_service_search_does_not_match_a_service_attached_to_another_shops_category(): void
    {
        $shop = $this->createShop('Mismatched Service Shop');
        $otherShop = $this->createShop('Other Service Shop');
        $otherCategory = $this->createCategory($otherShop, 'Other Shop Category');
        $this->createService($shop, $otherCategory, 'Cross Tenant Phrase');

        $this->getJson('/api/public/shops?search=Cross%20Tenant%20Phrase')
            ->assertOk()
            ->assertJsonCount(0, 'shops');
    }

    public function test_directory_preview_and_profile_only_show_each_shops_own_active_catalog(): void
    {
        $shopA = $this->createShop('Shop A');
        $shopB = $this->createShop('Shop B');
        $categoryA = $this->createCategory($shopA, 'Shop A Category');
        $categoryB = $this->createCategory($shopB, 'Shop B Category');
        $this->createService($shopA, $categoryA, 'Shop A Active Service');
        $this->createService($shopA, $categoryA, 'Shop A Inactive Service', ['is_active' => false]);
        $this->createService($shopB, $categoryB, 'Shop B Active Service');
        $this->createService($shopB, $categoryA, 'Shop B Service With Shop A Category');
        $this->createService($shopA, $categoryB, 'Shop A Service With Shop B Category');

        $directory = $this->getJson('/api/public/shops')->assertOk();
        $shopAListEntry = collect($directory->json('shops'))->firstWhere('id', $shopA->id);

        $this->assertSame(['Shop A Active Service'], $shopAListEntry['services']);
        $this->assertSame(0, $shopAListEntry['services_more_count']);

        $profile = $this->getJson("/api/shops/{$shopA->slug}/profile")
            ->assertOk()
            ->json('shop.service_catalog');

        $this->assertCount(1, $profile);
        $this->assertSame('Shop A Category', $profile[0]['name']);
        $this->assertSame(['Shop A Active Service'], array_column($profile[0]['services'], 'name'));

        $shopBProfile = $this->getJson("/api/shops/{$shopB->slug}/profile")
            ->assertOk()
            ->json('shop.service_catalog');
        $this->assertSame('Shop B Category', $shopBProfile[0]['name']);
        $this->assertSame(['Shop B Active Service'], array_column($shopBProfile[0]['services'], 'name'));
    }

    public function test_directory_search_does_not_match_services_in_another_shops_category(): void
    {
        $shopA = $this->createShop('Category Owner Shop');
        $shopB = $this->createShop('Service Owner Shop');
        $categoryA = $this->createCategory($shopA, 'Shop A Category');
        $this->createService($shopB, $categoryA, 'Mismatched Category Search Phrase');

        $this->getJson('/api/public/shops?search=Mismatched%20Category%20Search')
            ->assertOk()
            ->assertJsonCount(0, 'shops');
    }

    public function test_directory_paginates_and_validates_the_page_size(): void
    {
        $this->createShop('Alpha Shop');
        $secondShop = $this->createShop('Bravo Shop');
        $this->createShop('Charlie Shop');

        $this->getJson('/api/public/shops?page=2&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'shops')
            ->assertJsonPath('shops.0.id', $secondShop->id)
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.last_page', 3)
            ->assertJsonPath('pagination.per_page', 1)
            ->assertJsonPath('pagination.total', 3);

        $this->getJson('/api/public/shops?per_page=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_directory_query_rejects_invalid_search_and_page_values(): void
    {
        $this->getJson('/api/public/shops?search[]=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');

        $this->getJson('/api/public/shops?page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page');
    }

    public function test_public_profile_keeps_its_existing_active_only_eligibility(): void
    {
        $expiredButActiveShop = $this->createShop('Expired Profile Shop', [
            'trial_ends_at' => now()->subDay(),
        ]);
        $inactiveShop = $this->createShop('Inactive Profile Shop', [
            'is_active' => false,
        ]);

        $this->getJson("/api/shops/{$expiredButActiveShop->slug}/profile")->assertOk();
        $this->getJson("/api/shops/{$inactiveShop->slug}/profile")->assertNotFound();
    }

    public function test_public_profile_does_not_return_generated_hours_when_hours_are_unconfigured(): void
    {
        $shop = $this->createShop('Hours Not Configured Shop');

        $this->getJson("/api/shops/{$shop->slug}/profile")
            ->assertOk()
            ->assertJsonPath('shop.business_hours', null);
    }

    public function test_public_profile_description_is_optional_and_whitespace_is_not_meaningful_content(): void
    {
        $shop = $this->createShop('Optional Description Shop');

        $this->getJson("/api/shops/{$shop->slug}/profile")
            ->assertOk()
            ->assertJsonPath('shop.description', null);

        $shop->update(['description' => " \n\t "]);

        $this->getJson("/api/shops/{$shop->slug}/profile")
            ->assertOk()
            ->assertJsonPath('shop.description', " \n\t ");
    }

    private function createShop(string $name, array $attributes = []): Shop
    {
        return Shop::create(array_merge([
            'name' => $name,
            'slug' => str($name)->slug() . '-' . uniqid(),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ], $attributes));
    }

    private function createCategory(Shop $shop, string $name, array $attributes = []): ServiceCategory
    {
        return ServiceCategory::create(array_merge([
            'shop_id' => $shop->id,
            'name' => $name,
            'is_active' => true,
        ], $attributes));
    }

    private function createService(Shop $shop, ServiceCategory $category, string $name, array $attributes = []): ServiceItem
    {
        return ServiceItem::create(array_merge([
            'shop_id' => $shop->id,
            'service_category_id' => $category->id,
            'name' => $name,
            'is_active' => true,
        ], $attributes));
    }
}
