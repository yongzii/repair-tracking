<?php

use Illuminate\Contracts\Console\Kernel;

const CONFIRMATION_FLAG = '--confirm-disposable-filesystem-write';
const EXPECTED_PROJECT_ROOT = 'C:\\Users\\Admin\\repair-tracking';

function fail(string $message): never
{
    fwrite(STDERR, "FAILED: {$message}\n");
    exit(1);
}

function normalizedPath(string $path): string
{
    $path = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

    return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
}

function fileIdentity(array|false $stat): ?array
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

    return ['dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino']];
}

function identityAt(string $path): ?array
{
    clearstatcache(true, $path);

    return fileIdentity(@lstat($path));
}

function writeExclusive(string $path, string $contents, array &$createdFiles): array
{
    $stream = @fopen($path, 'x+b');
    if (! is_resource($stream)) {
        throw new RuntimeException('An exclusive disposable-file creation failed.');
    }

    $identity = fileIdentity(fstat($stream));
    if ($identity === null) {
        fclose($stream);
        fwrite(STDERR, 'CLEANUP REQUIRED: identity is unavailable for the exclusively created disposable file: '.$path."\n");
        throw new RuntimeException('The filesystem does not expose a usable identity for safe cleanup.');
    }
    $createdFiles[$path] = $identity;

    $written = 0;
    $length = strlen($contents);
    while ($written < $length) {
        $count = fwrite($stream, substr($contents, $written));
        if ($count === false || $count === 0) {
            fclose($stream);
            throw new RuntimeException('Writing a disposable test file failed.');
        }
        $written += $count;
    }

    if (! fflush($stream)) {
        fclose($stream);
        throw new RuntimeException('Flushing a disposable test file failed.');
    }

    fclose($stream);

    if (identityAt($path) !== $identity) {
        throw new RuntimeException('The disposable file identity changed after creation.');
    }

    return $identity;
}

function unlinkOwned(string $path, ?array $identity): bool
{
    if ($identity === null) {
        return false;
    }

    $currentIdentity = identityAt($path);
    if ($currentIdentity === null) {
        return false;
    }

    if ($currentIdentity !== $identity) {
        return false;
    }

    return @unlink($path);
}

if (! in_array(CONFIRMATION_FLAG, $argv, true)) {
    fail('Refusing to write files. Re-run with '.CONFIRMATION_FLAG.' after reviewing this script.');
}

$projectRoot = realpath(dirname(__DIR__));
if ($projectRoot === false || normalizedPath($projectRoot) !== normalizedPath(EXPECTED_PROJECT_ROOT)) {
    fail('The script is not running from the expected RepairSync Cloud project root.');
}

require $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
$app = require $projectRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
$app->make(Kernel::class)->bootstrap();

$environment = (string) $app->environment();
if (strtolower($environment) === 'production') {
    fail('Refusing to run in the production environment.');
}

$expectedPublicRoot = $projectRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public';
$expectedPrivateRoot = $projectRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private';
$publicConfig = config('filesystems.disks.public');
$privateConfig = config('filesystems.disks.local');

if (
    ! is_array($publicConfig)
    || ! is_array($privateConfig)
    || ($publicConfig['driver'] ?? null) !== 'local'
    || ($privateConfig['driver'] ?? null) !== 'local'
) {
    fail('Both configured proof disks must use the local driver.');
}

$publicRoot = realpath((string) ($publicConfig['root'] ?? ''));
$privateRoot = realpath((string) ($privateConfig['root'] ?? ''));
if (
    $publicRoot === false
    || $privateRoot === false
    || normalizedPath((string) ($publicConfig['root'] ?? '')) !== normalizedPath($expectedPublicRoot)
    || normalizedPath((string) ($privateConfig['root'] ?? '')) !== normalizedPath($expectedPrivateRoot)
    || normalizedPath($publicRoot) !== normalizedPath($expectedPublicRoot)
    || normalizedPath($privateRoot) !== normalizedPath($expectedPrivateRoot)
    || normalizedPath($publicRoot) === normalizedPath($privateRoot)
) {
    fail('Configured public/private storage roots are missing, redirected, or unexpected.');
}

if (! is_dir($privateRoot) || ! is_readable($privateRoot) || ! is_writable($privateRoot)) {
    fail('The configured private storage root is not readable and writable.');
}

$token = bin2hex(random_bytes(16));
$testDirectory = $privateRoot.DIRECTORY_SEPARATOR.'.subscription-proof-link-check-'.$token;
$sourcePath = $testDirectory.DIRECTORY_SEPARATOR.'source.bin';
$publishedPath = $testDirectory.DIRECTORY_SEPARATOR.'published.bin';
$existingPath = $testDirectory.DIRECTORY_SEPARATOR.'existing.bin';

foreach ([$testDirectory, $sourcePath, $publishedPath, $existingPath] as $path) {
    if (file_exists($path) || is_link($path)) {
        fail('A unique disposable path unexpectedly exists; refusing to continue.');
    }
}

if (! @mkdir($testDirectory, 0700)) {
    fail('Could not exclusively create the unique disposable test directory.');
}
$directoryIdentity = identityAt($testDirectory);
if ($directoryIdentity === null || is_link($testDirectory)) {
    fwrite(STDERR, 'CLEANUP REQUIRED: the created disposable directory identity could not be established: '.$testDirectory."\n");
    fail('The disposable test directory identity could not be established safely.');
}

$createdFiles = [];
$testError = null;
$results = [];

try {
    $sourceContents = "RepairSync disposable hard-link verification\n".bin2hex(random_bytes(24));
    $existingContents = "Existing destination sentinel\n".bin2hex(random_bytes(24));

    writeExclusive($sourcePath, $sourceContents, $createdFiles);
    writeExclusive($existingPath, $existingContents, $createdFiles);

    $sourceHash = hash_file('sha256', $sourcePath);
    $existingHash = hash_file('sha256', $existingPath);
    if (! is_string($sourceHash) || ! is_string($existingHash)) {
        throw new RuntimeException('A disposable-file hash could not be calculated.');
    }

    if (! @link($sourcePath, $publishedPath)) {
        throw new RuntimeException('PHP link() could not publish a hard link on the private storage volume.');
    }
    $createdFiles[$publishedPath] = $createdFiles[$sourcePath];

    $publishedIdentity = identityAt($publishedPath);
    $publishedHash = hash_file('sha256', $publishedPath);
    if (
        $publishedIdentity !== $createdFiles[$sourcePath]
        || $publishedHash !== $sourceHash
    ) {
        throw new RuntimeException('The published hard link identity or SHA-256 did not match its source.');
    }
    $results[] = 'PASS: link() succeeded; source and published destination share identity and SHA-256.';

    if (@link($sourcePath, $existingPath)) {
        throw new RuntimeException('link() unexpectedly succeeded for an existing destination.');
    }

    if (
        identityAt($existingPath) !== $createdFiles[$existingPath]
        || hash_file('sha256', $existingPath) !== $existingHash
    ) {
        throw new RuntimeException('The existing destination changed during the no-overwrite check.');
    }
    $results[] = 'PASS: link() failed for an existing destination and its identity/hash remained unchanged.';
} catch (Throwable $exception) {
    $testError = $exception->getMessage();
} finally {
    $cleanupComplete = true;
    foreach ([$publishedPath, $existingPath, $sourcePath] as $path) {
        if (array_key_exists($path, $createdFiles) && ! unlinkOwned($path, $createdFiles[$path])) {
            $cleanupComplete = false;
            fwrite(STDERR, 'CLEANUP REQUIRED: an owned disposable path could not be safely removed: '.$path."\n");
        }
    }

    clearstatcache(true, $testDirectory);
    $currentDirectoryIdentity = identityAt($testDirectory);
    $directoryEntries = is_dir($testDirectory) ? @scandir($testDirectory) : false;
    if (
        $currentDirectoryIdentity === $directoryIdentity
        && is_array($directoryEntries)
        && $directoryEntries === ['.', '..']
    ) {
        if (! @rmdir($testDirectory)) {
            $cleanupComplete = false;
            fwrite(STDERR, 'CLEANUP REQUIRED: the empty disposable directory could not be removed: '.$testDirectory."\n");
        }
    } else {
        $cleanupComplete = false;
        fwrite(STDERR, 'CLEANUP REQUIRED: the disposable directory was changed or contains unexpected entries: '.$testDirectory."\n");
    }

    if (! $cleanupComplete && $testError === null) {
        $testError = 'Disposable cleanup could not be safely completed.';
    }
}

if ($testError !== null) {
    fail($testError);
}

fwrite(STDOUT, "Environment: {$environment}\n");
fwrite(STDOUT, "Public disk root (validated; not accessed for files): {$publicRoot}\n");
fwrite(STDOUT, "Private disk root (disposable files only): {$privateRoot}\n");
foreach ($results as $result) {
    fwrite(STDOUT, $result."\n");
}
fwrite(STDOUT, "PASS: only the uniquely named disposable private directory was removed.\n");
