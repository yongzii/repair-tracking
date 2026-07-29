<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_histories', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->after('id')->constrained('shops')->nullOnDelete();
        });

        Schema::table('job_order_parts', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->after('id')->constrained('shops')->nullOnDelete();
        });

        Schema::table('job_order_payments', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->after('id')->constrained('shops')->nullOnDelete();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->after('id')->constrained('shops')->nullOnDelete();
        });

        // SQLite does not support Laravel's joined update form. Fresh test
        // databases have no legacy child rows to backfill.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::table('status_histories')
            ->join('job_orders', 'status_histories.job_order_id', '=', 'job_orders.id')
            ->whereNull('status_histories.shop_id')
            ->update(['status_histories.shop_id' => DB::raw('job_orders.shop_id')]);

        DB::table('job_order_parts')
            ->join('job_orders', 'job_order_parts.job_order_id', '=', 'job_orders.id')
            ->whereNull('job_order_parts.shop_id')
            ->update(['job_order_parts.shop_id' => DB::raw('job_orders.shop_id')]);

        DB::table('job_order_payments')
            ->join('job_orders', 'job_order_payments.job_order_id', '=', 'job_orders.id')
            ->whereNull('job_order_payments.shop_id')
            ->update(['job_order_payments.shop_id' => DB::raw('job_orders.shop_id')]);

        DB::table('stock_movements')
            ->leftJoin('job_orders', 'stock_movements.job_order_id', '=', 'job_orders.id')
            ->leftJoin('spare_parts', 'stock_movements.spare_part_id', '=', 'spare_parts.id')
            ->whereNull('stock_movements.shop_id')
            ->update(['stock_movements.shop_id' => DB::raw('COALESCE(job_orders.shop_id, spare_parts.shop_id)')]);
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_id');
        });

        Schema::table('job_order_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_id');
        });

        Schema::table('job_order_parts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_id');
        });

        Schema::table('status_histories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_id');
        });
    }
};
