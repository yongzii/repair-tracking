<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use App\Models\JobOrder;
use App\Models\Technician;
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

    protected function authorizeStaffOrAdmin(Request $request): User
    {
        return $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
    }

    protected function resolveTechnician(User $user): Technician
    {
        $technician = $this->findTechnician($user);

        if (!$technician) {
            abort(Response::HTTP_FORBIDDEN, 'No active technician profile is linked to this account.');
        }

        return $technician;
    }

    protected function findTechnician(User $user): ?Technician
    {
        if ($user->technician_id) {
            return $user->technician()
                ->where('shop_id', $user->shop_id)
                ->where('is_active', true)
                ->first();
        }

        if (!$user->email) {
            return null;
        }

        return Technician::query()
            ->where('shop_id', $user->shop_id)
            ->where('email', $user->email)
            ->where('is_active', true)
            ->first();
    }

    protected function ensureAssignedToTechnician(JobOrder $jobOrder, Technician $technician): void
    {
        if ((int) $jobOrder->shop_id !== (int) $technician->shop_id) {
            abort(Response::HTTP_FORBIDDEN, 'You can only access jobs from your assigned shop.');
        }

        if ((int) $jobOrder->technician_id !== (int) $technician->id) {
            abort(Response::HTTP_FORBIDDEN, 'You can only access jobs assigned to your technician profile.');
        }
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
