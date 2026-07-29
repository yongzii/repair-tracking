<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSubscriptionSetting;
use App\Models\SubscriptionRequest;
use Illuminate\Http\Request;

class SubscriptionRequestController extends Controller
{
    public function options(Request $request)
    {
        $user = $request->user()?->loadMissing('shop');

        if (!$user || !$user->shop) {
            abort(403, 'This account is not linked to a shop yet.');
        }

        return response()->json([
            'settings' => PlatformSubscriptionSetting::current(),
            'pending_request' => $this->mapRequest(
                $user->shop->subscriptionRequests()
                    ->where('status', SubscriptionRequest::STATUS_PENDING)
                    ->latest()
                    ->first()
            ),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user()?->loadMissing('shop');

        if (!$user || !$user->shop) {
            abort(403, 'This account is not linked to a shop yet.');
        }

        $settings = PlatformSubscriptionSetting::current();

        $validated = $request->validate([
            'plan_key' => ['required', 'string', 'max:80'],
            'payment_method_key' => ['required', 'string', 'max:80'],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:4096'],
        ]);

        $plan = collect($settings['plans'])->firstWhere('key', $validated['plan_key']);
        $method = collect($settings['payment_methods'])->firstWhere('key', $validated['payment_method_key']);

        if (!$plan || !$method) {
            return response()->json([
                'message' => 'Selected subscription plan or payment method is no longer available.',
            ], 422);
        }

        $proofPath = $request->file('proof')->store("shops/{$user->shop->id}/subscription-proofs", 'public');

        $subscriptionRequest = SubscriptionRequest::create([
            'shop_id' => $user->shop->id,
            'requested_by_id' => $user->id,
            'plan_key' => $plan['key'],
            'plan_name' => $plan['name'],
            'amount' => $plan['amount'],
            'duration_months' => $plan['duration_months'] ?? null,
            'payment_method_key' => $method['key'],
            'payment_method_name' => $method['name'],
            'reference_number' => $validated['reference_number'] ?? null,
            'proof_path' => $proofPath,
            'status' => SubscriptionRequest::STATUS_PENDING,
        ]);

        return response()->json([
            'message' => 'Subscription request submitted. Please wait for platform admin verification.',
            'subscription_request' => $this->mapRequest($subscriptionRequest->fresh()),
        ], 201);
    }

    private function mapRequest(?SubscriptionRequest $request): ?array
    {
        if (!$request) {
            return null;
        }

        return [
            'id' => $request->id,
            'plan_name' => $request->plan_name,
            'amount' => (float) $request->amount,
            'payment_method_name' => $request->payment_method_name,
            'reference_number' => $request->reference_number,
            'proof_url' => $request->proofUrl(),
            'status' => $request->status,
            'created_at_formatted' => $request->created_at?->format('M d, Y h:i A'),
        ];
    }
}
