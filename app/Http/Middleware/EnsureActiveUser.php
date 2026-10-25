<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $currentUser = $user ? User::query()->find($user->getKey()) : null;

        $shopIsInactive = false;

        if ($currentUser && !$currentUser->hasRole(User::ROLE_PLATFORM_ADMIN) && $currentUser->shop_id) {
            $currentUser->loadMissing('shop');
            $shopIsInactive = !$currentUser->shop?->is_active;
        }

        if (!$currentUser?->isActive() || $shopIsInactive) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(Response::HTTP_FORBIDDEN, 'Authentication is required.');
        }

        $request->setUserResolver(fn () => $currentUser);

        return $next($request);
    }
}
