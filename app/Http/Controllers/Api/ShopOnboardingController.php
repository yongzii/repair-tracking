<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
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

        $shop = DB::transaction(function () use ($validated, $slug) {
            $shop = Shop::create([
                'name' => $validated['shop_name'],
                'slug' => $slug,
                'email' => $validated['shop_email'] ?? null,
                'phone' => $validated['shop_phone'] ?? null,
                'address' => $validated['shop_address'] ?? null,
                'subscription_plan' => 'starter',
                'trial_ends_at' => now()->addDays(14),
                'is_active' => true,
            ]);

            User::create([
                'shop_id' => $shop->id,
                'name' => $validated['owner_name'],
                'email' => $validated['owner_email'],
                'password' => $validated['owner_password'],
                'role' => User::ROLE_SHOP_ADMIN,
                'is_active' => true,
            ]);

            return $shop;
        });

        return response()->json([
            'message' => 'Shop workspace created successfully. You can now sign in to manage your repair operations.',
            'shop' => [
                'name' => $shop->name,
                'slug' => $shop->slug,
                'tracking_url' => url('/shops/' . $shop->slug . '/track'),
            ],
            'owner' => [
                'email' => $validated['owner_email'],
            ],
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
}
