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
        Schema::create('job_orders', function (Blueprint $table) {
            $table->id();
            
            // Job Order Info
            $table->string('job_order_number')->unique();
            
            // Customer Info
            $table->string('customer_name');
            $table->string('customer_contact')->nullable();
            
            // Device Info
            $table->string('device_type');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            
            // Problem Description
            $table->text('problem_description');
            
            // Status
            $table->enum('current_status', [
                'RECEIVED',
                'DIAGNOSING',
                'WAITING_PARTS',
                'IN_PROGRESS',
                'READY_FOR_PICKUP',
                'PICKED_UP',
                'UNREPAIRABLE'
            ])->default('RECEIVED');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_orders');
    }
};
