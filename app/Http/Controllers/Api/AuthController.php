<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Technician;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function user(Request $request)
    {
        $user = $request->user();

        if ($user) {
            $user->loadMissing('shop');
        }

        return response()->json([
            'user' => $user ? $this->formatUser($user) : null,
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $loginLimiter = RateLimiter::limiter('login');
        $limit = $loginLimiter($request);
        $limiterKey = $limit->key;

        if (RateLimiter::tooManyAttempts($limiterKey, $limit->maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($limiterKey);

            return response()->json([
                'message' => 'Too many login attempts. Please try again later.',
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        if (!Auth::attempt($credentials)) {
            RateLimiter::hit($limiterKey, $limit->decaySeconds);

            throw ValidationException::withMessages([
                'email' => 'The provided credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($limiterKey);

        $request->session()->regenerate();
        $request->session()->regenerateToken();
        $user = $request->user()?->loadMissing('shop');

        if (!$user?->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'This account is inactive. Please contact the administrator.',
            ]);
        }

        if (!$user->hasRole(User::ROLE_PLATFORM_ADMIN) && (!$user->shop || !$user->shop->is_active)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'This account is not linked to an active shop workspace.',
            ]);
        }

        if (!$user || !$user->hasAnyRole(User::portalRoles())) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'This account role is not yet enabled for the operations portal.',
            ]);
        }

        if ($user->isTechnician()) {
            $hasTechnicianProfile = Technician::query()
                ->where('shop_id', $user->shop_id)
                ->where('email', $user->email)
                ->where('is_active', true)
                ->exists();

            if (!$hasTechnicianProfile) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                throw ValidationException::withMessages([
                    'email' => 'No active technician profile is linked to this account.',
                ]);
            }
        }

        $user->forceFill([
            'last_login_at' => now(),
        ])->save();

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'auth.login',
            module: 'authentication',
            description: 'User signed in to the operations portal.',
            newValues: ['email' => $user->email]
        );

        return response()->json([
            'message' => 'Login successful.',
            'user' => $this->formatUser($user),
            'csrf_token' => csrf_token(),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user) {
            AuditLogger::log(
                request: $request,
                user: $user,
                action: 'auth.logout',
                module: 'authentication',
                description: 'User signed out of the operations portal.'
            );
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'shop_id' => $user->shop_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'shop' => $user->shop ? [
                'id' => $user->shop->id,
                'name' => $user->shop->name,
                'slug' => $user->shop->slug,
                'logo_url' => $user->shop->logoUrl(),
                'access_until' => $user->shop->trial_ends_at,
                'access_until_formatted' => $user->shop->trial_ends_at?->format('M d, Y'),
                'has_active_access' => $user->shop->hasActiveAccess(),
                'is_close_out_mode' => $user->shop->isInCloseOutMode(),
            ] : null,
        ];
    }
}
