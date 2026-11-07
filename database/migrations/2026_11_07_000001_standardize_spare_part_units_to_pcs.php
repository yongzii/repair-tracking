<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // This is intentionally irreversible: all existing quantities are individual pieces,
        // so no original unit label can be safely restored.
        DB::table('spare_parts')->update(['unit' => 'pcs']);
    }

    public function down(): void
    {
        // Original unit labels cannot be reconstructed after standardization.
    }
};
