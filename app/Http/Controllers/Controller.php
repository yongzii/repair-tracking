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

        return (int) $user->shop_id;
    }

    protected function ensureSameShop(Request $request, Model $record, string $shopKey = 'shop_id'): void
    {
        if ((int) $record->getAttribute($shopKey) !== $this->currentShopId($request)) {
            abort(Response::HTTP_NOT_FOUND, 'Record not found.');
        }
    }
}
