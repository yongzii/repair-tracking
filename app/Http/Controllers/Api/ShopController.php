<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ShopController extends Controller
{
    public function show(Request $request)
    {
        $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);

        $shop = $request->user()?->shop;

        if (!$shop) {
            abort(404, 'Shop profile not found.');
        }

        return response()->json([
            'shop' => $this->mapShop($shop),
        ]);
    }

    public function publicProfile(Shop $shop)
    {
        if (!$shop->is_active) {
            abort(404, 'Shop not found.');
        }

        return response()->json([
            'shop' => [
                'name' => $shop->name,
                'slug' => $shop->slug,
                'phone' => $shop->phone,
                'address' => $shop->address,
                'business_hours' => $this->normalizeBusinessHours($shop->business_hours ?? []),
                'logo_url' => $shop->logoUrl(),
                'service_catalog' => $this->serviceCatalog($shop, true),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);

        $shop = $request->user()?->shop;

        if (!$shop) {
            abort(404, 'Shop profile not found.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => [
                'nullable',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('shops', 'slug')->ignore($shop->id),
            ],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('shops', 'email')->ignore($shop->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'business_hours' => ['nullable', 'array'],
            'business_hours.*.open' => ['nullable', 'boolean'],
            'business_hours.*.open_time' => ['nullable', 'date_format:H:i'],
            'business_hours.*.close_time' => ['nullable', 'date_format:H:i'],
            'default_warranty_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'claim_terms' => ['nullable', 'string', 'max:10000'],
            'warranty_terms' => ['nullable', 'string', 'max:10000'],
            'notification_settings' => ['nullable', 'array'],
            'notification_settings.email_enabled' => ['nullable', 'boolean'],
            'notification_settings.sms_enabled' => ['nullable', 'boolean'],
            'notification_settings.events' => ['nullable', 'array'],
            'notification_settings.events.RECEIVED' => ['nullable', 'boolean'],
            'notification_settings.events.DIAGNOSING' => ['nullable', 'boolean'],
            'notification_settings.events.WAITING_PARTS' => ['nullable', 'boolean'],
            'notification_settings.events.IN_PROGRESS' => ['nullable', 'boolean'],
            'notification_settings.events.READY_FOR_PICKUP' => ['nullable', 'boolean'],
            'notification_settings.events.PICKED_UP' => ['nullable', 'boolean'],
            'notification_settings.events.RTO' => ['nullable', 'boolean'],
            'notification_settings.events.RTO_RELEASED' => ['nullable', 'boolean'],
            'notification_settings.events.UNREPAIRABLE' => ['nullable', 'boolean'],
        ]);

        $slug = $validated['slug'] ?? Str::slug($validated['name']);
        $slug = $slug !== '' ? $slug : Str::slug($shop->name);

        $shop->update([
            'name' => $validated['name'],
            'slug' => $slug,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'business_hours' => $this->normalizeBusinessHours($validated['business_hours'] ?? []),
            'default_warranty_days' => $validated['default_warranty_days'],
            'claim_terms' => $validated['claim_terms'] ?? null,
            'warranty_terms' => $validated['warranty_terms'] ?? null,
            'notification_settings' => $this->normalizeNotificationSettings($validated['notification_settings'] ?? []),
        ]);

        $request->user()?->load('shop');

        return response()->json([
            'message' => 'Shop settings updated successfully.',
            'shop' => $this->mapShop($shop->fresh()),
        ]);
    }

    public function uploadLogo(Request $request)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);

        $shop = $request->user()?->shop;

        if (!$shop) {
            abort(404, 'Shop profile not found.');
        }

        $validated = $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        if ($shop->logo_path) {
            Storage::disk('public')->delete($shop->logo_path);
        }

        $path = $validated['logo']->store("shop-logos/{$shop->id}", 'public');

        $shop->update([
            'logo_path' => $path,
        ]);

        $request->user()?->load('shop');

        return response()->json([
            'message' => 'Shop logo uploaded successfully.',
            'shop' => $this->mapShop($shop->fresh()),
        ]);
    }

    public function deleteLogo(Request $request)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);

        $shop = $request->user()?->shop;

        if (!$shop) {
            abort(404, 'Shop profile not found.');
        }

        if ($shop->logo_path) {
            Storage::disk('public')->delete($shop->logo_path);
        }

        $shop->update([
            'logo_path' => null,
        ]);

        $request->user()?->load('shop');

        return response()->json([
            'message' => 'Shop logo removed.',
            'shop' => $this->mapShop($shop->fresh()),
        ]);
    }

    public function storeServiceCategory(Request $request)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $shop = $request->user()?->shop;

        if (!$shop) {
            abort(404, 'Shop profile not found.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('service_categories', 'name')->where(fn ($query) => $query->where('shop_id', $shop->id))],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $category = ServiceCategory::create([
            'shop_id' => $shop->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Service category added.',
            'category' => $this->mapServiceCategory($category->fresh('services'), false),
            'service_catalog' => $this->serviceCatalog($shop->fresh(), false),
        ], 201);
    }

    public function updateServiceCategory(Request $request, ServiceCategory $category)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $shop = $request->user()?->shop;

        if (!$shop || (int) $category->shop_id !== (int) $shop->id) {
            abort(404, 'Service category not found.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('service_categories', 'name')->where(fn ($query) => $query->where('shop_id', $shop->id))->ignore($category->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? $category->sort_order,
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : $category->is_active,
        ]);

        return response()->json([
            'message' => 'Service category updated.',
            'category' => $this->mapServiceCategory($category->fresh('services'), false),
            'service_catalog' => $this->serviceCatalog($shop->fresh(), false),
        ]);
    }

    public function deactivateServiceCategory(Request $request, ServiceCategory $category)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $shop = $request->user()?->shop;

        if (!$shop || (int) $category->shop_id !== (int) $shop->id) {
            abort(404, 'Service category not found.');
        }

        $category->update(['is_active' => false]);
        $category->services()->update(['is_active' => false]);

        return response()->json([
            'message' => 'Service category deactivated.',
            'service_catalog' => $this->serviceCatalog($shop->fresh(), false),
        ]);
    }

    public function storeServiceItem(Request $request, ServiceCategory $category)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $shop = $request->user()?->shop;

        if (!$shop || (int) $category->shop_id !== (int) $shop->id) {
            abort(404, 'Service category not found.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('service_items', 'name')->where(fn ($query) => $query->where('service_category_id', $category->id))],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $service = ServiceItem::create([
            'shop_id' => $shop->id,
            'service_category_id' => $category->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Service added.',
            'service' => $this->mapServiceItem($service),
            'service_catalog' => $this->serviceCatalog($shop->fresh(), false),
        ], 201);
    }

    public function updateServiceItem(Request $request, ServiceItem $service)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $shop = $request->user()?->shop;

        if (!$shop || (int) $service->shop_id !== (int) $shop->id) {
            abort(404, 'Service not found.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('service_items', 'name')->where(fn ($query) => $query->where('service_category_id', $service->service_category_id))->ignore($service->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $service->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? $service->sort_order,
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : $service->is_active,
        ]);

        return response()->json([
            'message' => 'Service updated.',
            'service' => $this->mapServiceItem($service->fresh()),
            'service_catalog' => $this->serviceCatalog($shop->fresh(), false),
        ]);
    }

    public function deactivateServiceItem(Request $request, ServiceItem $service)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $shop = $request->user()?->shop;

        if (!$shop || (int) $service->shop_id !== (int) $shop->id) {
            abort(404, 'Service not found.');
        }

        $service->update(['is_active' => false]);

        return response()->json([
            'message' => 'Service deactivated.',
            'service_catalog' => $this->serviceCatalog($shop->fresh(), false),
        ]);
    }

    private function mapShop(Shop $shop): array
    {
        return [
            'id' => $shop->id,
            'name' => $shop->name,
            'slug' => $shop->slug,
            'email' => $shop->email,
            'phone' => $shop->phone,
            'address' => $shop->address,
            'business_hours' => $this->normalizeBusinessHours($shop->business_hours ?? []),
            'logo_path' => $shop->logo_path,
            'logo_url' => $shop->logoUrl(),
            'default_warranty_days' => $shop->default_warranty_days,
            'claim_terms' => $shop->claim_terms,
            'warranty_terms' => $shop->warranty_terms,
            'notification_settings' => $this->normalizeNotificationSettings($shop->notification_settings ?? []),
            'service_catalog' => $this->serviceCatalog($shop, false),
            'notification_capabilities' => [
                'email_configured' => (bool) config('mail.mailers.smtp.host'),
                'sms_configured' => (bool) (config('services.twilio.sid') && config('services.twilio.token') && config('services.twilio.from')),
            ],
            'subscription_plan' => $shop->subscription_plan,
            'trial_ends_at' => $shop->trial_ends_at,
            'trial_ends_at_formatted' => $shop->trial_ends_at?->format('M d, Y'),
            'is_active' => (bool) $shop->is_active,
            'public_tracking_url' => url('/shops/' . $shop->slug . '/track'),
            'public_booking_url' => url('/shops/' . $shop->slug . '/book'),
            'public_consultation_url' => url('/shops/' . $shop->slug . '/consultation'),
        ];
    }

    private function serviceCatalog(Shop $shop, bool $activeOnly): array
    {
        return $shop->serviceCategories()
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->with(['services' => fn ($query) => $query
                ->when($activeOnly, fn ($builder) => $builder->where('is_active', true))
                ->orderBy('sort_order')
                ->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceCategory $category) => $this->mapServiceCategory($category, $activeOnly))
            ->all();
    }

    private function mapServiceCategory(ServiceCategory $category, bool $activeOnly): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'sort_order' => $category->sort_order,
            'is_active' => (bool) $category->is_active,
            'services' => $category->services
                ->when($activeOnly, fn ($services) => $services->where('is_active', true))
                ->map(fn (ServiceItem $service) => $this->mapServiceItem($service))
                ->values()
                ->all(),
        ];
    }

    private function mapServiceItem(ServiceItem $service): array
    {
        return [
            'id' => $service->id,
            'service_category_id' => $service->service_category_id,
            'name' => $service->name,
            'description' => $service->description,
            'sort_order' => $service->sort_order,
            'is_active' => (bool) $service->is_active,
        ];
    }

    private function normalizeBusinessHours(?array $hours): array
    {
        $days = [
            'monday' => 'Monday',
            'tuesday' => 'Tuesday',
            'wednesday' => 'Wednesday',
            'thursday' => 'Thursday',
            'friday' => 'Friday',
            'saturday' => 'Saturday',
            'sunday' => 'Sunday',
        ];

        $hours = $hours ?? [];

        return collect($days)
            ->mapWithKeys(function (string $label, string $key) use ($hours) {
                $day = is_array($hours[$key] ?? null) ? $hours[$key] : [];
                $open = array_key_exists('open', $day)
                    ? (bool) $day['open']
                    : $key !== 'sunday';

                return [
                    $key => [
                        'label' => $label,
                        'open' => $open,
                        'open_time' => $day['open_time'] ?? '09:00',
                        'close_time' => $day['close_time'] ?? '18:00',
                    ],
                ];
            })
            ->all();
    }

    private function normalizeNotificationSettings(?array $settings): array
    {
        $events = [
            'RECEIVED',
            'DIAGNOSING',
            'WAITING_PARTS',
            'IN_PROGRESS',
            'READY_FOR_PICKUP',
            'PICKED_UP',
            'RTO',
            'RTO_RELEASED',
            'UNREPAIRABLE',
        ];

        $settings = $settings ?? [];
        $inputEvents = is_array($settings['events'] ?? null) ? $settings['events'] : [];

        return [
            'email_enabled' => array_key_exists('email_enabled', $settings) ? (bool) $settings['email_enabled'] : true,
            'sms_enabled' => array_key_exists('sms_enabled', $settings) ? (bool) $settings['sms_enabled'] : false,
            'events' => collect($events)
                ->mapWithKeys(fn (string $event) => [
                    $event => array_key_exists($event, $inputEvents) ? (bool) $inputEvents[$event] : true,
                ])
                ->all(),
        ];
    }
}
