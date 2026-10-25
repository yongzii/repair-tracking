<?php

namespace Tests\MySql;

use App\Models\JobOrder;
use App\Models\JobOrderPayment;
use App\Models\JobOrderPartOrder;
use App\Models\JobOrderPart;
use App\Models\JobOrderRefund;
use App\Models\AuditLog;
use App\Models\PartRequest;
use App\Models\PlatformSubscriptionPayment;
use App\Models\Shop;
use App\Models\SparePart;
use App\Models\StatusHistory;
use App\Models\SubscriptionRequest;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WorkflowConcurrencyTest extends TestCase
{
    private array $shopIds = [];

    private array $userIds = [];

    private array $jobOrderIds = [];

    private array $subscriptionRequestIds = [];

    private array $sparePartIds = [];

    public function test_two_concurrent_subscription_approvals_finalize_once(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser(User::ROLE_PLATFORM_ADMIN);
        $subscriptionRequest = SubscriptionRequest::create([
            'shop_id' => $shop->id,
            'requested_by_id' => null,
            'plan_key' => 'monthly',
            'plan_name' => 'Monthly',
            'amount' => 1500,
            'duration_months' => 1,
            'payment_method_key' => 'gcash',
            'payment_method_name' => 'GCash',
            'reference_number' => 'MYSQL-' . Str::upper(Str::random(10)),
            'status' => SubscriptionRequest::STATUS_PENDING,
        ]);
        $this->subscriptionRequestIds[] = $subscriptionRequest->id;

        $responses = $this->runContenders(
            'subscription-approve',
            'subscription_requests',
            $subscriptionRequest->id,
            $admin->id,
            $admin->id
        );

        $this->assertSortedStatuses($responses, [200, 422]);
        $this->assertSame(SubscriptionRequest::STATUS_APPROVED, $subscriptionRequest->fresh()->status);
        $this->assertSame(1, PlatformSubscriptionPayment::where('shop_id', $shop->id)->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'platform.subscription_request.approved')
            ->where('record_id', $subscriptionRequest->id)
            ->count());
    }

    public function test_two_concurrent_pickups_release_a_ready_job_only_once(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser(User::ROLE_ADMIN, $shop);
        $jobOrder = $this->createJobOrder($shop, 'READY_FOR_PICKUP');

        $responses = $this->runContenders('pickup', 'job_orders', $jobOrder->id, $admin->id, $admin->id);

        $this->assertSortedStatuses($responses, [200, 422]);
        $this->assertSame('PICKED_UP', $jobOrder->fresh()->current_status);
        $this->assertNotNull($jobOrder->fresh()->picked_up_at);
        $this->assertSame(1, StatusHistory::where('job_order_id', $jobOrder->id)->where('status', 'PICKED_UP')->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'job_order.picked_up')
            ->where('record_id', $jobOrder->id)
            ->count());
    }

    public function test_two_concurrent_rto_releases_release_an_rto_job_only_once(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser(User::ROLE_ADMIN, $shop);
        $jobOrder = $this->createJobOrder($shop, 'RTO');

        $responses = $this->runContenders('rto-release', 'job_orders', $jobOrder->id, $admin->id, $admin->id);

        $this->assertSortedStatuses($responses, [200, 422]);
        $this->assertSame('RTO_RELEASED', $jobOrder->fresh()->current_status);
        $this->assertNotNull($jobOrder->fresh()->picked_up_at);
        $this->assertSame(1, StatusHistory::where('job_order_id', $jobOrder->id)->where('status', 'RTO_RELEASED')->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'job_order.rto_released')
            ->where('record_id', $jobOrder->id)
            ->count());
    }

    public function test_concurrent_rto_transitions_create_refund_obligations_once(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser(User::ROLE_ADMIN, $shop);
        $jobOrder = $this->createJobOrder($shop, 'IN_PROGRESS');
        $jobOrder->update(['total_cost' => 1000, 'amount_paid' => 300, 'payment_status' => 'PARTIAL']);
        $payment = JobOrderPayment::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'idempotency_key' => (string) Str::uuid(),
            'amount' => 300,
            'payment_type' => 'PARTIAL_PAYMENT',
            'payment_method' => 'CASH',
            'received_at' => now(),
            'received_by' => $admin->name,
        ]);

        $responses = $this->runContenders('rto-transition', 'job_orders', $jobOrder->id, $admin->id, $admin->id);

        $this->assertSame(200, $responses['first']['status']);
        $this->assertSame(200, $responses['second']['status']);
        $this->assertSame('RTO', $jobOrder->fresh()->current_status);
        $this->assertSame(1, StatusHistory::where('job_order_id', $jobOrder->id)->where('status', 'RTO')->count());
        $this->assertSame(1, $jobOrder->refunds()->count());
        $this->assertSame((int) $payment->id, (int) $jobOrder->refunds()->sole()->job_order_payment_id);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'job_order.rto_refund_obligation_created')
            ->where('record_id', $jobOrder->id)
            ->count());
    }

    public function test_concurrent_refund_issuance_records_one_issuance_only(): void
    {
        $shop = $this->createShop();
        $firstAdmin = $this->createUser(User::ROLE_ADMIN, $shop);
        $secondAdmin = $this->createUser(User::ROLE_ADMIN, $shop);
        $jobOrder = $this->createJobOrder($shop, 'RTO');
        $payment = JobOrderPayment::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'idempotency_key' => (string) Str::uuid(),
            'amount' => 300,
            'payment_type' => 'PARTIAL_PAYMENT',
            'payment_method' => 'CASH',
            'received_at' => now(),
            'received_by' => $firstAdmin->name,
        ]);
        $refund = JobOrderRefund::create([
            'shop_id' => $shop->id,
            'job_order_id' => $jobOrder->id,
            'job_order_payment_id' => $payment->id,
            'amount' => 300,
            'status' => JobOrderRefund::STATUS_PENDING,
            'reason' => 'RTO / Repair unsuccessful',
            'initiated_by' => $firstAdmin->id,
            'initiated_at' => now(),
        ]);

        $responses = $this->runContenders(
            'refund-issue',
            'job_orders',
            $jobOrder->id,
            $firstAdmin->id,
            $secondAdmin->id,
            $refund->id
        );

        $this->assertSortedStatuses($responses, [200, 422]);
        $this->assertSame(JobOrderRefund::STATUS_ISSUED, $refund->fresh()->status);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'job_order.rto_refund_issued')
            ->where('record_id', $jobOrder->id)
            ->count());
    }

    public function test_concurrent_technician_part_add_and_ready_transition_are_serialized(): void
    {
        $shop = $this->createShop();
        $admin = $this->createUser(User::ROLE_ADMIN, $shop);
        $technicianUser = $this->createUser(User::ROLE_TECHNICIAN, $shop);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => 'MySQL Concurrency Technician',
            'email' => $technicianUser->email,
            'is_active' => true,
        ]);
        $jobOrder = $this->createJobOrder($shop, 'IN_PROGRESS');
        $jobOrder->update([
            'technician_id' => $technician->id,
            'repair_approved_at' => now(),
        ]);
        $sparePart = SparePart::create([
            'shop_id' => $shop->id,
            'name' => 'MySQL Concurrency Part ' . Str::random(8),
            'part_number' => 'MYSQL-' . Str::upper(Str::random(10)),
            'stock_quantity' => 5,
            'unit_cost' => 25,
            'is_active' => true,
        ]);
        $this->sparePartIds[] = $sparePart->id;

        $responses = $this->runContenders(
            ['first' => 'status-ready', 'second' => 'add-part'],
            'job_orders',
            $jobOrder->id,
            $admin->id,
            $technicianUser->id,
            $sparePart->id
        );

        $this->assertSame(200, $responses['first']['status']);
        $this->assertContains($responses['second']['status'], [201, 422]);
        $this->assertSame('READY_FOR_PICKUP', $jobOrder->fresh()->current_status);

        if ($responses['second']['status'] === 201) {
            $this->assertSame(1, JobOrderPart::where('job_order_id', $jobOrder->id)->count());
            $this->assertSame(4, $sparePart->fresh()->stock_quantity);
            $this->assertSame(25.0, (float) $jobOrder->fresh()->total_cost);
        } else {
            $this->assertSame(0, JobOrderPart::where('job_order_id', $jobOrder->id)->count());
            $this->assertSame(5, $sparePart->fresh()->stock_quantity);
            $this->assertSame(0.0, (float) $jobOrder->fresh()->total_cost);
        }
    }

    public function test_concurrent_general_part_requests_with_the_same_key_create_once(): void
    {
        $shop = $this->createShop();
        $firstTechnician = $this->createTechnicianUser($shop, 'First General Technician');
        $secondTechnician = $this->createTechnicianUser($shop, 'Second General Technician');
        $key = (string) Str::uuid();
        $payload = [
            'part_name' => 'Concurrent LCD',
            'quantity' => 2,
            'note' => 'Concurrent request.',
            'idempotency_key' => $key,
        ];

        $responses = $this->runContenders(
            'create-part-request',
            'shops',
            $shop->id,
            $firstTechnician->id,
            $secondTechnician->id,
            json_encode($payload, JSON_THROW_ON_ERROR),
            false,
            null,
            false,
        );

        $this->assertSortedStatuses($responses, [200, 201]);
        $request = PartRequest::where('shop_id', $shop->id)->where('idempotency_key', $key)->sole();
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'part_request.created')
            ->where('record_type', 'PartRequest')
            ->where('record_id', $request->id)
            ->count());
    }

    public function test_concurrent_general_part_request_key_reuse_with_changed_payload_conflicts(): void
    {
        $shop = $this->createShop();
        $firstTechnician = $this->createTechnicianUser($shop, 'First Conflict Technician');
        $secondTechnician = $this->createTechnicianUser($shop, 'Second Conflict Technician');
        $key = (string) Str::uuid();
        $firstPayload = [
            'part_name' => 'Concurrent LCD',
            'quantity' => 2,
            'note' => 'First request.',
            'idempotency_key' => $key,
        ];
        $secondPayload = $firstPayload;
        $secondPayload['quantity'] = 3;

        $responses = $this->runContenders(
            ['first' => 'create-part-request', 'second' => 'create-part-request'],
            'shops',
            $shop->id,
            $firstTechnician->id,
            $secondTechnician->id,
            json_encode($firstPayload, JSON_THROW_ON_ERROR),
            false,
            json_encode($secondPayload, JSON_THROW_ON_ERROR),
            false,
        );

        $this->assertSortedStatuses($responses, [201, 409]);
        $this->assertSame(1, PartRequest::where('shop_id', $shop->id)->where('idempotency_key', $key)->count());
    }

    public function test_concurrent_job_part_orders_with_the_same_key_create_once(): void
    {
        $shop = $this->createShop();
        $technician = $this->createTechnicianUser($shop, 'Concurrent Job Technician');
        $jobOrder = $this->createJobOrder($shop, 'IN_PROGRESS');
        $jobOrder->update(['technician_id' => $technician->technician->id]);
        $key = (string) Str::uuid();
        $payload = [
            'part_name' => 'Concurrent Job LCD',
            'quantity' => 1,
            'reason' => 'Concurrent job part order.',
            'estimated_unit_price' => '8500.00',
            'idempotency_key' => $key,
        ];

        $responses = $this->runContenders(
            'create-job-part-order',
            'job_orders',
            $jobOrder->id,
            $technician->id,
            $technician->id,
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $this->assertSortedStatuses($responses, [200, 201]);
        $order = JobOrderPartOrder::where('shop_id', $shop->id)->where('idempotency_key', $key)->sole();
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'job_order_part_order.created')
            ->where('record_type', 'JobOrderPartOrder')
            ->where('record_id', $order->id)
            ->count());
    }

    public function test_concurrent_job_part_order_key_reuse_with_changed_payload_conflicts(): void
    {
        $shop = $this->createShop();
        $technician = $this->createTechnicianUser($shop, 'Concurrent Job Conflict Technician');
        $jobOrder = $this->createJobOrder($shop, 'IN_PROGRESS');
        $jobOrder->update(['technician_id' => $technician->technician->id]);
        $key = (string) Str::uuid();
        $firstPayload = [
            'part_name' => 'Concurrent Job LCD',
            'quantity' => 1,
            'reason' => 'First job part order.',
            'estimated_unit_price' => '8500.00',
            'idempotency_key' => $key,
        ];
        $secondPayload = $firstPayload;
        $secondPayload['quantity'] = 2;

        $responses = $this->runContenders(
            ['first' => 'create-job-part-order', 'second' => 'create-job-part-order'],
            'job_orders',
            $jobOrder->id,
            $technician->id,
            $technician->id,
            json_encode($firstPayload, JSON_THROW_ON_ERROR),
            true,
            json_encode($secondPayload, JSON_THROW_ON_ERROR),
        );

        $this->assertSortedStatuses($responses, [201, 409]);
        $this->assertSame(1, JobOrderPartOrder::where('shop_id', $shop->id)->where('idempotency_key', $key)->count());
    }

    protected function tearDown(): void
    {
        AuditLog::query()
            ->where(function ($query) {
                $hasJobOrders = $this->jobOrderIds !== [];

                if ($this->jobOrderIds !== []) {
                    $query->whereIn('record_type', [JobOrder::class, class_basename(JobOrder::class)])
                        ->whereIn('record_id', $this->jobOrderIds);
                }

                if ($this->subscriptionRequestIds !== []) {
                    $method = $hasJobOrders ? 'orWhere' : 'where';
                    $query->{$method}(function ($subscriptionLogs) {
                        $subscriptionLogs->whereIn('record_type', [
                            SubscriptionRequest::class,
                            class_basename(SubscriptionRequest::class),
                        ])->whereIn('record_id', $this->subscriptionRequestIds);
                    });
                }
            })
            ->delete();

        foreach ($this->jobOrderIds as $jobOrderId) {
            JobOrderPartOrder::where('job_order_id', $jobOrderId)->delete();
            JobOrderRefund::where('job_order_id', $jobOrderId)->delete();
            JobOrderPayment::where('job_order_id', $jobOrderId)->delete();
            JobOrder::withTrashed()->whereKey($jobOrderId)->forceDelete();
        }

        foreach ($this->subscriptionRequestIds as $requestId) {
            SubscriptionRequest::whereKey($requestId)->delete();
        }

        foreach ($this->sparePartIds as $sparePartId) {
            SparePart::whereKey($sparePartId)->delete();
        }

        foreach ($this->userIds as $userId) {
            User::whereKey($userId)->delete();
        }

        foreach ($this->shopIds as $shopId) {
            Shop::whereKey($shopId)->delete();
        }

        parent::tearDown();
    }

    private function runContenders(
        string|array $scenario,
        string $lockedTable,
        int $lockedId,
        int $firstUserId,
        int $secondUserId,
        int|string|null $sparePartId = null,
        bool $waitForTargetLock = true,
        ?string $secondSparePartId = null,
        bool $holdTargetLock = true,
    ): array {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'repairsync-mysql-' . Str::uuid();
        if (!mkdir($directory, 0700)) {
            $this->fail('Could not create isolated MySQL concurrency-test coordination directory.');
        }

        $goFile = $directory . DIRECTORY_SEPARATOR . 'go';
        $processes = [];
        $workerConnectionIds = [];
        $parentConnectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $database = config('database.connections.mysql');
        $workerEnvironment = [
            'APP_ENV' => 'mysql-testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'repair_tracking_test',
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
            'DB_URL' => '',
        ];

        DB::beginTransaction();

        try {
            if ($holdTargetLock) {
                DB::table($lockedTable)->where('id', $lockedId)->lockForUpdate()->first();
            }

            foreach ([['first', $firstUserId], ['second', $secondUserId]] as [$name, $userId]) {
                $readyFile = $directory . DIRECTORY_SEPARATOR . $name . '.ready';
                $startedFile = $directory . DIRECTORY_SEPARATOR . $name . '.started';
                $resultFile = $directory . DIRECTORY_SEPARATOR . $name . '.json';
                if (is_array($scenario) && !isset($scenario[$name])) {
                    $this->fail("No MySQL worker scenario was configured for {$name}.");
                }
                $workerScenario = is_array($scenario) ? $scenario[$name] : $scenario;
                $command = [
                    PHP_BINARY,
                    base_path('tests/Support/mysql_concurrency_worker.php'),
                    $workerScenario,
                    (string) $lockedId,
                    (string) $userId,
                    $readyFile,
                    $startedFile,
                    $goFile,
                    $resultFile,
                    $sparePartId === null ? '' : (string) $sparePartId,
                ];
                if ($name === 'second' && $secondSparePartId !== null) {
                    $command[count($command) - 1] = $secondSparePartId;
                }

                $process = new Process($command, base_path(), $workerEnvironment);
                $process->setTimeout(90);
                $process->start();
                $processes[$name] = [$process, $readyFile, $startedFile, $resultFile];
            }

            foreach ($processes as $name => [$process, $readyFile, , $resultFile]) {
                $this->waitForFile($readyFile, "worker-ready ({$name})", $process, $resultFile);
                $ready = json_decode((string) file_get_contents($readyFile), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('repair_tracking_test', $ready['database'] ?? null);
                $workerConnectionIds[$name] = (int) ($ready['connection_id'] ?? 0);
            }

            $this->assertNotContains(0, $workerConnectionIds);
            $this->assertCount(count($workerConnectionIds), array_unique($workerConnectionIds));
            $this->assertNotContains($parentConnectionId, $workerConnectionIds);

            touch($goFile);
            foreach ($processes as $name => [$process, , $startedFile, $resultFile]) {
                $this->waitForFile($startedFile, "worker-start ({$name})", $process, $resultFile);
            }

            if ($waitForTargetLock) {
                $this->waitForDatabaseLockContenders($workerConnectionIds, $processes, $lockedTable, $lockedId);
            }
            DB::commit();

            $results = [];
            foreach ($processes as $name => [$process, , , $resultFile]) {
                $process->wait();
                if (!$process->isSuccessful() || !is_file($resultFile)) {
                    $this->fail($this->workerDiagnostic($name, $process, $resultFile));
                }
                $results[$name] = json_decode((string) file_get_contents($resultFile), true, flags: JSON_THROW_ON_ERROR);
                if (isset($results[$name]['error'])) {
                    $this->fail("MySQL concurrency worker {$name} reported an exception: {$results[$name]['error']}");
                }
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($processes as [$process]) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }

    private function waitForFile(string $path, string $description, Process $process, string $resultFile): void
    {
        $deadline = microtime(true) + 15;
        while (!is_file($path) && microtime(true) < $deadline) {
            if (!$process->isRunning()) {
                $this->fail($this->workerDiagnostic($description, $process, $resultFile));
            }
            usleep(10000);
        }

        if (!is_file($path)) {
            $this->fail("Timed out waiting for MySQL concurrency {$description} signal.\n" .
                $this->workerDiagnostic($description, $process, $resultFile));
        }
    }

    private function waitForDatabaseLockContenders(
        array $workerConnectionIds,
        array $processes,
        string $lockedTable,
        int $lockedId,
    ): void
    {
        $deadline = microtime(true) + 15;
        $contendingProcesses = [];

        do {
            $processStates = DB::select(
                'SELECT ID, COMMAND, TIME, STATE, INFO FROM information_schema.PROCESSLIST WHERE ID IN (' .
                implode(',', array_map('intval', $workerConnectionIds)) . ')'
            );
            $contendingProcesses = array_filter($processStates, function (object $process) use ($lockedTable, $lockedId): bool {
                $query = strtolower((string) $process->INFO);
                $query = str_replace('`', '', $query);

                return $process->COMMAND === 'Execute'
                    && str_contains($query, ' from ' . strtolower($lockedTable) . ' ')
                    && str_contains($query, ' = ' . $lockedId)
                    && str_contains($query, 'for update');
            });

            if (count($contendingProcesses) === count($workerConnectionIds)) {
                return;
            }

            foreach ($processes as $name => [$process, , , $resultFile]) {
                if (!$process->isRunning() && !is_file($resultFile)) {
                    $this->fail($this->workerDiagnostic($name, $process, $resultFile));
                }
            }

            usleep(10000);
        } while (microtime(true) < $deadline);

        $diagnostics = [];
        foreach ($processes as $name => [$process, , , $resultFile]) {
            $diagnostics[] = $this->workerDiagnostic($name, $process, $resultFile);
        }

        $this->fail(
            'MySQL concurrency workers did not both issue the target SELECT FOR UPDATE while the parent held the InnoDB row lock. ' .
            'Worker connection IDs: ' . json_encode($workerConnectionIds) .
            '; observed target lock queries: ' . json_encode($contendingProcesses) . "\n" .
            implode("\n\n", $diagnostics)
        );
    }

    private function workerDiagnostic(string $name, Process $process, string $resultFile): string
    {
        $result = is_file($resultFile) ? file_get_contents($resultFile) : '(no worker result file)';

        return sprintf(
            "MySQL concurrency worker %s failed.\nCommand: %s\nExit code: %s\nStdout:\n%s\nStderr:\n%s\nResult:\n%s",
            $name,
            $process->getCommandLine(),
            var_export($process->getExitCode(), true),
            $process->getOutput() ?: '(empty)',
            $process->getErrorOutput() ?: '(empty)',
            $result ?: '(empty)'
        );
    }

    private function assertSortedStatuses(array $responses, array $expected): void
    {
        $statuses = [$responses['first']['status'], $responses['second']['status']];
        sort($statuses);
        sort($expected);
        $this->assertSame($expected, $statuses);
    }

    private function createShop(): Shop
    {
        $shop = Shop::create([
            'name' => 'MySQL Concurrency ' . Str::random(10),
            'slug' => 'mysql-' . Str::lower(Str::random(16)),
            'trial_ends_at' => now()->addDays(30),
            'is_active' => true,
        ]);
        $this->shopIds[] = $shop->id;

        return $shop;
    }

    private function createUser(string $role, ?Shop $shop = null): User
    {
        $user = User::factory()->create([
            'shop_id' => $shop?->id,
            'role' => $role,
            'is_active' => true,
        ]);
        $this->userIds[] = $user->id;

        return $user;
    }

    private function createTechnicianUser(Shop $shop, string $name): User
    {
        $user = $this->createUser(User::ROLE_TECHNICIAN, $shop);
        $technician = Technician::create([
            'shop_id' => $shop->id,
            'name' => $name,
            'email' => $user->email,
            'is_active' => true,
        ]);
        $user->setRelation('technician', $technician);

        return $user;
    }

    private function createJobOrder(Shop $shop, string $status): JobOrder
    {
        $jobOrder = JobOrder::create([
            'shop_id' => $shop->id,
            'job_order_number' => 'MYSQL-' . Str::upper(Str::random(12)),
            'customer_name' => 'Synthetic Concurrency Customer',
            'customer_phone' => '09000000000',
            'device_type' => 'Phone',
            'problem_description' => 'Synthetic concurrency test fixture.',
            'current_status' => $status,
            'total_cost' => 0,
            'amount_paid' => 0,
            'payment_status' => 'PENDING',
        ]);
        $this->jobOrderIds[] = $jobOrder->id;

        return $jobOrder;
    }
}
