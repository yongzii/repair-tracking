<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_order_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('job_order_payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->default('PENDING');
            $table->string('reason', 255);
            $table->string('refund_method', 40)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->text('reconciliation_reason')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('initiated_at');
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'job_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_refunds');
    }
};
