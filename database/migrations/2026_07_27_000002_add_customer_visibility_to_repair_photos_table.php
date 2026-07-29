<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_photos', function (Blueprint $table) {
            if (!Schema::hasColumn('repair_photos', 'visible_to_customer')) {
                $table->boolean('visible_to_customer')->default(false)->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('repair_photos', function (Blueprint $table) {
            if (Schema::hasColumn('repair_photos', 'visible_to_customer')) {
                $table->dropColumn('visible_to_customer');
            }
        });
    }
};
