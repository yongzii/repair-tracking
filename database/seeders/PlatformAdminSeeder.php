<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('repair.platform_admin.email');
        $password = (string) config('repair.platform_admin.password');

        if (app()->environment('production') && ($email === 'platform@example.com' || $password === 'password')) {
            throw new RuntimeException('Set PLATFORM_ADMIN_EMAIL and PLATFORM_ADMIN_PASSWORD before seeding production.');
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'shop_id' => null,
                'name' => config('repair.platform_admin.name'),
                'password' => bcrypt($password),
                'role' => User::ROLE_PLATFORM_ADMIN,
                'is_active' => true,
            ]
        );
    }
}
