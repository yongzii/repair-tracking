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
        Schema::create('technicians', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->json('specialties')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Add technician_id to job_orders
        Schema::table('job_orders', function (Blueprint $table) {
            $table->foreignId('technician_id')->nullable()->constrained('technicians')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Technician::class);
        });
        Schema::dropIfExists('technicians');
    }
};
