<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('part_requests') || !Schema::hasTable('spare_parts') || !Schema::hasTable('users')) {
            throw new RuntimeException('Cannot add part request inventory receiving: required tables are missing.');
        }
        if (DB::getDriverName() === 'mysql') {
            foreach (['part_requests', 'spare_parts', 'users'] as $table) {
                $engine = DB::table('information_schema.TABLES')
                    ->where('TABLE_SCHEMA', DB::getDatabaseName())
                    ->where('TABLE_NAME', $table)
                    ->value('ENGINE');
                if (strcasecmp((string) $engine, 'InnoDB') !== 0) {
                    throw new RuntimeException("Cannot add part request inventory receiving: {$table} must use InnoDB.");
                }
            }
        }

        if (!Schema::hasColumn('part_requests', 'inventory_spare_part_id')) {
            Schema::table('part_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('inventory_spare_part_id')->nullable();
            });
        }

        if (!Schema::hasColumn('part_requests', 'received_by_user_id')) {
            Schema::table('part_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('received_by_user_id')->nullable();
            });
        }

        if (!Schema::hasColumn('part_requests', 'received_at')) {
            Schema::table('part_requests', function (Blueprint $table) {
                $table->timestamp('received_at')->nullable();
            });
        }

        $this->ensureForeignKey('inventory_spare_part_id', 'spare_parts', 'pr_inventory_spare_part_fk');
        $this->ensureForeignKey('received_by_user_id', 'users', 'pr_received_by_user_fk');
    }

    public function down(): void
    {
        if (!Schema::hasTable('part_requests')) {
            return;
        }

        if (Schema::hasColumn('part_requests', 'received_at')
            && DB::table('part_requests')->whereNotNull('received_at')->exists()) {
            throw new RuntimeException('Cannot roll back part request inventory receiving while receipt history exists.');
        }

        foreach ([
            'pr_inventory_spare_part_fk' => 'inventory_spare_part_id',
            'pr_received_by_user_fk' => 'received_by_user_id',
        ] as $constraint => $column) {
            if (Schema::hasColumn('part_requests', $column)
                && collect(Schema::getForeignKeys('part_requests'))->contains(fn (array $foreignKey) => $foreignKey['name'] === $constraint)) {
                Schema::table('part_requests', fn (Blueprint $table) => $table->dropForeign($constraint));
            }
        }

        foreach (['inventory_spare_part_id', 'received_by_user_id', 'received_at'] as $column) {
            if (Schema::hasColumn('part_requests', $column)) {
                Schema::table('part_requests', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function ensureForeignKey(string $column, string $referencedTable, string $name): void
    {
        $foreignKeys = Schema::getForeignKeys('part_requests');
        $existing = collect($foreignKeys)->first(fn (array $foreignKey) =>
            $foreignKey['name'] === $name || in_array($column, $foreignKey['columns'], true)
        );

        if ($existing) {
            if ($existing['columns'] !== [$column]
                || $existing['foreign_table'] !== $referencedTable
                || $existing['foreign_columns'] !== ['id']
                || strtoupper((string) ($existing['on_delete'] ?? '')) !== 'SET NULL') {
                throw new RuntimeException("Cannot add part request inventory receiving: existing foreign key for {$column} is incompatible.");
            }

            return;
        }

        Schema::table('part_requests', function (Blueprint $table) use ($column, $referencedTable, $name) {
            $table->foreign($column, $name)
                ->references('id')
                ->on($referencedTable)
                ->nullOnDelete();
        });
    }
};
