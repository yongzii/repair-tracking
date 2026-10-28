<?php

namespace App\Console\Commands;

use App\Models\SubscriptionRequest;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AuditSubscriptionProofs extends Command
{
    protected $signature = 'subscription-proofs:audit
        {--confirm-read-only : Confirm this read-only inventory is intentional}';

    protected $description = 'Read-only inventory of legacy public subscription payment proofs.';

    protected $help = 'Requires --confirm-read-only and refuses the production environment. Laravel filesystem abstractions may not distinguish an absent file from inaccessible remote storage; uncertain results are sent to manual review.';

    private const SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public function handle(): int
    {
        $environment = (string) app()->environment();
        $connection = (string) config('database.default', 'unknown');

        $this->line("Laravel environment: {$environment}");
        $this->line("Database connection: {$connection}");
        $this->line('Absence/orphan checks are definitive only for readable configured local disks; other disk types require manual review.');

        if (strtolower($environment) === 'production') {
            $this->components->error('Refusing to inventory in the production environment.');

            return self::FAILURE;
        }

        if (!$this->option('confirm-read-only')) {
            $this->components->error('Inventory not started. Re-run with --confirm-read-only after verifying the configured database and disks are controlled local resources.');

            return self::FAILURE;
        }

        $publicDisk = Storage::disk('public');
        $privateDisk = Storage::disk('local');
        $rows = [];
        $referencedPaths = [];
        $hasMalformedPaths = false;
        $counts = [
            'valid public copy candidate' => 0,
            'already-present private file' => 0,
            'missing public proof' => 0,
            'malformed/out-of-scope path' => 0,
            'destination collision' => 0,
            'orphaned legacy file' => 0,
            'manual review' => 0,
            'inspection error' => 0,
            'no proof path' => 0,
        ];

        SubscriptionRequest::query()
            ->select(['id', 'shop_id', 'proof_path'])
            ->orderBy('id')
            ->chunkById(200, function ($requests) use (
                $publicDisk,
                $privateDisk,
                &$rows,
                &$referencedPaths,
                &$hasMalformedPaths,
                &$counts
            ): void {
                foreach ($requests as $request) {
                    if ($request->proof_path === null) {
                        $counts['no proof path']++;
                        continue;
                    }

                    [$path, $pathIssue] = $this->validatedPath($request->proof_path, (int) $request->shop_id);

                    if ($pathIssue !== null) {
                        $hasMalformedPaths = true;
                        $counts['malformed/out-of-scope path']++;
                        $rows[] = $this->row(
                            'malformed/out-of-scope path',
                            (int) $request->id,
                            (int) $request->shop_id,
                            $pathIssue,
                            'not inspected',
                            'not inspected'
                        );
                        continue;
                    }

                    $referencedPaths[$path] = true;
                    $rows[] = $this->classifyRequest(
                        $request,
                        $path,
                        $publicDisk,
                        $privateDisk,
                        $counts
                    );
                }
            });

        $this->findOrphans($publicDisk, $referencedPaths, $hasMalformedPaths, $rows, $counts);

        $this->components->info('READ-ONLY inventory only. No database or filesystem changes are performed.');
        $this->table(
            ['Classification', 'Request ID', 'Shop ID', 'Relative path', 'Public file', 'Private file'],
            $rows
        );

        $this->newLine();
        $this->line('Summary:');
        foreach ($counts as $classification => $count) {
            $this->line(sprintf('  %s: %d', ucfirst($classification), $count));
        }

        return $counts['inspection error'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function classifyRequest(
        SubscriptionRequest $request,
        string $path,
        FilesystemAdapter $publicDisk,
        FilesystemAdapter $privateDisk,
        array &$counts
    ): array {
        try {
            $publicPresence = $this->filePresence('public', $publicDisk, $path);
            $privatePresence = $this->filePresence('local', $privateDisk, $path);

            if ($publicPresence === 'uncertain' || $privatePresence === 'uncertain') {
                return $this->manualReview(
                    'file absence or accessibility is uncertain',
                    $path,
                    (int) $request->id,
                    (int) $request->shop_id,
                    null,
                    null,
                    $counts
                );
            }

            $publicExists = $publicPresence === 'present';
            $privateExists = $privatePresence === 'present';

            if (!$publicExists && !$privateExists) {
                $counts['missing public proof']++;

                return $this->row(
                    'missing public proof',
                    (int) $request->id,
                    (int) $request->shop_id,
                    $path,
                    'missing',
                    'missing'
                );
            }

            $public = $publicExists ? $this->fileMetadata($publicDisk, $path) : null;
            $private = $privateExists ? $this->fileMetadata($privateDisk, $path) : null;

            if (($public !== null && $public['issue'] !== null)
                || ($private !== null && $private['issue'] !== null)
            ) {
                $details = implode('; ', array_filter([
                    $public['issue'] ?? null,
                    $private['issue'] ?? null,
                ]));

                return $this->inspectionError(
                    $details,
                    $path,
                    (int) $request->id,
                    (int) $request->shop_id,
                    $public,
                    $private,
                    $counts
                );
            }

            if ($public !== null && $private !== null) {
                if ($public['sha256'] !== $private['sha256']) {
                    $counts['destination collision']++;

                    return $this->row(
                        'destination collision',
                        (int) $request->id,
                        (int) $request->shop_id,
                        $path,
                        $this->metadataLabel($public),
                        $this->metadataLabel($private)
                    );
                }

                if (!$this->metadataMatches($public, $private)) {
                    return $this->manualReview(
                        'Matching checksum but file metadata differs',
                        $path,
                        (int) $request->id,
                        (int) $request->shop_id,
                        $public,
                        $private,
                        $counts
                    );
                }

                if (!$this->hasSupportedMime($public) || !$this->hasSupportedMime($private)) {
                    return $this->manualReview(
                        'MIME type is unavailable or unsupported',
                        $path,
                        (int) $request->id,
                        (int) $request->shop_id,
                        $public,
                        $private,
                        $counts
                    );
                }

                $counts['already-present private file']++;

                return $this->row(
                    'already-present private file',
                    (int) $request->id,
                    (int) $request->shop_id,
                    $path,
                    $this->metadataLabel($public),
                    $this->metadataLabel($private)
                );
            }

            if ($private !== null) {
                if (!$this->hasSupportedMime($private)) {
                    return $this->manualReview(
                        'Private file MIME type is unavailable or unsupported',
                        $path,
                        (int) $request->id,
                        (int) $request->shop_id,
                        null,
                        $private,
                        $counts
                    );
                }

                $counts['already-present private file']++;

                return $this->row(
                    'already-present private file',
                    (int) $request->id,
                    (int) $request->shop_id,
                    $path,
                    'missing',
                    $this->metadataLabel($private)
                );
            }

            if (!$this->hasSupportedMime($public)) {
                return $this->manualReview(
                    'Public file MIME type is unavailable or unsupported',
                    $path,
                    (int) $request->id,
                    (int) $request->shop_id,
                    $public,
                    null,
                    $counts
                );
            }

            $counts['valid public copy candidate']++;

            return $this->row(
                'valid public copy candidate',
                (int) $request->id,
                (int) $request->shop_id,
                $path,
                $this->metadataLabel($public),
                'missing'
            );
        } catch (Throwable $exception) {
            return $this->inspectionError(
                'filesystem operation failed (' . $exception::class . ')',
                $path,
                (int) $request->id,
                (int) $request->shop_id,
                null,
                null,
                $counts
            );
        }
    }

    private function findOrphans(
        FilesystemAdapter $publicDisk,
        array $referencedPaths,
        bool $hasMalformedPaths,
        array &$rows,
        array &$counts
    ): void {
        if (($this->diskDriver('public') ?? '') !== 'local') {
            $counts['manual review']++;
            $rows[] = $this->row(
                'manual review: configured public disk is not local; orphan status cannot be established',
                '-',
                '-',
                'shops',
                'not scanned',
                'not applicable'
            );

            return;
        }

        try {
            $files = $this->listLegacyProofFiles($publicDisk);
        } catch (Throwable $exception) {
            $counts['inspection error']++;
            $rows[] = $this->row(
                'inspection error',
                '-',
                '-',
                'shops',
                'could not safely enumerate local proof directories (' . $exception::class . ')',
                'not applicable'
            );

            return;
        }

        foreach ($files as $file) {
            if (!preg_match('/\Ashops\/([1-9][0-9]*)\/subscription-proofs\//', $file, $matches)) {
                $counts['inspection error']++;
                $rows[] = $this->row(
                    'inspection error',
                    '-',
                    '-',
                    'shops',
                    'unexpected path in proof-directory listing',
                    'not applicable'
                );
                continue;
            }

            $shopId = (int) $matches[1];
            [$path, $pathIssue] = $this->validatedPath($file, $shopId);

            if ($pathIssue !== null || $path !== $file) {
                $counts['inspection error']++;
                $rows[] = $this->row(
                    'inspection error',
                    '-',
                    $shopId,
                    $file,
                    'unexpected path in proof directory',
                    'not applicable'
                );
                continue;
            }

            if (isset($referencedPaths[$path])) {
                continue;
            }

            try {
                $metadata = $this->fileMetadata($publicDisk, $path);
            } catch (Throwable $exception) {
                $counts['inspection error']++;
                $rows[] = $this->row(
                    'inspection error',
                    '-',
                    $shopId,
                    $path,
                    'could not inspect orphan (' . $exception::class . ')',
                    'not applicable'
                );
                continue;
            }

            if ($metadata['issue'] !== null) {
                $counts['inspection error']++;
                $rows[] = $this->row(
                    'inspection error',
                    '-',
                    $shopId,
                    $path,
                    $metadata['issue'],
                    'not applicable'
                );
                continue;
            }

            if ($hasMalformedPaths) {
                $counts['manual review']++;
                $rows[] = $this->row(
                    'manual review: malformed database path may refer to this file',
                    '-',
                    $shopId,
                    $path,
                    $this->metadataLabel($metadata),
                    'not applicable'
                );
            } else {
                $counts['orphaned legacy file']++;
                $rows[] = $this->row(
                    'orphaned legacy file',
                    '-',
                    $shopId,
                    $path,
                    $this->metadataLabel($metadata),
                    'not applicable'
                );
            }
        }
    }

    private function listLegacyProofFiles(FilesystemAdapter $disk): array
    {
        $root = realpath($disk->path(''));
        if ($root === false || !is_dir($root) || !is_readable($root)) {
            throw new \RuntimeException('Configured public disk root is not safely readable.');
        }

        $shopsPath = $root . DIRECTORY_SEPARATOR . 'shops';
        $rootEntries = @scandir($root);
        if ($rootEntries === false) {
            throw new \RuntimeException('Configured public disk root cannot be listed.');
        }

        if (!in_array('shops', $rootEntries, true)) {
            return [];
        }

        if (is_link($shopsPath) || !is_dir($shopsPath)) {
            throw new \RuntimeException('The shops path is not a regular directory.');
        }

        $shopsRoot = realpath($shopsPath);
        if ($shopsRoot === false || !$this->isWithinRoot($shopsRoot, $root)) {
            throw new \RuntimeException('The shops directory resolves outside the configured public disk.');
        }

        $shopEntries = @scandir($shopsRoot);
        if ($shopEntries === false) {
            throw new \RuntimeException('The shops directory cannot be listed.');
        }

        $files = [];
        foreach ($shopEntries as $shopEntry) {
            if (!preg_match('/\A[1-9][0-9]*\z/', $shopEntry)) {
                continue;
            }

            $shopPath = $shopsRoot . DIRECTORY_SEPARATOR . $shopEntry;
            if (is_link($shopPath) || !is_dir($shopPath)) {
                throw new \RuntimeException('A shop directory is not a regular directory.');
            }

            $shopRoot = realpath($shopPath);
            if ($shopRoot === false || !$this->isWithinRoot($shopRoot, $root)) {
                throw new \RuntimeException('A shop directory resolves outside the configured public disk.');
            }

            $shopContents = @scandir($shopRoot);
            if ($shopContents === false) {
                throw new \RuntimeException('A shop directory cannot be listed.');
            }

            if (!in_array('subscription-proofs', $shopContents, true)) {
                continue;
            }

            $proofRootPath = $shopRoot . DIRECTORY_SEPARATOR . 'subscription-proofs';
            if (is_link($proofRootPath) || !is_dir($proofRootPath)) {
                throw new \RuntimeException('A subscription-proof path is not a regular directory.');
            }

            $proofRoot = realpath($proofRootPath);
            if ($proofRoot === false || !$this->isWithinRoot($proofRoot, $root)) {
                throw new \RuntimeException('A subscription-proof directory resolves outside the configured public disk.');
            }

            $files = array_merge(
                $files,
                $this->walkProofDirectory($proofRoot, $root, "shops/{$shopEntry}/subscription-proofs")
            );
        }

        sort($files, SORT_STRING);

        return $files;
    }

    private function walkProofDirectory(string $directory, string $root, string $relativeDirectory): array
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new \RuntimeException('A subscription-proof directory cannot be listed.');
        }

        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path)) {
                throw new \RuntimeException('A symlink was found inside a subscription-proof directory.');
            }

            $resolved = realpath($path);
            if ($resolved === false || !$this->isWithinRoot($resolved, $root)) {
                throw new \RuntimeException('A proof path could not be verified within the configured public disk.');
            }

            $relativePath = $relativeDirectory . '/' . $entry;
            if (is_dir($resolved)) {
                $files = array_merge($files, $this->walkProofDirectory($resolved, $root, $relativePath));
            } elseif (is_file($resolved)) {
                $files[] = $relativePath;
            } else {
                throw new \RuntimeException('A non-regular filesystem entry was found in a proof directory.');
            }
        }

        return $files;
    }

    private function filePresence(
        string $diskName,
        FilesystemAdapter $disk,
        string $path
    ): string {
        if ($this->diskDriver($diskName) !== 'local') {
            return $disk->exists($path) ? 'present' : 'uncertain';
        }

        $root = realpath($disk->path(''));
        if ($root === false || !is_dir($root) || !is_readable($root)) {
            return 'uncertain';
        }

        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $current = $root;
        $segments = explode('/', $path);

        foreach ($segments as $index => $segment) {
            if (!is_dir($current) || !is_readable($current)) {
                return 'uncertain';
            }

            $entries = @scandir($current);
            if ($entries === false) {
                return 'uncertain';
            }

            if (!in_array($segment, $entries, true)) {
                return 'absent';
            }

            $candidate = $current . DIRECTORY_SEPARATOR . $segment;
            $resolved = realpath($candidate);

            if ($resolved === false || !$this->isWithinRoot($resolved, $root)) {
                return 'uncertain';
            }

            if ($index === array_key_last($segments)) {
                return is_file($resolved) && is_readable($resolved) ? 'present' : 'uncertain';
            }

            if (!is_dir($resolved)) {
                return 'uncertain';
            }

            $current = $resolved;
        }

        return 'uncertain';
    }

    private function diskDriver(string $diskName): ?string
    {
        $driver = config("filesystems.disks.{$diskName}.driver");

        return is_string($driver) ? $driver : null;
    }

    private function isWithinRoot(string $path, string $root): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private function fileMetadata(FilesystemAdapter $disk, string $path): array
    {
        $stream = $disk->readStream($path);

        if (!is_resource($stream)) {
            return ['issue' => 'file is not readable'];
        }

        try {
            $context = hash_init('sha256');
            $bytesRead = hash_update_stream($context, $stream);

            if ($bytesRead === false) {
                return ['issue' => 'checksum could not be calculated'];
            }

            $sha256 = hash_final($context);
        } finally {
            fclose($stream);
        }

        $size = $disk->size($path);
        if (!is_int($size) || $size !== $bytesRead) {
            return ['issue' => 'reported size does not match bytes read'];
        }

        $mimeType = $disk->mimeType($path);

        return [
            'issue' => null,
            'size' => $size,
            'sha256' => $sha256,
            'mime' => is_string($mimeType) ? $mimeType : null,
        ];
    }

    private function validatedPath(mixed $storedPath, int $shopId): array
    {
        if (!is_string($storedPath) || $storedPath === '') {
            return [null, 'empty or invalid path'];
        }

        if (
            str_starts_with($storedPath, '/')
            || preg_match('/\A[A-Za-z]:/', $storedPath)
            || str_contains($storedPath, "\0")
            || str_contains($storedPath, '\\')
        ) {
            return [null, 'absolute or non-canonical path'];
        }

        $segments = explode('/', $storedPath);
        if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return [null, 'path traversal segment'];
        }

        if (in_array('', $segments, true)) {
            return [null, 'empty path segment'];
        }

        if (!preg_match('/\Ashops\/([1-9][0-9]*)\/subscription-proofs\/([^\/]+)\z/', $storedPath, $matches)) {
            return [null, 'outside expected subscription-proof path format'];
        }

        if ((int) $matches[1] !== $shopId) {
            return [null, 'shop directory does not match request'];
        }

        return [$storedPath, null];
    }

    private function metadataMatches(array $public, array $private): bool
    {
        return $public['size'] === $private['size']
            && $public['mime'] === $private['mime'];
    }

    private function hasSupportedMime(array $metadata): bool
    {
        return is_string($metadata['mime'])
            && in_array($metadata['mime'], self::SUPPORTED_MIME_TYPES, true);
    }

    private function metadataLabel(array $metadata): string
    {
        if (isset($metadata['issue'])) {
            return $metadata['issue'];
        }

        return sprintf(
            'size=%d; sha256=%s; mime=%s',
            $metadata['size'],
            $metadata['sha256'],
            $metadata['mime'] ?? 'unavailable'
        );
    }

    private function manualReview(
        string $reason,
        string $path,
        int $requestId,
        int $shopId,
        ?array $public,
        ?array $private,
        array &$counts
    ): array {
        $counts['manual review']++;

        return $this->row(
            "manual review: {$reason}",
            $requestId,
            $shopId,
            $path,
            $public ? $this->metadataLabel($public) : 'missing',
            $private ? $this->metadataLabel($private) : 'missing'
        );
    }

    private function inspectionError(
        string $details,
        string $path,
        int $requestId,
        int $shopId,
        ?array $public,
        ?array $private,
        array &$counts
    ): array {
        $counts['inspection error']++;

        return $this->row(
            "inspection error: {$details}",
            $requestId,
            $shopId,
            $path,
            $public ? $this->metadataLabel($public) : 'missing',
            $private ? $this->metadataLabel($private) : 'missing'
        );
    }

    private function row(
        string $classification,
        int|string $requestId,
        int|string $shopId,
        string $path,
        string $public,
        string $private
    ): array {
        $displayPath = preg_replace('/[\x00-\x1F\x7F]/', '?', $path) ?? '[invalid path]';

        return [$classification, (string) $requestId, (string) $shopId, $displayPath, $public, $private];
    }
}
