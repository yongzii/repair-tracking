<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $statuses = [
        'RECEIVED',
        'DIAGNOSING',
        'WAITING_PARTS',
        'IN_PROGRESS',
        'READY_FOR_PICKUP',
        'PICKED_UP',
        'RTO',
        'RTO_RELEASED',
    ];

    public function up(): void
    {
        DB::table('job_orders')
            ->where('current_status', 'UNREPAIRABLE')
            ->update(['current_status' => 'RTO']);

        DB::table('status_histories')
            ->where('status', 'UNREPAIRABLE')
            ->update(['status' => 'RTO']);

        Schema::table('job_orders', function (Blueprint $table) {
            $table->enum('current_status', $this->statuses)->default('RECEIVED')->change();
        });

        Schema::table('status_histories', function (Blueprint $table) {
            $table->enum('status', $this->statuses)->change();
        });
    }

    public function down(): void
    {
        $statuses = [
            'RECEIVED',
            'DIAGNOSING',
            'WAITING_PARTS',
            'IN_PROGRESS',
            'READY_FOR_PICKUP',
            'PICKED_UP',
            'UNREPAIRABLE',
            'RTO',
            'RTO_RELEASED',
        ];

        Schema::table('job_orders', function (Blueprint $table) use ($statuses) {
            $table->enum('current_status', $statuses)->default('RECEIVED')->change();
        });

        Schema::table('status_histories', function (Blueprint $table) use ($statuses) {
            $table->enum('status', $statuses)->change();
        });
    }
};
