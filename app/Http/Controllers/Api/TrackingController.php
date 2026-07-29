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

        if (!empty($validated['shop_slug'])) {
            $shop = Shop::where('slug', $validated['shop_slug'])->first();

            if (!$shop || !$shop->is_active) {
                return response()->json([
                    'message' => 'Shop not found for the provided tracking code.',
                ], 404);
            }

            return $this->showForShop($shop, $validated['job_order_number']);
        }

        $matches = JobOrder::query()
            ->with('shop:id,name,slug')
            ->whereHas('shop', fn ($query) => $query->where('is_active', true))
            ->where('job_order_number', strtoupper($validated['job_order_number']))
            ->get();

        if ($matches->isEmpty()) {
            return response()->json([
                'message' => 'Job Order not found.',
            ], 404);
        }

        if ($matches->count() > 1) {
            return response()->json([
                'message' => 'Multiple shops matched this tracking number. Please enter the shop code shown on your receipt.',
                'shops' => $matches
                    ->map(fn (JobOrder $jobOrder) => [
                        'name' => $jobOrder->shop?->name,
                        'slug' => $jobOrder->shop?->slug,
                        'tracking_url' => $jobOrder->shop ? url('/shops/' . $jobOrder->shop->slug . '/track/' . $jobOrder->job_order_number) : null,
                    ])
                    ->filter(fn (array $shop) => !empty($shop['slug']))
                    ->values(),
            ], 409);
        }

        return response()->json($this->buildTrackingResponse($matches->first()));
    }

    public function show(string $jobOrderNumber)
    {
        $jobOrder = JobOrder::where('job_order_number', strtoupper($jobOrderNumber))
            ->whereHas('shop', fn ($query) => $query->where('is_active', true))
            ->first();

        if (!$jobOrder) {
            return response()->json([
                'message' => 'Job Order not found.',
            ], 404);
        }

        return response()->json($this->buildTrackingResponse($jobOrder));
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
        $warrantyExpiresAt = $warrantyDays > 0 && $jobOrder->picked_up_at
            ? $jobOrder->picked_up_at->copy()->addDays($warrantyDays)
            : null;

        return [
            'id' => $jobOrder->id,
            'job_order_number' => $jobOrder->job_order_number,
            'service_type' => $jobOrder->service_type ?? 'STANDARD',
            'is_warranty_service' => ($jobOrder->service_type ?? 'STANDARD') === 'WARRANTY_SERVICE',
            'warranty_parent_job_order' => $jobOrder->warrantyParentJobOrder ? [
                'job_order_number' => $jobOrder->warrantyParentJobOrder->job_order_number,
                'created_at_formatted' => $jobOrder->warrantyParentJobOrder->created_at?->format('M d, Y'),
                'problem_description' => $jobOrder->warrantyParentJobOrder->problem_description,
            ] : null,
            'customer_name' => $jobOrder->customer_name,
            'customer_email' => $jobOrder->customer_email,
            'customer_phone' => $jobOrder->customer_phone,
            'customer_contact' => $jobOrder->customer_contact,
            'device_type' => $jobOrder->device_type,
            'brand' => $jobOrder->brand,
            'model' => $jobOrder->model,
            'problem_description' => $jobOrder->problem_description,
            'current_status' => $jobOrder->current_status,
            'priority' => $jobOrder->priority,
            'payment_status' => $jobOrder->payment_status ?? 'PENDING',
            'total_cost' => (float) ($jobOrder->total_cost ?? 0),
            'amount_paid' => (float) ($jobOrder->amount_paid ?? 0),
            'balance' => round(max((float) ($jobOrder->total_cost ?? 0) - (float) ($jobOrder->amount_paid ?? 0), 0), 2),
            'is_fully_paid' => ((float) ($jobOrder->total_cost ?? 0) <= 0)
                || ((float) ($jobOrder->amount_paid ?? 0) >= (float) ($jobOrder->total_cost ?? 0)),
            'picked_up_at' => $jobOrder->picked_up_at,
            'picked_up_by' => $jobOrder->picked_up_by,
            'created_at' => $jobOrder->created_at,
            'created_at_formatted' => $jobOrder->created_at?->format('M d, Y'),
            'picked_up_at_formatted' => $jobOrder->picked_up_at
                ? $jobOrder->picked_up_at->format('M d, Y \a\t h:i A')
                : null,
            'warranty_days' => $warrantyDays,
            'warranty_details' => $jobOrder->warranty_details,
            'warranty_expires_at' => $warrantyExpiresAt,
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
                    'created_at' => $history->created_at,
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
                'url' => $photo->publicUrl(),
                'description' => $photo->description,
                'created_at' => $photo->created_at,
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
