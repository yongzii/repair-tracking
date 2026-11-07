<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $deviceLocationForeignKey = collect(Schema::getForeignKeys('job_orders'))
            ->first(fn (array $foreignKey) => ($foreignKey['name'] ?? null) === 'job_device_location_user_fk'
                || (
                    ($foreignKey['columns'] ?? []) === ['device_location_updated_by_user_id']
                    && ($foreignKey['foreign_table'] ?? null) === 'users'
                    && ($foreignKey['foreign_columns'] ?? []) === ['id']
                ));

        if ($deviceLocationForeignKey !== null) {
            Schema::table('job_orders', function (Blueprint $table) use ($deviceLocationForeignKey) {
                $table->dropForeign(DB::getDriverName() === 'sqlite'
                    ? ['device_location_updated_by_user_id']
                    : ($deviceLocationForeignKey['name'] ?? 'job_device_location_user_fk'));
            });
        }

        $columns = array_filter([
            Schema::hasColumn('job_orders', 'device_location') ? 'device_location' : null,
            Schema::hasColumn('job_orders', 'device_location_updated_at') ? 'device_location_updated_at' : null,
            Schema::hasColumn('job_orders', 'device_location_updated_by_user_id') ? 'device_location_updated_by_user_id' : null,
        ]);

        if ($columns !== []) {
            Schema::withoutForeignKeyConstraints(function () use ($columns) {
                Schema::table('job_orders', function (Blueprint $table) use ($columns) {
                    $table->dropColumn($columns);
                });
            });
        }
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('device_location', 20)->nullable();
            $table->timestamp('device_location_updated_at')->nullable();
            $table->unsignedBigInteger('device_location_updated_by_user_id')->nullable();
            $table->foreign('device_location_updated_by_user_id', 'job_device_location_user_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }
};
