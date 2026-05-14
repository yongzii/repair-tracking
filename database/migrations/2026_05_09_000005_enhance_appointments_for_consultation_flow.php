<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->text('customer_response')->nullable()->after('staff_note');
            $table->string('recommended_action', 60)->nullable()->after('customer_response');
            $table->foreignId('converted_job_order_id')->nullable()->after('recommended_action')->constrained('job_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_job_order_id');
            $table->dropColumn(['customer_response', 'recommended_action']);
        });
    }
};
