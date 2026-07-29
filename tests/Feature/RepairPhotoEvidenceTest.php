<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\RepairPhoto;
use App\Models\Shop;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RepairPhotoEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_upload_and_view_job_order_evidence_photo(): void
    {
        Storage::fake('public');

        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/photos", [
                'photo' => UploadedFile::fake()->image('before.jpg', 900, 600),
                'photo_type' => RepairPhoto::TYPE_BEFORE,
                'description' => 'Device condition before diagnosis.',
            ])
            ->assertCreated()
            ->assertJsonPath('photo.photo_type', RepairPhoto::TYPE_BEFORE)
            ->assertJsonPath('photo.visible_to_customer', false);

        $photo = RepairPhoto::firstOrFail();
        Storage::disk('public')->assertExists($photo->photo_path);

        $this->actingAs($staff)
            ->getJson("/api/admin/job-orders/{$jobOrder->id}")
            ->assertOk()
            ->assertJsonPath('photos.0.description', 'Device condition before diagnosis.');
    }

    public function test_customer_tracking_only_shows_photos_marked_visible_to_customer(): void
    {
        Storage::fake('public');

        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop);

        $hiddenPath = UploadedFile::fake()->image('internal.jpg')->store('repair-photos/test', 'public');
        $visiblePath = UploadedFile::fake()->image('customer.jpg')->store('repair-photos/test', 'public');

        RepairPhoto::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'photo_type' => RepairPhoto::TYPE_EVIDENCE,
            'photo_path' => $hiddenPath,
            'description' => 'Internal diagnostic note.',
            'visible_to_customer' => false,
            'uploaded_by' => $staff->name,
            'uploaded_by_role' => $staff->role,
        ]);

        $visiblePhoto = RepairPhoto::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'photo_type' => RepairPhoto::TYPE_AFTER,
            'photo_path' => $visiblePath,
            'description' => 'Ready for customer review.',
            'visible_to_customer' => true,
            'uploaded_by' => $staff->name,
            'uploaded_by_role' => $staff->role,
        ]);

        $this->getJson("/api/shops/{$shop->slug}/track/{$jobOrder->job_order_number}")
            ->assertOk()
            ->assertJsonCount(1, 'photos')
            ->assertJsonPath('photos.0.id', $visiblePhoto->id)
            ->assertJsonPath('photos.0.description', 'Ready for customer review.');
    }

    public function test_staff_can_publish_and_hide_photo_from_customer_tracking(): void
    {
        Storage::fake('public');

        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop);
        $path = UploadedFile::fake()->image('proof.jpg')->store('repair-photos/test', 'public');

        $photo = RepairPhoto::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'photo_type' => RepairPhoto::TYPE_EVIDENCE,
            'photo_path' => $path,
            'visible_to_customer' => false,
            'uploaded_by' => $staff->name,
            'uploaded_by_role' => $staff->role,
        ]);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/photos/{$photo->id}/visibility", [
                'visible_to_customer' => true,
            ])
            ->assertOk()
            ->assertJsonPath('photo.visible_to_customer', true);

        $this->assertTrue($photo->fresh()->visible_to_customer);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/photos/{$photo->id}/visibility", [
                'visible_to_customer' => false,
            ])
            ->assertOk()
            ->assertJsonPath('photo.visible_to_customer', false);

        $this->assertFalse($photo->fresh()->visible_to_customer);
    }

    public function test_assigned_technician_can_upload_job_order_photo(): void
    {
        Storage::fake('public');

        [$shop, $technicianUser] = $this->createShopUser(User::ROLE_TECHNICIAN);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Tech User',
            'email' => $technicianUser->email,
            'is_active' => true,
        ]);
        $jobOrder = $this->createJobOrder($shop, $technician->id);

        $this->actingAs($technicianUser)
            ->postJson("/api/technician/job-orders/{$jobOrder->id}/photos", [
                'photo' => UploadedFile::fake()->image('during.png', 800, 600),
                'photo_type' => RepairPhoto::TYPE_DURING,
                'description' => 'Board inspection after opening.',
            ])
            ->assertCreated()
            ->assertJsonPath('photo.uploaded_by', 'Tech User');

        $this->assertDatabaseHas('repair_photos', [
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'photo_type' => RepairPhoto::TYPE_DURING,
        ]);
    }

    public function test_photo_delete_removes_database_row_and_stored_file(): void
    {
        Storage::fake('public');

        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop);
        $path = UploadedFile::fake()->image('after.webp', 700, 500)->store('repair-photos/test', 'public');

        $photo = RepairPhoto::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'photo_type' => RepairPhoto::TYPE_AFTER,
            'photo_path' => $path,
            'original_name' => 'after.webp',
            'uploaded_by' => $admin->name,
            'uploaded_by_role' => $admin->role,
        ]);

        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/job-orders/{$jobOrder->id}/photos/{$photo->id}")
            ->assertOk();

        $this->assertDatabaseMissing('repair_photos', [
            'id' => $photo->id,
        ]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_technician_cannot_upload_photo_to_unassigned_job_order(): void
    {
        Storage::fake('public');

        [$shop, $technicianUser] = $this->createShopUser(User::ROLE_TECHNICIAN);
        Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Tech User',
            'email' => $technicianUser->email,
            'is_active' => true,
        ]);
        $jobOrder = $this->createJobOrder($shop);

        $this->actingAs($technicianUser)
            ->postJson("/api/technician/job-orders/{$jobOrder->id}/photos", [
                'photo' => UploadedFile::fake()->image('blocked.jpg', 800, 600),
                'photo_type' => RepairPhoto::TYPE_EVIDENCE,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('repair_photos', 0);
    }

    private function createShopUser(string $role): array
    {
        $shop = Shop::create([
            'name' => 'Evidence Test Shop ' . $role,
            'slug' => 'evidence-test-shop-' . $role,
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'shop_id' => $shop->id,
            'role' => $role,
            'is_active' => true,
        ]);

        return [$shop, $user];
    }

    private function createJobOrder(Shop $shop, ?int $technicianId = null): JobOrder
    {
        return JobOrder::create([
            'shop_id' => $shop->id,
            'technician_id' => $technicianId,
            'job_order_number' => 'TEST-PHOTO-' . $shop->id . '-' . ($technicianId ?? 'NONE'),
            'customer_name' => 'Customer Name',
            'customer_phone' => '09123456789',
            'device_type' => 'Phone',
            'problem_description' => 'No display.',
            'current_status' => 'DIAGNOSING',
        ]);
    }
}
