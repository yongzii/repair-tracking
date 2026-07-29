<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('plan_key', 80);
            $table->string('plan_name', 120);
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('duration_months')->nullable();
            $table->string('payment_method_key', 80);
            $table->string('payment_method_name', 120);
            $table->string('reference_number', 120)->nullable();
            $table->string('proof_path')->nullable();
            $table->string('status', 30)->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_requests');
    }
};
