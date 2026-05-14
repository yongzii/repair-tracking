<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\Shop;
use App\Models\StatusHistory;
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

            if (!$shop) {
                return response()->json([
                    'message' => 'Shop not found for the provided tracking code.',
                ], 404);
            }

            return $this->showForShop($shop, $validated['job_order_number']);
        }

        $matches = JobOrder::query()
            ->with('shop:id,name,slug')
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
        $validated = $request->validate([
            'job_order_number' => ['required', 'string'],
        ]);

        return $this->showForShop($shop, $validated['job_order_number']);
    }

    public function showForShop(Shop $shop, string $jobOrderNumber)
    {
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
            ],
            'jobOrder' => $this->formatJobOrder($jobOrder),
            'timeline' => $this->formatTimeline($jobOrder),
        ];
    }

    private function formatJobOrder(JobOrder $jobOrder): array
    {
        return [
            'id' => $jobOrder->id,
            'job_order_number' => $jobOrder->job_order_number,
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
            'picked_up_at' => $jobOrder->picked_up_at,
            'picked_up_by' => $jobOrder->picked_up_by,
            'created_at' => $jobOrder->created_at,
            'created_at_formatted' => $jobOrder->created_at?->format('M d, Y'),
            'picked_up_at_formatted' => $jobOrder->picked_up_at
                ? $jobOrder->picked_up_at->format('M d, Y \a\t h:i A')
                : null,
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
}
