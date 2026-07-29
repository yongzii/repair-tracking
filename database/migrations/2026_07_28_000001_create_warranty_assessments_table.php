<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('job_orders', 'service_type')) {
                $table->string('service_type', 40)->default('STANDARD')->after('job_order_number');
            }

            if (!Schema::hasColumn('job_orders', 'warranty_parent_job_order_id')) {
                $table->foreignId('warranty_parent_job_order_id')
                    ->nullable()
                    ->after('service_type')
                    ->constrained('job_orders')
                    ->nullOnDelete();
            }
        });

        Schema::create('warranty_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('job_order_id')->constrained('job_orders')->cascadeOnDelete();
            $table->foreignId('warranty_job_order_id')->nullable()->constrained('job_orders')->nullOnDelete();
            $table->text('reported_issue');
            $table->timestamp('brought_at')->nullable();
            $table->string('checked_by', 100)->nullable();
            $table->enum('result', ['NEEDS_DIAGNOSIS', 'COVERED', 'NOT_COVERED'])->default('NEEDS_DIAGNOSIS');
            $table->text('assessment_notes')->nullable();
            $table->string('created_by', 100)->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'job_order_id']);
            $table->index(['result', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_assessments');

        Schema::table('job_orders', function (Blueprint $table) {
            if (Schema::hasColumn('job_orders', 'warranty_parent_job_order_id')) {
                $table->dropConstrainedForeignId('warranty_parent_job_order_id');
            }

            if (Schema::hasColumn('job_orders', 'service_type')) {
                $table->dropColumn('service_type');
            }
        });
    }
};
