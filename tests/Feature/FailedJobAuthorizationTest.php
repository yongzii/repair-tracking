<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class FailedJobAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_list_failed_jobs_without_internal_fields(): void
    {
        $failedJobId = $this->createFailedJob();
        $platformAdmin = $this->createPlatformAdmin();

        $response = $this->actingAs($platformAdmin)
            ->getJson('/api/platform/failed-jobs')
            ->assertOk()
            ->assertJsonPath('failedJobs.data.0.id', $failedJobId)
            ->assertJsonPath('failedJobs.data.0.queue', 'default');

        $this->assertSame(
            ['id', 'queue', 'failed_at'],
            array_keys($response->json('failedJobs.data.0'))
        );
        $response->assertJsonMissingPath('failedJobs.data.0.uuid');
        $response->assertJsonMissingPath('failedJobs.data.0.payload');
        $response->assertJsonMissingPath('failedJobs.data.0.exception');
    }

    public function test_platform_admin_can_retry_a_failed_job_using_its_uuid(): void
    {
        $failedJobId = $this->createFailedJob();
        $uuid = DB::table('failed_jobs')->where('id', $failedJobId)->value('uuid');
        $platformAdmin = $this->createPlatformAdmin();

        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:retry', ['id' => $uuid])
            ->andReturnUsing(function () use ($failedJobId): int {
                DB::table('failed_jobs')->where('id', $failedJobId)->delete();

                return 0;
            });

        $this->actingAs($platformAdmin)
            ->postJson("/api/platform/failed-jobs/{$failedJobId}/retry")
            ->assertOk()
            ->assertJsonPath('message', 'Failed job successfully dispatched for retry.');

        $this->assertDatabaseMissing('failed_jobs', ['id' => $failedJobId]);
    }

    public function test_retry_returns_not_found_for_a_missing_failed_job(): void
    {
        $platformAdmin = $this->createPlatformAdmin();

        $this->actingAs($platformAdmin)
            ->postJson('/api/platform/failed-jobs/999999/retry')
            ->assertNotFound()
            ->assertJsonPath('message', 'Failed job not found.');
    }

    public function test_nonzero_retry_command_result_does_not_return_success(): void
    {
        $failedJobId = $this->createFailedJob();
        $platformAdmin = $this->createPlatformAdmin();

        Artisan::shouldReceive('call')
            ->once()
            ->andReturn(1);

        $this->actingAs($platformAdmin)
            ->postJson("/api/platform/failed-jobs/{$failedJobId}/retry")
            ->assertStatus(500)
            ->assertJsonPath('message', 'The failed job could not be dispatched for retry.');

        $this->assertDatabaseHas('failed_jobs', ['id' => $failedJobId]);
    }

    public function test_retry_command_exception_returns_a_safe_error(): void
    {
        $failedJobId = $this->createFailedJob();
        $platformAdmin = $this->createPlatformAdmin();

        Artisan::shouldReceive('call')
            ->once()
            ->andThrow(new RuntimeException('internal queue connection details'));
        Log::shouldReceive('error')->once();

        $this->actingAs($platformAdmin)
            ->postJson("/api/platform/failed-jobs/{$failedJobId}/retry")
            ->assertStatus(500)
            ->assertJsonPath('message', 'Unable to retry the failed job. Please try again.')
            ->assertDontSee('internal queue connection details');

        $this->assertDatabaseHas('failed_jobs', ['id' => $failedJobId]);
    }

    public function test_retry_does_not_return_success_if_the_failed_job_remains(): void
    {
        $failedJobId = $this->createFailedJob();
        $platformAdmin = $this->createPlatformAdmin();

        Artisan::shouldReceive('call')
            ->once()
            ->andReturn(0);
        Log::shouldReceive('warning')->once();

        $this->actingAs($platformAdmin)
            ->postJson("/api/platform/failed-jobs/{$failedJobId}/retry")
            ->assertStatus(500)
            ->assertJsonPath('message', 'The failed job could not be dispatched for retry.');

        $this->assertDatabaseHas('failed_jobs', ['id' => $failedJobId]);
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

    public function test_delete_returns_not_found_for_a_missing_failed_job(): void
    {
        $this->actingAs($this->createPlatformAdmin())
            ->deleteJson('/api/platform/failed-jobs/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Failed job not found.');
    }

    public function test_delete_failure_does_not_return_success(): void
    {
        $platformAdmin = $this->createPlatformAdmin();

        DB::shouldReceive('table')
            ->once()
            ->with('failed_jobs')
            ->andThrow(new RuntimeException('internal database details'));
        Log::shouldReceive('error')->once();

        $this->actingAs($platformAdmin)
            ->deleteJson('/api/platform/failed-jobs/1')
            ->assertStatus(500)
            ->assertJsonPath('message', 'Unable to delete the failed job. Please try again.')
            ->assertDontSee('internal database details');
    }

    public function test_non_platform_roles_cannot_list_retry_or_delete_failed_jobs(): void
    {
        foreach ([
            User::ROLE_SHOP_ADMIN,
            User::ROLE_ADMIN,
            User::ROLE_STAFF,
            User::ROLE_TECHNICIAN,
            'customer',
        ] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'is_active' => true,
            ]);

            $this->actingAs($user)
                ->getJson('/api/platform/failed-jobs')
                ->assertForbidden();

            $this->actingAs($user)
                ->postJson('/api/platform/failed-jobs/1/retry')
                ->assertForbidden();

            $this->actingAs($user)
                ->deleteJson('/api/platform/failed-jobs/1')
                ->assertForbidden();
        }
    }

    public function test_unauthenticated_users_cannot_access_failed_job_endpoints(): void
    {
        $this->getJson('/api/platform/failed-jobs')->assertUnauthorized();
        $this->postJson('/api/platform/failed-jobs/1/retry')->assertUnauthorized();
        $this->deleteJson('/api/platform/failed-jobs/1')->assertUnauthorized();
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
            'payload' => '{"secret":"must not be exposed"}',
            'exception' => 'Test exception details must not be exposed',
            'failed_at' => now(),
        ]);
    }
}
