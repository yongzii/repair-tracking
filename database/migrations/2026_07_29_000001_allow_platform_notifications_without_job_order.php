<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->dropForeign(['job_order_id']);
        });

        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('job_order_id')->nullable()->change();
            $table->foreign('job_order_id')->references('id')->on('job_orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->dropForeign(['job_order_id']);
        });

        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('job_order_id')->nullable(false)->change();
            $table->foreign('job_order_id')->references('id')->on('job_orders')->cascadeOnDelete();
        });
    }
};
