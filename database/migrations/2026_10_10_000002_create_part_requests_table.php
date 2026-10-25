<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('part_name', 150);
            $table->unsignedInteger('quantity')->default(1);
            $table->string('note', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'created_at']);
        });

        DB::table('status_histories')
            ->join('job_orders', 'job_orders.id', '=', 'status_histories.job_order_id')
            ->where('status_histories.status', 'WAITING_PARTS')
            ->where('status_histories.note', 'like', 'Parts requested:%')
            ->select([
                'status_histories.id as history_id',
                'status_histories.job_order_id',
                'status_histories.note',
                'status_histories.created_at',
                'job_orders.shop_id',
            ])
            ->orderBy('status_histories.id')
            ->chunkById(500, function ($histories): void {
                $requests = [];

                foreach ($histories as $history) {
                    if (!preg_match(
                        '/^Parts requested:\s*(.+?)\s*\(Qty\s+(\d+)\)(?:\.\s*Note:\s*(.+))?$/',
                        trim((string) $history->note),
                        $matches
                    )) {
                        continue;
                    }

                    $requests[] = [
                        'shop_id' => $history->shop_id,
                        'job_order_id' => $history->job_order_id,
                        'part_name' => trim($matches[1]),
                        'quantity' => (int) $matches[2],
                        'note' => isset($matches[3]) ? trim($matches[3]) : null,
                        'status' => 'pending',
                        'created_at' => $history->created_at,
                        'updated_at' => $history->created_at,
                    ];
                }

                if ($requests !== []) {
                    DB::table('part_requests')->insert($requests);
                }
            }, 'status_histories.id', 'history_id');
    }

    public function down(): void
    {
        Schema::dropIfExists('part_requests');
    }
};
