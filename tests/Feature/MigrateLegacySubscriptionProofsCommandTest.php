<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\SubscriptionRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class MigrateLegacySubscriptionProofsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_CONTENT = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89"
        ."\x00\x00\x00\x0bIDAT\x08\xd7c\xf8\xcf\xc0\xf0\x1f\x00\x05\x00\x01\xff\x89\x99=\x1d"
        ."\x00\x00\x00\x00IEND\xaeB`\x82";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_it_copies_and_verifies_a_valid_proof_without_changing_source_or_record(): void
    {
        $request = $this->createRequest();
        Storage::disk('public')->put($request->proof_path, self::PNG_CONTENT);
        $before = $request->fresh()->getAttributes();

        [$exitCode, $output] = $this->runMigration([$request->id]);

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('copied and verified', $output);
        $this->assertStringContainsString('Temporary artifact retained in private storage', $output);
        $this->assertStringContainsString(hash('sha256', self::PNG_CONTENT), $output);
        $this->assertStringContainsString('mime=image/png', $output);
        Storage::disk('public')->assertExists($request->proof_path);
        Storage::disk('local')->assertExists($request->proof_path);
        $this->assertSame(
            hash('sha256', self::PNG_CONTENT),
            hash('sha256', Storage::disk('local')->get($request->proof_path))
        );
        $temporaryPath = collect(Storage::disk('local')->allFiles())
            ->first(fn (string $path): bool => str_contains($path, '.migration-'));
        $this->assertNotNull($temporaryPath);
        $this->assertSame(
            hash('sha256', self::PNG_CONTENT),
            hash('sha256', Storage::disk('local')->get($temporaryPath))
        );
        $this->assertSame($before, $request->fresh()->getAttributes());
        $this->assertSame([$request->proof_path], Storage::disk('public')->allFiles());
    }

    public function test_it_rejects_traversal_and_wrong_shop_paths_without_copying(): void
    {
        $shop = $this->createShop();
        $traversal = $this->createRequest(
            $shop,
            "shops/{$shop->id}/subscription-proofs/../proof.png"
        );
        $absolute = $this->createRequest($shop, 'C:/outside/proof.png');
        $wrongShop = $this->createRequest($shop, 'shops/999/subscription-proofs/proof.png');
        Storage::disk('public')->put('shops/'.$shop->id.'/subscription-proofs/proof.png', self::PNG_CONTENT);

        [$exitCode, $output] = $this->runMigration([$traversal->id, $absolute->id, $wrongShop->id]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('proof path contains an invalid segment', $output);
        $this->assertStringContainsString('proof path is absolute or non-canonical', $output);
        $this->assertStringContainsString('proof path shop does not match request ownership', $output);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('subscription_requests', ['id' => $traversal->id]);
        $this->assertDatabaseHas('subscription_requests', ['id' => $absolute->id]);
        $this->assertDatabaseHas('subscription_requests', ['id' => $wrongShop->id]);
    }

    public function test_it_rejects_unsupported_mime_types(): void
    {
        $shop = $this->createShop();
        $request = $this->createRequest($shop, "shops/{$shop->id}/subscription-proofs/proof.txt");
        Storage::disk('public')->put($request->proof_path, 'not a supported proof file');

        [$exitCode, $output] = $this->runMigration([$request->id]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('unsupported source MIME type', $output);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Storage::disk('public')->assertExists($request->proof_path);
    }

    public function test_it_enforces_the_existing_4096_kilobyte_upload_limit(): void
    {
        $request = $this->createRequest();
        Storage::disk('public')->put($request->proof_path, self::PNG_CONTENT.str_repeat('x', 4 * 1024 * 1024));

        [$exitCode, $output] = $this->runMigration([$request->id]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('exceeds the 4096 KB upload limit', $output);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Storage::disk('public')->assertExists($request->proof_path);
    }

    public function test_identical_existing_destination_is_verified_without_overwriting(): void
    {
        $request = $this->createRequest();
        Storage::disk('public')->put($request->proof_path, self::PNG_CONTENT);
        Storage::disk('local')->put($request->proof_path, self::PNG_CONTENT);
        $privateBefore = hash('sha256', Storage::disk('local')->get($request->proof_path));

        [$exitCode, $output] = $this->runMigration([$request->id]);

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('already verified', $output);
        $this->assertSame($privateBefore, hash('sha256', Storage::disk('local')->get($request->proof_path)));
        $this->assertSame([$request->proof_path], Storage::disk('local')->allFiles());
        Storage::disk('public')->assertExists($request->proof_path);
    }

    public function test_different_existing_destination_is_a_collision_and_remains_unchanged(): void
    {
        $request = $this->createRequest();
        Storage::disk('public')->put($request->proof_path, self::PNG_CONTENT);
        Storage::disk('local')->put($request->proof_path, self::PNG_CONTENT.'different');
        $privateBefore = hash('sha256', Storage::disk('local')->get($request->proof_path));

        [$exitCode, $output] = $this->runMigration([$request->id]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('collision', $output);
        $this->assertSame($privateBefore, hash('sha256', Storage::disk('local')->get($request->proof_path)));
        $this->assertSame([$request->proof_path], Storage::disk('local')->allFiles());
        Storage::disk('public')->assertExists($request->proof_path);
    }

    public function test_unreadable_source_and_destination_obstructions_fail_closed(): void
    {
        $unreadable = $this->createRequest();
        Storage::disk('public')->makeDirectory($unreadable->proof_path);

        $obstructed = $this->createRequest();
        Storage::disk('public')->put($obstructed->proof_path, self::PNG_CONTENT);
        Storage::disk('local')->makeDirectory($obstructed->proof_path);

        [$exitCode, $output] = $this->runMigration([$unreadable->id, $obstructed->id]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('source is missing, inaccessible, or not a regular file', $output);
        $this->assertStringContainsString('destination presence or accessibility is uncertain', $output);
        $this->assertDatabaseHas('subscription_requests', ['id' => $unreadable->id]);
        $this->assertDatabaseHas('subscription_requests', ['id' => $obstructed->id]);
        $this->assertTrue(Storage::disk('public')->directoryExists($unreadable->proof_path));
        Storage::disk('public')->assertExists($obstructed->proof_path);
    }

    public function test_copy_stream_failure_retains_its_temporary_private_file_for_review(): void
    {
        $request = $this->createRequest();
        Storage::disk('public')->put($request->proof_path, self::PNG_CONTENT);
        $beforeDatabase = $request->fresh()->getAttributes();
        $publicHash = hash('sha256', Storage::disk('public')->get($request->proof_path));
        $command = new class extends \App\Console\Commands\MigrateLegacySubscriptionProofs
        {
            protected function copyStream($source, $destination): int|false
            {
                stream_copy_to_stream($source, $destination);

                return false;
            }
        };

        [$exitCode, $output] = $this->runMigrationWithCommand($command, [$request->id]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('copy was incomplete or could not be flushed', $output);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertStringContainsString('.migration-', Storage::disk('local')->allFiles()[0]);
        $this->assertSame([$request->proof_path], Storage::disk('public')->allFiles());
        $this->assertSame($publicHash, hash('sha256', Storage::disk('public')->get($request->proof_path)));
        $this->assertSame($beforeDatabase, $request->fresh()->getAttributes());
    }

    public function test_failed_exclusive_temp_creation_preserves_the_pre_existing_temp_file(): void
    {
        $request = $this->createRequest();
        Storage::disk('public')->put($request->proof_path, self::PNG_CONTENT);
        $beforeDatabase = $request->fresh()->getAttributes();
        $publicHash = hash('sha256', Storage::disk('public')->get($request->proof_path));
        $command = new class extends \App\Console\Commands\MigrateLegacySubscriptionProofs
        {
            protected function openTemporaryFile(string $temporaryPath)
            {
                file_put_contents($temporaryPath, 'pre-existing unrelated temporary file');

                return false;
            }
        };

        [$exitCode, $output] = $this->runMigrationWithCommand($command, [$request->id]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('exclusive temporary destination creation failed', $output);
        $temporaryFiles = Storage::disk('local')->allFiles();
        $this->assertCount(1, $temporaryFiles);
        $this->assertSame(
            'pre-existing unrelated temporary file',
            Storage::disk('local')->get($temporaryFiles[0])
        );
        $this->assertSame($publicHash, hash('sha256', Storage::disk('public')->get($request->proof_path)));
        $this->assertSame($beforeDatabase, $request->fresh()->getAttributes());
    }

    public function test_temporary_path_replacement_is_retained_when_identity_changes(): void
    {
        $request = $this->createRequest();
        Storage::disk('public')->put($request->proof_path, self::PNG_CONTENT);
        $beforeDatabase = $request->fresh()->getAttributes();
        $replacement = 'replacement at the temporary path';
        $command = new class($replacement) extends \App\Console\Commands\MigrateLegacySubscriptionProofs
        {
            public function __construct(private string $replacement)
            {
                parent::__construct();
            }

            protected function copyStream($source, $destination): int|false
            {
                stream_copy_to_stream($source, $destination);

                return false;
            }

            protected function beforeTemporaryRetention(string $temporaryPath): void
            {
                unlink($temporaryPath);
                file_put_contents($temporaryPath, $this->replacement);
            }
        };

        [$exitCode, $output] = $this->runMigrationWithCommand($command, [$request->id]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('copy was incomplete or could not be flushed', $output);
        $this->assertStringContainsString('path identity differs or cannot be established', $output);
        $temporaryFiles = Storage::disk('local')->allFiles();
        $this->assertCount(1, $temporaryFiles);
        $this->assertSame($replacement, Storage::disk('local')->get($temporaryFiles[0]));
        $this->assertStringContainsString('path identity differs or cannot be established', $output);
        $this->assertSame([$request->proof_path], Storage::disk('public')->allFiles());
        $this->assertDatabaseHas('subscription_requests', ['id' => $request->id]);
        $this->assertSame(
            hash('sha256', self::PNG_CONTENT),
            hash('sha256', Storage::disk('public')->get($request->proof_path))
        );
    }

    public function test_destination_created_before_atomic_publication_is_never_overwritten(): void
    {
        $request = $this->createRequest();
        Storage::disk('public')->put($request->proof_path, self::PNG_CONTENT);
        $destinationContent = self::PNG_CONTENT.'concurrent destination';
        $beforeDatabase = $request->fresh()->getAttributes();
        $publicHash = hash('sha256', Storage::disk('public')->get($request->proof_path));
        $command = new class($destinationContent) extends \App\Console\Commands\MigrateLegacySubscriptionProofs
        {
            public function __construct(private string $destinationContent)
            {
                parent::__construct();
            }

            protected function publishWithoutOverwrite(string $temporaryPath, string $destinationPath): bool
            {
                file_put_contents($destinationPath, $this->destinationContent);

                return parent::publishWithoutOverwrite($temporaryPath, $destinationPath);
            }
        };

        [$exitCode, $output] = $this->runMigrationWithCommand($command, [$request->id]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('collision', $output);
        $this->assertSame(
            hash('sha256', $destinationContent),
            hash('sha256', Storage::disk('local')->get($request->proof_path))
        );
        $privateFiles = Storage::disk('local')->allFiles();
        $this->assertCount(2, $privateFiles);
        $this->assertContains($request->proof_path, $privateFiles);
        $this->assertCount(1, array_filter($privateFiles, fn (string $path): bool => str_contains($path, '.migration-')));
        $this->assertSame($publicHash, hash('sha256', Storage::disk('public')->get($request->proof_path)));
        $this->assertSame($beforeDatabase, $request->fresh()->getAttributes());
    }

    public function test_post_publication_verification_failure_leaves_concurrent_replacement_untouched(): void
    {
        $request = $this->createRequest();
        Storage::disk('public')->put($request->proof_path, self::PNG_CONTENT);
        $replacement = "\x89PNG\r\n\x1a\nconcurrent replacement";
        $beforeDatabase = $request->fresh()->getAttributes();
        $publicHash = hash('sha256', Storage::disk('public')->get($request->proof_path));
        $command = new class($replacement) extends \App\Console\Commands\MigrateLegacySubscriptionProofs
        {
            public function __construct(private string $replacement)
            {
                parent::__construct();
            }

            protected function afterPublish(string $destinationPath): void
            {
                unlink($destinationPath);
                file_put_contents($destinationPath, $this->replacement);
            }
        };

        [$exitCode, $output] = $this->runMigrationWithCommand($command, [$request->id]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('manual review required', $output);
        $this->assertStringContainsString('was left untouched', $output);
        $this->assertSame($replacement, Storage::disk('local')->get($request->proof_path));
        $privateFiles = Storage::disk('local')->allFiles();
        $this->assertCount(2, $privateFiles);
        $this->assertContains($request->proof_path, $privateFiles);
        $this->assertCount(1, array_filter($privateFiles, fn (string $path): bool => str_contains($path, '.migration-')));
        $this->assertSame($publicHash, hash('sha256', Storage::disk('public')->get($request->proof_path)));
        $this->assertSame($beforeDatabase, $request->fresh()->getAttributes());
    }

    public function test_atomic_publication_failure_preserves_private_temporary_and_public_files(): void
    {
        $request = $this->createRequest();
        Storage::disk('public')->put($request->proof_path, self::PNG_CONTENT);
        $command = new class extends \App\Console\Commands\MigrateLegacySubscriptionProofs
        {
            protected function publishWithoutOverwrite(string $temporaryPath, string $destinationPath): bool
            {
                return false;
            }
        };

        [$exitCode, $output] = $this->runMigrationWithCommand($command, [$request->id]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('atomic no-overwrite publication failed', $output);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertStringContainsString('.migration-', Storage::disk('local')->allFiles()[0]);
        $this->assertSame([$request->proof_path], Storage::disk('public')->allFiles());
    }

    public function test_reruns_are_idempotent_and_preserve_sources_records_and_existing_private_files(): void
    {
        $request = $this->createRequest();
        Storage::disk('public')->put($request->proof_path, self::PNG_CONTENT);
        $beforeDatabase = $request->fresh()->getAttributes();
        $publicHash = hash('sha256', Storage::disk('public')->get($request->proof_path));

        [$firstExitCode, $firstOutput] = $this->runMigration([$request->id]);
        [$secondExitCode, $secondOutput] = $this->runMigration([$request->id]);

        $this->assertSame(0, $firstExitCode, $firstOutput);
        $this->assertSame(0, $secondExitCode, $secondOutput);
        $this->assertStringContainsString('copied and verified', $firstOutput);
        $this->assertStringContainsString('Temporary artifact retained in private storage', $firstOutput);
        $this->assertStringContainsString('already verified', $secondOutput);
        $this->assertSame($beforeDatabase, $request->fresh()->getAttributes());
        $this->assertSame($publicHash, hash('sha256', Storage::disk('public')->get($request->proof_path)));
        $this->assertSame([$request->proof_path], Storage::disk('public')->allFiles());
        $privateFiles = Storage::disk('local')->allFiles();
        $this->assertCount(2, $privateFiles);
        $this->assertContains($request->proof_path, $privateFiles);
        $this->assertCount(1, array_filter($privateFiles, fn (string $path): bool => str_contains($path, '.migration-')));
    }

    public function test_explicit_opt_in_and_request_ids_are_required_and_production_is_rejected(): void
    {
        [$noOptInExit, $noOptInOutput] = $this->runMigration([], false);
        $this->assertSame(1, $noOptInExit);
        $this->assertStringContainsString('Copy not started.', $noOptInOutput);

        [$noIdExit, $noIdOutput] = $this->runMigration([]);
        $this->assertSame(1, $noIdExit);
        $this->assertStringContainsString('Provide one or more positive --request-id values.', $noIdOutput);

        $this->app->detectEnvironment(fn () => 'production');
        [$productionExit, $productionOutput] = $this->runMigration([1]);
        $this->assertSame(1, $productionExit);
        $this->assertStringContainsString('Refusing to migrate proofs in the production environment.', $productionOutput);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_orphan_files_are_not_processed_without_an_explicit_database_request_id(): void
    {
        Storage::disk('public')->put('shops/7/subscription-proofs/orphan.png', self::PNG_CONTENT);

        [$exitCode, $output] = $this->runMigration([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Orphan files are never processed.', $output);
        Storage::disk('public')->assertExists('shops/7/subscription-proofs/orphan.png');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_only_explicitly_selected_requests_are_processed(): void
    {
        $selected = $this->createRequest();
        $unselected = $this->createRequest();
        Storage::disk('public')->put($selected->proof_path, self::PNG_CONTENT);
        Storage::disk('public')->put($unselected->proof_path, self::PNG_CONTENT);
        Storage::disk('local')->put($unselected->proof_path, self::PNG_CONTENT);
        $unselectedPrivateHash = hash('sha256', Storage::disk('local')->get($unselected->proof_path));

        [$exitCode, $output] = $this->runMigration([$selected->id]);

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString("request={$selected->id}", $output);
        $this->assertStringNotContainsString("request={$unselected->id}", $output);
        Storage::disk('local')->assertExists($selected->proof_path);
        Storage::disk('public')->assertExists($selected->proof_path);
        Storage::disk('public')->assertExists($unselected->proof_path);
        $this->assertSame(
            $unselectedPrivateHash,
            hash('sha256', Storage::disk('local')->get($unselected->proof_path))
        );
        $privateFiles = Storage::disk('local')->allFiles();
        $this->assertContains($selected->proof_path, $privateFiles);
        $this->assertContains($unselected->proof_path, $privateFiles);
        $this->assertCount(3, $privateFiles);
        $this->assertCount(1, array_filter($privateFiles, fn (string $path): bool => str_contains($path, '.migration-')));
    }

    public function test_oversized_numeric_request_ids_are_rejected_without_selecting_any_request(): void
    {
        $request = $this->createRequest();
        Storage::disk('public')->put($request->proof_path, self::PNG_CONTENT);
        $beforeDatabase = $request->fresh()->getAttributes();
        $largerThanPhpIntMax = str_repeat('9', strlen((string) PHP_INT_MAX) + 1);
        $overflowingValueBasedOnAnExistingId = (string) $request->id.str_repeat('0', strlen((string) PHP_INT_MAX));

        foreach ([$largerThanPhpIntMax, $overflowingValueBasedOnAnExistingId] as $oversizedId) {
            [$exitCode, $output] = $this->runMigration([$oversizedId]);

            $this->assertSame(1, $exitCode, $output);
            $this->assertStringContainsString('Provide one or more positive --request-id values.', $output);
            $this->assertSame([], Storage::disk('local')->allFiles());
            $this->assertSame($beforeDatabase, $request->fresh()->getAttributes());
            Storage::disk('public')->assertExists($request->proof_path);
        }
    }

    private function runMigration(array $requestIds, bool $confirm = true): array
    {
        $parameters = ['--confirm-copy' => $confirm];
        foreach ($requestIds as $requestId) {
            $parameters['--request-id'][] = (string) $requestId;
        }

        $exitCode = Artisan::call('subscription-proofs:migrate', $parameters);

        return [$exitCode, Artisan::output()];
    }

    private function runMigrationWithCommand(
        \App\Console\Commands\MigrateLegacySubscriptionProofs $command,
        array $requestIds
    ): array {
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $parameters = ['--confirm-copy' => true];
        foreach ($requestIds as $requestId) {
            $parameters['--request-id'][] = (string) $requestId;
        }

        $exitCode = $tester->execute($parameters);

        return [$exitCode, $tester->getDisplay()];
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
            'proof_path' => $proofPath ?? "shops/{$shop->id}/subscription-proofs/proof-{$shop->id}.png",
            'status' => SubscriptionRequest::STATUS_PENDING,
        ]);
    }

    private function createShop(): Shop
    {
        $name = 'Migration Shop '.uniqid();

        return Shop::create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'subscription_plan' => 'starter',
            'is_active' => true,
        ]);
    }
}
