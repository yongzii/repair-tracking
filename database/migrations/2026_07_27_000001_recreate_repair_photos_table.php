<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('repair_photos')) {
            return;
        }

        Schema::create('repair_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('job_order_id')->constrained('job_orders')->cascadeOnDelete();
            $table->enum('photo_type', ['BEFORE', 'DURING', 'AFTER', 'EVIDENCE'])->default('EVIDENCE');
            $table->string('photo_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->text('description')->nullable();
            $table->string('uploaded_by')->nullable();
            $table->string('uploaded_by_role', 50)->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'job_order_id']);
            $table->index(['job_order_id', 'photo_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_photos');
    }
};
