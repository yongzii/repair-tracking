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
            // Priority and Service Level
            $table->enum('priority', ['STANDARD', 'EXPRESS', 'EMERGENCY'])->default('STANDARD')->after('current_status');
            
            // Cost Tracking
            $table->decimal('estimated_cost', 10, 2)->nullable()->after('priority');
            $table->decimal('parts_cost', 10, 2)->default(0)->after('estimated_cost');
            $table->decimal('labor_cost', 10, 2)->default(0)->after('parts_cost');
            $table->decimal('total_cost', 10, 2)->default(0)->after('labor_cost');
            
            // Payment Tracking
            $table->enum('payment_status', ['PENDING', 'PARTIAL', 'PAID'])->default('PENDING')->after('total_cost');
            $table->decimal('amount_paid', 10, 2)->default(0)->after('payment_status');
            $table->date('payment_date')->nullable()->after('amount_paid');
            $table->text('payment_notes')->nullable()->after('payment_date');
            
            // Warranty & Guarantee
            $table->integer('warranty_days')->default(30)->after('payment_notes');
            $table->text('warranty_details')->nullable()->after('warranty_days');
            
            // Service Details
            $table->text('service_performed')->nullable()->after('warranty_details');
            $table->text('internal_notes')->nullable()->after('service_performed');
            
            // Customer Experience
            $table->integer('repeat_customer_count')->default(0)->after('internal_notes');
            $table->dateTime('estimated_completion')->nullable()->after('repeat_customer_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn([
                'priority',
                'estimated_cost',
                'parts_cost',
                'labor_cost',
                'total_cost',
                'payment_status',
                'amount_paid',
                'payment_date',
                'payment_notes',
                'warranty_days',
                'warranty_details',
                'service_performed',
                'internal_notes',
                'repeat_customer_count',
                'estimated_completion',
            ]);
        });
    }
};
