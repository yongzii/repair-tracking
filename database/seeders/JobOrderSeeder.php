<?php

namespace Database\Seeders;

use App\Models\JobOrder;
use App\Models\Shop;
use App\Models\StatusHistory;
use Illuminate\Database\Seeder;

class JobOrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $shop = Shop::query()->first();

        // Create test job order
        $jobOrder = JobOrder::create([
            'shop_id' => $shop?->id,
            'job_order_number' => 'RSMS-2025-AB12CD',
            'customer_name' => 'Juan Dela Cruz',
            'customer_contact' => '09123456789',
            'device_type' => 'Laptop',
            'brand' => 'Acer',
            'model' => 'Aspire 5',
            'serial_number' => 'SN123456',
            'problem_description' => 'Laptop not powering on, possible battery issue',
            'included_accessories' => 'Charger',
            'repair_approved_at' => now(),
            'repair_approved_by_customer' => 'Juan Dela Cruz',
            'repair_approval_recorded_by' => 'System Administrator',
            'repair_approval_notes' => 'Approved during seed data setup.',
            'current_status' => 'IN_PROGRESS',
        ]);

        // Add status history
        StatusHistory::create([
            'shop_id' => $shop?->id,
            'job_order_id' => $jobOrder->id,
            'status' => 'RECEIVED',
            'note' => 'Device received and logged',
        ]);

        StatusHistory::create([
            'shop_id' => $shop?->id,
            'job_order_id' => $jobOrder->id,
            'status' => 'DIAGNOSING',
            'note' => 'Initial diagnosis completed',
        ]);

        StatusHistory::create([
            'shop_id' => $shop?->id,
            'job_order_id' => $jobOrder->id,
            'status' => 'WAITING_PARTS',
            'note' => 'Waiting for replacement battery',
        ]);

        StatusHistory::create([
            'shop_id' => $shop?->id,
            'job_order_id' => $jobOrder->id,
            'status' => 'IN_PROGRESS',
            'note' => 'Battery replacement in progress',
        ]);

        // Create another test job order
        $jobOrder2 = JobOrder::create([
            'shop_id' => $shop?->id,
            'job_order_number' => 'RSMS-2025-XY98ZW',
            'customer_name' => 'Maria Santos',
            'customer_contact' => '09987654321',
            'device_type' => 'Desktop PC',
            'brand' => 'Custom',
            'model' => 'Gaming PC',
            'serial_number' => 'SN789012',
            'problem_description' => 'Graphics card not detected',
            'included_accessories' => 'Power cable',
            'repair_approved_at' => now(),
            'repair_approved_by_customer' => 'Maria Santos',
            'repair_approval_recorded_by' => 'System Administrator',
            'repair_approval_notes' => 'Approved during seed data setup.',
            'current_status' => 'READY_FOR_PICKUP',
        ]);

        StatusHistory::create([
            'shop_id' => $shop?->id,
            'job_order_id' => $jobOrder2->id,
            'status' => 'RECEIVED',
            'note' => 'Desktop received',
        ]);

        StatusHistory::create([
            'shop_id' => $shop?->id,
            'job_order_id' => $jobOrder2->id,
            'status' => 'DIAGNOSING',
            'note' => 'GPU driver issue identified',
        ]);

        StatusHistory::create([
            'shop_id' => $shop?->id,
            'job_order_id' => $jobOrder2->id,
            'status' => 'IN_PROGRESS',
            'note' => 'Driver reinstalled and tested',
        ]);

        StatusHistory::create([
            'shop_id' => $shop?->id,
            'job_order_id' => $jobOrder2->id,
            'status' => 'READY_FOR_PICKUP',
            'note' => 'System fully operational. Ready for pickup.',
        ]);
    }
}
