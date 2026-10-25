<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\JobOrder;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentConversionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_appointment_conversion_creates_only_one_job_order_on_repeated_requests(): void
    {
        $shop = Shop::create([
            'name' => 'Conversion Test Shop',
            'slug' => 'conversion-test-shop',
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);
        $staff = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_STAFF,
            'is_active' => true,
        ]);
        $appointment = Appointment::create([
            'shop_id' => $shop->id,
            'confirmation_code' => 'APT-2026-CONVERT001',
            'customer_name' => 'Conversion Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'DROP_OFF',
            'device_type' => 'Phone',
            'concern' => 'Device will not power on.',
            'preferred_at' => now()->addDay(),
            'status' => 'CONFIRMED',
        ]);

        $firstResponse = $this->actingAs($staff)
            ->postJson("/api/admin/appointments/{$appointment->id}/convert-to-job-order")
            ->assertCreated()
            ->assertJsonPath('message', 'Appointment converted to job order.');

        $jobOrderId = $firstResponse->json('jobOrder.id');

        $this->actingAs($staff)
            ->postJson("/api/admin/appointments/{$appointment->id}/convert-to-job-order")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This appointment is already linked to a job order.')
            ->assertJsonPath('jobOrder.id', $jobOrderId);

        $appointment->refresh();
        $this->assertSame($jobOrderId, $appointment->converted_job_order_id);
        $this->assertSame('COMPLETED', $appointment->status);
        $this->assertSame(1, JobOrder::query()->where('shop_id', $shop->id)->count());
    }
}
