<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['shop_id', 'name']);
        });

        Schema::create('service_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['service_category_id', 'name']);
        });

        Schema::table('job_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('job_orders', 'service_category_id')) {
                $table->foreignId('service_category_id')->nullable()->after('device_type')->constrained('service_categories')->nullOnDelete();
            }
            if (!Schema::hasColumn('job_orders', 'service_item_id')) {
                $table->foreignId('service_item_id')->nullable()->after('service_category_id')->constrained('service_items')->nullOnDelete();
            }
        });

        Schema::table('appointments', function (Blueprint $table) {
            if (!Schema::hasColumn('appointments', 'service_category_id')) {
                $table->foreignId('service_category_id')->nullable()->after('device_type')->constrained('service_categories')->nullOnDelete();
            }
            if (!Schema::hasColumn('appointments', 'service_item_id')) {
                $table->foreignId('service_item_id')->nullable()->after('service_category_id')->constrained('service_items')->nullOnDelete();
            }
        });

        $this->seedExistingShops();
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (Schema::hasColumn('appointments', 'service_item_id')) {
                $table->dropConstrainedForeignId('service_item_id');
            }
            if (Schema::hasColumn('appointments', 'service_category_id')) {
                $table->dropConstrainedForeignId('service_category_id');
            }
        });

        Schema::table('job_orders', function (Blueprint $table) {
            if (Schema::hasColumn('job_orders', 'service_item_id')) {
                $table->dropConstrainedForeignId('service_item_id');
            }
            if (Schema::hasColumn('job_orders', 'service_category_id')) {
                $table->dropConstrainedForeignId('service_category_id');
            }
        });

        Schema::dropIfExists('service_items');
        Schema::dropIfExists('service_categories');
    }

    private function seedExistingShops(): void
    {
        $shops = DB::table('shops')->select('id')->get();
        $now = now();

        foreach ($shops as $shop) {
            foreach ($this->defaultCatalog() as $categoryIndex => $category) {
                $categoryId = DB::table('service_categories')->insertGetId([
                    'shop_id' => $shop->id,
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'sort_order' => $categoryIndex + 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($category['services'] as $serviceIndex => $serviceName) {
                    DB::table('service_items')->insert([
                        'shop_id' => $shop->id,
                        'service_category_id' => $categoryId,
                        'name' => $serviceName,
                        'sort_order' => $serviceIndex + 1,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
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
};
