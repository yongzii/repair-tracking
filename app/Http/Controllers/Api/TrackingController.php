<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\RepairPhoto;
use App\Models\Shop;
use App\Models\StatusHistory;
use App\Models\WarrantyAssessment;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    public function search(Request $request)
    {
        $validated = $request->validate([
            'job_order_number' => ['required', 'string'],
            'shop_slug' => ['nullable', 'string'],
        ]);

        if (empty($validated['shop_slug'])) {
            return response()->json([
                'message' => 'Shop code is required for tracking.',
                'errors' => [
                    'shop_slug' => ['The shop code field is required.'],
                ],
            ], 422);
        }

        $shop = Shop::where('slug', $validated['shop_slug'])
            ->where('is_active', true)
            ->first();

        if (!$shop) {
            return response()->json([
                'message' => 'Job Order not found for this shop.',
            ], 404);
        }

        return $this->showForShop($shop, $validated['job_order_number']);
    }

    public function show(Request $request, string $jobOrderNumber)
    {
        return response()->json([
            'message' => 'Shop code is required for tracking.',
            'errors' => [
                'shop_slug' => ['The shop code field is required.'],
            ],
        ], 422);
    }

    public function searchForShop(Request $request, Shop $shop)
    {
        if (!$shop->is_active) {
            abort(404, 'Shop not found.');
        }

        $validated = $request->validate([
            'job_order_number' => ['required', 'string'],
        ]);

        return $this->showForShop($shop, $validated['job_order_number']);
    }

    public function showForShop(Shop $shop, string $jobOrderNumber)
    {
        if (!$shop->is_active) {
            abort(404, 'Shop not found.');
        }

        $jobOrder = JobOrder::query()
            ->where('shop_id', $shop->id)
            ->where('job_order_number', strtoupper($jobOrderNumber))
            ->first();

        if (!$jobOrder) {
            return response()->json([
                'message' => 'Job Order not found for this shop.',
            ], 404);
        }

        return response()->json($this->buildTrackingResponse($jobOrder));
    }

    private function buildTrackingResponse(JobOrder $jobOrder): array
    {
        return [
            'shop' => [
                'name' => $jobOrder->shop?->name,
                'slug' => $jobOrder->shop?->slug,
                'phone' => $jobOrder->shop?->phone,
                'address' => $jobOrder->shop?->address,
                'business_hours' => $jobOrder->shop?->business_hours,
                'logo_url' => $jobOrder->shop?->logoUrl(),
            ],
            'jobOrder' => $this->formatJobOrder($jobOrder),
            'timeline' => $this->formatTimeline($jobOrder),
            'photos' => $this->formatCustomerPhotos($jobOrder),
            'warrantyAssessments' => $this->formatWarrantyAssessments($jobOrder),
        ];
    }

    private function formatJobOrder(JobOrder $jobOrder): array
    {
        $warrantyDays = (int) ($jobOrder->warranty_days ?? 0);
        $warrantyExpiresAt = $warrantyDays > 0 && $jobOrder->current_status === 'PICKED_UP' && $jobOrder->picked_up_at
            ? $jobOrder->picked_up_at->copy()->addDays($warrantyDays)
            : null;

        return [
            'job_order_number' => $jobOrder->job_order_number,
            'service_type' => $jobOrder->service_type ?? 'STANDARD',
            'is_warranty_service' => ($jobOrder->service_type ?? 'STANDARD') === 'WARRANTY_SERVICE',
            'warranty_parent_job_order' => $jobOrder->warrantyParentJobOrder ? [
                'job_order_number' => $jobOrder->warrantyParentJobOrder->job_order_number,
            ] : null,
            'customer_name' => $jobOrder->customer_name,
            'device_type' => $jobOrder->device_type,
            'brand' => $jobOrder->brand,
            'model' => $jobOrder->model,
            'current_status' => $jobOrder->current_status,
            'created_at_formatted' => $jobOrder->created_at?->format('M d, Y'),
            'picked_up_at_formatted' => $jobOrder->picked_up_at
                ? $jobOrder->picked_up_at->format('M d, Y \a\t h:i A')
                : null,
            'warranty_days' => $warrantyDays,
            'warranty_details' => $jobOrder->warranty_details,
            'warranty_expires_at_formatted' => $warrantyExpiresAt?->format('M d, Y'),
            'is_under_warranty' => $warrantyExpiresAt ? now()->lessThanOrEqualTo($warrantyExpiresAt) : false,
        ];
    }

    private function formatTimeline(JobOrder $jobOrder): array
    {
        return $jobOrder->statusHistories()
            ->orderBy('created_at')
            ->get()
            ->map(function (StatusHistory $history) {
                return [
                    'id' => $history->id,
                    'status' => $history->status,
                    'note' => $history->note,
                    'created_at_formatted' => $history->created_at?->format('M d, Y \a\t h:i A'),
                ];
            })
            ->all();
    }

    private function formatCustomerPhotos(JobOrder $jobOrder): array
    {
        return $jobOrder->repairPhotos()
            ->where('visible_to_customer', true)
            ->latest()
            ->get()
            ->map(fn (RepairPhoto $photo) => [
                'id' => $photo->id,
                'photo_type' => $photo->photo_type,
                'photo_type_label' => str_replace('_', ' ', $photo->photo_type),
                'url' => route('tracking.photos.file', [
                    'shop' => $jobOrder->shop?->slug,
                    'jobOrderNumber' => $jobOrder->job_order_number,
                    'photo' => $photo->id,
                ]),
                'description' => $photo->description,
                'created_at_formatted' => $photo->created_at?->format('M d, Y \a\t h:i A'),
            ])
            ->all();
    }

    private function formatWarrantyAssessments(JobOrder $jobOrder): array
    {
        return $jobOrder->warrantyAssessments()
            ->with('warrantyJobOrder:id,job_order_number,current_status')
            ->latest()
            ->get()
            ->map(fn (WarrantyAssessment $assessment) => [
                'id' => $assessment->id,
                'reported_issue' => $assessment->reported_issue,
                'result' => $assessment->result,
                'result_label' => str_replace('_', ' ', $assessment->result),
                'brought_at_formatted' => $assessment->brought_at?->format('M d, Y'),
                'created_at_formatted' => $assessment->created_at?->format('M d, Y'),
                'warranty_job_order' => $assessment->warrantyJobOrder ? [
                    'job_order_number' => $assessment->warrantyJobOrder->job_order_number,
                    'current_status' => $assessment->warrantyJobOrder->current_status,
                ] : null,
            ])
            ->all();
    }
}
