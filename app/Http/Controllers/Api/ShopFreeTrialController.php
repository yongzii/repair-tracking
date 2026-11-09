<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopFreeTrialController extends Controller
{
    public function activate(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->role !== User::ROLE_SHOP_ADMIN || !$user->shop_id) {
            abort(403, "Only the Shop Admin can activate this shop's free trial.");
        }

        $result = DB::transaction(function () use ($user) {
            $shop = Shop::query()
                ->lockForUpdate()
                ->find($user->shop_id);

            if (!$shop || !$shop->is_active) {
                return ['error' => 'inactive'];
            }

            if ($shop->hasActiveAccess()) {
                return ['error' => 'paid'];
            }

            if ($shop->hasUsedFreeTrial()) {
                return ['error' => 'used'];
            }

            if (!$shop->isEligibleForFreeTrial()) {
                return ['error' => 'ineligible'];
            }

            if (!$shop->activateFreeTrial()) {
                return ['error' => 'ineligible'];
            }

            return ['shop' => $shop->fresh()];
        });

        if (($result['error'] ?? null) === 'inactive') {
            abort(403, 'This shop is inactive and cannot activate a free trial.');
        }

        if (($result['error'] ?? null) === 'paid') {
            return response()->json([
                'message' => 'This shop already has active subscription coverage.',
            ], 409);
        }

        if (($result['error'] ?? null) === 'used') {
            return response()->json([
                'message' => 'This shop has already used its free trial.',
            ], 409);
        }

        if (($result['error'] ?? null) === 'ineligible') {
            return response()->json([
                'message' => 'This shop is not eligible to activate a free trial.',
            ], 409);
        }

        /** @var Shop $shop */
        $shop = $result['shop'];

        return response()->json([
            'message' => 'The 7-day free trial has been activated.',
            'trial' => [
                'eligible' => (bool) $shop->free_trial_eligible,
                'used' => $shop->hasUsedFreeTrial(),
                'active' => $shop->isFreeTrialActive(),
                'started_at' => $shop->free_trial_started_at?->toISOString(),
                'ends_at' => $shop->freeTrialExpirationAt()?->toISOString(),
                'used_at' => $shop->free_trial_used_at?->toISOString(),
            ],
        ]);
    }
}
