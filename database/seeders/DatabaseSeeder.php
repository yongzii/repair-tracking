<?php

namespace Database\Seeders;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(PlatformAdminSeeder::class);

        $seedDemoData = filter_var(config('repair.seed_demo_data'), FILTER_VALIDATE_BOOL);

        if (!$seedDemoData) {
            return;
        }

        $shop = Shop::query()->firstOrCreate(
            ['slug' => 'default-shop'],
            [
                'name' => config('app.name', 'Repair Tracking Default Shop'),
                'subscription_plan' => 'starter',
                'is_active' => true,
            ]
        );

        // Create admin user
        User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'shop_id' => $shop->id,
                'name' => 'System Administrator',
                'password' => bcrypt('password'),
                'role' => User::ROLE_SHOP_ADMIN,
                'is_active' => true,
            ]
        );

        $this->call([
            JobOrderSeeder::class,
        ]);
    }
}
