<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('confirmation_code', 24)->unique();
            $table->string('customer_name', 120);
            $table->string('customer_email', 150)->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->enum('booking_type', ['CONSULTATION', 'DROP_OFF', 'PICKUP'])->default('DROP_OFF');
            $table->string('device_type', 80);
            $table->string('brand', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->text('concern');
            $table->dateTime('preferred_at');
            $table->dateTime('scheduled_at')->nullable();
            $table->enum('status', ['PENDING', 'CONFIRMED', 'RESCHEDULED', 'CANCELLED', 'COMPLETED'])->default('PENDING');
            $table->text('staff_note')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('responded_by')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'preferred_at']);
            $table->index(['shop_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
