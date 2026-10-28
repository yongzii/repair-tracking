<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\JobOrder;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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

    public function test_public_lookup_returns_a_friendly_not_found_message_for_unknown_shop_or_code(): void
    {
        $this->getJson('/api/shops/hismak/appointments/APT-2026-UNKNOWN1234')
            ->assertNotFound()
            ->assertJsonPath('message', 'We could not find that request. Check the shop code and confirmation code, then try again.')
            ->assertDontSee('No query results for model')
            ->assertDontSee('App\\Models\\Shop');
    }

    public function test_unrelated_api_not_found_uses_a_generic_message(): void
    {
        Route::get('/api/testing/not-found', fn () => abort(404));

        $this->getJson('/api/testing/not-found')
            ->assertNotFound()
            ->assertJsonPath('message', 'The requested resource was not found.')
            ->assertDontSee('confirmation code');
    }

    public function test_unexpected_api_errors_do_not_expose_exception_details(): void
    {
        Route::get('/api/testing/friendly-error', function () {
            throw new \RuntimeException('Sensitive database connection details.');
        });

        $this->getJson('/api/testing/friendly-error')
            ->assertInternalServerError()
            ->assertJsonPath('message', 'Something went wrong while processing your request. Please try again later.')
            ->assertDontSee('Sensitive database connection details.')
            ->assertDontSee('RuntimeException');
    }

    public function test_consultation_request_does_not_need_a_preferred_time_or_create_a_job_order(): void
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
            'booking_type' => 'CONSULTATION',
            'device_type' => 'Phone',
            'concern' => 'Device will not power on.',
        ])->assertCreated();

        $confirmationCode = $response->json('appointment.confirmation_code');

        $this->assertMatchesRegularExpression('/^APT-\d{4}-[A-Z0-9]{12}$/', $confirmationCode);
        $this->assertNull($response->json('appointment.preferred_at'));
        $this->assertSame(0, JobOrder::query()->count());
        $this->getJson("/api/shops/{$shop->slug}/appointments/{$confirmationCode}")->assertOk();
    }

    public function test_consultation_request_accepts_a_custom_device_category(): void
    {
        $shop = Shop::create([
            'name' => 'Custom Device Category Shop ' . uniqid(),
            'slug' => 'custom-device-category-' . uniqid(),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);

        $this->postJson("/api/shops/{$shop->slug}/appointments", [
            'customer_name' => 'Starlink Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'CONSULTATION',
            'device_type' => 'Starlink',
            'concern' => 'The satellite internet connection is dropping.',
        ])
            ->assertCreated()
            ->assertJsonPath('appointment.device_type', 'Starlink');
    }

    public function test_consultation_device_history_is_saved_in_the_existing_concern_field(): void
    {
        $shop = Shop::create([
            'name' => 'Consultation History Shop ' . uniqid(),
            'slug' => 'consultation-history-' . uniqid(),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/shops/{$shop->slug}/appointments", [
            'customer_name' => 'Consultation Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'CONSULTATION',
            'device_type' => 'Laptop',
            'concern' => 'The laptop shuts down after several minutes.',
            'problem_started' => 'Yesterday afternoon',
            'previous_incidents' => 'It overheated after being left in the sun.',
            'previous_repairs' => 'The battery was replaced last year.',
            'troubleshooting_attempted' => 'I tried another charger.',
            'additional_details' => 'The power light blinks twice before shutting down.',
        ])->assertCreated();

        $this->assertSame(
            "Problem description:\nThe laptop shuts down after several minutes.\n\n"
                . "When it started:\nYesterday afternoon\n\n"
                . "Previous damage or incidents:\nIt overheated after being left in the sun.\n\n"
                . "Previous repairs:\nThe battery was replaced last year.\n\n"
                . "Troubleshooting already attempted:\nI tried another charger.\n\n"
                . "Additional information:\nThe power light blinks twice before shutting down.",
            Appointment::query()->where('confirmation_code', $response->json('appointment.confirmation_code'))->value('concern')
        );
    }

    public function test_consultation_requires_a_meaningful_problem_description(): void
    {
        $shop = Shop::create([
            'name' => 'Consultation Validation Shop ' . uniqid(),
            'slug' => 'consultation-validation-' . uniqid(),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);

        $this->postJson("/api/shops/{$shop->slug}/appointments", [
            'customer_name' => 'Consultation Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'CONSULTATION',
            'device_type' => 'Laptop',
            'concern' => 'Broken',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('concern');
    }

    public function test_public_consultations_and_drop_off_bookings_keep_separate_time_requirements(): void
    {
        $shop = Shop::create([
            'name' => 'Consultation Only Shop ' . uniqid(),
            'slug' => 'consultation-only-' . uniqid(),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);

        $this->postJson("/api/shops/{$shop->slug}/appointments", [
            'customer_name' => 'Consultation Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'CONSULTATION',
            'device_type' => 'Phone',
            'concern' => 'Device will not power on.',
        ])
            ->assertCreated()
            ->assertJsonPath('appointment.booking_type', 'CONSULTATION');

        $this->postJson("/api/shops/{$shop->slug}/appointments", [
            'customer_name' => 'Drop-off Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'DROP_OFF',
            'device_type' => 'Phone',
            'concern' => 'Device will not power on.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('preferred_at');

        $this->postJson("/api/shops/{$shop->slug}/appointments", [
            'customer_name' => 'Drop-off Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'DROP_OFF',
            'device_type' => 'Phone',
            'concern' => 'Device will not power on.',
            'preferred_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('appointment.booking_type', 'DROP_OFF');
    }

    public function test_consultation_reply_is_customer_written_status_independent_and_keeps_follow_ups_open(): void
    {
        $shop = Shop::create([
            'name' => 'Consultation Response Shop',
            'slug' => 'consultation-response-shop',
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
            'confirmation_code' => 'APT-2026-INFO1234567',
            'customer_name' => 'Consultation Customer',
            'customer_email' => 'customer@example.test',
            'booking_type' => 'CONSULTATION',
            'device_type' => 'Laptop',
            'concern' => 'Laptop shuts down unexpectedly.',
            'preferred_at' => null,
            'status' => 'PENDING',
            'customer_response' => " \n ",
            'recommended_action' => 'BRING_TO_SHOP',
        ]);

        $this->getJson("/api/shops/{$shop->slug}/appointments/{$appointment->confirmation_code}")
            ->assertOk()
            ->assertJsonPath('appointment.status', 'PENDING')
            ->assertJsonPath('appointment.status_label', 'Pending')
            ->assertJsonPath('appointment.customer_response', null)
            ->assertJsonPath('appointment.awaiting_customer_information', false)
            ->assertJsonPath('appointment.inspection_recommended', false)
            ->assertJsonPath('appointment.recommended_action_label', null);

        $this->actingAs($staff)
            ->postJson("/api/admin/appointments/{$appointment->id}/status", [
                'status' => 'PENDING',
                'customer_response' => 'Please bring your laptop to the shop for a physical inspection.',
            ])
            ->assertOk();

        $this->getJson("/api/shops/{$shop->slug}/appointments/{$appointment->confirmation_code}")
            ->assertOk()
            ->assertJsonPath('appointment.confirmation_code', $appointment->confirmation_code)
            ->assertJsonPath('appointment.status', 'PENDING')
            ->assertJsonPath('appointment.status_label', 'Responded')
            ->assertJsonPath('appointment.customer_response', 'Please bring your laptop to the shop for a physical inspection.')
            ->assertJsonPath('appointment.awaiting_customer_information', false)
            ->assertJsonPath('appointment.inspection_recommended', false)
            ->assertJsonPath('appointment.recommended_action_label', null)
            ->assertJsonMissingPath('appointment.staff_note')
            ->assertJsonMissingPath('appointment.responded_by')
            ->assertJsonMissingPath('appointment.customer_email');
        $this->assertSame(0, JobOrder::query()->count());

        $this->actingAs($staff)
            ->postJson("/api/admin/appointments/{$appointment->id}/status", [
                'status' => 'PENDING',
                'customer_response' => 'Follow-up: call the shop if you need help finding the address.',
            ])
            ->assertOk();

        $this->getJson("/api/shops/{$shop->slug}/appointments/{$appointment->confirmation_code}")
            ->assertOk()
            ->assertJsonPath('appointment.status', 'PENDING')
            ->assertJsonPath('appointment.customer_response', 'Follow-up: call the shop if you need help finding the address.');

        $this->actingAs($staff)
            ->postJson("/api/admin/appointments/{$appointment->id}/status", [
                'status' => 'PENDING',
                'customer_response' => " \n ",
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_response');

        $this->actingAs($staff)
            ->postJson("/api/admin/appointments/{$appointment->id}/status", [
                'status' => 'COMPLETED',
                'customer_response' => 'We have provided the requested guidance.',
            ])
            ->assertOk();

        $this->getJson("/api/shops/{$shop->slug}/appointments/{$appointment->confirmation_code}")
            ->assertOk()
            ->assertJsonPath('appointment.status_label', 'Responded')
            ->assertJsonPath('appointment.status', 'COMPLETED')
            ->assertJsonPath('appointment.awaiting_customer_information', false);
    }

    public function test_public_consultation_lookup_is_scoped_to_the_selected_shop(): void
    {
        [$shop, $appointment] = $this->createAppointment();
        $otherShop = Shop::create([
            'name' => 'Other Public Shop ' . uniqid(),
            'slug' => 'other-public-shop-' . uniqid(),
            'is_active' => true,
        ]);

        $this->getJson("/api/shops/{$otherShop->slug}/appointments/{$appointment->confirmation_code}")
            ->assertNotFound();
    }

    public function test_cancelling_a_replied_consultation_preserves_the_reply_and_shows_cancelled_to_customer_and_staff(): void
    {
        $shop = Shop::create([
            'name' => 'Cancelled Consultation Shop',
            'slug' => 'cancelled-consultation-shop',
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
            'confirmation_code' => 'APT-2026-CANCEL12345',
            'customer_name' => 'Consultation Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'CONSULTATION',
            'device_type' => 'Laptop',
            'concern' => 'Laptop shuts down unexpectedly.',
            'preferred_at' => null,
            'status' => 'PENDING',
            'customer_response' => 'Please bring your laptop in for inspection.',
        ]);

        $this->actingAs($staff)
            ->postJson("/api/admin/appointments/{$appointment->id}/status", [
                'status' => 'CANCELLED',
            ])
            ->assertOk()
            ->assertJsonPath('appointment.status_label', 'Cancelled')
            ->assertJsonPath('appointment.customer_response', 'Please bring your laptop in for inspection.');

        $this->getJson("/api/shops/{$shop->slug}/appointments/{$appointment->confirmation_code}")
            ->assertOk()
            ->assertJsonPath('appointment.status', 'CANCELLED')
            ->assertJsonPath('appointment.status_label', 'Cancelled')
            ->assertJsonPath('appointment.customer_response', 'Please bring your laptop in for inspection.');
    }

    public function test_consultation_requires_either_email_or_phone_but_not_both(): void
    {
        $shop = Shop::create([
            'name' => 'Contact Validation Shop ' . uniqid(),
            'slug' => 'contact-validation-' . uniqid(),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);
        $request = [
            'customer_name' => 'Consultation Customer',
            'booking_type' => 'CONSULTATION',
            'device_type' => 'Laptop',
            'concern' => 'The laptop shuts down unexpectedly.',
        ];

        $this->postJson("/api/shops/{$shop->slug}/appointments", $request)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_email', 'customer_phone']);

        $this->postJson("/api/shops/{$shop->slug}/appointments", [
            ...$request,
            'customer_email' => 'customer@example.test',
        ])
            ->assertCreated()
            ->assertJsonPath('appointment.customer_email', 'customer@example.test')
            ->assertJsonPath('appointment.customer_phone', null);
    }

    public function test_public_shop_picker_only_returns_workspaces_accepting_requests(): void
    {
        $acceptingShop = Shop::create([
            'name' => 'Accepting Shop',
            'slug' => 'accepting-shop',
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);
        Shop::create([
            'name' => 'Expired Shop',
            'slug' => 'expired-shop',
            'trial_ends_at' => now()->subDay(),
            'is_active' => true,
        ]);

        $this->getJson('/api/public/shops')
            ->assertOk()
            ->assertJsonCount(1, 'shops')
            ->assertJsonPath('shops.0.id', $acceptingShop->id)
            ->assertJsonMissingPath('shops.0.email');
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