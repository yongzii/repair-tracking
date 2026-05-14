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
        if (Schema::hasTable('repair_photos')) {
            Schema::drop('repair_photos');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('repair_photos')) {
            Schema::create('repair_photos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('job_order_id')->constrained()->onDelete('cascade');
                $table->enum('photo_type', ['BEFORE', 'AFTER', 'DURING', 'EVIDENCE']);
                $table->string('photo_path');
                $table->text('description')->nullable();
                $table->string('uploaded_by')->nullable();
                $table->timestamps();
            });
        }
    }
};
