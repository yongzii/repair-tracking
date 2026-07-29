<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('job_order_payments')
            ->where('payment_type', 'DOWN_PAYMENT')
            ->update(['payment_type' => 'PARTIAL_PAYMENT']);
    }

    public function down(): void
    {
        //
    }
};
