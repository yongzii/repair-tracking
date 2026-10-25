<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAppointmentConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_confirmation_returns_customer_safe_appointment_details_only(): void
    {
        [$shop, $appointment] = $this->createAppointment();

        $this->getJson("/api/shops/{$shop->slug}/appointments/{$appointment->confirmation_code}")
            ->assertOk()
            ->assertJsonPath('appointment.confirmation_code', $appointment->confirmation_code)
            ->assertJsonPath('appointment.customer_name', 'Customer Name')
            ->assertJsonPath('appointment.status', 'CONFIRMED')
            ->assertJsonPath('appointment.preferred_at_formatted', $appointment->preferred_at->format('M d, Y h:i A'))
            ->assertJsonPath('appointment.scheduled_at_formatted', $appointment->scheduled_at->format('M d, Y h:i A'))
            ->assertJsonPath('appointment.customer_response', 'Your device is ready for drop-off.')
            ->assertJsonPath('appointment.recommended_action_label', 'Bring the device to the shop')
            ->assertJsonPath('appointment.photo_url', null)
            ->assertJsonMissingPath('appointment.id')
            ->assertJsonMissingPath('appointment.shop_id')
            ->assertJsonMissingPath('appointment.customer_email')
            ->assertJsonMissingPath('appointment.customer_phone')
            ->assertJsonMissingPath('appointment.staff_note')
            ->assertJsonMissingPath('appointment.responded_by')
            ->assertJsonMissingPath('appointment.responded_at')
            ->assertJsonMissingPath('appointment.converted_job_order_id')
            ->assertJsonMissingPath('appointment.created_at');
    }

    public function test_authenticated_staff_appointment_response_keeps_existing_fields(): void
    {
        [$shop, $appointment] = $this->createAppointment();
        $staff = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => User::ROLE_STAFF,
            'is_active' => true,
        ]);

        $this->actingAs($staff)
            ->getJson("/api/admin/appointments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('appointment.id', $appointment->id)
            ->assertJsonPath('appointment.customer_email', 'customer@example.test')
            ->assertJsonPath('appointment.customer_phone', '09123456789')
            ->assertJsonPath('appointment.staff_note', 'Internal scheduling note.')
            ->assertJsonPath('appointment.responded_by', 'Shop Staff')
            ->assertJsonPath('appointment.responded_at', $appointment->responded_at->toJSON());
    }

    public function test_public_confirmation_lookup_is_rate_limited_after_thirty_requests(): void
    {
        [$shop, $appointment] = $this->createAppointment();
        $url = "/api/shops/{$shop->slug}/appointments/{$appointment->confirmation_code}";

        for ($request = 1; $request <= 30; $request++) {
            $this->getJson($url)->assertOk();
        }

        $this->getJson($url)->assertTooManyRequests();
    }

    public function test_new_confirmation_codes_keep_the_prefix_and_use_a_twelve_character_suffix(): void
    {
        $shop = Shop::create([
            'name' => 'Generated Appointment Shop ' . uniqid(),
            'slug' => 'generated-appointment-' . uniqid(),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/shops/{$shop->slug}/appointments", [
            'customer_name' => 'New Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'DROP_OFF',
            'device_type' => 'Phone',
            'concern' => 'Device will not power on.',
            'preferred_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated();

        $confirmationCode = $response->json('appointment.confirmation_code');

        $this->assertMatchesRegularExpression('/^APT-\d{4}-[A-Z0-9]{12}$/', $confirmationCode);
        $this->getJson("/api/shops/{$shop->slug}/appointments/{$confirmationCode}")->assertOk();
    }

    private function createAppointment(): array
    {
        $shop = Shop::create([
            'name' => 'Public Appointment Shop ' . uniqid(),
            'slug' => 'public-appointment-' . uniqid(),
            'is_active' => true,
        ]);

        $appointment = Appointment::create([
            'shop_id' => $shop->id,
            'confirmation_code' => 'APT-2026-' . strtoupper(substr(uniqid(), -6)),
            'customer_name' => 'Customer Name',
            'customer_email' => 'customer@example.test',
            'customer_phone' => '09123456789',
            'booking_type' => 'DROP_OFF',
            'device_type' => 'Phone',
            'brand' => 'Example',
            'model' => 'Model One',
            'concern' => 'Device does not power on.',
            'preferred_at' => now()->addDay(),
            'scheduled_at' => now()->addDays(2),
            'status' => 'CONFIRMED',
            'staff_note' => 'Internal scheduling note.',
            'customer_response' => 'Your device is ready for drop-off.',
            'recommended_action' => 'BRING_TO_SHOP',
            'responded_at' => now(),
            'responded_by' => 'Shop Staff',
        ]);

        return [$shop, $appointment];
    }
}