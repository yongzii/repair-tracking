<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->after('id')->constrained('shops')->nullOnDelete();
        });

        Schema::table('technicians', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->after('id')->constrained('shops')->nullOnDelete();
        });

        Schema::table('job_orders', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->after('id')->constrained('shops')->nullOnDelete();
        });

        Schema::table('spare_parts', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->after('id')->constrained('shops')->nullOnDelete();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->after('id')->constrained('shops')->nullOnDelete();
        });

        $now = Carbon::now();
        $defaultShopId = DB::table('shops')->insertGetId([
            'name' => config('app.name', 'Repair Tracking Default Shop'),
            'slug' => 'default-shop',
            'subscription_plan' => 'starter',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('users')->whereNull('shop_id')->update(['shop_id' => $defaultShopId]);
        DB::table('technicians')->whereNull('shop_id')->update(['shop_id' => $defaultShopId]);
        DB::table('job_orders')->whereNull('shop_id')->update(['shop_id' => $defaultShopId]);
        DB::table('spare_parts')->whereNull('shop_id')->update(['shop_id' => $defaultShopId]);
        DB::table('audit_logs')->whereNull('shop_id')->update(['shop_id' => $defaultShopId]);

        Schema::table('spare_parts', function (Blueprint $table) {
            $table->dropUnique('spare_parts_part_number_unique');
            $table->unique(['shop_id', 'part_number'], 'spare_parts_shop_id_part_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('spare_parts', function (Blueprint $table) {
            $table->dropUnique('spare_parts_shop_id_part_number_unique');
            $table->unique('part_number');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_id');
        });

        Schema::table('spare_parts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_id');
        });

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_id');
        });

        Schema::table('technicians', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_id');
        });
    }
};
