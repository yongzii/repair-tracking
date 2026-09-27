<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FailedJobAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_list_failed_jobs(): void
    {
        $failedJobId = $this->createFailedJob();
        $platformAdmin = $this->createPlatformAdmin();

        $this->actingAs($platformAdmin)
            ->getJson('/api/platform/failed-jobs')
            ->assertOk()
            ->assertJsonPath('failedJobs.data.0.id', $failedJobId)
            ->assertJsonPath('failedJobs.data.0.queue', 'default');
    }

    public function test_platform_admin_can_retry_a_failed_job(): void
    {
        $failedJobId = $this->createFailedJob();
        $platformAdmin = $this->createPlatformAdmin();

        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:retry', ['id' => $failedJobId])
            ->andReturn(0);

        $this->actingAs($platformAdmin)
            ->postJson("/api/platform/failed-jobs/{$failedJobId}/retry")
            ->assertOk()
            ->assertJsonPath('message', "Retry triggered for failed job #{$failedJobId}.");
    }

    public function test_platform_admin_can_delete_a_failed_job(): void
    {
        $failedJobId = $this->createFailedJob();
        $platformAdmin = $this->createPlatformAdmin();

        $this->actingAs($platformAdmin)
            ->deleteJson("/api/platform/failed-jobs/{$failedJobId}")
            ->assertOk()
            ->assertJsonPath('message', "Failed job #{$failedJobId} deleted.");

        $this->assertDatabaseMissing('failed_jobs', ['id' => $failedJobId]);
    }

    public function test_non_platform_roles_cannot_list_failed_jobs(): void
    {
        foreach ([User::ROLE_SHOP_ADMIN, User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_TECHNICIAN, 'customer'] as $role) {
            $this->actingAs(User::factory()->create([
                'role' => $role,
                'is_active' => true,
            ]))
                ->getJson('/api/platform/failed-jobs')
                ->assertForbidden();
        }
    }

    public function test_unauthenticated_users_cannot_access_failed_jobs(): void
    {
        $this->getJson('/api/platform/failed-jobs')
            ->assertUnauthorized();
    }

    public function test_deactivated_platform_admin_cannot_access_failed_jobs(): void
    {
        $platformAdmin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'is_active' => false,
        ]);

        $this->actingAs($platformAdmin)
            ->getJson('/api/platform/failed-jobs')
            ->assertForbidden();
    }

    public function test_old_admin_failed_job_endpoints_are_not_usable_by_shop_admins_or_legacy_admins(): void
    {
        foreach ([User::ROLE_SHOP_ADMIN, User::ROLE_ADMIN] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'is_active' => true,
            ]);

            $this->actingAs($user)
                ->getJson('/api/admin/failed-jobs')
                ->assertNotFound();

            $this->actingAs($user)
                ->postJson('/api/admin/failed-jobs/1/retry')
                ->assertNotFound();

            $this->actingAs($user)
                ->deleteJson('/api/admin/failed-jobs/1')
                ->assertNotFound();
        }
    }

    private function createPlatformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'is_active' => true,
        ]);
    }

    private function createFailedJob(): int
    {
        return DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Test exception',
            'failed_at' => now(),
        ]);
    }
}
