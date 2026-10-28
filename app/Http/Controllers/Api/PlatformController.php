<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerNotification;
use App\Models\JobOrder;
use App\Models\PlatformSubscriptionSetting;
use App\Models\PlatformSubscriptionPayment;
use App\Models\Shop;
use App\Models\SubscriptionRequest;
use App\Models\Technician;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PlatformController extends Controller
{
    public function dashboard(Request $request)
    {
        $this->authorizeRoles($request, User::ROLE_PLATFORM_ADMIN);

        $shops = Shop::query();
        $jobOrders = JobOrder::query();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $monthPayments = PlatformSubscriptionPayment::query()
            ->whereBetween('received_at', [$monthStart, $monthEnd]);
        $monthPaymentCount = (clone $monthPayments)->count();
        $monthCollected = (float) (clone $monthPayments)->sum('amount');
        $approvedRequests = SubscriptionRequest::query()
            ->where('status', SubscriptionRequest::STATUS_APPROVED)
            ->whereBetween('reviewed_at', [$monthStart, $monthEnd]);
        $approvedRequestCount = (clone $approvedRequests)->count();
        $approvedRequestAmount = (float) (clone $approvedRequests)->sum('amount');
        $monthPaymentLedger = (clone $monthPayments)
            ->with('shop:id,name,slug')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();
        $monthPaymentLedger->getCollection()->transform(fn (PlatformSubscriptionPayment $payment) => $this->mapSubscriptionPayment($payment));

        return response()->json([
            'summary' => [
                'total_shops' => (clone $shops)->count(),
                'active_shops' => (clone $shops)->where('is_active', true)->count(),
                'inactive_shops' => (clone $shops)->where('is_active', false)->count(),
                'trial_shops' => (clone $shops)->where('is_active', true)->whereNotNull('trial_ends_at')->where('trial_ends_at', '>=', now())->count(),
                'trial_expiring_soon' => (clone $shops)->where('is_active', true)->whereBetween('trial_ends_at', [now(), now()->addDays(7)])->count(),
                'expired_trials' => (clone $shops)->where('is_active', true)->whereNotNull('trial_ends_at')->where('trial_ends_at', '<', now())->count(),
                'no_access_date_shops' => (clone $shops)->where('is_active', true)->whereNull('trial_ends_at')->count(),
                'platform_collected_this_month' => $monthCollected,
                'platform_payments_this_month' => $monthPaymentCount,
                'platform_approved_requests_this_month' => $approvedRequestCount,
                'platform_approved_request_amount_this_month' => $approvedRequestAmount,
                'platform_additional_payment_records_this_month' => max(0, $monthPaymentCount - $approvedRequestCount),
                'platform_payment_difference_from_requests_this_month' => $monthCollected - $approvedRequestAmount,
                'portal_users' => User::whereIn('role', User::portalRoles())->count(),
                'technicians' => Technician::count(),
                'job_orders' => (clone $jobOrders)->count(),
                'total_revenue' => (clone $jobOrders)->sum('total_cost'),
                'pending_payments' => (clone $jobOrders)->sum(DB::raw('total_cost - amount_paid')),
            ],
            'recent_shops' => Shop::query()
                ->with(['users' => fn ($query) => $query->whereIn('role', User::adminRoles())->orderByDesc('is_active')->oldest()])
                ->withCount(['users', 'technicians', 'jobOrders'])
                ->latest()
                ->take(8)
                ->get()
                ->map(fn (Shop $shop) => $this->mapShop($shop)),
            'platform_month_payments' => $monthPaymentLedger,
            'recent_reminders' => CustomerNotification::query()
                ->whereNull('job_order_id')
                ->where('trigger', 'like', 'platform_subscription:%')
                ->with('shop:id,name,slug')
                ->latest()
                ->take(10)
                ->get()
                ->map(fn (CustomerNotification $notification) => $this->mapPlatformReminder($notification)),
            'pending_subscription_requests' => SubscriptionRequest::query()
                ->where('status', SubscriptionRequest::STATUS_PENDING)
                ->with(['shop:id,name,slug,email', 'requestedBy:id,name,email'])
                ->latest()
                ->take(8)
                ->get()
                ->map(fn (SubscriptionRequest $subscriptionRequest) => $this->mapSubscriptionRequest($subscriptionRequest)),
        ]);
    }

    public function subscriptionSettings(Request $request)
    {
        $this->authorizeRoles($request, User::ROLE_PLATFORM_ADMIN);

        return response()->json([
            'settings' => PlatformSubscriptionSetting::current(),
        ]);
    }

    public function updateSubscriptionSettings(Request $request)
    {
        $user = $this->authorizeRoles($request, User::ROLE_PLATFORM_ADMIN);

        $validated = $request->validate([
            'plans' => ['required', 'array', 'min:1'],
            'plans.*.key' => ['required', 'string', 'max:80'],
            'plans.*.name' => ['required', 'string', 'max:120'],
            'plans.*.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'plans.*.duration_months' => ['required', 'integer', 'min:1', 'max:120'],
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*.key' => ['required', 'string', 'max:80'],
            'payment_methods.*.name' => ['required', 'string', 'max:120'],
            'payment_methods.*.account_name' => ['nullable', 'string', 'max:150'],
            'payment_methods.*.account_number' => ['nullable', 'string', 'max:150'],
            'payment_methods.*.instructions' => ['nullable', 'string', 'max:500'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        $settings = PlatformSubscriptionSetting::query()->firstOrCreate([]);
        $before = $settings->only(['plans', 'payment_methods', 'instructions']);

        $settings->update([
            'plans' => collect($validated['plans'])->map(fn (array $plan) => [
                'key' => Str::slug($plan['key']),
                'name' => $plan['name'],
                'amount' => (float) $plan['amount'],
                'duration_months' => (int) $plan['duration_months'],
            ])->values()->all(),
            'payment_methods' => collect($validated['payment_methods'])->map(fn (array $method) => [
                'key' => Str::slug($method['key']),
                'name' => $method['name'],
                'account_name' => $method['account_name'] ?? null,
                'account_number' => $method['account_number'] ?? null,
                'instructions' => $method['instructions'] ?? null,
            ])->values()->all(),
            'instructions' => $validated['instructions'] ?? null,
        ]);

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'platform.subscription_settings.updated',
            module: 'platform_billing',
            record: $settings,
            description: 'Updated platform subscription payment settings.',
            oldValues: $before,
            newValues: $settings->fresh()->only(['plans', 'payment_methods', 'instructions'])
        );

        return response()->json([
            'message' => 'Subscription settings updated.',
            'settings' => PlatformSubscriptionSetting::current(),
        ]);
    }

    public function subscriptionRequests(Request $request)
    {
        $this->authorizeRoles($request, User::ROLE_PLATFORM_ADMIN);

        $status = $request->query('status', SubscriptionRequest::STATUS_PENDING);

        $requests = SubscriptionRequest::query()
            ->with(['shop:id,name,slug,email', 'requestedBy:id,name,email', 'reviewedBy:id,name,email'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate((int) $request->query('per_page', 10))
            ->withQueryString();

        $requests->getCollection()->transform(fn (SubscriptionRequest $subscriptionRequest) => $this->mapSubscriptionRequest($subscriptionRequest));

        return response()->json([
            'subscription_requests' => $requests,
        ]);
    }

    public function approveSubscriptionRequest(Request $request, SubscriptionRequest $subscriptionRequest)
    {
        $user = $this->authorizeRoles($request, User::ROLE_PLATFORM_ADMIN);

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $approved = DB::transaction(function () use ($subscriptionRequest, $user, $validated) {
            $lockedRequest = SubscriptionRequest::query()
                ->lockForUpdate()
                ->findOrFail($subscriptionRequest->id);

            if ($lockedRequest->status !== SubscriptionRequest::STATUS_PENDING) {
                return false;
            }

            $shop = Shop::query()
                ->lockForUpdate()
                ->findOrFail($lockedRequest->shop_id);
            $start = $shop->trial_ends_at && $shop->trial_ends_at->isFuture()
                ? $shop->trial_ends_at->copy()->addDay()->startOfDay()
                : now()->startOfDay();
            $end = $start->copy()->addMonths($lockedRequest->duration_months ?: 1)->subDay()->endOfDay();

            PlatformSubscriptionPayment::create([
                'shop_id' => $shop->id,
                'amount' => $lockedRequest->amount,
                'payment_method' => $lockedRequest->payment_method_name,
                'reference_number' => $lockedRequest->reference_number,
                'covered_from' => $start,
                'covered_until' => $end,
                'notes' => $validated['admin_notes'] ?? 'Approved subscription request.',
                'received_at' => now(),
                'received_by' => $user->name,
            ]);

            $shop->forceFill([
                'subscription_plan' => $lockedRequest->plan_key,
                'trial_ends_at' => $end,
                'is_active' => true,
            ])->save();

            $lockedRequest->forceFill([
                'status' => SubscriptionRequest::STATUS_APPROVED,
                'reviewed_by_id' => $user->id,
                'reviewed_at' => now(),
                'admin_notes' => $validated['admin_notes'] ?? null,
            ])->save();

            return true;
        });

        if (!$approved) {
            return response()->json(['message' => 'This subscription request was already reviewed.'], 422);
        }

        $subscriptionRequest->refresh();
        $shop = $subscriptionRequest->shop;

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'platform.subscription_request.approved',
            module: 'platform_billing',
            record: $subscriptionRequest,
            description: "Approved subscription request for {$shop->name}.",
            newValues: [
                'shop_id' => $shop->id,
                'plan' => $subscriptionRequest->plan_name,
                'amount' => $subscriptionRequest->amount,
                'access_until' => $shop->fresh()->trial_ends_at,
            ]
        );

        return response()->json([
            'message' => 'Subscription request approved and access activated.',
            'subscription_request' => $this->mapSubscriptionRequest($subscriptionRequest->fresh()->load(['shop', 'requestedBy', 'reviewedBy'])),
        ]);
    }

    public function rejectSubscriptionRequest(Request $request, SubscriptionRequest $subscriptionRequest)
    {
        $user = $this->authorizeRoles($request, User::ROLE_PLATFORM_ADMIN);

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $rejected = DB::transaction(function () use ($subscriptionRequest, $user, $validated) {
            $lockedRequest = SubscriptionRequest::query()
                ->lockForUpdate()
                ->findOrFail($subscriptionRequest->id);

            if ($lockedRequest->status !== SubscriptionRequest::STATUS_PENDING) {
                return false;
            }

            $lockedRequest->forceFill([
                'status' => SubscriptionRequest::STATUS_REJECTED,
                'reviewed_by_id' => $user->id,
                'reviewed_at' => now(),
                'admin_notes' => $validated['admin_notes'] ?? null,
            ])->save();

            return true;
        });

        if (!$rejected) {
            return response()->json(['message' => 'This subscription request was already reviewed.'], 422);
        }

        $subscriptionRequest->refresh();

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'platform.subscription_request.rejected',
            module: 'platform_billing',
            record: $subscriptionRequest,
            description: "Rejected subscription request for {$subscriptionRequest->shop?->name}.",
            newValues: $subscriptionRequest->fresh()->only(['status', 'admin_notes', 'reviewed_by_id', 'reviewed_at'])
        );

        return response()->json([
            'message' => 'Subscription request rejected.',
            'subscription_request' => $this->mapSubscriptionRequest($subscriptionRequest->fresh()->load(['shop', 'requestedBy', 'reviewedBy'])),
        ]);
    }

    public function shops(Request $request)
    {
        $this->authorizeRoles($request, User::ROLE_PLATFORM_ADMIN);

        $query = Shop::query()
            ->with(['users' => fn ($builder) => $builder->whereIn('role', User::adminRoles())->orderByDesc('is_active')->oldest()])
            ->with(['subscriptionPayments' => fn ($builder) => $builder->latest('received_at')->latest()->limit(3)])
            ->withCount(['users', 'technicians', 'jobOrders'])
            ->latest();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($accessStatus = $request->query('access_status')) {
            match ($accessStatus) {
                'active' => $query->where('is_active', true)
                    ->whereNotNull('trial_ends_at')
                    ->where('trial_ends_at', '>', now()->addDays(7)),
                'expiring' => $query->where('is_active', true)->whereBetween('trial_ends_at', [now(), now()->addDays(7)]),
                'close_out' => $query->where('is_active', true)->whereNotNull('trial_ends_at')->where('trial_ends_at', '<', now()),
                'needs_setup' => $query->where('is_active', true)->whereNull('trial_ends_at'),
                'inactive' => $query->where('is_active', false),
                default => null,
            };
        }

        if ($plan = $request->query('subscription_plan')) {
            $query->where('subscription_plan', $plan);
        }

        $shops = $query->paginate((int) $request->query('per_page', 15))->withQueryString();
        $shops->getCollection()->transform(fn (Shop $shop) => $this->mapShop($shop));

        return response()->json([
            'shops' => $shops,
            'plans' => Shop::query()
                ->select('subscription_plan')
                ->distinct()
                ->orderBy('subscription_plan')
                ->pluck('subscription_plan')
                ->filter()
                ->values(),
        ]);
    }

    public function updateShop(Request $request, Shop $shop)
    {
        $user = $this->authorizeRoles($request, User::ROLE_PLATFORM_ADMIN);

        $validated = $request->validate([
            'subscription_plan' => ['required', 'string', 'max:50'],
            'trial_ends_at' => ['nullable', 'date'],
            'is_active' => ['required', 'boolean'],
        ]);

        $before = $shop->only(['subscription_plan', 'trial_ends_at', 'is_active']);

        $shop->update([
            'subscription_plan' => $validated['subscription_plan'],
            'trial_ends_at' => $validated['trial_ends_at'] ?? null,
            'is_active' => $validated['is_active'],
        ]);

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'platform.shop.updated',
            module: 'platform',
            record: $shop,
            description: "Updated platform settings for {$shop->name}.",
            oldValues: $before,
            newValues: $shop->fresh()->only(['subscription_plan', 'trial_ends_at', 'is_active'])
        );

        return response()->json([
            'message' => 'Shop workspace updated successfully.',
            'shop' => $this->mapShop($shop->fresh()->loadCount(['users', 'technicians', 'jobOrders'])),
        ]);
    }

    public function toggleShop(Request $request, Shop $shop)
    {
        $user = $this->authorizeRoles($request, User::ROLE_PLATFORM_ADMIN);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'subscription_plan' => ['nullable', 'string', 'max:50'],
        ]);

        $before = $shop->only(['subscription_plan', 'is_active']);

        $shop->update([
            'is_active' => $validated['is_active'],
            'subscription_plan' => $validated['subscription_plan'] ?? $shop->subscription_plan,
        ]);

        AuditLogger::log(
            request: $request,
            user: $user,
            action: $shop->is_active ? 'platform.shop.activated' : 'platform.shop.deactivated',
            module: 'platform',
            record: $shop,
            description: $shop->is_active
                ? "Activated shop workspace {$shop->name}."
                : "Deactivated shop workspace {$shop->name}.",
            oldValues: $before,
            newValues: $shop->fresh()->only(['subscription_plan', 'is_active'])
        );

        return response()->json([
            'message' => $shop->is_active ? 'Shop workspace activated.' : 'Shop workspace deactivated.',
            'shop' => $this->mapShop($shop->fresh()->loadCount(['users', 'technicians', 'jobOrders'])),
        ]);
    }

    public function resetShopAdminPassword(Request $request, Shop $shop)
    {
        $user = $this->authorizeRoles($request, User::ROLE_PLATFORM_ADMIN);

        $admin = $shop->users()
            ->whereIn('role', User::adminRoles())
            ->orderByDesc('is_active')
            ->oldest()
            ->first();

        if (!$admin) {
            return response()->json([
                'message' => 'This shop has no admin account to reset.',
            ], 422);
        }

        $temporaryPassword = Str::password(12, true, true, false, false);
        $admin->forceFill([
            'password' => Hash::make($temporaryPassword),
            'is_active' => true,
        ])->save();

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'platform.shop_admin.password_reset',
            module: 'platform',
            record: $admin,
            description: "Reset shop admin password for {$admin->email} in {$shop->name}.",
            newValues: [
                'shop_id' => $shop->id,
                'shop_name' => $shop->name,
                'admin_email' => $admin->email,
            ]
        );

        return response()->json([
            'message' => 'Shop admin password reset successfully.',
            'admin' => $this->mapShopAdmin($admin),
            'temporary_password' => $temporaryPassword,
        ]);
    }

    public function storeSubscriptionPayment(Request $request, Shop $shop)
    {
        $user = $this->authorizeRoles($request, User::ROLE_PLATFORM_ADMIN);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'payment_method' => ['nullable', 'string', 'max:40'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'covered_from' => ['nullable', 'date'],
            'covered_until' => ['nullable', 'date', 'after_or_equal:covered_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'received_at' => ['nullable', 'date'],
        ]);

        $payment = PlatformSubscriptionPayment::create([
            'shop_id' => $shop->id,
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'] ?? null,
            'reference_number' => $validated['reference_number'] ?? null,
            'covered_from' => $validated['covered_from'] ?? null,
            'covered_until' => $validated['covered_until'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'received_at' => $validated['received_at'] ?? now(),
            'received_by' => $user?->name,
        ]);

        if ($payment->covered_until && (!$shop->trial_ends_at || $payment->covered_until->gt($shop->trial_ends_at))) {
            $shop->forceFill([
                'trial_ends_at' => $payment->covered_until->copy()->endOfDay(),
                'is_active' => true,
            ])->save();
        }

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'platform.subscription_payment.recorded',
            module: 'platform_billing',
            record: $payment,
            description: "Recorded subscription payment for {$shop->name}.",
            newValues: [
                ...$payment->only(['shop_id', 'amount', 'payment_method', 'reference_number', 'covered_from', 'covered_until']),
                'shop_access_until' => $shop->fresh()->trial_ends_at,
            ]
        );

        return response()->json([
            'message' => 'Subscription payment recorded.',
            'payment' => $this->mapSubscriptionPayment($payment->fresh()),
            'shop' => $this->mapShop($shop->fresh()->load([
                'users' => fn ($query) => $query->whereIn('role', User::adminRoles())->orderByDesc('is_active')->oldest(),
                'subscriptionPayments' => fn ($query) => $query->latest('received_at')->latest()->limit(3),
            ])->loadCount(['users', 'technicians', 'jobOrders'])),
        ], 201);
    }

    private function mapShop(Shop $shop): array
    {
        $admin = $shop->relationLoaded('users')
            ? $shop->users->first(fn (User $user) => in_array($user->role, User::adminRoles(), true))
            : $shop->users()
                ->whereIn('role', User::adminRoles())
                ->orderByDesc('is_active')
                ->oldest()
                ->first();

        return [
            'id' => $shop->id,
            'name' => $shop->name,
            'slug' => $shop->slug,
            'email' => $shop->email,
            'phone' => $shop->phone,
            'address' => $shop->address,
            'subscription_plan' => $shop->subscription_plan,
            'trial_ends_at' => $shop->trial_ends_at,
            'trial_ends_at_formatted' => $shop->trial_ends_at?->format('M d, Y'),
            'trial_status' => $this->trialStatus($shop),
            'access_status' => $this->accessStatus($shop),
            'access_status_label' => $this->accessStatusLabel($shop),
            'access_status_help' => $this->accessStatusHelp($shop),
            'can_accept_new_work' => $shop->hasActiveAccess(),
            'is_close_out_mode' => $shop->isInCloseOutMode(),
            'is_active' => (bool) $shop->is_active,
            'admin' => $admin ? $this->mapShopAdmin($admin) : null,
            'subscription_payments' => $shop->relationLoaded('subscriptionPayments')
                ? $shop->subscriptionPayments->map(fn (PlatformSubscriptionPayment $payment) => $this->mapSubscriptionPayment($payment))->values()->all()
                : [],
            'users_count' => (int) ($shop->users_count ?? 0),
            'technicians_count' => (int) ($shop->technicians_count ?? 0),
            'job_orders_count' => (int) ($shop->job_orders_count ?? 0),
            'public_tracking_url' => url('/shops/' . $shop->slug . '/track'),
            'created_at_formatted' => $shop->created_at?->format('M d, Y'),
        ];
    }

    private function mapShopAdmin(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'last_login_at_formatted' => $user->last_login_at?->format('M d, Y h:i A'),
        ];
    }

    private function trialStatus(Shop $shop): string
    {
        if (!$shop->trial_ends_at) {
            return 'none';
        }

        if ($shop->trial_ends_at->isPast()) {
            return 'expired';
        }

        if ($shop->trial_ends_at->lte(now()->addDays(7))) {
            return 'expiring';
        }

        return 'active';
    }

    private function accessStatus(Shop $shop): string
    {
        if (!$shop->is_active) {
            return 'inactive';
        }

        if ($shop->isInCloseOutMode()) {
            return 'close_out';
        }

        if (!$shop->trial_ends_at) {
            return 'needs_setup';
        }

        if ($shop->trial_ends_at && $shop->trial_ends_at->lte(now()->addDays(7))) {
            return 'expiring';
        }

        return 'active';
    }

    private function accessStatusLabel(Shop $shop): string
    {
        return match ($this->accessStatus($shop)) {
            'inactive' => 'Inactive',
            'close_out' => 'Close-out',
            'expiring' => 'Expiring Soon',
            'needs_setup' => 'Needs Setup',
            default => 'Active Access',
        };
    }

    private function accessStatusHelp(Shop $shop): string
    {
        return match ($this->accessStatus($shop)) {
            'inactive' => 'Shop login and public tracking are blocked.',
            'close_out' => 'Subscription expired. Existing jobs can be finished, but new intake is locked.',
            'expiring' => 'Access is active but renewal is due soon.',
            'needs_setup' => 'No access date set. New intake is locked until billing coverage is recorded.',
            default => 'Shop can accept new work.',
        };
    }

    private function mapSubscriptionPayment(PlatformSubscriptionPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'shop_name' => $payment->relationLoaded('shop') ? $payment->shop?->name : null,
            'shop_slug' => $payment->relationLoaded('shop') ? $payment->shop?->slug : null,
            'amount' => (float) $payment->amount,
            'payment_method' => $payment->payment_method ?: 'UNSPECIFIED',
            'reference_number' => $payment->reference_number,
            'covered_from' => $payment->covered_from,
            'covered_from_formatted' => $payment->covered_from?->format('M d, Y'),
            'covered_until' => $payment->covered_until,
            'covered_until_formatted' => $payment->covered_until?->format('M d, Y'),
            'notes' => $payment->notes,
            'received_at' => $payment->received_at,
            'received_at_formatted' => $payment->received_at?->format('M d, Y h:i A') ?? $payment->created_at?->format('M d, Y h:i A'),
            'received_by' => $payment->received_by,
        ];
    }

    private function mapPlatformReminder(CustomerNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'shop_id' => $notification->shop_id,
            'shop_name' => $notification->shop?->name,
            'shop_slug' => $notification->shop?->slug,
            'recipient' => $notification->recipient,
            'subject' => $notification->subject,
            'message_preview' => mb_strimwidth($notification->message, 0, 140, '...'),
            'status' => $notification->status,
            'status_label' => strtoupper($notification->status),
            'trigger' => $notification->trigger,
            'trigger_label' => $this->platformReminderTriggerLabel($notification->trigger),
            'queued_at_formatted' => $notification->queued_at?->format('M d, Y h:i A'),
            'sent_at_formatted' => $notification->sent_at?->format('M d, Y h:i A'),
            'failed_at_formatted' => $notification->failed_at?->format('M d, Y h:i A'),
            'error_message' => $notification->error_message,
        ];
    }

    private function mapSubscriptionRequest(SubscriptionRequest $request): array
    {
        return [
            'id' => $request->id,
            'shop_id' => $request->shop_id,
            'shop_name' => $request->shop?->name,
            'shop_slug' => $request->shop?->slug,
            'requested_by' => $request->requestedBy ? [
                'name' => $request->requestedBy->name,
                'email' => $request->requestedBy->email,
            ] : null,
            'reviewed_by' => $request->reviewedBy ? [
                'name' => $request->reviewedBy->name,
                'email' => $request->reviewedBy->email,
            ] : null,
            'plan_key' => $request->plan_key,
            'plan_name' => $request->plan_name,
            'amount' => (float) $request->amount,
            'duration_months' => $request->duration_months,
            'payment_method_name' => $request->payment_method_name,
            'reference_number' => $request->reference_number,
            'proof_url' => $request->proofUrl(),
            'status' => $request->status,
            'status_label' => strtoupper($request->status),
            'admin_notes' => $request->admin_notes,
            'created_at_formatted' => $request->created_at?->format('M d, Y h:i A'),
            'reviewed_at_formatted' => $request->reviewed_at?->format('M d, Y h:i A'),
        ];
    }

    private function platformReminderTriggerLabel(?string $trigger): string
    {
        return match ($trigger) {
            'platform_subscription:expires_in_7_days' => 'Expires in 7 days',
            'platform_subscription:expires_in_3_days' => 'Expires in 3 days',
            'platform_subscription:expires_today' => 'Expires today',
            default => 'Subscription reminder',
        };
    }
}
