<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_credentials_below_limit_keep_existing_validation_response(): void
    {
        $this->createShopUser('login-limit-below@example.test');

        $this->login('login-limit-below@example.test', 'wrong-password')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'The provided credentials do not match our records.');
    }

    public function test_sixth_failed_credential_attempt_returns_429_with_retry_after(): void
    {
        $this->createShopUser('login-limit-threshold@example.test');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login('login-limit-threshold@example.test', 'wrong-password')
                ->assertUnprocessable();
        }

        $this->login('login-limit-threshold@example.test', 'wrong-password')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too many login attempts. Please try again later.');
    }

    public function test_successful_login_clears_failed_attempts_for_the_same_identity(): void
    {
        $this->createShopUser('login-limit-reset@example.test');

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->login('login-limit-reset@example.test', 'wrong-password')
                ->assertUnprocessable();
        }

        $this->login('login-limit-reset@example.test', 'password')
            ->assertOk()
            ->assertJsonPath('message', 'Login successful.');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login('login-limit-reset@example.test', 'wrong-password')
                ->assertUnprocessable();
        }

        $this->login('login-limit-reset@example.test', 'wrong-password')
            ->assertTooManyRequests();
    }

    public function test_limiter_normalizes_email_case(): void
    {
        $this->createShopUser('login-limit-normalized@example.test');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $email = $attempt === 0
                ? 'LOGIN-LIMIT-NORMALIZED@EXAMPLE.TEST'
                : 'login-limit-normalized@example.test';

            $this->login($email, 'wrong-password')->assertUnprocessable();
        }

        $this->login('login-limit-normalized@example.test', 'wrong-password')
            ->assertTooManyRequests();
    }

    public function test_failed_attempts_for_another_email_at_the_same_ip_are_separate(): void
    {
        $this->createShopUser('login-limit-one@example.test');
        $this->createShopUser('login-limit-two@example.test');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login('login-limit-one@example.test', 'wrong-password')->assertUnprocessable();
        }

        $this->login('login-limit-two@example.test', 'wrong-password')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_failed_attempts_from_another_ip_are_separate(): void
    {
        $this->createShopUser('login-limit-ip@example.test');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login('login-limit-ip@example.test', 'wrong-password', '192.0.2.10')
                ->assertUnprocessable();
        }

        $this->login('login-limit-ip@example.test', 'wrong-password', '192.0.2.11')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_inactive_account_login_is_not_counted_as_a_failed_credential_attempt(): void
    {
        $user = $this->createShopUser('login-limit-inactive@example.test');
        $user->update(['is_active' => false]);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->login('login-limit-inactive@example.test', 'password')
                ->assertUnprocessable()
                ->assertJsonPath('errors.email.0', 'This account is inactive. Please contact the administrator.');
        }
    }

    public function test_successful_login_session_and_csrf_response_remain_unchanged(): void
    {
        $this->createShopUser('login-limit-session@example.test');

        $this->login('login-limit-session@example.test', 'password')
            ->assertOk()
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('user.email', 'login-limit-session@example.test')
            ->assertJsonStructure(['csrf_token', 'user']);

        $this->assertAuthenticated();
    }

    private function login(string $email, string $password, string $ip = '192.0.2.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/auth/login', compact('email', 'password'));
    }

    private function createShopUser(string $email): User
    {
        $shop = Shop::create([
            'name' => 'Login Rate Limit Shop ' . uniqid(),
            'slug' => 'login-rate-limit-' . uniqid(),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);

        return User::factory()->create([
            'shop_id' => $shop->id,
            'name' => 'Login Rate Limit User',
            'email' => $email,
            'role' => User::ROLE_STAFF,
            'is_active' => true,
        ]);
    }
}