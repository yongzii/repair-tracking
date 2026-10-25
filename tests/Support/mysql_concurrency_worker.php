<?php

use App\Http\Controllers\Api\JobOrderController;
use App\Http\Controllers\Api\JobOrderPartOrderController;
use App\Http\Controllers\Api\PartRequestController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\TechnicianPortalController;
use App\Models\JobOrder;
use App\Models\PartRequest;
use App\Models\JobOrderRefund;
use App\Models\SubscriptionRequest;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[$script, $scenario, $recordId, $userId, $readyFile, $startedFile, $goFile, $resultFile, $sparePartId] = $argv;

try {
    if (
        ($_SERVER['APP_ENV'] ?? getenv('APP_ENV')) !== 'mysql-testing'
        || ($_SERVER['DB_CONNECTION'] ?? getenv('DB_CONNECTION')) !== 'mysql'
        || ($_SERVER['DB_HOST'] ?? getenv('DB_HOST')) !== '127.0.0.1'
        || ($_SERVER['DB_PORT'] ?? getenv('DB_PORT')) !== '3306'
        || ($_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE')) !== 'repair_tracking_test'
    ) {
        throw new RuntimeException('Refusing MySQL concurrency worker outside the dedicated local test database.');
    }

    $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (
        config('app.env') !== 'mysql-testing'
        || config('database.default') !== 'mysql'
        || config('database.connections.mysql.database') !== 'repair_tracking_test'
        || config('database.connections.mysql.host') !== '127.0.0.1'
        || (string) config('database.connections.mysql.port') !== '3306'
        || config('database.connections.mysql.url')
    ) {
        throw new RuntimeException('Refusing MySQL concurrency worker because the effective database configuration is unsafe.');
    }

    DB::connection()->getPdo();
    $user = User::findOrFail((int) $userId);
    $request = match ($scenario) {
        'subscription-approve' => Request::create('/api/platform/subscription-requests/' . $recordId . '/approve', 'POST', []),
        'pickup' => Request::create('/api/admin/job-orders/' . $recordId . '/mark-picked-up', 'POST', [
            'picked_up_by' => 'Synthetic Test Customer',
        ]),
        'rto-release' => Request::create('/api/admin/job-orders/' . $recordId . '/release-rto', 'POST', [
            'picked_up_by' => 'Synthetic Test Customer',
        ]),
        'rto-transition' => Request::create('/api/admin/job-orders/' . $recordId . '/update-status', 'POST', [
            'status' => 'RTO',
        ]),
        'refund-issue' => Request::create('/api/admin/job-orders/' . $recordId . '/refunds/' . $sparePartId . '/issue', 'POST', [
            'refund_method' => 'CASH',
        ]),
        'status-ready' => Request::create('/api/admin/job-orders/' . $recordId . '/update-status', 'POST', [
            'status' => 'READY_FOR_PICKUP',
        ]),
        'add-part' => Request::create('/api/technician/job-orders/' . $recordId . '/parts', 'POST', [
            'spare_part_id' => (int) $sparePartId,
            'quantity' => 1,
        ]),
        'create-part-request' => Request::create('/api/technician/part-requests', 'POST', json_decode($sparePartId, true, flags: JSON_THROW_ON_ERROR)),
        'create-job-part-order' => Request::create('/api/technician/job-orders/' . $recordId . '/part-orders', 'POST', json_decode($sparePartId, true, flags: JSON_THROW_ON_ERROR)),
        default => throw new InvalidArgumentException('Unknown MySQL concurrency scenario.'),
    };
    $request->setUserResolver(static fn () => $user);

    $connectionId = (int) DB::connection()->selectOne('SELECT CONNECTION_ID() AS id')->id;
    $databaseName = (string) DB::connection()->selectOne('SELECT DATABASE() AS name')->name;
    file_put_contents($readyFile, json_encode([
        'connection_id' => $connectionId,
        'database' => $databaseName,
    ], JSON_THROW_ON_ERROR));
    fwrite(STDOUT, "Worker ready on MySQL connection {$connectionId} using database {$databaseName}." . PHP_EOL);
    $deadline = microtime(true) + 15;
    while (!is_file($goFile) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (!is_file($goFile)) {
        throw new RuntimeException('Timed out waiting for concurrency-test start signal.');
    }
    touch($startedFile);

    $request->headers->set('Accept', 'application/json');
    try {
        $response = match ($scenario) {
            'subscription-approve' => app(PlatformController::class)->approveSubscriptionRequest(
                $request,
                SubscriptionRequest::findOrFail((int) $recordId)
            ),
            'pickup' => app(JobOrderController::class)->markPickedUp(
                $request,
                JobOrder::findOrFail((int) $recordId)
            ),
            'rto-release' => app(JobOrderController::class)->releaseRto(
                $request,
                JobOrder::findOrFail((int) $recordId)
            ),
            'rto-transition' => app(JobOrderController::class)->updateStatus(
                $request,
                JobOrder::findOrFail((int) $recordId)
            ),
            'refund-issue' => app(JobOrderController::class)->issueRtoRefund(
                $request,
                JobOrder::findOrFail((int) $recordId),
                JobOrderRefund::findOrFail((int) $sparePartId)
            ),
            'status-ready' => app(JobOrderController::class)->updateStatus(
                $request,
                JobOrder::findOrFail((int) $recordId)
            ),
            'add-part' => app(TechnicianPortalController::class)->addPart(
                $request,
                JobOrder::findOrFail((int) $recordId)
            ),
            'create-part-request' => app(PartRequestController::class)->store($request),
            'create-job-part-order' => app(JobOrderPartOrderController::class)->store(
                $request,
                JobOrder::findOrFail((int) $recordId)
            ),
        };
    } catch (Throwable $exception) {
        $response = app(ExceptionHandler::class)->render($request, $exception);
    }

    file_put_contents($resultFile, json_encode([
        'connection_id' => $connectionId,
        'database' => $databaseName,
        'status' => $response->getStatusCode(),
        'body' => $response->getContent(),
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'error' => get_class($exception) . ': ' . $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
