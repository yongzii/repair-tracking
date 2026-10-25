<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsernameAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_logs_in_with_email_and_password(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'email' => 'platform-admin@example.test',
            'password' => 'password',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.role', User::ROLE_PLATFORM_ADMIN);

        $this->assertAuthenticatedAs($user);
    }

    public function test_shop_admin_logs_in_with_email_and_password(): void
    {
        [$shop, $user] = $this->createShopUser(User::ROLE_SHOP_ADMIN, [
            'email' => 'shop-admin@example.test',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.role', User::ROLE_SHOP_ADMIN)
            ->assertJsonPath('user.shop_id', $shop->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_staff_logs_in_with_username_without_a_personal_email(): void
    {
        [$shop, $user] = $this->createShopUser(User::ROLE_STAFF, [
            'email' => null,
            'username' => 'counter-staff',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'COUNTER-STAFF',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.role', User::ROLE_STAFF)
            ->assertJsonPath('user.email', null);

        $this->assertAuthenticatedAs($user);
        $this->assertSame($shop->id, $user->shop_id);
    }

    public function test_technician_logs_in_with_username_and_uses_the_linked_profile(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Assigned Technician',
            'email' => 'roster-only@example.test',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'shop_id' => $shop->id,
            'technician_id' => $technician->id,
            'name' => 'Assigned Technician',
            'email' => null,
            'username' => 'repair-tech',
            'role' => User::ROLE_TECHNICIAN,
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'repair-tech',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.role', User::ROLE_TECHNICIAN);

        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/technician/dashboard')
            ->assertOk()
            ->assertJsonPath('technician.id', $technician->id);
    }

    public function test_shop_admin_can_create_a_username_only_technician_account_for_a_local_profile(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'New Roster Technician',
            'email' => 'roster-contact@example.test',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => $technician->name,
            'username' => 'new-roster-tech',
            'email' => null,
            'role' => User::ROLE_TECHNICIAN,
            'technician_id' => $technician->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.username', 'new-roster-tech')
            ->assertJsonPath('user.email', null)
            ->assertJsonPath('user.technician_id', $technician->id);

        $this->assertDatabaseHas('users', [
            'id' => $response->json('user.id'),
            'shop_id' => $shop->id,
            'technician_id' => $technician->id,
            'email' => null,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'new-roster-tech',
            'password' => $response->json('generated_password'),
        ])
            ->assertOk()
            ->assertJsonPath('user.role', User::ROLE_TECHNICIAN);
    }

    public function test_invalid_credentials_are_rejected_and_staff_email_is_not_a_login_identifier(): void
    {
        $this->createShopUser(User::ROLE_STAFF, [
            'email' => 'staff-email@example.test',
            'username' => 'staff-login',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'staff-login',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/auth/login', [
            'email' => 'staff-email@example.test',
            'password' => 'password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_login_requires_an_identifier_and_password(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_failed_username_attempts_are_rate_limited_case_insensitively(): void
    {
        $this->createShopUser(User::ROLE_STAFF, [
            'email' => null,
            'username' => 'username-rate-limit',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => $attempt === 0 ? 'USERNAME-RATE-LIMIT' : 'username-rate-limit',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', [
            'email' => 'username-rate-limit',
            'password' => 'wrong-password',
        ])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

    public function test_inactive_username_account_cannot_log_in(): void
    {
        $this->createShopUser(User::ROLE_STAFF, [
            'email' => null,
            'username' => 'inactive-username',
            'is_active' => false,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'inactive-username',
            'password' => 'password',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'This account is inactive. Please contact the administrator.');

        $this->assertGuest();
    }

    public function test_shop_admin_can_create_username_only_staff_in_their_shop(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'New Staff',
            'username' => '  New-Staff ',
            'email' => null,
            'role' => User::ROLE_STAFF,
            'password' => 'temporary-password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.username', 'new-staff')
            ->assertJsonPath('user.email', null)
            ->assertJsonPath('user.shop_id', $shop->id);

        $user = User::query()->findOrFail($response->json('user.id'));
        $this->assertSame($shop->id, $user->shop_id);
        $this->assertTrue(Hash::check('temporary-password', $user->password));
    }

    public function test_shop_admin_cannot_choose_another_shop_for_a_new_user(): void
    {
        [, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        [$otherShop] = $this->createShopUser(User::ROLE_SHOP_ADMIN);

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'shop_id' => $otherShop->id,
            'name' => 'Wrong Shop Staff',
            'username' => 'wrong-shop-staff',
            'email' => null,
            'role' => User::ROLE_STAFF,
            'password' => 'temporary-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shop_id');

        $this->assertDatabaseMissing('users', ['username' => 'wrong-shop-staff']);
    }

    public function test_shop_admin_cannot_read_or_edit_another_shops_user(): void
    {
        [, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        [$otherShop, $otherUser] = $this->createShopUser(User::ROLE_STAFF, [
            'username' => 'other-shop-user',
        ]);
        $this->assertNotSame($admin->shop_id, $otherShop->id);

        $this->actingAs($admin)
            ->getJson("/api/admin/users/{$otherUser->id}")
            ->assertNotFound();

        $this->putJson("/api/admin/users/{$otherUser->id}", [
            'name' => 'Unauthorized Change',
            'username' => 'unauthorized-change',
            'email' => null,
            'role' => User::ROLE_STAFF,
            'is_active' => true,
        ])->assertNotFound();

        $this->assertDatabaseHas('users', [
            'id' => $otherUser->id,
            'name' => $otherUser->name,
            'username' => 'other-shop-user',
        ]);
    }

    public function test_shop_admin_cannot_link_a_technician_account_to_another_shop_profile(): void
    {
        [, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        [$otherShop] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        $otherTechnician = Technician::create([
            'shop_id' => $otherShop->id,
            'name' => 'Other Shop Technician',
            'email' => 'other-technician@example.test',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Local Technician Account',
            'username' => 'local-tech-account',
            'email' => null,
            'role' => User::ROLE_TECHNICIAN,
            'technician_id' => $otherTechnician->id,
            'password' => 'temporary-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('technician_id');
    }

    public function test_usernames_are_unique_case_insensitively(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        User::factory()->create([
            'shop_id' => $shop->id,
            'email' => null,
            'username' => 'existing-staff',
            'role' => User::ROLE_STAFF,
        ]);

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Duplicate User',
            'username' => 'EXISTING-STAFF',
            'email' => null,
            'role' => User::ROLE_STAFF,
            'password' => 'temporary-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    public function test_shop_admin_account_creation_still_requires_an_email(): void
    {
        [, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Second Admin',
            'role' => User::ROLE_SHOP_ADMIN,
            'password' => 'temporary-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    private function createShopUser(string $role, array $attributes = []): array
    {
        $shop = Shop::create([
            'name' => 'Username Auth Shop ' . uniqid(),
            'slug' => 'username-auth-' . uniqid(),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);

        $user = User::factory()->create(array_merge([
            'shop_id' => $shop->id,
            'role' => $role,
            'password' => 'password',
            'is_active' => true,
        ], $attributes));

        return [$shop, $user];
    }
}
