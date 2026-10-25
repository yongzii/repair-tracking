<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserAccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_change_their_password(): void
    {
        $user = User::factory()->create([
            'password' => 'old-password',
        ]);

        $this->actingAs($user)
            ->postJson('/api/auth/password', [
                'current_password' => 'old-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertOk()
            ->assertJson(['message' => 'Password changed successfully.']);

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'auth.password_changed',
        ]);
    }

    public function test_password_change_requires_the_current_password_and_confirmation(): void
    {
        $user = User::factory()->create([
            'password' => 'old-password',
        ]);

        $this->actingAs($user)
            ->postJson('/api/auth/password', [
                'current_password' => 'incorrect-password',
                'password' => 'new-password',
                'password_confirmation' => 'different-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password', 'password']);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_shop_admin_can_permanently_remove_another_user_in_their_shop(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        $user = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_STAFF,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJson(['message' => 'User account removed successfully.']);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'user.deleted',
            'record_type' => 'User',
            'record_id' => $user->id,
        ]);
    }

    public function test_shop_admin_cannot_remove_their_own_account(): void
    {
        [, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertUnprocessable()
            ->assertJson(['message' => 'You cannot remove your own account while logged in.']);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_shop_admin_can_remove_an_inactive_admin_without_affecting_active_admins(): void
    {
        [$shop, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        $inactiveAdmin = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_ADMIN,
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$inactiveAdmin->id}")
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseMissing('users', ['id' => $inactiveAdmin->id]);
    }

    public function test_shop_admin_cannot_remove_a_user_from_another_shop(): void
    {
        [, $admin] = $this->createShopUser(User::ROLE_SHOP_ADMIN);
        [$otherShop] = $this->createShopUser(User::ROLE_STAFF);
        $otherUser = User::factory()->create([
            'shop_id' => $otherShop->id,
            'role' => User::ROLE_STAFF,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$otherUser->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $otherUser->id]);
    }

    private function createShopUser(string $role): array
    {
        $shop = Shop::create([
            'name' => 'Account Security Test Shop ' . uniqid(),
            'slug' => 'account-security-test-' . uniqid(),
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
