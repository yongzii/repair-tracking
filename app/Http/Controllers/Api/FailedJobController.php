<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class FailedJobController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePlatformAdmin($request);

        $failedJobs = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->paginate(20);

        return response()->json([
            'failedJobs' => $failedJobs,
        ]);
    }

    public function retry(Request $request, int $id)
    {
        $this->authorizePlatformAdmin($request);

        Artisan::call('queue:retry', ['id' => $id]);

        return response()->json([
            'message' => "Retry triggered for failed job #{$id}.",
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizePlatformAdmin($request);

        DB::table('failed_jobs')->where('id', $id)->delete();

        return response()->json([
            'message' => "Failed job #{$id} deleted.",
        ]);
    }

    private function authorizePlatformAdmin(Request $request): void
    {
        $this->authorizeRoles($request, User::ROLE_PLATFORM_ADMIN);
    }
}
