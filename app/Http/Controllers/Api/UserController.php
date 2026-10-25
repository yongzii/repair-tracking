<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Technician;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $shopId = $this->currentShopId($request);

        $query = User::query()
            ->where('shop_id', $shopId)
            ->with('technician')
            ->latest();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('role', 'like', "%{$search}%");
            });
        }

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $users = $query->paginate((int) $request->query('per_page', 15))->withQueryString();
        $users->getCollection()->transform(fn (User $user) => $this->mapUser($user));

        return response()->json([
            'users' => $users,
            'roles' => User::availableRoles(),
            'technicians' => $this->shopTechnicians($shopId),
        ]);
    }

    public function show(Request $request, User $user)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $this->ensureSameShop($request, $user);
        $shopId = $this->currentShopId($request);

        return response()->json([
            'user' => $this->mapUser($user),
            'roles' => User::availableRoles(),
            'technicians' => $this->shopTechnicians($shopId, $user),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $shopId = $this->currentShopId($request);
        $this->normalizeUsername($request);
        $role = $request->input('role');
        $requiresEmail = in_array($role, User::adminRoles(), true);
        $requiresUsername = in_array($role, [User::ROLE_STAFF, User::ROLE_TECHNICIAN], true);
        $requiresTechnician = $role === User::ROLE_TECHNICIAN;

        $validated = $request->validate([
            'shop_id' => ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                new RequiredIf($requiresEmail),
                'nullable',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'username' => [
                new RequiredIf($requiresUsername),
                'nullable',
                'string',
                'min:3',
                'max:32',
                'regex:/\A[a-zA-Z0-9._-]+\z/',
                'unique:users,username',
            ],
            'role' => ['required', Rule::in(User::availableRoles())],
            'technician_id' => [
                new RequiredIf($requiresTechnician),
                Rule::prohibitedIf(!$requiresTechnician),
                'nullable',
                'integer',
                Rule::exists('technicians', 'id')->where(fn ($query) => $query->where('shop_id', $shopId)),
                'unique:users,technician_id',
            ],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        if ($requiresTechnician) {
            $this->ensureTechnicianIsAvailable((int) $validated['technician_id'], $shopId);
        }

        $generatedPassword = null;
        if (empty($validated['password'])) {
            $generatedPassword = Str::password(12, true, true, false, false);
            $validated['password'] = $generatedPassword;
        }

        $user = User::create([
            'shop_id' => $shopId,
            'technician_id' => $validated['technician_id'] ?? null,
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'username' => $validated['username'] ?? null,
            'role' => $validated['role'],
            'password' => $validated['password'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'user.created',
            module: 'users',
            record: $user,
            description: "Created {$user->role} account for " . ($user->username ?: $user->email) . '.',
            newValues: $user->only(['name', 'email', 'username', 'technician_id', 'role', 'is_active'])
        );

        return response()->json([
            'message' => 'User account created successfully.',
            'user' => $this->mapUser($user),
            'generated_password' => $generatedPassword,
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $this->ensureSameShop($request, $user);
        $shopId = $this->currentShopId($request);
        $this->normalizeUsername($request);
        $role = $request->input('role');
        $requiresEmail = in_array($role, User::adminRoles(), true);
        $requiresUsername = in_array($role, [User::ROLE_STAFF, User::ROLE_TECHNICIAN], true);
        $requiresTechnician = $role === User::ROLE_TECHNICIAN;

        $before = $user->only(['name', 'email', 'username', 'technician_id', 'role', 'is_active']);

        $validated = $request->validate([
            'shop_id' => ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                new RequiredIf($requiresEmail),
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'username' => [
                new RequiredIf($requiresUsername),
                'nullable',
                'string',
                'min:3',
                'max:32',
                'regex:/\A[a-zA-Z0-9._-]+\z/',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'role' => ['required', Rule::in(User::availableRoles())],
            'technician_id' => [
                new RequiredIf($requiresTechnician),
                Rule::prohibitedIf(!$requiresTechnician),
                'nullable',
                'integer',
                Rule::exists('technicians', 'id')->where(fn ($query) => $query->where('shop_id', $shopId)),
                Rule::unique('users', 'technician_id')->ignore($user->id),
            ],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($requiresTechnician) {
            $this->ensureTechnicianIsAvailable((int) $validated['technician_id'], $shopId, $user, false);
        }

        if ($user->id === $request->user()?->id && !$validated['is_active']) {
            return response()->json([
                'message' => 'You cannot deactivate your own account while logged in.',
            ], 422);
        }

        if ($user->isAdmin() && !in_array($validated['role'], User::adminRoles(), true) && $this->countActiveAdmins($shopId) <= 1) {
            return response()->json([
                'message' => 'At least one active admin account must remain in the system.',
            ], 422);
        }

        if ($user->isAdmin() && !$validated['is_active'] && $this->countActiveAdmins($shopId) <= 1) {
            return response()->json([
                'message' => 'You cannot deactivate the last active admin account.',
            ], 422);
        }

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'username' => $validated['username'] ?? $user->username,
            'technician_id' => $validated['technician_id'] ?? null,
            'role' => $validated['role'],
            'is_active' => $validated['is_active'],
        ]);

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'user.updated',
            module: 'users',
            record: $user,
            description: 'Updated account ' . ($user->username ?: $user->email) . '.',
            oldValues: $before,
            newValues: $user->fresh()->only(['name', 'email', 'username', 'technician_id', 'role', 'is_active'])
        );

        return response()->json([
            'message' => 'User account updated successfully.',
            'user' => $this->mapUser($user->fresh()),
        ]);
    }

    public function activate(Request $request, User $user)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $this->ensureSameShop($request, $user);

        $user->update(['is_active' => true]);

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'user.activated',
            module: 'users',
            record: $user,
            description: 'Activated account ' . ($user->username ?: $user->email) . '.',
            oldValues: ['is_active' => false],
            newValues: ['is_active' => true]
        );

        return response()->json([
            'message' => 'User account activated.',
            'user' => $this->mapUser($user->fresh()),
        ]);
    }

    public function deactivate(Request $request, User $user)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $this->ensureSameShop($request, $user);
        $shopId = $this->currentShopId($request);

        if ($user->id === $request->user()?->id) {
            return response()->json([
                'message' => 'You cannot deactivate your own account while logged in.',
            ], 422);
        }

        if ($user->isAdmin() && $this->countActiveAdmins($shopId) <= 1) {
            return response()->json([
                'message' => 'You cannot deactivate the last active admin account.',
            ], 422);
        }

        $user->update(['is_active' => false]);

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'user.deactivated',
            module: 'users',
            record: $user,
            description: 'Deactivated account ' . ($user->username ?: $user->email) . '.',
            oldValues: ['is_active' => true],
            newValues: ['is_active' => false]
        );

        return response()->json([
            'message' => 'User account deactivated.',
            'user' => $this->mapUser($user->fresh()),
        ]);
    }

    public function resetPassword(Request $request, User $user)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $this->ensureSameShop($request, $user);

        $temporaryPassword = Str::password(12, true, true, false, false);
        $user->forceFill([
            'password' => Hash::make($temporaryPassword),
        ])->save();

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'user.password_reset',
            module: 'users',
            record: $user,
            description: 'Reset password for ' . ($user->username ?: $user->email) . '.'
        );

        return response()->json([
            'message' => 'Password reset successfully.',
            'temporary_password' => $temporaryPassword,
        ]);
    }

    public function destroy(Request $request, User $user)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $this->ensureSameShop($request, $user);
        $shopId = $this->currentShopId($request);

        if ($user->id === $request->user()?->id) {
            return response()->json([
                'message' => 'You cannot remove your own account while logged in.',
            ], 422);
        }

        if ($user->isAdmin() && $user->is_active && $this->countActiveAdmins($shopId) <= 1) {
            return response()->json([
                'message' => 'You cannot remove the last active admin account.',
            ], 422);
        }

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'user.deleted',
            module: 'users',
            record: $user,
            description: 'Removed account ' . ($user->username ?: $user->email) . '.',
            oldValues: $user->only(['name', 'email', 'username', 'technician_id', 'role', 'is_active'])
        );

        $user->delete();

        return response()->json([
            'message' => 'User account removed successfully.',
        ]);
    }

    private function mapUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'role' => $user->role,
            'shop_id' => $user->shop_id,
            'shop_name' => $user->shop?->name,
            'technician_id' => $user->technician_id,
            'technician_name' => $user->technician?->name,
            'is_active' => (bool) $user->is_active,
            'last_login_at' => $user->last_login_at,
            'last_login_at_formatted' => $user->last_login_at?->format('M d, Y h:i A'),
            'created_at' => $user->created_at,
            'created_at_formatted' => $user->created_at?->format('M d, Y'),
        ];
    }

    private function countActiveAdmins(int $shopId): int
    {
        return User::query()
            ->where('shop_id', $shopId)
            ->whereIn('role', User::adminRoles())
            ->where('is_active', true)
            ->count();
    }

    private function shopTechnicians(int $shopId, ?User $currentUser = null): array
    {
        $query = Technician::query()
            ->where('technicians.shop_id', $shopId)
            ->where(function ($builder) use ($currentUser) {
                $builder->where('technicians.is_active', true);

                if ($currentUser?->technician_id) {
                    $builder->orWhere('technicians.id', $currentUser->technician_id);
                }

                if ($currentUser?->email) {
                    $builder->orWhere('technicians.email', $currentUser->email);
                }
            })
            ->whereDoesntHave('user', function ($userQuery) use ($currentUser) {
                if ($currentUser) {
                    $userQuery->where('users.id', '!=', $currentUser->id);
                }
            })
            ->whereNotExists(function ($userQuery) use ($shopId, $currentUser) {
                $userQuery->selectRaw('1')
                    ->from('users')
                    ->whereColumn('users.email', 'technicians.email')
                    ->where('users.shop_id', $shopId)
                    ->where('users.role', User::ROLE_TECHNICIAN);

                if ($currentUser) {
                    $userQuery->where('users.id', '!=', $currentUser->id);
                }
            });

        return $query
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (Technician $technician) => [
                'id' => $technician->id,
                'name' => $technician->name,
                'email' => $technician->email,
                'is_active' => (bool) $technician->is_active,
            ])
            ->all();
    }

    private function normalizeUsername(Request $request): void
    {
        if ($request->filled('username')) {
            $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        }
    }

    private function ensureTechnicianIsAvailable(
        int $technicianId,
        int $shopId,
        ?User $except = null,
        bool $requireActive = true
    ): void
    {
        $technician = Technician::query()
            ->where('shop_id', $shopId)
            ->findOrFail($technicianId);

        if ($requireActive && !$technician->is_active) {
            throw ValidationException::withMessages([
                'technician_id' => 'Select an active technician profile.',
            ]);
        }

        $query = User::query()
            ->where('shop_id', $shopId)
            ->where('role', User::ROLE_TECHNICIAN)
            ->where(function ($builder) use ($technicianId, $technician) {
                $builder->where('technician_id', $technicianId);

                if ($technician->email) {
                    $builder->orWhere(function ($legacyQuery) use ($technician) {
                        $legacyQuery->whereNull('technician_id')
                            ->where('email', $technician->email);
                    });
                }
            });

        if ($except) {
            $query->where('id', '!=', $except->id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'technician_id' => 'This technician profile already has an account.',
            ]);
        }
    }
}
