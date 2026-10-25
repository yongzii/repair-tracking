<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppointmentPhotoPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_appointment_photos_are_private_and_publicly_served_by_confirmation_route(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $shop = $this->createShop();

        $response = $this->postJson("/api/shops/{$shop->slug}/appointments", [
            'customer_name' => 'Photo Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'CONSULTATION',
            'device_type' => 'Phone',
            'concern' => 'Screen is cracked.',
            'preferred_at' => now()->addDay()->toIso8601String(),
            'photo' => UploadedFile::fake()->image('device.jpg'),
        ])->assertCreated();

        $appointment = Appointment::firstOrFail();
        $this->assertStringStartsWith("appointments/{$shop->id}/", $appointment->photo_path);
        $this->assertTrue(Storage::disk('local')->exists($appointment->photo_path));
        $this->assertFalse(Storage::disk('public')->exists($appointment->photo_path));
        $this->assertSame(
            route('public.appointment.photo', ['shop' => $shop->slug, 'confirmationCode' => $appointment->confirmation_code]),
            $response->json('appointment.photo_url')
        );
        $this->assertStringNotContainsString('/storage/', $response->json('appointment.photo_url'));

        $this->get($response->json('appointment.photo_url'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_public_photo_route_requires_matching_active_shop_appointment_code_and_existing_photo(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$shopA, $appointmentA] = $this->createAppointmentWithPhoto();
        $shopB = $this->createShop();

        $this->get($this->publicPhotoUrl($shopA, $appointmentA))->assertOk();
        $this->get("/api/shops/{$shopB->slug}/appointments/{$appointmentA->confirmation_code}/photo")
            ->assertNotFound();
        $this->get("/api/shops/{$shopA->slug}/appointments/WRONG-CODE/photo")
            ->assertNotFound();
        $this->get("/api/shops/{$shopA->slug}/appointments/NO-SUCH-CODE/photo")
            ->assertNotFound();

        $appointmentWithoutPhoto = $this->createAppointment($shopA, null);
        $this->get($this->publicPhotoUrl($shopA, $appointmentWithoutPhoto))->assertNotFound();

        $missingPathAppointment = $this->createAppointment($shopA, "appointments/{$shopA->id}/missing.jpg");
        $this->get($this->publicPhotoUrl($shopA, $missingPathAppointment))->assertNotFound();
    }

    public function test_staff_photo_route_is_authenticated_and_tenant_scoped(): void
    {
        Storage::fake('local');
        [$shopA, $appointmentA] = $this->createAppointmentWithPhoto();
        [$shopB, $appointmentB] = $this->createAppointmentWithPhoto();
        $staffA = $this->createShopUser($shopA, User::ROLE_STAFF);

        $this->getJson("/api/admin/appointments/{$appointmentA->id}/photo")
            ->assertUnauthorized();

        $this->actingAs($staffA)
            ->get("/api/admin/appointments/{$appointmentA->id}/photo")
            ->assertOk();

        $this->getJson("/api/admin/appointments/{$appointmentB->id}/photo")
            ->assertNotFound();
    }

    public function test_staff_appointment_response_uses_protected_photo_route(): void
    {
        Storage::fake('local');
        [$shop, $appointment] = $this->createAppointmentWithPhoto();
        $staff = $this->createShopUser($shop, User::ROLE_STAFF);

        $response = $this->actingAs($staff)
            ->getJson("/api/admin/appointments/{$appointment->id}")
            ->assertOk();

        $this->assertSame(
            route('admin.appointment.photo', ['appointment' => $appointment->id]),
            $response->json('appointment.photo_url')
        );
        $this->assertStringNotContainsString('/storage/', $response->json('appointment.photo_url'));
    }

    public function test_legacy_public_photo_migration_copies_verifies_and_removes_public_copy(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $shop = $this->createShop();
        $legacyPath = "appointments/{$shop->id}/legacy.jpg";
        $appointment = $this->createAppointment($shop, $legacyPath);
        Storage::disk('public')->put($appointment->photo_path, 'legacy appointment image bytes');

        $this->assertSame(0, Artisan::call('appointments:photos:privatize'), Artisan::output());

        $this->assertTrue(Storage::disk('local')->exists($appointment->photo_path));
        $this->assertFalse(Storage::disk('public')->exists($appointment->photo_path));
        $this->assertSame(
            'legacy appointment image bytes',
            Storage::disk('local')->get($appointment->photo_path)
        );
    }

    private function createAppointmentWithPhoto(): array
    {
        $shop = $this->createShop();
        $path = UploadedFile::fake()->image('appointment.jpg')->store("appointments/{$shop->id}", 'local');

        return [$shop, $this->createAppointment($shop, $path)];
    }

    private function createAppointment(Shop $shop, ?string $photoPath): Appointment
    {
        return Appointment::create([
            'shop_id' => $shop->id,
            'confirmation_code' => 'APT-2026-' . strtoupper(substr(uniqid(), -6)),
            'customer_name' => 'Photo Customer',
            'customer_phone' => '09123456789',
            'booking_type' => 'CONSULTATION',
            'device_type' => 'Phone',
            'concern' => 'Device photo test.',
            'photo_path' => $photoPath,
            'preferred_at' => now()->addDay(),
            'status' => 'PENDING',
        ]);
    }

    private function createShop(): Shop
    {
        return Shop::create([
            'name' => 'Appointment Photo Shop ' . uniqid(),
            'slug' => 'appointment-photo-' . uniqid(),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);
    }

    private function createShopUser(Shop $shop, string $role): User
    {
        return User::factory()->create([
            'shop_id' => $shop->id,
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function publicPhotoUrl(Shop $shop, Appointment $appointment): string
    {
        return route('public.appointment.photo', [
            'shop' => $shop->slug,
            'confirmationCode' => $appointment->confirmation_code,
        ]);
    }
}