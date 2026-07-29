<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class Controller
{
    /**
     * Ensure the authenticated user matches one of the allowed roles.
     *
     * @param  array<int, string>|string  $roles
     */
    protected function authorizeRoles(Request $request, array|string $roles): User
    {
        $user = $request->user();

        if (!$user) {
            abort(Response::HTTP_FORBIDDEN, 'Authentication is required.');
        }

        $allowedRoles = is_array($roles) ? $roles : [$roles];

        if (!$user->hasAnyRole($allowedRoles)) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have access to this module.');
        }

        if (!$user->hasRole(User::ROLE_PLATFORM_ADMIN) && $user->shop_id && !$request->isMethodSafe()) {
            $user->loadMissing('shop');

            if (!$user->shop?->hasActiveAccess() && !$user->shop?->isInCloseOutMode()) {
                abort(Response::HTTP_PAYMENT_REQUIRED, 'Subscription required. Preview mode lets you explore the system, but saving records and transactions are locked until payment is activated.');
            }
        }

        return $user;
    }

    protected function currentShopId(Request $request): int
    {
        $user = $request->user();

        if (!$user) {
            abort(Response::HTTP_FORBIDDEN, 'Authentication is required.');
        }

        if (!$user->shop_id) {
            abort(Response::HTTP_FORBIDDEN, 'This account is not linked to a shop yet.');
        }

        $user->loadMissing('shop');

        if (!$request->isMethodSafe() && !$user->shop?->hasActiveAccess() && !$user->shop?->isInCloseOutMode()) {
            abort(Response::HTTP_PAYMENT_REQUIRED, 'Subscription required. Preview mode lets you explore the system, but saving records and transactions are locked until payment is activated.');
        }

        return (int) $user->shop_id;
    }

    protected function ensureShopCanStartNewWork(Request $request): void
    {
        $user = $request->user()?->loadMissing('shop');

        if (!$user || !$user->shop) {
            abort(Response::HTTP_FORBIDDEN, 'This account is not linked to a shop yet.');
        }

        if (!$user->shop->hasActiveAccess()) {
            $message = $user->shop->trial_ends_at
                ? 'Shop access has expired. Existing job orders can still be completed, but new intake is locked until the subscription is renewed.'
                : 'Shop access needs setup. Existing job orders can still be completed, but new intake is locked until billing coverage is recorded.';

            abort(Response::HTTP_PAYMENT_REQUIRED, $message);
        }
    }

    protected function ensureSameShop(Request $request, Model $record, string $shopKey = 'shop_id'): void
    {
        if ((int) $record->getAttribute($shopKey) !== $this->currentShopId($request)) {
            abort(Response::HTTP_NOT_FOUND, 'Record not found.');
        }
    }
}
