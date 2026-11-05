<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class FailedJobController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePlatformAdmin($request);

        $failedJobs = DB::table('failed_jobs')
            ->select('id', 'queue', 'failed_at')
            ->orderByDesc('failed_at')
            ->paginate(20);

        return response()->json([
            'failedJobs' => $failedJobs,
        ]);
    }

    public function retry(Request $request, int $id)
    {
        $this->authorizePlatformAdmin($request);

        try {
            $failedJob = DB::table('failed_jobs')
                ->select('uuid')
                ->where('id', $id)
                ->first();

            if (!$failedJob) {
                return response()->json(['message' => 'Failed job not found.'], Response::HTTP_NOT_FOUND);
            }

            $exitCode = Artisan::call('queue:retry', ['id' => $failedJob->uuid]);

            if ($exitCode !== 0) {
                Log::warning('Failed job retry command returned a nonzero exit code.', [
                    'failed_job_id' => $id,
                    'exit_code' => $exitCode,
                ]);

                return response()->json([
                    'message' => 'The failed job could not be dispatched for retry.',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            if (DB::table('failed_jobs')->where('id', $id)->exists()) {
                Log::warning('Failed job remained in failed_jobs after retry command completed.', [
                    'failed_job_id' => $id,
                ]);

                return response()->json([
                    'message' => 'The failed job could not be dispatched for retry.',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return response()->json([
                'message' => 'Failed job successfully dispatched for retry.',
            ]);
        } catch (Throwable $exception) {
            Log::error('Failed job retry failed unexpectedly.', [
                'failed_job_id' => $id,
                'exception' => $exception,
            ]);

            return response()->json([
                'message' => 'Unable to retry the failed job. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizePlatformAdmin($request);

        try {
            $deleted = DB::table('failed_jobs')->where('id', $id)->delete();

            if (!$deleted) {
                return response()->json(['message' => 'Failed job not found.'], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'message' => "Failed job #{$id} deleted.",
            ]);
        } catch (Throwable $exception) {
            Log::error('Failed job deletion failed unexpectedly.', [
                'failed_job_id' => $id,
                'exception' => $exception,
            ]);

            return response()->json([
                'message' => 'Unable to delete the failed job. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function authorizePlatformAdmin(Request $request): void
    {
        $this->authorizeRoles($request, User::ROLE_PLATFORM_ADMIN);
    }
}
