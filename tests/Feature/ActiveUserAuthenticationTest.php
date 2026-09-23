<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveUserAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_shop_roles_can_access_their_authenticated_endpoints(): void
    {
        foreach ([User::ROLE_SHOP_ADMIN, User::ROLE_ADMIN, User::ROLE_STAFF] as $role) {
            [$shop, $user] = $this->createShopUser($role);

            $this->actingAs($user)
                ->getJson('/api/admin/dashboard')
                ->assertOk();
        }
    }

    public function test_active_technician_can_access_the_technician_endpoint(): void
    {
        [$shop, $user] = $this->createShopUser(User::ROLE_TECHNICIAN);
        Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Active Technician',
            'email' => $user->email,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/api/technician/dashboard')
            ->assertOk();
    }

    public function test_active_platform_admin_can_access_the_platform_endpoint(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/api/platform/dashboard')
            ->assertOk();
    }

    public function test_deactivated_shop_roles_lose_existing_authenticated_access(): void
    {
        foreach ([User::ROLE_SHOP_ADMIN, User::ROLE_ADMIN, User::ROLE_STAFF] as $role) {
            [$shop, $user] = $this->createShopUser($role);

            $this->actingAs($user)
                ->getJson('/api/admin/dashboard')
                ->assertOk();

            $user->update(['is_active' => false]);

            $this->getJson('/api/admin/dashboard')
                ->assertForbidden();
        }
    }

    public function test_deactivated_technician_loses_existing_authenticated_access(): void
    {
        [$shop, $user] = $this->createShopUser(User::ROLE_TECHNICIAN);
        Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Deactivated Technician',
            'email' => $user->email,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/api/technician/dashboard')
            ->assertOk();

        $user->update(['is_active' => false]);

        $this->getJson('/api/technician/dashboard')
            ->assertForbidden();
    }

    public function test_deactivated_platform_admin_loses_existing_authenticated_access(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/api/platform/dashboard')
            ->assertOk();

        $user->update(['is_active' => false]);

        $this->getJson('/api/platform/dashboard')
            ->assertForbidden();
    }

    public function test_inactive_user_cannot_login(): void
    {
        [$shop, $user] = $this->createShopUser(User::ROLE_STAFF);
        $user->update(['is_active' => false]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_deactivated_user_can_still_logout_and_public_routes_remain_public(): void
    {
        [$shop, $user] = $this->createShopUser(User::ROLE_STAFF);
        $user->update(['is_active' => false]);

        $this->actingAs($user)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->getJson("/api/shops/{$shop->slug}/profile")
            ->assertOk();
    }

    private function createShopUser(string $role): array
    {
        $shop = Shop::create([
            'name' => 'Active User Test Shop ' . uniqid(),
            'slug' => 'active-user-test-' . uniqid(),
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
}