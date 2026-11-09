<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('free_trial_eligible')->default(false);
            $table->timestamp('free_trial_started_at')->nullable();
            $table->timestamp('free_trial_ends_at')->nullable();
            $table->timestamp('free_trial_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn([
                'free_trial_eligible',
                'free_trial_started_at',
                'free_trial_ends_at',
                'free_trial_used_at',
            ]);
        });
    }
};
