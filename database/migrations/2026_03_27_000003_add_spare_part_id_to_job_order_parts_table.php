<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_order_parts', function (Blueprint $table) {
            $table->foreignId('spare_part_id')
                ->nullable()
                ->after('job_order_id')
                ->constrained('spare_parts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('job_order_parts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('spare_part_id');
        });
    }
};
