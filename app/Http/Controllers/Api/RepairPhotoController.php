<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\RepairPhoto;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class RepairPhotoController extends Controller
{
    public function showForStaff(Request $request, JobOrder $jobOrder, RepairPhoto $photo)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);
        $this->ensurePhotoBelongsToJobOrder($photo, $jobOrder);

        return $this->stream($photo);
    }

    public function showForTechnician(Request $request, JobOrder $jobOrder, RepairPhoto $photo)
    {
        $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
        $technician = $this->resolveTechnician($user);
        $this->ensureSameShop($request, $jobOrder);
        $this->ensureAssignedToTechnician($jobOrder, $technician);
        $this->ensurePhotoBelongsToJobOrder($photo, $jobOrder);

        return $this->stream($photo);
    }

    public function showForCustomer(Shop $shop, string $jobOrderNumber, RepairPhoto $photo)
    {
        if (!$shop->is_active) {
            abort(Response::HTTP_NOT_FOUND, 'Shop not found.');
        }

        $jobOrder = JobOrder::query()
            ->where('shop_id', $shop->id)
            ->where('job_order_number', strtoupper($jobOrderNumber))
            ->first();

        if (!$jobOrder) {
            abort(Response::HTTP_NOT_FOUND, 'Job Order not found for this shop.');
        }

        $this->ensurePhotoBelongsToJobOrder($photo, $jobOrder);

        if (!$photo->visible_to_customer) {
            abort(Response::HTTP_NOT_FOUND, 'Photo not found.');
        }

        return $this->stream($photo);
    }

    private function ensurePhotoBelongsToJobOrder(RepairPhoto $photo, JobOrder $jobOrder): void
    {
        if ((int) $photo->job_order_id !== (int) $jobOrder->id || (int) $photo->shop_id !== (int) $jobOrder->shop_id) {
            abort(Response::HTTP_NOT_FOUND, 'Photo not found.');
        }
    }

    private function stream(RepairPhoto $photo)
    {
    $r2 = Storage::disk('r2');
    $local = Storage::disk('local');

    // Prefer persistent R2 storage.
    // Fall back to local storage for legacy photos.
    if ($r2->exists($photo->photo_path)) {
        $contents = $r2->get($photo->photo_path);
    } elseif ($local->exists($photo->photo_path)) {
        $contents = $local->get($photo->photo_path);
    } else {
        abort(Response::HTTP_NOT_FOUND, 'Photo file not found.');
    }

    // Detect the actual image MIME type.
    $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);

    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        abort(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'Unsupported photo type.');
    }

    return response($contents, Response::HTTP_OK, [
        'Content-Type' => $mimeType,
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control' => 'private, no-store',
        ]);
    }
}
