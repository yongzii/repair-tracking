<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('job_orders', 'technician_id')) {
                $table->foreignId('technician_id')->nullable()->constrained('technicians')->onDelete('set null')->after('customer_contact');
            }
            if (!Schema::hasColumn('job_orders', 'technician_rating')) {
                $table->integer('technician_rating')->nullable()->after('repeat_customer_count');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            if (Schema::hasColumn('job_orders', 'technician_id')) {
                $table->dropForeign(['technician_id']);
                $table->dropColumn('technician_id');
            }
            if (Schema::hasColumn('job_orders', 'technician_rating')) {
                $table->dropColumn('technician_rating');
            }
        });
    }
};
