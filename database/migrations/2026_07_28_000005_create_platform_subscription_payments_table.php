<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('payment_method', 40)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->date('covered_from')->nullable();
            $table->date('covered_until')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->string('received_by')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'received_at']);
            $table->index(['covered_from', 'covered_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_subscription_payments');
    }
};
