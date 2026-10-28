<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dateTime('preferred_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('appointments')
            ->whereNull('preferred_at')
            ->update(['preferred_at' => DB::raw('created_at')]);

        Schema::table('appointments', function (Blueprint $table) {
            $table->dateTime('preferred_at')->nullable(false)->change();
        });
    }
};
