<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('part_requests', 'category')) {
            Schema::table('part_requests', function (Blueprint $table): void {
                $table->string('category', 100)->nullable()->after('part_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('part_requests', 'category')) {
            Schema::table('part_requests', function (Blueprint $table): void {
                $table->dropColumn('category');
            });
        }
    }
};
