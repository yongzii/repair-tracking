<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\SubscriptionRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubscriptionProofInventoryCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_CONTENT = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89"
        . "\x00\x00\x00\x0bIDAT\x08\xd7c\xf8\xcf\xc0\xf0\x1f\x00\x05\x00\x01\xff\x89\x99=\x1d"
        . "\x00\x00\x00\x00IEND\xaeB`\x82";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_it_reports_a_public_copy_candidate_with_file_metadata(): void
    {
        $request = $this->createRequest();
        Storage::disk('public')->put($request->proof_path, self::PNG_CONTENT);

        [$exitCode, $output] = $this->runAudit();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('valid public copy candidate', $output);
        $this->assertStringContainsString('sha256=' . hash('sha256', self::PNG_CONTENT), $output);
        $this->assertStringContainsString('image/png', $output);
    }

    public function test_it_classifies_traversal_and_wrong_shop_paths_without_inspecting_them(): void
    {
        $shop = $this->createShop();
        $traversalRequest = $this->createRequest($shop, 'shops/' . $shop->id . '/subscription-proofs/../../other/proof.png');
        $absoluteRequest = $this->createRequest($shop, 'C:/private/proof.png');
        $unixAbsoluteRequest = $this->createRequest($shop, '/private/proof.png');
        $wrongShopRequest = $this->createRequest($shop, 'shops/999/subscription-proofs/proof.png');

        [$exitCode, $output] = $this->runAudit();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('path traversal segment', $output);
        $this->assertStringContainsString('absolute or non-canonical path', $output);
        $this->assertStringContainsString('shop directory does not match request', $output);
        $this->assertStringContainsString('Malformed/out-of-scope path: 4', $output);

        $this->assertDatabaseHas('subscription_requests', ['id' => $traversalRequest->id]);
        $this->assertDatabaseHas('subscription_requests', ['id' => $absoluteRequest->id]);
        $this->assertDatabaseHas('subscription_requests', ['id' => $unixAbsoluteRequest->id]);
        $this->assertDatabaseHas('subscription_requests', ['id' => $wrongShopRequest->id]);
    }

    public function test_it_reports_missing_proofs_and_safely_identified_orphans(): void
    {
        $request = $this->createRequest();
        $orphanPath = "shops/{$request->shop_id}/subscription-proofs/orphan.png";
        Storage::disk('public')->put($orphanPath, self::PNG_CONTENT);

        [$exitCode, $output] = $this->runAudit();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('missing public proof', $output);
        $this->assertStringContainsString('orphaned legacy file', $output);
        $this->assertStringContainsString($orphanPath, $output);
    }

    public function test_unsupported_mime_types_are_sent_to_manual_review(): void
    {
        $shop = $this->createShop();
        $request = $this->createRequest($shop, "shops/{$shop->id}/subscription-proofs/proof.txt");
        Storage::disk('public')->put($request->proof_path, 'not an allowed proof format');

        [$exitCode, $output] = $this->runAudit();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('manual review: Public file MIME type is unavailable or unsupported', $output);
        $this->assertStringContainsString('text/plain', $output);
        $this->assertStringNotContainsString('valid public copy candidate', $output);
    }

    public function test_it_distinguishes_matching_private_files_from_destination_collisions(): void
    {
        $matchingRequest = $this->createRequest();
        $collisionRequest = $this->createRequest($this->shopForRequest($matchingRequest), $this->nextProofPath($matchingRequest));

        Storage::disk('public')->put($matchingRequest->proof_path, self::PNG_CONTENT);
        Storage::disk('local')->put($matchingRequest->proof_path, self::PNG_CONTENT);

        $differentContent = self::PNG_CONTENT . 'different';
        Storage::disk('public')->put($collisionRequest->proof_path, self::PNG_CONTENT);
        Storage::disk('local')->put($collisionRequest->proof_path, $differentContent);

        [$exitCode, $output] = $this->runAudit();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('already-present private file', $output);
        $this->assertStringContainsString('destination collision', $output);
        $this->assertStringContainsString(hash('sha256', self::PNG_CONTENT), $output);
        $this->assertStringContainsString(hash('sha256', $differentContent), $output);
    }

    public function test_reruns_are_deterministic_and_do_not_change_files_or_records(): void
    {
        $request = $this->createRequest();
        Storage::disk('public')->put($request->proof_path, self::PNG_CONTENT);
        $beforeDatabase = SubscriptionRequest::query()->orderBy('id')->get()->toArray();
        $beforePublicHash = hash('sha256', Storage::disk('public')->get($request->proof_path));
        $beforePrivateFiles = Storage::disk('local')->allFiles();

        [$firstExitCode, $firstOutput] = $this->runAudit();
        [$secondExitCode, $secondOutput] = $this->runAudit();

        $this->assertSame(0, $firstExitCode, $firstOutput);
        $this->assertSame(0, $secondExitCode, $secondOutput);
        $this->assertStringContainsString('valid public copy candidate', $firstOutput);
        $this->assertSame($firstOutput, $secondOutput);
        $this->assertSame($beforeDatabase, SubscriptionRequest::query()->orderBy('id')->get()->toArray());
        $this->assertSame($beforePublicHash, hash('sha256', Storage::disk('public')->get($request->proof_path)));
        $this->assertSame($beforePrivateFiles, Storage::disk('local')->allFiles());
    }

    public function test_filesystem_errors_are_reported_and_do_not_become_safe_candidates(): void
    {
        $request = $this->createRequest();
        $localDisk = Storage::disk('local');
        $publicDisk = \Mockery::mock(FilesystemAdapter::class);
        config(['filesystems.disks.public.driver' => 's3']);
        $publicDisk->shouldReceive('exists')
            ->once()
            ->with($request->proof_path)
            ->andThrow(new \RuntimeException('private filesystem details'));

        Storage::shouldReceive('disk')->with('public')->andReturn($publicDisk);
        Storage::shouldReceive('disk')->with('local')->andReturn($localDisk);

        [$exitCode, $output] = $this->runAudit();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('inspection error', $output);
        $this->assertStringNotContainsString('valid public copy candidate', $output);
        $this->assertStringNotContainsString('private filesystem details', $output);
        $this->assertDatabaseHas('subscription_requests', ['id' => $request->id]);
    }

    public function test_ambiguous_false_existence_checks_are_not_reported_as_missing_or_candidates(): void
    {
        $request = $this->createRequest();
        $publicDisk = \Mockery::mock(FilesystemAdapter::class);
        $localDisk = \Mockery::mock(FilesystemAdapter::class);
        $publicDisk->shouldReceive('exists')->once()->with($request->proof_path)->andReturnFalse();
        $localDisk->shouldReceive('exists')->once()->with($request->proof_path)->andReturnFalse();

        config([
            'filesystems.disks.public.driver' => 's3',
            'filesystems.disks.local.driver' => 's3',
        ]);
        Storage::shouldReceive('disk')->with('public')->andReturn($publicDisk);
        Storage::shouldReceive('disk')->with('local')->andReturn($localDisk);

        [$exitCode, $output] = $this->runAudit();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('file absence or accessibility is uncertain', $output);
        $this->assertStringNotContainsString('valid public copy candidate', $output);
        $this->assertStringNotContainsString('missing public proof', $output);
    }

    public function test_malformed_records_prevent_confident_orphan_classification(): void
    {
        $shop = $this->createShop();
        $this->createRequest($shop, "shops/{$shop->id}/subscription-proofs/../proof.png");
        $potentiallyRelatedPath = "shops/{$shop->id}/subscription-proofs/proof.png";
        Storage::disk('public')->put($potentiallyRelatedPath, self::PNG_CONTENT);

        [$exitCode, $output] = $this->runAudit();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('manual review: malformed database path may refer to this file', $output);
        $this->assertStringNotContainsString('orphaned legacy file', $output);
        $this->assertStringContainsString('Orphaned legacy file: 0', $output);
    }

    public function test_production_environment_is_rejected_even_with_opt_in_before_storage_or_database_scan(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        Storage::shouldReceive('disk')->never();
        DB::shouldReceive('select')->never();

        [$exitCode, $output] = $this->runAudit(true);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Laravel environment: production', $output);
        $this->assertStringContainsString('Refusing to inventory in the production environment.', $output);
        $this->assertStringNotContainsString('Summary:', $output);
    }

    public function test_explicit_opt_in_is_required_before_any_inventory(): void
    {
        $this->createRequest();
        Storage::shouldReceive('disk')->never();
        DB::shouldReceive('select')->never();

        [$exitCode, $output] = $this->runAudit(false);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Inventory not started.', $output);
        $this->assertStringContainsString('Laravel environment: testing', $output);
        $this->assertStringContainsString('Database connection: sqlite', $output);
        $this->assertStringNotContainsString('Summary:', $output);
    }

    private function createRequest(?Shop $shop = null, ?string $proofPath = null): SubscriptionRequest
    {
        $shop ??= $this->createShop();

        return SubscriptionRequest::create([
            'shop_id' => $shop->id,
            'plan_key' => 'monthly',
            'plan_name' => 'Monthly',
            'amount' => 1500,
            'payment_method_key' => 'gcash',
            'payment_method_name' => 'GCash',
            'proof_path' => $proofPath ?? "shops/{$shop->id}/subscription-proofs/proof-" . $shop->id . '-' . uniqid() . '.png',
            'status' => SubscriptionRequest::STATUS_PENDING,
        ]);
    }

    private function createShop(): Shop
    {
        $name = 'Inventory Shop ' . uniqid();

        return Shop::create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'subscription_plan' => 'starter',
            'is_active' => true,
        ]);
    }

    private function shopForRequest(SubscriptionRequest $request): Shop
    {
        return Shop::query()->findOrFail($request->shop_id);
    }

    private function nextProofPath(SubscriptionRequest $request): string
    {
        return "shops/{$request->shop_id}/subscription-proofs/collision-" . uniqid() . '.png';
    }

    private function runAudit(bool $confirmReadOnly = true): array
    {
        $exitCode = Artisan::call('subscription-proofs:audit', [
            '--confirm-read-only' => $confirmReadOnly,
        ]);

        return [$exitCode, Artisan::output()];
    }
}
