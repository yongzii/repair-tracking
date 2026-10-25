<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_order_part_orders', function (Blueprint $table) {
            $table->string('idempotency_key', 36)->nullable()->after('job_order_id');
            $table->string('idempotency_payload_hash', 64)->nullable()->after('idempotency_key');
            $table->unique(
                ['shop_id', 'idempotency_key'],
                'jopo_shop_idempotency_unique'
            );
        });

        Schema::table('part_requests', function (Blueprint $table) {
            $table->string('idempotency_key', 36)->nullable()->after('job_order_id');
            $table->string('idempotency_payload_hash', 64)->nullable()->after('idempotency_key');
            $table->unique(
                ['shop_id', 'idempotency_key'],
                'preq_shop_idempotency_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('job_order_part_orders', function (Blueprint $table) {
            $table->dropUnique('jopo_shop_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'idempotency_payload_hash']);
        });

        Schema::table('part_requests', function (Blueprint $table) {
            $table->dropUnique('preq_shop_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'idempotency_payload_hash']);
        });
    }
};
