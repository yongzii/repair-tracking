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
        Schema::create('status_histories', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('job_order_id')
                ->constrained('job_orders')
                ->onDelete('cascade');
            
            $table->enum('status', [
                'RECEIVED',
                'DIAGNOSING',
                'WAITING_PARTS',
                'IN_PROGRESS',
                'READY_FOR_PICKUP',
                'PICKED_UP',
                'UNREPAIRABLE'
            ]);
            
            $table->text('note')->nullable();
            
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_histories');
    }
};
