<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->text('included_accessories')->nullable()->after('problem_description');
            $table->timestamp('repair_approved_at')->nullable()->after('included_accessories');
            $table->string('repair_approved_by_customer', 100)->nullable()->after('repair_approved_at');
            $table->string('repair_approval_recorded_by', 100)->nullable()->after('repair_approved_by_customer');
            $table->text('repair_approval_notes')->nullable()->after('repair_approval_recorded_by');
            $table->string('pickup_override_by', 100)->nullable()->after('picked_up_by');
            $table->text('pickup_override_reason')->nullable()->after('pickup_override_by');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->text('claim_terms')->nullable()->after('default_warranty_days');
            $table->text('warranty_terms')->nullable()->after('claim_terms');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn([
                'included_accessories',
                'repair_approved_at',
                'repair_approved_by_customer',
                'repair_approval_recorded_by',
                'repair_approval_notes',
                'pickup_override_by',
                'pickup_override_reason',
            ]);
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['claim_terms', 'warranty_terms']);
        });
    }
};
