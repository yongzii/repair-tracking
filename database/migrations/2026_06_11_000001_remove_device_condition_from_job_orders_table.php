<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('job_orders', 'device_condition')) {
            Schema::table('job_orders', function (Blueprint $table) {
                $table->dropColumn('device_condition');
            });
        }
    }

    public function down(): void
    {
        // Intentionally no-op: device condition was removed from the job order flow.
    }
};
