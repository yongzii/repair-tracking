<?php

namespace App\Console\Commands;

use App\Models\SubscriptionRequest;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MigrateLegacySubscriptionProofs extends Command
{
    protected $signature = 'subscription-proofs:migrate
        {--confirm-copy : Confirm copying selected legacy proofs to private storage}
        {--request-id=* : Subscription request ID to revalidate and copy; may be repeated}';

    protected $description = 'Copy selected legacy subscription proofs to private storage without overwriting files.';

    protected $help = 'Requires --confirm-copy and one or more --request-id options. Refuses production. Only local filesystem disks are supported; public originals and database records are never changed. Temporary files are retained because portable PHP cannot safely unlink a path without a replacement race; review and clean them manually.';

    private const MAX_UPLOAD_BYTES = 4096 * 1024;

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

        if (strtolower($environment) === 'production') {
            $this->components->error('Refusing to migrate proofs in the production environment.');

            return self::FAILURE;
        }

        if (! $this->option('confirm-copy')) {
            $this->components->error('Copy not started. Verify the configured database and disks, then provide --confirm-copy.');

            return self::FAILURE;
        }

        $requestIds = $this->validatedRequestIds($this->option('request-id'));
        if ($requestIds === null || $requestIds === []) {
            $this->components->error('Provide one or more positive --request-id values. Orphan files are never processed.');

            return self::FAILURE;
        }

        if ($this->diskDriver('public') !== 'local' || $this->diskDriver('local') !== 'local') {
            $this->components->error('Refusing to copy unless both public and local disks use the local driver.');

            return self::FAILURE;
        }

        $publicDisk = Storage::disk('public');
        $privateDisk = Storage::disk('local');
        $failed = false;

        foreach ($requestIds as $requestId) {
            try {
                $request = SubscriptionRequest::query()
                    ->select(['id', 'shop_id', 'proof_path'])
                    ->whereKey($requestId)
                    ->first();

                if (! $request) {
                    $this->reportResult('not found', $requestId, '-', 'request does not exist');
                    $failed = true;

                    continue;
                }

                [$path, $pathIssue] = $this->validatedPath($request->proof_path, (int) $request->shop_id);
                if ($pathIssue !== null) {
                    $this->reportResult('rejected', $requestId, $path ?? '-', $pathIssue);
                    $failed = true;

                    continue;
                }

                $sourcePath = $this->resolveLocalFile($publicDisk, $path);
                if ($sourcePath === null) {
                    $this->reportResult('failed', $requestId, $path, 'source is missing, inaccessible, or not a regular file');
                    $failed = true;

                    continue;
                }

                $sourceMetadata = $this->fileMetadata($sourcePath);
                if ($sourceMetadata['issue'] !== null) {
                    $this->reportResult('failed', $requestId, $path, $sourceMetadata['issue']);
                    $failed = true;

                    continue;
                }

                if (! $this->hasSupportedMime($sourceMetadata['mime'])) {
                    $this->reportResult('rejected', $requestId, $path, 'unsupported source MIME type');
                    $failed = true;

                    continue;
                }

                if ($sourceMetadata['size'] > self::MAX_UPLOAD_BYTES) {
                    $this->reportResult('rejected', $requestId, $path, 'source exceeds the 4096 KB upload limit');
                    $failed = true;

                    continue;
                }

                $destination = $this->destinationStatus($privateDisk, $path);
                if ($destination['status'] === 'uncertain') {
                    $this->reportResult('failed', $requestId, $path, 'destination presence or accessibility is uncertain');
                    $failed = true;

                    continue;
                }

                if ($destination['status'] === 'present') {
                    $existingMetadata = $this->fileMetadata($destination['path']);
                    if ($existingMetadata['issue'] !== null) {
                        $this->reportResult('failed', $requestId, $path, 'existing destination could not be verified');
                        $failed = true;
                    } elseif ($this->metadataMatches($sourceMetadata, $existingMetadata)) {
                        $this->reportResult('already verified', $requestId, $path, $this->metadataSummary($sourceMetadata));
                    } else {
                        $this->reportResult('collision', $requestId, $path, 'existing destination differs from source');
                        $failed = true;
                    }

                    continue;
                }

                $result = $this->copyWithoutOverwrite(
                    $publicDisk,
                    $privateDisk,
                    $path,
                    $sourcePath,
                    $sourceMetadata
                );

                $this->reportResult(
                    $result['status'],
                    $requestId,
                    $path,
                    $result['details']
                );

                if ($result['status'] !== 'copied and verified' && $result['status'] !== 'already verified') {
                    $failed = true;
                }
            } catch (Throwable $exception) {
                $this->reportResult(
                    'failed',
                    $requestId,
                    '-',
                    'operation failed ('.$exception::class.')'
                );
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function copyWithoutOverwrite(
        FilesystemAdapter $publicDisk,
        FilesystemAdapter $privateDisk,
        string $relativePath,
        string $sourcePath,
        array $sourceMetadata
    ): array {
        $privateRoot = realpath($privateDisk->path(''));
        if ($privateRoot === false || ! is_dir($privateRoot) || ! is_writable($privateRoot)) {
            return ['status' => 'failed', 'details' => 'private storage root is unavailable or not writable'];
        }

        $parent = $this->ensureDestinationDirectory(
            $privateRoot,
            dirname(str_replace('/', DIRECTORY_SEPARATOR, $relativePath))
        );
        if ($parent === null) {
            return ['status' => 'failed', 'details' => 'destination directory could not be safely prepared'];
        }

        $destinationPath = $parent.DIRECTORY_SEPARATOR.basename($relativePath);
        $temporaryPath = $parent.DIRECTORY_SEPARATOR.'.'.basename($relativePath)
            .'.migration-'.bin2hex(random_bytes(12)).'.tmp';
        $sourceStream = null;
        $temporaryStream = null;
        $temporaryCreated = false;
        $temporaryIdentity = null;

        try {
            $sourceStream = @fopen($sourcePath, 'rb');
            if (! is_resource($sourceStream)) {
                return ['status' => 'failed', 'details' => 'source could not be opened for reading'];
            }

            $temporaryStream = $this->openTemporaryFile($temporaryPath);
            if (! is_resource($temporaryStream)) {
                return ['status' => 'failed', 'details' => 'exclusive temporary destination creation failed'];
            }
            $temporaryCreated = true;
            $temporaryIdentity = $this->fileIdentity(fstat($temporaryStream));
            if ($temporaryIdentity === null) {
                return ['status' => 'failed', 'details' => 'temporary file ownership could not be safely established'];
            }

            $copiedBytes = $this->copyStream($sourceStream, $temporaryStream);
            if ($copiedBytes === false || $copiedBytes !== $sourceMetadata['size'] || ! fflush($temporaryStream)) {
                return ['status' => 'failed', 'details' => 'copy was incomplete or could not be flushed'];
            }

            fclose($temporaryStream);
            $temporaryStream = null;
            fclose($sourceStream);
            $sourceStream = null;

            $temporaryMetadata = $this->fileMetadata($temporaryPath);
            if (! $this->metadataMatches($sourceMetadata, $temporaryMetadata)) {
                return ['status' => 'failed', 'details' => 'temporary copy verification did not match source'];
            }

            if (! $this->publishWithoutOverwrite($temporaryPath, $destinationPath)) {
                $destination = $this->destinationStatus($privateDisk, $relativePath);
                if ($destination['status'] === 'present') {
                    $existingMetadata = $this->fileMetadata($destination['path']);
                    if ($this->metadataMatches($sourceMetadata, $existingMetadata)) {
                        return [
                            'status' => 'already verified',
                            'details' => $this->metadataSummary($sourceMetadata),
                        ];
                    }

                    return ['status' => 'collision', 'details' => 'destination appeared during copy and differs from source'];
                }

                return ['status' => 'failed', 'details' => 'atomic no-overwrite publication failed'];
            }

            try {
                $this->afterPublish($destinationPath);
                $destination = $this->resolveLocalFile($privateDisk, $relativePath);
                $destinationMetadata = $destination === null
                    ? ['issue' => 'published destination could not be safely reopened']
                    : $this->fileMetadata($destination);
            } catch (Throwable) {
                return [
                    'status' => 'manual review required',
                    'details' => 'published destination could not be verified and was left untouched',
                ];
            }

            if ($destination === null) {
                return [
                    'status' => 'manual review required',
                    'details' => 'published destination could not be verified and was left untouched',
                ];
            }

            if (! $this->metadataMatches($sourceMetadata, $destinationMetadata)) {
                return [
                    'status' => 'manual review required',
                    'details' => 'published destination verification did not match source and was left untouched',
                ];
            }

            return [
                'status' => 'copied and verified',
                'details' => $this->metadataSummary($destinationMetadata),
            ];
        } finally {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }
            if (is_resource($temporaryStream)) {
                fclose($temporaryStream);
            }
            if ($temporaryCreated) {
                $this->beforeTemporaryRetention($temporaryPath);
                $currentIdentity = $this->fileIdentity(@lstat($temporaryPath));
                $ownership = $temporaryIdentity !== null && $currentIdentity === $temporaryIdentity
                    ? 'created file identity still matches'
                    : 'path identity differs or cannot be established';
                $this->components->warn(
                    'Temporary artifact retained in private storage ('.$ownership
                    .'); automatic deletion is disabled to avoid a replacement race: '.basename($temporaryPath)
                );
            }
        }
    }

    private function ensureDestinationDirectory(string $root, string $relativeDirectory): ?string
    {
        $current = $root;
        foreach (array_filter(explode(DIRECTORY_SEPARATOR, $relativeDirectory), 'strlen') as $segment) {
            $candidate = $current.DIRECTORY_SEPARATOR.$segment;
            if (is_link($candidate)) {
                return null;
            }

            if (! file_exists($candidate) && ! @mkdir($candidate, 0775)) {
                if (! is_dir($candidate) || is_link($candidate)) {
                    return null;
                }
            }

            $resolved = realpath($candidate);
            if ($resolved === false || ! $this->isWithinRoot($resolved, $root) || ! is_dir($resolved)) {
                return null;
            }

            $current = $resolved;
        }

        return $current;
    }

    protected function openTemporaryFile(string $temporaryPath)
    {
        return @fopen($temporaryPath, 'x+b');
    }

    protected function copyStream($source, $destination): int|false
    {
        return stream_copy_to_stream($source, $destination);
    }

    protected function publishWithoutOverwrite(string $temporaryPath, string $destinationPath): bool
    {
        return @link($temporaryPath, $destinationPath);
    }

    protected function afterPublish(string $destinationPath): void {}

    protected function beforeTemporaryRetention(string $temporaryPath): void {}

    private function fileIdentity(array|false $stat): ?array
    {
        if (
            $stat === false
            || ! isset($stat['dev'], $stat['ino'])
            || ! is_numeric($stat['dev'])
            || ! is_numeric($stat['ino'])
            || (int) $stat['ino'] === 0
        ) {
            return null;
        }

        return [
            'dev' => (int) $stat['dev'],
            'ino' => (int) $stat['ino'],
        ];
    }

    private function destinationStatus(FilesystemAdapter $disk, string $path): array
    {
        $root = realpath($disk->path(''));
        if ($root === false || ! is_dir($root) || ! is_readable($root)) {
            return ['status' => 'uncertain', 'path' => null];
        }

        $current = $root;
        $segments = explode('/', $path);
        foreach ($segments as $index => $segment) {
            if (! is_dir($current) || ! is_readable($current)) {
                return ['status' => 'uncertain', 'path' => null];
            }

            $entries = @scandir($current);
            if ($entries === false) {
                return ['status' => 'uncertain', 'path' => null];
            }
            if (! in_array($segment, $entries, true)) {
                return ['status' => 'absent', 'path' => null];
            }

            $candidate = $current.DIRECTORY_SEPARATOR.$segment;
            if (is_link($candidate)) {
                return ['status' => 'uncertain', 'path' => null];
            }

            $resolved = realpath($candidate);
            if ($resolved === false || ! $this->isWithinRoot($resolved, $root)) {
                return ['status' => 'uncertain', 'path' => null];
            }

            if ($index === array_key_last($segments)) {
                return is_file($resolved) && is_readable($resolved)
                    ? ['status' => 'present', 'path' => $resolved]
                    : ['status' => 'uncertain', 'path' => null];
            }
            if (! is_dir($resolved)) {
                return ['status' => 'uncertain', 'path' => null];
            }

            $current = $resolved;
        }

        return ['status' => 'uncertain', 'path' => null];
    }

    private function resolveLocalFile(FilesystemAdapter $disk, string $path): ?string
    {
        $root = realpath($disk->path(''));
        if ($root === false || ! is_dir($root) || ! is_readable($root)) {
            return null;
        }

        $current = $root;
        $segments = explode('/', $path);
        foreach ($segments as $index => $segment) {
            $candidate = $current.DIRECTORY_SEPARATOR.$segment;
            if (is_link($candidate)) {
                return null;
            }

            $resolved = realpath($candidate);
            if ($resolved === false || ! $this->isWithinRoot($resolved, $root)) {
                return null;
            }

            if ($index === array_key_last($segments)) {
                return is_file($resolved) && is_readable($resolved) ? $resolved : null;
            }
            if (! is_dir($resolved) || ! is_readable($resolved)) {
                return null;
            }

            $current = $resolved;
        }

        return null;
    }

    private function fileMetadata(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return ['issue' => 'file is not readable'];
        }

        $size = filesize($path);
        if (! is_int($size)) {
            return ['issue' => 'file size could not be verified'];
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            return ['issue' => 'file exceeds the 4096 KB upload limit'];
        }

        $sha256 = hash_file('sha256', $path);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        if (! is_string($sha256) || ! is_string($mime)) {
            return ['issue' => 'file metadata or checksum could not be verified'];
        }

        return [
            'issue' => null,
            'size' => $size,
            'sha256' => $sha256,
            'mime' => $mime,
        ];
    }

    private function validatedPath(mixed $path, int $shopId): array
    {
        if (! is_string($path) || $path === '') {
            return [null, 'proof path is empty or invalid'];
        }

        if (
            str_starts_with($path, '/')
            || preg_match('/\A[A-Za-z]:/', $path)
            || str_contains($path, '\\')
            || str_contains($path, "\0")
            || preg_match('/[\x00-\x1F\x7F]/', $path)
        ) {
            return [null, 'proof path is absolute or non-canonical'];
        }

        $segments = explode('/', $path);
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return [null, 'proof path contains an invalid segment'];
        }

        if (! preg_match('/\Ashops\/([1-9][0-9]*)\/subscription-proofs\/([A-Za-z0-9._-]+)\z/', $path, $matches)) {
            return [null, 'proof path is outside the expected format'];
        }

        if ((int) $matches[1] !== $shopId) {
            return [null, 'proof path shop does not match request ownership'];
        }

        return [$path, null];
    }

    private function metadataMatches(array $expected, array $actual): bool
    {
        return ($actual['issue'] ?? null) === null
            && $expected['size'] === $actual['size']
            && hash_equals($expected['sha256'], $actual['sha256'])
            && $expected['mime'] === $actual['mime']
            && $this->hasSupportedMime($actual['mime']);
    }

    private function hasSupportedMime(?string $mime): bool
    {
        return $mime !== null && in_array($mime, self::SUPPORTED_MIME_TYPES, true);
    }

    private function validatedRequestIds(mixed $ids): ?array
    {
        if (! is_array($ids)) {
            return null;
        }

        $validated = [];
        foreach ($ids as $id) {
            if (! is_string($id) || ! preg_match('/\A[1-9][0-9]*\z/', $id)) {
                return null;
            }

            $maxInteger = (string) PHP_INT_MAX;
            if (
                strlen($id) > strlen($maxInteger)
                || (strlen($id) === strlen($maxInteger) && strcmp($id, $maxInteger) > 0)
            ) {
                return null;
            }

            $validated[] = (int) $id;
        }

        return array_values(array_unique($validated));
    }

    private function diskDriver(string $disk): ?string
    {
        $driver = config("filesystems.disks.{$disk}.driver");

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

        return str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }

    private function metadataSummary(array $metadata): string
    {
        return sprintf(
            'size=%d; sha256=%s; mime=%s',
            $metadata['size'],
            $metadata['sha256'],
            $metadata['mime']
        );
    }

    private function reportResult(string $status, int $requestId, string $path, string $details): void
    {
        $safePath = preg_replace('/[\x00-\x1F\x7F]/', '?', $path) ?? '[invalid path]';
        $this->line(sprintf(
            '%s: request=%d path=%s %s',
            $status,
            $requestId,
            $safePath,
            $details
        ));
    }
}
