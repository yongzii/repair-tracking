<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertMigrationPrerequisites();

        if (!Schema::hasTable('job_order_part_orders')) {
            Schema::create('job_order_part_orders', function (Blueprint $table) {
                if (DB::getDriverName() === 'mysql') {
                    $table->engine = 'InnoDB';
                }

                $table->id();
                $table->unsignedBigInteger('shop_id');
                $table->unsignedBigInteger('job_order_id');
                $table->unsignedBigInteger('requested_by_user_id')->nullable();
                $table->unsignedBigInteger('customer_decision_recorded_by_user_id')->nullable();
                $table->unsignedBigInteger('received_by_user_id')->nullable();
                $table->unsignedBigInteger('issued_by_user_id')->nullable();
                $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
                $table->unsignedBigInteger('inventory_spare_part_id')->nullable();
                $table->string('part_name', 150);
                $table->unsignedInteger('quantity');
                $table->text('reason');
                $table->decimal('estimated_unit_price', 10, 2);
                $table->string('customer_decision', 20)->default('pending');
                $table->timestamp('customer_decision_at')->nullable();
                $table->string('customer_decision_note', 255)->nullable();
                $table->decimal('required_downpayment_amount', 10, 2)->nullable();
                $table->string('status', 30)->default('awaiting_approval');
                $table->string('supplier_name', 150)->nullable();
                $table->string('purchase_reference', 100)->nullable();
                $table->date('expected_arrival_date')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancellation_reason', 255)->nullable();
                $table->timestamps();

                $table->index(['shop_id', 'status']);
                $table->index(['job_order_id', 'created_at']);

                $table->foreign('shop_id', 'jopo_shop_fk')->references('id')->on('shops')->onDelete('cascade');
                $table->foreign('job_order_id', 'jopo_job_fk')->references('id')->on('job_orders')->onDelete('restrict');
                $table->foreign('requested_by_user_id', 'jopo_requester_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('customer_decision_recorded_by_user_id', 'jopo_decision_user_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('received_by_user_id', 'jopo_receiver_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('issued_by_user_id', 'jopo_issuer_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('cancelled_by_user_id', 'jopo_canceller_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('inventory_spare_part_id', 'jopo_inventory_fk')->references('id')->on('spare_parts')->nullOnDelete();
            });
        }

        $this->ensureIndex(
            'job_order_part_orders',
            ['shop_id', 'status'],
            'job_order_part_orders_shop_id_status_index'
        );
        $this->ensureIndex(
            'job_order_part_orders',
            ['job_order_id', 'created_at'],
            'job_order_part_orders_job_order_id_created_at_index'
        );

        $this->ensureForeignKey('job_order_part_orders', 'shop_id', 'shops', 'jopo_shop_fk', 'cascade');
        $this->ensureForeignKey('job_order_part_orders', 'job_order_id', 'job_orders', 'jopo_job_fk', 'restrict');
        $this->ensureForeignKey('job_order_part_orders', 'requested_by_user_id', 'users', 'jopo_requester_fk', 'set null');
        $this->ensureForeignKey('job_order_part_orders', 'customer_decision_recorded_by_user_id', 'users', 'jopo_decision_user_fk', 'set null');
        $this->ensureForeignKey('job_order_part_orders', 'received_by_user_id', 'users', 'jopo_receiver_fk', 'set null');
        $this->ensureForeignKey('job_order_part_orders', 'issued_by_user_id', 'users', 'jopo_issuer_fk', 'set null');
        $this->ensureForeignKey('job_order_part_orders', 'cancelled_by_user_id', 'users', 'jopo_canceller_fk', 'set null');
        $this->ensureForeignKey('job_order_part_orders', 'inventory_spare_part_id', 'spare_parts', 'jopo_inventory_fk', 'set null');

        if (DB::getDriverName() === 'sqlite' && (
            !Schema::hasColumn('job_orders', 'device_location')
            || !Schema::hasColumn('job_orders', 'device_location_updated_at')
            || !Schema::hasColumn('job_orders', 'device_location_updated_by_user_id')
        )) {
            Schema::table('job_orders', function (Blueprint $table) {
                if (!Schema::hasColumn('job_orders', 'device_location')) {
                    $table->string('device_location', 20)->nullable();
                }
                if (!Schema::hasColumn('job_orders', 'device_location_updated_at')) {
                    $table->timestamp('device_location_updated_at')->nullable();
                }
                if (!Schema::hasColumn('job_orders', 'device_location_updated_by_user_id')) {
                    $table->unsignedBigInteger('device_location_updated_by_user_id')->nullable();
                }
                if (!$this->hasForeignKey('job_orders', 'device_location_updated_by_user_id', 'users')) {
                    $table->foreign('device_location_updated_by_user_id', 'job_device_location_user_fk')
                        ->references('id')
                        ->on('users')
                        ->nullOnDelete();
                }
            });
        } else {
            if (!Schema::hasColumn('job_orders', 'device_location')) {
                Schema::table('job_orders', function (Blueprint $table) {
                    $table->string('device_location', 20)->nullable();
                });
            }
            if (!Schema::hasColumn('job_orders', 'device_location_updated_at')) {
                Schema::table('job_orders', function (Blueprint $table) {
                    $table->timestamp('device_location_updated_at')->nullable();
                });
            }
            if (!Schema::hasColumn('job_orders', 'device_location_updated_by_user_id')) {
                Schema::table('job_orders', function (Blueprint $table) {
                    $table->unsignedBigInteger('device_location_updated_by_user_id')->nullable();
                });
            }
        }
        $this->ensureForeignKey('job_orders', 'device_location_updated_by_user_id', 'users', 'job_device_location_user_fk', 'set null');

        if (DB::getDriverName() === 'sqlite' && !Schema::hasColumn('job_order_parts', 'job_order_part_order_id')) {
            Schema::table('job_order_parts', function (Blueprint $table) {
                $table->unsignedBigInteger('job_order_part_order_id')->nullable();
                $table->unique('job_order_part_order_id', 'jop_order_link_uq');
                $table->foreign('job_order_part_order_id', 'job_part_order_link_fk')
                    ->references('id')
                    ->on('job_order_part_orders')
                    ->onDelete('restrict');
            });
        } else {
            if (!Schema::hasColumn('job_order_parts', 'job_order_part_order_id')) {
                Schema::table('job_order_parts', function (Blueprint $table) {
                    $table->unsignedBigInteger('job_order_part_order_id')->nullable();
                });
            }
            $this->ensureUniqueIndex('job_order_parts', 'job_order_part_order_id', 'jop_order_link_uq');
        }
        $this->ensureForeignKey('job_order_parts', 'job_order_part_order_id', 'job_order_part_orders', 'job_part_order_link_fk', 'restrict');
    }

    private function assertNullableForSetNull(string $table, string $column, string $onDelete, string $constraint): void
    {
        if ($onDelete !== 'set null' || DB::getDriverName() !== 'mysql') {
            return;
        }

        $nullable = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('is_nullable');

        if (strtoupper((string) $nullable) !== 'YES') {
            throw new RuntimeException(
                "Cannot enforce {$constraint}: {$table}.{$column} must be nullable for ON DELETE SET NULL."
            );
        }
    }

    private function assertConstraintNameAvailable(
        string $table,
        string $column,
        string $referencedTable,
        string $name
    ): void {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $existing = DB::table('information_schema.key_column_usage')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('constraint_name', $name)
            ->whereNotNull('referenced_table_name')
            ->selectRaw('TABLE_NAME AS table_name, COLUMN_NAME AS column_name, REFERENCED_TABLE_NAME AS referenced_table_name, REFERENCED_COLUMN_NAME AS referenced_column_name')
            ->first();

        if (
            $existing !== null
            && (
                $existing->table_name !== $table
                || $existing->column_name !== $column
                || $existing->referenced_table_name !== $referencedTable
                || $existing->referenced_column_name !== 'id'
            )
        ) {
            throw new RuntimeException(
                "Cannot add {$name}: that foreign-key name is already used by a different relationship."
            );
        }
    }

    public function down(): void
    {
        $this->dropForeignKey('job_order_parts', 'job_order_part_order_id', 'job_part_order_link_fk');
        if (Schema::hasColumn('job_order_parts', 'job_order_part_order_id')) {
            $this->dropUniqueIndexIfExists('job_order_parts', 'job_order_part_order_id');
            Schema::table('job_order_parts', function (Blueprint $table) {
                $table->dropColumn('job_order_part_order_id');
            });
        }

        $this->dropForeignKey('job_orders', 'device_location_updated_by_user_id', 'job_device_location_user_fk');
        if (Schema::hasColumn('job_orders', 'device_location_updated_by_user_id')) {
            Schema::table('job_orders', function (Blueprint $table) {
                $table->dropColumn('device_location_updated_by_user_id');
            });
        }
        if (Schema::hasColumn('job_orders', 'device_location_updated_at')) {
            Schema::table('job_orders', function (Blueprint $table) {
                $table->dropColumn('device_location_updated_at');
            });
        }
        if (Schema::hasColumn('job_orders', 'device_location')) {
            Schema::table('job_orders', function (Blueprint $table) {
                $table->dropColumn('device_location');
            });
        }

        foreach ([
            ['shop_id', 'jopo_shop_fk'],
            ['job_order_id', 'jopo_job_fk'],
            ['requested_by_user_id', 'jopo_requester_fk'],
            ['customer_decision_recorded_by_user_id', 'jopo_decision_user_fk'],
            ['received_by_user_id', 'jopo_receiver_fk'],
            ['issued_by_user_id', 'jopo_issuer_fk'],
            ['cancelled_by_user_id', 'jopo_canceller_fk'],
            ['inventory_spare_part_id', 'jopo_inventory_fk'],
        ] as [$column, $name]) {
            $this->dropForeignKey('job_order_part_orders', $column, $name);
        }
        Schema::dropIfExists('job_order_part_orders');
    }

    private function ensureForeignKey(string $table, string $column, string $referencedTable, string $name, string $onDelete): void
    {
        $this->assertConstraintName($name);

        if (!Schema::hasTable($table)) {
            throw new RuntimeException("Cannot add {$name}: child table {$table} does not exist.");
        }
        if (!Schema::hasTable($referencedTable)) {
            throw new RuntimeException("Cannot add {$name}: referenced table {$referencedTable} does not exist.");
        }
        if (!Schema::hasColumn($table, $column)) {
            throw new RuntimeException("Cannot add {$name}: {$table}.{$column} does not exist.");
        }
        if (!Schema::hasColumn($referencedTable, 'id')) {
            throw new RuntimeException("Cannot add {$name}: referenced column {$referencedTable}.id does not exist.");
        }

        $this->assertInnoDb($table, $name);
        $this->assertInnoDb($referencedTable, $name);
        $this->assertColumnTypesMatch($table, $column, $referencedTable, 'id', $name);
        $this->assertNullableForSetNull($table, $column, $onDelete, $name);
        $this->assertReferencedColumnIsUnique($referencedTable, 'id', $name);
        $this->assertNoOrphanedReferences($table, $column, $referencedTable, 'id', $name);
        $this->assertConstraintNameAvailable($table, $column, $referencedTable, $name);

        $existingForeignKey = $this->findForeignKey($table, $column, $referencedTable);
        if ($existingForeignKey !== null) {
            if (strtolower((string) ($existingForeignKey['on_delete'] ?? '')) !== strtolower($onDelete)) {
                throw new RuntimeException(
                    "Cannot resume {$name}: {$table}.{$column} already references {$referencedTable}.id with a different ON DELETE action."
                );
            }

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            throw new RuntimeException("Cannot safely add missing SQLite foreign key {$name} to an existing table.");
        }

        $indexName = "{$name}_idx";
        if (strlen($indexName) > 64) {
            throw new RuntimeException("Cannot add {$name}: supporting index name exceeds MySQL's 64-character limit.");
        }
        $this->ensureSupportingIndex($table, $column, $indexName, $name);

        Schema::table($table, function (Blueprint $blueprint) use ($column, $referencedTable, $name, $onDelete) {
            $foreign = $blueprint->foreign($column, $name)->references('id')->on($referencedTable);
            if ($onDelete === 'set null') {
                $foreign->nullOnDelete();
            } else {
                $foreign->onDelete($onDelete);
            }
        });
    }

    private function assertMigrationPrerequisites(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('This migration can only safely run on MySQL or SQLite.');
        }

        foreach (['shops', 'job_orders', 'users', 'spare_parts', 'job_order_parts'] as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException("Cannot run this migration: required table {$table} does not exist.");
            }
        }

        foreach ($this->foreignKeyDefinitions() as [$table, $column, $referencedTable, $name]) {
            $this->assertConstraintName($name);
            $this->assertConstraintNameAvailable($table, $column, $referencedTable, $name);

            $childTableExists = Schema::hasTable($table);
            if (!$childTableExists && $table !== 'job_order_part_orders') {
                throw new RuntimeException("Cannot run this migration: child table {$table} does not exist.");
            }
            if ($childTableExists) {
                $this->assertInnoDb($table, $name);
            }

            if (!Schema::hasTable($referencedTable)) {
                if ($referencedTable === 'job_order_part_orders' && !Schema::hasTable($referencedTable)) {
                    continue;
                }

                throw new RuntimeException("Cannot run this migration: referenced table {$referencedTable} does not exist.");
            }

            if (!Schema::hasColumn($referencedTable, 'id')) {
                throw new RuntimeException("Cannot run this migration: referenced column {$referencedTable}.id does not exist.");
            }

            $this->assertInnoDb($referencedTable, $name);
            $this->assertReferencedColumnIsUnique($referencedTable, 'id', $name);

            if (!$childTableExists || !Schema::hasColumn($table, $column)) {
                continue;
            }

            $this->assertColumnTypesMatch($table, $column, $referencedTable, 'id', $name);
            $onDelete = match ($name) {
                'jopo_shop_fk' => 'cascade',
                'jopo_job_fk', 'job_part_order_link_fk' => 'restrict',
                default => 'set null',
            };
            $this->assertNullableForSetNull($table, $column, $onDelete, $name);
            $this->assertNoOrphanedReferences($table, $column, $referencedTable, 'id', $name);

            $existingForeignKey = $this->findForeignKey($table, $column, $referencedTable);
            if (
                $existingForeignKey !== null
                && strtolower((string) ($existingForeignKey['on_delete'] ?? '')) !== $onDelete
            ) {
                throw new RuntimeException(
                    "Cannot resume {$name}: {$table}.{$column} already references {$referencedTable}.id with a different ON DELETE action."
                );
            }
        }
    }

    private function foreignKeyDefinitions(): array
    {
        return [
            ['job_order_part_orders', 'shop_id', 'shops', 'jopo_shop_fk'],
            ['job_order_part_orders', 'job_order_id', 'job_orders', 'jopo_job_fk'],
            ['job_order_part_orders', 'requested_by_user_id', 'users', 'jopo_requester_fk'],
            ['job_order_part_orders', 'customer_decision_recorded_by_user_id', 'users', 'jopo_decision_user_fk'],
            ['job_order_part_orders', 'received_by_user_id', 'users', 'jopo_receiver_fk'],
            ['job_order_part_orders', 'issued_by_user_id', 'users', 'jopo_issuer_fk'],
            ['job_order_part_orders', 'cancelled_by_user_id', 'users', 'jopo_canceller_fk'],
            ['job_order_part_orders', 'inventory_spare_part_id', 'spare_parts', 'jopo_inventory_fk'],
            ['job_orders', 'device_location_updated_by_user_id', 'users', 'job_device_location_user_fk'],
            ['job_order_parts', 'job_order_part_order_id', 'job_order_part_orders', 'job_part_order_link_fk'],
        ];
    }

    private function assertConstraintName(string $name): void
    {
        if ($name === '' || strlen($name) > 64) {
            throw new RuntimeException("Foreign-key constraint name '{$name}' must be between 1 and 64 characters.");
        }
    }

    private function assertInnoDb(string $table, string $constraint): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $engine = DB::table('information_schema.tables')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->value('engine');

        if ($engine === null || strtoupper((string) $engine) !== 'INNODB') {
            throw new RuntimeException(
                "Cannot enforce {$constraint}: {$table} must use InnoDB (current engine: " . ($engine ?? 'missing') . ').'
            );
        }
    }

    private function assertColumnTypesMatch(
        string $table,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $constraint
    ): void {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $types = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where(function ($query) use ($table, $column, $referencedTable, $referencedColumn) {
                $query->where(function ($query) use ($table, $column) {
                    $query->where('table_name', $table)->where('column_name', $column);
                })->orWhere(function ($query) use ($referencedTable, $referencedColumn) {
                    $query->where('table_name', $referencedTable)->where('column_name', $referencedColumn);
                });
            })
            ->selectRaw('column_name AS fk_column_name, column_type AS fk_column_type')
            ->get()
            ->mapWithKeys(fn (object $metadata) => [
                $metadata->fk_column_name => $metadata->fk_column_type,
            ]);

        if (
            !$types->has($column)
            || !$types->has($referencedColumn)
            || strtolower((string) $types->get($column)) !== strtolower((string) $types->get($referencedColumn))
        ) {
            throw new RuntimeException(
                "Cannot enforce {$constraint}: {$table}.{$column} and {$referencedTable}.{$referencedColumn} must have matching MySQL column types."
            );
        }
    }

    private function assertReferencedColumnIsUnique(string $table, string $column, string $constraint): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (
                ($index['columns'] ?? []) === [$column]
                && (($index['primary'] ?? false) || ($index['unique'] ?? false))
            ) {
                return;
            }
        }

        throw new RuntimeException(
            "Cannot enforce {$constraint}: {$table}.{$column} must have a single-column primary or unique index."
        );
    }

    private function assertNoOrphanedReferences(
        string $table,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $constraint
    ): void {
        if (!Schema::hasColumn($table, $column)) {
            return;
        }

        $hasOrphans = DB::table($table . ' as child')
            ->leftJoin($referencedTable . ' as parent', "child.{$column}", '=', "parent.{$referencedColumn}")
            ->whereNotNull("child.{$column}")
            ->whereNull("parent.{$referencedColumn}")
            ->exists();

        if ($hasOrphans) {
            throw new RuntimeException(
                "Cannot enforce {$constraint}: {$table}.{$column} contains values with no matching {$referencedTable}.{$referencedColumn}."
            );
        }
    }

    private function ensureSupportingIndex(string $table, string $column, string $name, string $constraint): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            $columns = $index['columns'] ?? [];
            if ($columns !== [] && $columns[0] === $column) {
                return;
            }
            if (($index['name'] ?? null) === $name) {
                throw new RuntimeException(
                    "Cannot add {$constraint}: index {$name} already exists with incompatible columns."
                );
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $name) {
            $blueprint->index($column, $name);
        });
    }

    private function hasForeignKey(string $table, string $column, string $referencedTable): bool
    {
        return $this->findForeignKey($table, $column, $referencedTable) !== null;
    }

    private function findForeignKey(string $table, string $column, string $referencedTable): ?array
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (
                ($foreignKey['columns'] ?? []) === [$column]
                && ($foreignKey['foreign_table'] ?? null) === $referencedTable
                && ($foreignKey['foreign_columns'] ?? []) === ['id']
            ) {
                return $foreignKey;
            }
        }

        return null;
    }

    private function ensureUniqueIndex(string $table, string $column, string $name): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            $columns = $index['columns'] ?? [];
            if ($columns === [$column] && ($index['unique'] ?? false)) {
                return;
            }
            if (($index['name'] ?? null) === $name) {
                throw new RuntimeException(
                    "Cannot create unique index {$name}: an index with that name already exists with incompatible columns or uniqueness."
                );
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $name) {
            $blueprint->unique($column, $name);
        });
    }

    private function ensureIndex(string $table, array $columns, string $name): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            $indexColumns = $index['columns'] ?? [];
            if ($indexColumns === $columns) {
                return;
            }
            if (($index['name'] ?? null) === $name) {
                throw new RuntimeException(
                    "Cannot create index {$name}: an index with that name already exists with different columns."
                );
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
            $blueprint->index($columns, $name);
        });
    }

    private function dropUniqueIndexIfExists(string $table, string $column): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) === [$column] && ($index['unique'] ?? false)) {
                $name = $index['name'];
                Schema::table($table, function (Blueprint $blueprint) use ($name) {
                    $blueprint->dropUnique($name);
                });

                return;
            }
        }
    }

    private function dropForeignKey(string $table, string $column, string $name): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === $name || ($foreignKey['columns'] ?? []) === [$column]) {
                $foreignKeyName = $foreignKey['name'] ?? $name;
                Schema::table($table, function (Blueprint $blueprint) use ($foreignKeyName) {
                    $blueprint->dropForeign($foreignKeyName);
                });

                return;
            }
        }

        if (DB::getDriverName() === 'mysql') {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['name'] ?? null) === $name) {
                    Schema::table($table, function (Blueprint $blueprint) use ($name) {
                        $blueprint->dropIndex($name);
                    });

                    return;
                }
            }
        }
    }
};
