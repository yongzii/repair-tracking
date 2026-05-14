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
        Schema::create('job_order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_order_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->enum('payment_type', ['DOWN_PAYMENT', 'PARTIAL_PAYMENT', 'FINAL_PAYMENT'])->default('PARTIAL_PAYMENT');
            $table->string('payment_method', 40)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->string('received_by')->nullable();
            $table->timestamps();

            $table->index(['job_order_id', 'payment_type']);
            $table->index('received_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_order_payments');
    }
};
