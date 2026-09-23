<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_order_payments', function (Blueprint $table) {
            $table->string('idempotency_key', 36)->nullable()->unique()->after('job_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('job_order_payments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};