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
        $shop = Shop::query()->firstOrCreate(
            ['slug' => 'default-shop'],
            [
                'name' => config('app.name', 'Repair Tracking Default Shop'),
                'subscription_plan' => 'starter',
                'is_active' => true,
            ]
        );

        // Create admin user
        User::create([
            'shop_id' => $shop->id,
            'name' => 'System Administrator',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => 'shop_admin',
            'is_active' => true,
        ]);

        $this->call([
            JobOrderSeeder::class,
        ]);
    }
}
