<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
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
            'primary_color' => ['nullable', 'string', 'max:20', 'regex:/^#?[A-Fa-f0-9]{3,8}$/'],
            'default_warranty_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);

        $slug = $validated['slug'] ?? Str::slug($validated['name']);
        $slug = $slug !== '' ? $slug : Str::slug($shop->name);

        $shop->update([
            'name' => $validated['name'],
            'slug' => $slug,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'primary_color' => $validated['primary_color'] ?? null,
            'default_warranty_days' => $validated['default_warranty_days'],
        ]);

        $request->user()?->load('shop');

        return response()->json([
            'message' => 'Shop settings updated successfully.',
            'shop' => $this->mapShop($shop->fresh()),
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
            'primary_color' => $shop->primary_color,
            'default_warranty_days' => $shop->default_warranty_days,
            'subscription_plan' => $shop->subscription_plan,
            'trial_ends_at' => $shop->trial_ends_at,
            'trial_ends_at_formatted' => $shop->trial_ends_at?->format('M d, Y'),
            'is_active' => (bool) $shop->is_active,
            'public_tracking_url' => url('/shops/' . $shop->slug . '/track'),
            'public_booking_url' => url('/shops/' . $shop->slug . '/book'),
            'public_consultation_url' => url('/shops/' . $shop->slug . '/consultation'),
        ];
    }
}
