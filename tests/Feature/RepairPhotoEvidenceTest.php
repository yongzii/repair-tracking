<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\RepairPhoto;
use App\Models\Shop;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RepairPhotoEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_upload_and_view_job_order_evidence_photo(): void
    {
        Storage::fake('local');

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
        Storage::disk('local')->assertExists($photo->photo_path);
        $this->assertSame(
            route('admin.job-order.photos.file', ['jobOrder' => $jobOrder->id, 'photo' => $photo->id]),
            $this->getJson("/api/admin/job-orders/{$jobOrder->id}")->json('photos.0.url')
        );

        $this->actingAs($staff)
            ->get("/api/admin/job-orders/{$jobOrder->id}/photos/{$photo->id}/file")
            ->assertOk();

        $this->actingAs($staff)
            ->getJson("/api/admin/job-orders/{$jobOrder->id}")
            ->assertOk()
            ->assertJsonPath('photos.0.description', 'Device condition before diagnosis.');
    }

    public function test_customer_tracking_only_shows_photos_marked_visible_to_customer(): void
    {
        Storage::fake('local');

        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop);

        $hiddenPath = UploadedFile::fake()->image('internal.jpg')->store('repair-photos/test', 'local');
        $visiblePath = UploadedFile::fake()->image('customer.jpg')->store('repair-photos/test', 'local');

        $hiddenPhoto = RepairPhoto::create([
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

        $this->get("/api/shops/{$shop->slug}/track/{$jobOrder->job_order_number}/photos/{$visiblePhoto->id}")
            ->assertOk();

        $this->get("/api/shops/{$shop->slug}/track/{$jobOrder->job_order_number}/photos/{$hiddenPhoto->id}")
            ->assertNotFound();

        $this->assertSame(
            route('tracking.photos.file', [
                'shop' => $shop->slug,
                'jobOrderNumber' => $jobOrder->job_order_number,
                'photo' => $visiblePhoto->id,
            ]),
            $this->getJson("/api/shops/{$shop->slug}/track/{$jobOrder->job_order_number}")->json('photos.0.url')
        );
    }

    public function test_staff_can_publish_and_hide_photo_from_customer_tracking(): void
    {
        Storage::fake('local');

        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop);
        $path = UploadedFile::fake()->image('proof.jpg')->store('repair-photos/test', 'local');

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
        Storage::fake('local');

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
        Storage::fake('local');

        [$shop, $admin] = $this->createShopUser(User::ROLE_ADMIN);
        $jobOrder = $this->createJobOrder($shop);
        $path = UploadedFile::fake()->image('after.webp', 700, 500)->store('repair-photos/test', 'local');

        $photo = RepairPhoto::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'photo_type' => RepairPhoto::TYPE_AFTER,
            'photo_path' => $path,
            'original_name' => 'after.webp',
            'uploaded_by' => $admin->name,
            'uploaded_by_role' => $admin->role,
        ]);

        Storage::disk('local')->assertExists($path);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/job-orders/{$jobOrder->id}/photos/{$photo->id}")
            ->assertOk();

        $this->assertDatabaseMissing('repair_photos', [
            'id' => $photo->id,
        ]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_technician_cannot_upload_photo_to_unassigned_job_order(): void
    {
        Storage::fake('local');

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

    public function test_staff_cannot_retrieve_another_shops_photo(): void
    {
        Storage::fake('local');

        [$shopA, $staffA] = $this->createShopUser(User::ROLE_STAFF);
        [$shopB, $staffB] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shopB);
        $photo = $this->createPhoto($shopB, $jobOrder, $staffB, false);

        $this->actingAs($staffA)
            ->get("/api/admin/job-orders/{$jobOrder->id}/photos/{$photo->id}/file")
            ->assertNotFound();
    }

    public function test_assigned_technician_can_retrieve_a_photo(): void
    {
        Storage::fake('local');

        [$shop, $technicianUser] = $this->createShopUser(User::ROLE_TECHNICIAN);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Assigned Tech',
            'email' => $technicianUser->email,
            'is_active' => true,
        ]);
        $jobOrder = $this->createJobOrder($shop, $technician->id);
        $photo = $this->createPhoto($shop, $jobOrder, $technicianUser, false);

        $this->actingAs($technicianUser)
            ->get("/api/technician/job-orders/{$jobOrder->id}/photos/{$photo->id}/file")
            ->assertOk();
    }

    public function test_assigned_technician_can_set_photo_visibility_and_audit_change(): void
    {
        Storage::fake('local');

        [$shop, $technicianUser] = $this->createShopUser(User::ROLE_TECHNICIAN);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Visibility Tech',
            'email' => $technicianUser->email,
            'is_active' => true,
        ]);
        $jobOrder = $this->createJobOrder($shop, $technician->id);
        $photo = $this->createPhoto($shop, $jobOrder, $technicianUser, false);

        $this->actingAs($technicianUser)
            ->postJson("/api/technician/job-orders/{$jobOrder->id}/photos/{$photo->id}/visibility", [
                'visible_to_customer' => true,
            ])
            ->assertOk()
            ->assertJsonPath('photo.id', $photo->id)
            ->assertJsonPath('photo.visible_to_customer', true);

        $this->assertDatabaseHas('repair_photos', [
            'id' => $photo->id,
            'visible_to_customer' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $technicianUser->id,
            'shop_id' => $shop->id,
            'action' => 'job_order.photo_visibility_updated',
            'record_id' => $jobOrder->id,
        ]);

        $this->actingAs($technicianUser)
            ->postJson("/api/technician/job-orders/{$jobOrder->id}/photos/{$photo->id}/visibility", [
                'visible_to_customer' => false,
            ])
            ->assertOk()
            ->assertJsonPath('photo.visible_to_customer', false);
    }

    public function test_technician_cannot_retrieve_an_unassigned_job_photo(): void
    {
        Storage::fake('local');

        [$shop, $technicianUser] = $this->createShopUser(User::ROLE_TECHNICIAN);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Unassigned Tech',
            'email' => $technicianUser->email,
            'is_active' => true,
        ]);
        $otherTechnician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'Other Tech',
            'email' => 'other-tech@example.com',
            'is_active' => true,
        ]);
        $jobOrder = $this->createJobOrder($shop, $otherTechnician->id);
        $photo = $this->createPhoto($shop, $jobOrder, $technicianUser, false);

        $this->actingAs($technicianUser)
            ->get("/api/technician/job-orders/{$jobOrder->id}/photos/{$photo->id}/file")
            ->assertForbidden();
    }

    public function test_technician_cannot_retrieve_another_shops_photo(): void
    {
        Storage::fake('local');

        [$shopA, $technicianUser] = $this->createShopUser(User::ROLE_TECHNICIAN);
        $technician = Technician::create([
            'shop_id' => $shopA->id,
            'name' => 'Cross Shop Tech',
            'email' => $technicianUser->email,
            'is_active' => true,
        ]);
        [$shopB, $staffB] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shopB);
        $photo = $this->createPhoto($shopB, $jobOrder, $staffB, false);

        $this->actingAs($technicianUser)
            ->get("/api/technician/job-orders/{$jobOrder->id}/photos/{$photo->id}/file")
            ->assertNotFound();
    }

    public function test_technician_cannot_change_visibility_for_another_job_or_shop(): void
    {
        Storage::fake('local');

        [$shopA, $technicianUser] = $this->createShopUser(User::ROLE_TECHNICIAN);
        $technician = Technician::create([
            'shop_id' => $shopA->id,
            'name' => 'Isolation Tech',
            'email' => $technicianUser->email,
            'is_active' => true,
        ]);
        $jobA = $this->createJobOrder($shopA, $technician->id);
        $jobB = $this->createJobOrder($shopA);
        [$shopB, $staffB] = $this->createShopUser(User::ROLE_STAFF);
        $jobOtherShop = $this->createJobOrder($shopB);
        $photoB = $this->createPhoto($shopA, $jobB, $staffB, false);
        $photoOtherShop = $this->createPhoto($shopB, $jobOtherShop, $staffB, false);

        $this->actingAs($technicianUser)
            ->postJson("/api/technician/job-orders/{$jobA->id}/photos/{$photoB->id}/visibility", [
                'visible_to_customer' => true,
            ])
            ->assertNotFound();

        $this->actingAs($technicianUser)
            ->postJson("/api/technician/job-orders/{$jobOtherShop->id}/photos/{$photoOtherShop->id}/visibility", [
                'visible_to_customer' => true,
            ])
            ->assertNotFound();
    }

    public function test_public_tracking_cannot_retrieve_another_job_or_shop_photo(): void
    {
        Storage::fake('local');

        [$shopA, $staffA] = $this->createShopUser(User::ROLE_STAFF);
        [$shopB, $staffB] = $this->createShopUser(User::ROLE_STAFF);
        $jobA = $this->createJobOrder($shopA);
        $jobB = $this->createJobOrder($shopB);
        $photoB = $this->createPhoto($shopB, $jobB, $staffB, true);

        $this->get("/api/shops/{$shopA->slug}/track/{$jobA->job_order_number}/photos/{$photoB->id}")
            ->assertNotFound();

        $this->actingAs($staffA)
            ->get("/api/admin/job-orders/{$jobB->id}/photos/{$photoB->id}/file")
            ->assertNotFound();
    }

    public function test_private_photo_is_not_written_to_public_storage(): void
    {
        Storage::fake('local');

        [$shop, $staff] = $this->createShopUser(User::ROLE_STAFF);
        $jobOrder = $this->createJobOrder($shop);

        $this->actingAs($staff)
            ->postJson("/api/admin/job-orders/{$jobOrder->id}/photos", [
                'photo' => UploadedFile::fake()->image('private.jpg'),
                'photo_type' => RepairPhoto::TYPE_EVIDENCE,
            ])
            ->assertCreated();

        $photo = RepairPhoto::firstOrFail();
        Storage::disk('local')->assertExists($photo->photo_path);
        $this->assertFalse(File::exists(public_path('storage/' . $photo->photo_path)));
    }

    private function createShopUser(string $role): array
    {
        $shop = Shop::create([
            'name' => 'Evidence Test Shop ' . $role,
            'slug' => 'evidence-test-shop-' . $role . '-' . uniqid(),
            'trial_ends_at' => now()->addDays(30),
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

    private function createPhoto(Shop $shop, JobOrder $jobOrder, User $user, bool $visible): RepairPhoto
    {
        $path = UploadedFile::fake()->image('photo.jpg')->store('repair-photos/' . $shop->id . '/' . $jobOrder->id, 'local');

        return RepairPhoto::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'photo_type' => RepairPhoto::TYPE_EVIDENCE,
            'photo_path' => $path,
            'mime_type' => 'image/jpeg',
            'visible_to_customer' => $visible,
            'uploaded_by' => $user->name,
            'uploaded_by_role' => $user->role,
        ]);
    }
}
