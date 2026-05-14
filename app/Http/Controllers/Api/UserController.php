<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $shopId = $this->currentShopId($request);

        $query = User::query()
            ->where('shop_id', $shopId)
            ->latest();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
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
        ]);
    }

    public function show(Request $request, User $user)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $this->ensureSameShop($request, $user);

        return response()->json([
            'user' => $this->mapUser($user),
            'roles' => User::availableRoles(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $shopId = $this->currentShopId($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(User::availableRoles())],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        $generatedPassword = null;
        if (empty($validated['password'])) {
            $generatedPassword = Str::password(12, true, true, false, false);
            $validated['password'] = $generatedPassword;
        }

        $user = User::create([
            'shop_id' => $shopId,
            'name' => $validated['name'],
            'email' => $validated['email'],
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
            description: "Created {$user->role} account for {$user->email}.",
            newValues: $user->only(['name', 'email', 'role', 'is_active'])
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

        $before = $user->only(['name', 'email', 'role', 'is_active']);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(User::availableRoles())],
            'is_active' => ['required', 'boolean'],
        ]);

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

        $user->update($validated);

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'user.updated',
            module: 'users',
            record: $user,
            description: "Updated account {$user->email}.",
            oldValues: $before,
            newValues: $user->fresh()->only(['name', 'email', 'role', 'is_active'])
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
            description: "Activated account {$user->email}.",
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
            description: "Deactivated account {$user->email}.",
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
            description: "Reset password for {$user->email}."
        );

        return response()->json([
            'message' => 'Password reset successfully.',
            'temporary_password' => $temporaryPassword,
        ]);
    }

    private function mapUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'shop_id' => $user->shop_id,
            'shop_name' => $user->shop?->name,
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
}
