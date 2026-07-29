<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ShopOnboardingController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'shop_name' => ['required', 'string', 'max:150'],
            'shop_slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'shop_email' => ['nullable', 'email', 'max:150', 'unique:shops,email'],
            'shop_phone' => ['nullable', 'string', 'max:30'],
            'shop_address' => ['nullable', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:150'],
            'owner_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'owner_password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
        ]);

        $slug = $this->generateUniqueSlug($validated['shop_slug'] ?: Str::slug($validated['shop_name']));

        [$shop, $owner] = DB::transaction(function () use ($validated, $slug) {
            $shop = Shop::create([
                'name' => $validated['shop_name'],
                'slug' => $slug,
                'email' => $validated['shop_email'] ?? null,
                'phone' => $validated['shop_phone'] ?? null,
                'address' => $validated['shop_address'] ?? null,
                'subscription_plan' => 'starter',
                'trial_ends_at' => null,
                'is_active' => true,
            ]);

            $owner = User::create([
                'shop_id' => $shop->id,
                'name' => $validated['owner_name'],
                'email' => $validated['owner_email'],
                'password' => $validated['owner_password'],
                'role' => User::ROLE_SHOP_ADMIN,
                'is_active' => true,
            ]);

            $this->createDefaultServiceCatalog($shop);

            return [$shop, $owner];
        });

        Auth::login($owner);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        $owner->forceFill([
            'last_login_at' => now(),
        ])->save();

        $owner->loadMissing('shop');

        return response()->json([
            'message' => 'Shop workspace created successfully. You can sign in to explore preview mode, but subscription payment is required before saving records or transactions.',
            'shop' => [
                'name' => $shop->name,
                'slug' => $shop->slug,
                'tracking_url' => url('/shops/' . $shop->slug . '/track'),
            ],
            'owner' => [
                'email' => $validated['owner_email'],
            ],
            'user' => $this->formatOwner($owner),
            'csrf_token' => csrf_token(),
        ], 201);
    }

    public function checkSlug(Request $request)
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:120'],
        ]);

        $normalized = Str::slug($validated['slug']);

        return response()->json([
            'slug' => $normalized,
            'available' => $normalized !== '' && !Shop::where('slug', $normalized)->exists(),
        ]);
    }

    private function generateUniqueSlug(string $baseSlug): string
    {
        $baseSlug = Str::slug($baseSlug);

        if ($baseSlug === '') {
            $baseSlug = 'repair-shop';
        }

        $slug = $baseSlug;
        $counter = 2;

        while (Shop::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function createDefaultServiceCatalog(Shop $shop): void
    {
        foreach ($this->defaultCatalog() as $categoryIndex => $category) {
            $serviceCategory = ServiceCategory::create([
                'shop_id' => $shop->id,
                'name' => $category['name'],
                'description' => $category['description'],
                'sort_order' => $categoryIndex + 1,
                'is_active' => true,
            ]);

            foreach ($category['services'] as $serviceIndex => $serviceName) {
                ServiceItem::create([
                    'shop_id' => $shop->id,
                    'service_category_id' => $serviceCategory->id,
                    'name' => $serviceName,
                    'sort_order' => $serviceIndex + 1,
                    'is_active' => true,
                ]);
            }
        }
    }

    private function formatOwner(User $user): array
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

    private function defaultCatalog(): array
    {
        return [
            [
                'name' => 'Laptop',
                'description' => 'Laptop diagnostics, parts replacement, software, and upgrades.',
                'services' => ['Motherboard Repair', 'LCD Replacement', 'Keyboard Replacement', 'Reformat', 'RAM/SSD Upgrade', 'No Power', 'No Display', 'Cleaning / Repaste'],
            ],
            [
                'name' => 'Cellphone',
                'description' => 'Mobile phone hardware and software repair services.',
                'services' => ['Motherboard Repair', 'LCD Replacement', 'Battery Replacement', 'Bypass', 'Software Update', 'No Display Issue', 'Water Damage'],
            ],
            [
                'name' => 'Printer',
                'description' => 'Printer repair, maintenance, and setup.',
                'services' => ['Printer Diagnosis', 'Ink System Repair', 'Paper Jam Repair', 'Print Head Cleaning', 'Roller Replacement', 'Driver Setup'],
            ],
            [
                'name' => 'CCTV',
                'description' => 'CCTV installation, replacement, setup, and support.',
                'services' => ['CCTV Installation', 'Camera Replacement', 'DVR/NVR Setup', 'Cable Layout', 'Remote Viewing Setup', 'Troubleshooting'],
            ],
        ];
    }
}
