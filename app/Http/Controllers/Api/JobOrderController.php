<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendCustomerNotification;
use App\Models\CustomerNotification;
use App\Models\JobOrder;
use App\Models\JobOrderPayment;
use App\Models\JobOrderPart;
use App\Models\JobOrderRefund;
use App\Models\RepairPhoto;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\SparePart;
use App\Models\StatusHistory;
use App\Models\StockMovement;
use App\Models\Technician;
use App\Models\User;
use App\Models\WarrantyAssessment;
use App\Support\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class JobOrderController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeStaffOrAdmin($request);
        $shopId = $this->currentShopId($request);

        $jobOrders = JobOrder::query()
            ->where('shop_id', $shopId)
            ->with(['technician:id,name', 'warrantyParentJobOrder:id,job_order_number', 'serviceCategory:id,name', 'serviceItem:id,name'])
            ->withExists([
                'statusHistories as has_started_repair' => fn ($query) => $query->where('status', 'IN_PROGRESS'),
            ]);

        if ($search = trim((string) $request->query('search', ''))) {
            $jobOrders->where(function ($query) use ($search) {
                $query
                    ->where('job_order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('device_type', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $jobOrders->where('current_status', $status);
        }

        if ($jobGroup = $request->query('job_group')) {
            if ($jobGroup === 'released') {
                $jobOrders->whereIn('current_status', ['PICKED_UP', 'RTO_RELEASED']);
            } elseif ($jobGroup === 'active') {
                $jobOrders->whereNotIn('current_status', ['PICKED_UP', 'RTO_RELEASED']);
            } else {
                abort(422, 'Invalid job group.');
            }
        }

        if ($priority = $request->query('priority')) {
            $jobOrders->where('priority', $priority);
        }

        if ($paymentStatus = $request->query('payment_status')) {
            $jobOrders->where('payment_status', $paymentStatus);
        }

        if ($serviceType = $request->query('service_type')) {
            $jobOrders->where('service_type', $serviceType);
        }

        if ($serviceCategoryId = $request->query('service_category_id')) {
            $jobOrders->where('service_category_id', $serviceCategoryId);
        }

        if ($serviceItemId = $request->query('service_item_id')) {
            $jobOrders->where('service_item_id', $serviceItemId);
        }

        if ($technicianId = $request->query('technician_id')) {
            $jobOrders->where('technician_id', $technicianId);
        }

        if ($receivedFrom = $request->date('received_from')) {
            $jobOrders->whereDate('created_at', '>=', $receivedFrom);
        }

        if ($receivedTo = $request->date('received_to')) {
            $jobOrders->whereDate('created_at', '<=', $receivedTo);
        }

        if ($request->boolean('overdue_only')) {
            $jobOrders
                ->whereNotIn('current_status', ['READY_FOR_PICKUP', 'PICKED_UP', 'RTO', 'RTO_RELEASED'])
                ->whereNotNull('estimated_completion')
                ->where('estimated_completion', '<', now());
        }

        $jobOrders = $jobOrders
            ->latest()
            ->paginate(15)
            ->appends($request->query());
        $jobOrders->getCollection()->transform(fn (JobOrder $job) => $this->mapJobOrderSummary($job));

        return response()->json([
            'jobOrders' => $jobOrders,
        ]);
    }

    public function trashed(Request $request)
    {
        $this->authorizeAdmin($request);
        $shopId = $this->currentShopId($request);

        $jobOrders = JobOrder::onlyTrashed()
            ->where('shop_id', $shopId)
            ->withExists([
                'statusHistories as has_started_repair' => fn ($query) => $query->where('status', 'IN_PROGRESS'),
            ])
            ->latest('deleted_at')
            ->paginate(15);
        $jobOrders->getCollection()->transform(fn (JobOrder $job) => $this->mapJobOrderSummary($job));

        return response()->json([
            'jobOrders' => $jobOrders,
        ]);
    }

    public function meta(Request $request)
    {
        $this->authorizeStaffOrAdmin($request);
        $shopId = $this->currentShopId($request);

        return response()->json([
            'technicians' => Technician::where('shop_id', $shopId)->where('is_active', true)->get(['id', 'name']),
            'statuses' => [
                'RECEIVED',
                'DIAGNOSING',
                'WAITING_PARTS',
                'IN_PROGRESS',
                'READY_FOR_PICKUP',
                'PICKED_UP',
                'RTO',
                'RTO_RELEASED',
            ],
            'priorities' => ['STANDARD', 'EXPRESS', 'EMERGENCY'],
            'paymentStatuses' => ['PENDING', 'PARTIAL', 'PAID'],
            'serviceTypes' => [
                'STANDARD',
                'WARRANTY_SERVICE',
            ],
            'serviceCatalog' => $this->activeServiceCatalog($shopId),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureShopCanStartNewWork($request);
        $shopId = $this->currentShopId($request);

        $validated = $request->validate([
            'customer_name' => 'required|string|max:100',
            'customer_email' => 'nullable|email|max:150',
            'customer_phone' => 'required|string|max:30',
            'device_type' => 'required|string|max:50',
            'service_category_id' => ['nullable', 'integer'],
            'service_item_id' => ['nullable', 'integer'],
            'brand' => 'nullable|string|max:50',
            'model' => 'nullable|string|max:50',
            'serial_number' => 'nullable|string|max:100',
            'problem_description' => 'required|string',
            'included_accessories' => 'nullable|string|max:1000',
            'priority' => 'nullable|in:STANDARD,EXPRESS,EMERGENCY',
            'estimated_cost' => 'nullable|numeric|min:0',
            'partial_payment' => 'nullable|numeric|min:0',
            'down_payment' => 'nullable|numeric|min:0',
            'estimated_completion' => 'nullable|date',
            'technician_id' => [
                'nullable',
                Rule::exists('technicians', 'id')->where(fn ($query) => $query->where('shop_id', $shopId)),
            ],
        ]);
        $validated = $this->normalizeServiceSelection($shopId, $validated);

        $estimatedCost = (float) ($validated['estimated_cost'] ?? 0);
        $partialPayment = (float) ($validated['partial_payment'] ?? $validated['down_payment'] ?? 0);

        if ($partialPayment > 0 && $estimatedCost <= 0) {
            throw ValidationException::withMessages([
                'estimated_cost' => 'Estimated cost is required when initial payment is entered.',
            ]);
        }

        if ($estimatedCost > 0 && $partialPayment > $estimatedCost) {
            throw ValidationException::withMessages([
                'partial_payment' => 'Initial partial payment cannot be greater than estimated cost.',
            ]);
        }

        unset($validated['partial_payment']);
        unset($validated['down_payment']);

        if ($duplicate = $this->findRecentDuplicate($shopId, $validated)) {
            return response()->json([
                'message' => "Possible duplicate job order found: {$duplicate->job_order_number} created on {$duplicate->created_at->format('M d, Y')}.",
                'duplicate' => [
                    'id' => $duplicate->id,
                    'job_order_number' => $duplicate->job_order_number,
                    'created_at' => $duplicate->created_at,
                ],
            ], 422);
        }

        $paymentStatus = $partialPayment <= 0
            ? 'PENDING'
            : ($partialPayment >= $estimatedCost ? 'PAID' : 'PARTIAL');
        $paymentType = $paymentStatus === 'PAID' ? 'FINAL_PAYMENT' : 'PARTIAL_PAYMENT';
        $jobOrderNumber = $this->generateJobOrderNumber();

        $jobOrder = DB::transaction(function () use ($validated, $jobOrderNumber, $partialPayment, $estimatedCost, $paymentStatus, $paymentType, $request, $shopId) {
            $jobOrder = JobOrder::create([
                ...$validated,
                'shop_id' => $shopId,
                'job_order_number' => $jobOrderNumber,
                'received_by' => $request->user()?->name,
                'current_status' => 'RECEIVED',
                'device_location' => 'AT_SHOP',
                'priority' => $validated['priority'] ?? 'STANDARD',
                'warranty_days' => 0,
                'payment_status' => $paymentStatus,
                'parts_cost' => 0,
                'labor_cost' => 0,
                'total_cost' => $estimatedCost,
                'amount_paid' => $partialPayment,
                'payment_date' => $partialPayment > 0 ? now() : null,
                'payment_notes' => $partialPayment > 0 ? 'Initial partial payment upon intake.' : null,
            ]);

            if ($partialPayment > 0) {
                JobOrderPayment::create([
                    'shop_id' => $shopId,
                    'job_order_id' => $jobOrder->id,
                    'amount' => $partialPayment,
                    'payment_type' => $paymentType,
                    'payment_method' => 'CASH',
                    'notes' => 'Initial partial payment upon intake.',
                    'received_at' => now(),
                    'received_by' => $request->user()?->name,
                ]);
            }

            StatusHistory::create([
                'shop_id' => $shopId,
                'job_order_id' => $jobOrder->id,
                'status' => 'RECEIVED',
                'note' => 'Device received and registered in system',
            ]);

            return $jobOrder;
        });

        $this->notifyCustomer($jobOrder, 'RECEIVED', 'Device received and registered in system');

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'job_order.created',
            module: 'job_orders',
            record: $jobOrder,
            description: "Created job order {$jobOrder->job_order_number}.",
            newValues: $jobOrder->fresh()->only([
                'job_order_number',
                'customer_name',
                'device_type',
                'service_category_id',
                'service_item_id',
                'brand',
                'model',
                'current_status',
                'priority',
                'payment_status',
                'amount_paid',
                'total_cost',
                'technician_id',
            ])
        );

        return response()->json([
            'message' => "Job Order {$jobOrderNumber} created successfully.",
            'jobOrder' => $jobOrder,
        ], 201);
    }

    public function show(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        $jobOrder->load([
            'technician',
            'deviceLocationUpdatedBy:id,name',
            'statusHistories',
            'parts',
            'payments',
            'refunds',
            'repairPhotos',
            'warrantyAssessments.warrantyJobOrder',
        ]);

        return response()->json([
            'jobOrder' => $jobOrder,
            'statusHistories' => $jobOrder->statusHistories()->orderBy('created_at')->get(),
            'parts' => $jobOrder->parts()
                ->with('sparePart:id,name,stock_quantity,reorder_level,unit')
                ->latest()
                ->get(),
            'payments' => $jobOrder->payments()
                ->latest('received_at')
                ->latest('id')
                ->get()
                ->map(fn (JobOrderPayment $payment) => $this->mapPayment($payment)),
            'refunds' => $jobOrder->refunds()->latest('initiated_at')->get(),
            'legacy_refund_reconciliation_amount' => $this->legacyRefundReconciliationAmount($jobOrder),
            'photos' => $jobOrder->repairPhotos()
                ->latest()
                ->get()
                ->map(fn (RepairPhoto $photo) => $this->mapPhoto($photo)),
            'notifications' => $jobOrder->customerNotifications()
                ->latest()
                ->limit(25)
                ->get()
                ->map(fn (CustomerNotification $notification) => $this->mapNotification($notification)),
            'warrantyAssessments' => $jobOrder->warrantyAssessments()
                ->with('warrantyJobOrder:id,job_order_number,current_status')
                ->latest()
                ->get()
                ->map(fn (WarrantyAssessment $assessment) => $this->mapWarrantyAssessment($assessment)),
        ]);
    }

    public function invoice(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        $jobOrder->load(['technician', 'parts', 'payments']);

        $trackUrl = $jobOrder->shop?->slug
            ? url('/shops/' . $jobOrder->shop->slug . '/track/' . $jobOrder->job_order_number)
            : url('/track/' . $jobOrder->job_order_number);
        $partialPayment = (float) $jobOrder->payments()
            ->whereIn('payment_type', ['DOWN_PAYMENT', 'PARTIAL_PAYMENT'])
            ->sum('amount');

        if ($partialPayment <= 0 && (float) $jobOrder->amount_paid > 0 && (float) $jobOrder->estimated_cost > 0) {
            $partialPayment = min((float) $jobOrder->amount_paid, (float) $jobOrder->estimated_cost);
        }

        $isWarrantyService = ($jobOrder->service_type ?? 'STANDARD') === 'WARRANTY_SERVICE';
        $documentPrefix = $isWarrantyService ? 'WS-' : 'INV-';

        return response()->json([
            'shop' => [
                'name' => $jobOrder->shop?->name ?? config('app.name', 'Repair Service Management System'),
                'tagline' => config('repair.tagline', 'Electronics Repair Service Management'),
                'slug' => $jobOrder->shop?->slug,
                'phone' => $jobOrder->shop?->phone,
                'address' => $jobOrder->shop?->address,
                'business_hours' => $jobOrder->shop?->business_hours,
                'logo_url' => $jobOrder->shop?->logoUrl(),
            ],
            'document' => [
                'type' => $isWarrantyService ? 'service_summary' : 'invoice',
                'title' => $isWarrantyService ? 'Service Summary' : 'Invoice',
                'eyebrow' => $isWarrantyService ? 'Under Warranty Service' : 'Invoice',
                'print_label' => $isWarrantyService ? 'Print Service Summary' : 'Print Invoice',
                'is_warranty_service' => $isWarrantyService,
            ],
            'invoice' => [
                'invoice_number' => $documentPrefix . str_pad((string) $jobOrder->id, 6, '0', STR_PAD_LEFT),
                'issued_at' => now()->toIso8601String(),
                'issued_at_formatted' => now()->format('M d, Y h:i A'),
                'track_url' => $trackUrl,
                'track_number' => $jobOrder->job_order_number,
                'qr_value' => $trackUrl,
            ],
            'jobOrder' => $jobOrder,
            'customer' => [
                'name' => $jobOrder->customer_name,
                'email' => $jobOrder->customer_email,
                'phone' => $jobOrder->customer_phone,
                'contact' => $jobOrder->customer_contact,
            ],
            'device' => [
                'type' => $jobOrder->device_type,
                'brand' => $jobOrder->brand,
                'model' => $jobOrder->model,
                'serial_number' => $jobOrder->serial_number,
                'problem_description' => $jobOrder->problem_description,
            ],
            'costs' => [
                'estimated_cost' => $jobOrder->estimated_cost,
                'partial_payment' => $partialPayment,
                'down_payment' => $partialPayment,
                'estimated_balance' => max((float) $jobOrder->estimated_cost - $partialPayment, 0),
                'parts_cost' => $jobOrder->parts_cost,
                'labor_cost' => $jobOrder->labor_cost,
                'total_cost' => $jobOrder->total_cost,
                'amount_paid' => $jobOrder->amount_paid,
                'balance' => $jobOrder->balance,
            ],
            'service' => [
                'priority' => $jobOrder->priority,
                'current_status' => $jobOrder->current_status,
                'warranty_days' => $jobOrder->warranty_days,
                'estimated_completion' => $jobOrder->estimated_completion,
                'technician' => $jobOrder->technician?->name,
            ],
            'parts' => $jobOrder->parts()->latest()->get(),
        ]);
    }

    public function printData(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        $jobOrder->load(['technician', 'parts', 'payments']);

        return response()->json($this->buildPrintableData($jobOrder));
    }

    public function update(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);
        $shopId = $this->currentShopId($request);
        $before = $jobOrder->only([
            'customer_name',
            'customer_email',
            'customer_phone',
            'device_type',
            'service_category_id',
            'service_item_id',
            'brand',
            'model',
            'serial_number',
            'problem_description',
            'included_accessories',
            'priority',
            'warranty_days',
            'estimated_completion',
            'technician_id',
        ]);

        $validated = $request->validate([
            'customer_name' => 'required|string|max:100',
            'customer_email' => 'nullable|email|max:150',
            'customer_phone' => 'required|string|max:30',
            'device_type' => 'required|string|max:50',
            'service_category_id' => ['nullable', 'integer'],
            'service_item_id' => ['nullable', 'integer'],
            'brand' => 'nullable|string|max:50',
            'model' => 'nullable|string|max:50',
            'serial_number' => 'nullable|string|max:100',
            'problem_description' => 'required|string',
            'included_accessories' => 'nullable|string|max:1000',
            'priority' => 'nullable|in:STANDARD,EXPRESS,EMERGENCY',
            'warranty_days' => 'nullable|integer|min:0|max:3650',
            'estimated_completion' => 'nullable|date',
            'technician_id' => [
                'nullable',
                Rule::exists('technicians', 'id')->where(fn ($query) => $query->where('shop_id', $shopId)),
            ],
        ]);
        $validated = $this->normalizeServiceSelection($shopId, $validated);

        $jobOrder->update($validated);

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'job_order.updated',
            module: 'job_orders',
            record: $jobOrder,
            description: "Updated job order {$jobOrder->job_order_number}.",
            oldValues: $before,
            newValues: $jobOrder->fresh()->only(array_keys($before))
        );

        return response()->json([
            'message' => 'Job Order updated successfully.',
            'jobOrder' => $jobOrder->fresh(),
        ]);
    }

    public function updateWarranty(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);
        $before = $jobOrder->only(['warranty_days', 'warranty_details', 'service_performed']);

        $validated = $request->validate([
            'warranty_days' => 'required|integer|min:0|max:3650',
            'warranty_details' => 'nullable|string|max:2000',
            'service_performed' => 'nullable|string|max:2000',
        ]);

        $jobOrder->update($validated);

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'job_order.warranty_updated',
            module: 'job_orders',
            record: $jobOrder,
            description: "Updated warranty details for {$jobOrder->job_order_number}.",
            oldValues: $before,
            newValues: $jobOrder->fresh()->only(['warranty_days', 'warranty_details', 'service_performed'])
        );

        return response()->json([
            'message' => 'Warranty details updated.',
            'jobOrder' => $jobOrder->fresh(),
        ]);
    }

    public function recordWarrantyAssessment(Request $request, JobOrder $jobOrder)
    {
        $user = $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        $validated = $request->validate([
            'reported_issue' => ['required', 'string', 'max:2000'],
            'brought_at' => ['nullable', 'date'],
            'checked_by' => ['nullable', 'string', 'max:100'],
            'result' => ['required', Rule::in(WarrantyAssessment::results())],
            'assessment_notes' => ['nullable', 'string', 'max:3000'],
        ]);

        [$jobOrder, $assessment] = DB::transaction(function () use ($validated, $jobOrder, $request, $user) {
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $jobOrder->shop_id)
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);
            $warrantyJobOrder = null;

            if ($validated['result'] === WarrantyAssessment::RESULT_COVERED) {
                $this->assertCanIssueWarrantyService($lockedJobOrder);
                $this->assertNoActiveWarrantyService($lockedJobOrder);

                $warrantyJobOrder = JobOrder::create([
                    'shop_id' => $lockedJobOrder->shop_id,
                    'job_order_number' => $this->generateJobOrderNumber('WTY'),
                    'service_type' => 'WARRANTY_SERVICE',
                    'warranty_parent_job_order_id' => $lockedJobOrder->id,
                    'customer_name' => $lockedJobOrder->customer_name,
                    'customer_contact' => $lockedJobOrder->customer_contact,
                    'customer_email' => $lockedJobOrder->customer_email,
                    'customer_phone' => $lockedJobOrder->customer_phone,
                    'device_type' => $lockedJobOrder->device_type,
                    'service_category_id' => $lockedJobOrder->service_category_id,
                    'service_item_id' => $lockedJobOrder->service_item_id,
                    'brand' => $lockedJobOrder->brand,
                    'model' => $lockedJobOrder->model,
                    'serial_number' => $lockedJobOrder->serial_number,
                    'problem_description' => $validated['reported_issue'],
                    'received_by' => $request->user()?->name,
                    'included_accessories' => $lockedJobOrder->included_accessories,
                    'current_status' => 'RECEIVED',
                    'device_location' => 'AT_SHOP',
                    'priority' => 'STANDARD',
                    'estimated_cost' => 0,
                    'parts_cost' => 0,
                    'labor_cost' => 0,
                    'total_cost' => 0,
                    'amount_paid' => 0,
                    'payment_status' => 'PAID',
                    'warranty_days' => 0,
                    'internal_notes' => "Warranty service linked to {$jobOrder->job_order_number}.",
                ]);

                StatusHistory::create([
                    'shop_id' => $lockedJobOrder->shop_id,
                    'job_order_id' => $warrantyJobOrder->id,
                    'status' => 'RECEIVED',
                    'note' => "Warranty service created from {$lockedJobOrder->job_order_number}.",
                ]);
            }

            $assessment = WarrantyAssessment::create([
                'shop_id' => $lockedJobOrder->shop_id,
                'job_order_id' => $lockedJobOrder->id,
                'warranty_job_order_id' => $warrantyJobOrder?->id,
                'reported_issue' => $validated['reported_issue'],
                'brought_at' => $validated['brought_at'] ?? now(),
                'checked_by' => $validated['checked_by'] ?? null,
                'result' => $validated['result'],
                'assessment_notes' => $validated['assessment_notes'] ?? null,
                'created_by' => $user->name,
            ]);

            StatusHistory::create([
                'shop_id' => $lockedJobOrder->shop_id,
                'job_order_id' => $lockedJobOrder->id,
                'status' => $lockedJobOrder->current_status,
                'note' => 'Warranty assessment recorded: ' . str_replace('_', ' ', $assessment->result) . '.',
            ]);

            return [$lockedJobOrder, $assessment];
        });

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'job_order.warranty_assessed',
            module: 'job_orders',
            record: $jobOrder,
            description: "Recorded warranty assessment for {$jobOrder->job_order_number}.",
            newValues: $assessment->fresh()->only([
                'reported_issue',
                'result',
                'checked_by',
                'warranty_job_order_id',
            ])
        );

        return response()->json([
            'message' => $assessment->result === WarrantyAssessment::RESULT_COVERED
                ? 'Warranty assessment recorded and warranty service job created.'
                : 'Warranty assessment recorded.',
            'assessment' => $this->mapWarrantyAssessment($assessment->fresh()->load('warrantyJobOrder:id,job_order_number,current_status')),
        ], 201);
    }

    public function issueWarrantyService(Request $request, JobOrder $jobOrder)
    {
        $user = $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        [$jobOrder, $assessment] = DB::transaction(function () use ($jobOrder, $request, $user) {
            $shopId = $this->currentShopId($request);
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);

            if (($lockedJobOrder->service_type ?? 'STANDARD') === 'WARRANTY_SERVICE') {
                throw ValidationException::withMessages([
                    'warranty_service' => 'This job order is already a warranty service job.',
                ]);
            }

            $this->assertCanIssueWarrantyService($lockedJobOrder);
            $this->assertNoActiveWarrantyService($lockedJobOrder);

            $assessment = $this->createCoveredWarrantyService(
                jobOrder: $lockedJobOrder,
                request: $request,
                user: $user,
                reportedIssue: "Warranty service for {$lockedJobOrder->job_order_number}: {$lockedJobOrder->problem_description}",
                checkedBy: $request->user()?->name
            );

            return [$lockedJobOrder, $assessment];
        });

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'job_order.warranty_service_issued',
            module: 'job_orders',
            record: $jobOrder,
            description: "Issued warranty service for {$jobOrder->job_order_number}.",
            newValues: $assessment->fresh()->only([
                'reported_issue',
                'result',
                'checked_by',
                'warranty_job_order_id',
            ])
        );

        $assessment = $assessment->fresh()->load('warrantyJobOrder:id,job_order_number,current_status');

        return response()->json([
            'message' => 'Warranty service job created.',
            'assessment' => $this->mapWarrantyAssessment($assessment),
            'warrantyJobOrder' => [
                'id' => $assessment->warrantyJobOrder->id,
                'job_order_number' => $assessment->warrantyJobOrder->job_order_number,
                'current_status' => $assessment->warrantyJobOrder->current_status,
            ],
        ], 201);
    }

    public function cancelWarrantyService(Request $request, JobOrder $jobOrder)
    {
        $user = $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        if (($jobOrder->service_type ?? 'STANDARD') !== 'WARRANTY_SERVICE') {
            throw ValidationException::withMessages([
                'warranty_service' => 'Only under warranty service jobs can be canceled here.',
            ]);
        }

        if ($jobOrder->current_status !== 'RECEIVED' || $jobOrder->picked_up_at) {
            throw ValidationException::withMessages([
                'warranty_service' => 'Only newly issued warranty service jobs can be canceled. Use RTO/release once work has started.',
            ]);
        }

        $parentJobOrder = $jobOrder->warrantyParentJobOrder;
        $jobOrderNumber = $jobOrder->job_order_number;
        $before = $jobOrder->only(['job_order_number', 'customer_name', 'current_status', 'service_type', 'warranty_parent_job_order_id']);

        DB::transaction(function () use ($jobOrder) {
            WarrantyAssessment::query()
                ->where('warranty_job_order_id', $jobOrder->id)
                ->delete();

            $jobOrder->delete();
        });

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'job_order.warranty_service_canceled',
            module: 'job_orders',
            description: "Canceled warranty service {$jobOrderNumber}.",
            oldValues: $before
        );

        return response()->json([
            'message' => "Warranty service {$jobOrderNumber} canceled.",
            'parentJobOrder' => $parentJobOrder ? [
                'id' => $parentJobOrder->id,
                'job_order_number' => $parentJobOrder->job_order_number,
            ] : null,
        ]);
    }

    public function recordRepairApproval(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);
        $before = $jobOrder->only([
            'repair_approved_at',
            'repair_approved_by_customer',
            'repair_approval_recorded_by',
            'repair_approval_notes',
        ]);

        $validated = $request->validate([
            'repair_approved_by_customer' => 'required|string|max:100',
            'repair_approval_notes' => 'nullable|string|max:2000',
        ]);

        $jobOrder->update([
            'repair_approved_at' => now(),
            'repair_approved_by_customer' => $validated['repair_approved_by_customer'],
            'repair_approval_recorded_by' => $request->user()?->name,
            'repair_approval_notes' => $validated['repair_approval_notes'] ?? null,
        ]);

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'job_order.repair_approved',
            module: 'job_orders',
            record: $jobOrder,
            description: "Recorded customer repair approval for {$jobOrder->job_order_number}.",
            oldValues: $before,
            newValues: $jobOrder->fresh()->only(array_keys($before))
        );

        return response()->json([
            'message' => 'Customer repair approval recorded.',
            'jobOrder' => $jobOrder->fresh(),
        ]);
    }

    public function storePhoto(Request $request, JobOrder $jobOrder)
    {
        $user = $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        $validated = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'photo_type' => ['required', Rule::in(RepairPhoto::types())],
            'description' => ['nullable', 'string', 'max:1000'],
            'visible_to_customer' => ['nullable', 'boolean'],
        ]);

        $file = $validated['photo'];
        $path = $file->store("repair-photos/{$jobOrder->shop_id}/{$jobOrder->id}", 'local');

        $photo = RepairPhoto::create([
            'shop_id' => $jobOrder->shop_id,
            'job_order_id' => $jobOrder->id,
            'photo_type' => $validated['photo_type'],
            'photo_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'description' => $validated['description'] ?? null,
            'visible_to_customer' => $request->boolean('visible_to_customer'),
            'uploaded_by' => $user->name,
            'uploaded_by_role' => $user->role,
        ]);

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'job_order.photo_uploaded',
            module: 'job_orders',
            record: $jobOrder,
            description: "Uploaded {$photo->photo_type} photo for {$jobOrder->job_order_number}.",
            newValues: $photo->only(['photo_type', 'original_name', 'description', 'uploaded_by'])
        );

        return response()->json([
            'message' => 'Photo uploaded successfully.',
            'photo' => $this->mapPhoto($photo),
        ], 201);
    }

    public function updatePhotoVisibility(Request $request, JobOrder $jobOrder, RepairPhoto $photo)
    {
        $user = $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        if ((int) $photo->job_order_id !== (int) $jobOrder->id || (int) $photo->shop_id !== (int) $jobOrder->shop_id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'visible_to_customer' => ['required', 'boolean'],
        ]);

        $before = $photo->only(['visible_to_customer']);
        $photo->update([
            'visible_to_customer' => $validated['visible_to_customer'],
        ]);

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'job_order.photo_visibility_updated',
            module: 'job_orders',
            record: $jobOrder,
            description: ($photo->visible_to_customer ? 'Published' : 'Hid') . " photo from customer tracking for {$jobOrder->job_order_number}.",
            oldValues: $before,
            newValues: $photo->fresh()->only(['visible_to_customer'])
        );

        return response()->json([
            'message' => $photo->visible_to_customer ? 'Photo is now visible to customer.' : 'Photo is now internal only.',
            'photo' => $this->mapPhoto($photo->fresh()),
        ]);
    }

    public function deletePhoto(Request $request, JobOrder $jobOrder, RepairPhoto $photo)
    {
        $user = $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        if ((int) $photo->job_order_id !== (int) $jobOrder->id || (int) $photo->shop_id !== (int) $jobOrder->shop_id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $before = $photo->only(['photo_type', 'photo_path', 'original_name', 'description', 'uploaded_by']);

        Storage::disk('local')->delete($photo->photo_path);
        $photo->delete();

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'job_order.photo_deleted',
            module: 'job_orders',
            record: $jobOrder,
            description: "Deleted {$before['photo_type']} photo from {$jobOrder->job_order_number}.",
            oldValues: $before
        );

        return response()->json([
            'message' => 'Photo deleted.',
        ]);
    }

    public function retryNotification(Request $request, JobOrder $jobOrder, CustomerNotification $notification)
    {
        $user = $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        if ((int) $notification->job_order_id !== (int) $jobOrder->id || (int) $notification->shop_id !== (int) $jobOrder->shop_id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $updated = CustomerNotification::query()
            ->whereKey($notification->id)
            ->where('job_order_id', $jobOrder->id)
            ->where('shop_id', $jobOrder->shop_id)
            ->where('status', CustomerNotification::STATUS_FAILED)
            ->update([
                'status' => CustomerNotification::STATUS_QUEUED,
                'queued_at' => now(),
                'sent_at' => null,
                'failed_at' => null,
                'error_message' => null,
            ]);

        if (!$updated) {
            throw ValidationException::withMessages([
                'notification' => 'Only failed notifications can be retried.',
            ]);
        }

        $notification->refresh();

        SendCustomerNotification::dispatch(
            null,
            null,
            $notification->subject ?? "{$jobOrder->job_order_number} update",
            $notification->message,
            $notification->id
        );

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'job_order.notification_retried',
            module: 'job_orders',
            record: $jobOrder,
            description: "Retried {$notification->channel} notification for {$jobOrder->job_order_number}.",
            newValues: $notification->fresh()->only(['channel', 'recipient', 'status', 'trigger'])
        );

        return response()->json([
            'message' => 'Notification queued for retry.',
            'notification' => $this->mapNotification($notification->fresh()),
        ]);
    }

    public function destroy(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        if ($jobOrder->repairHasStarted()) {
            throw ValidationException::withMessages([
                'job_order' => 'This job order cannot be deleted because the approved repair has already started.',
            ]);
        }

        $jobOrderNumber = $jobOrder->job_order_number;
        $before = $jobOrder->only(['job_order_number', 'customer_name', 'current_status', 'payment_status', 'amount_paid', 'total_cost']);
        $jobOrder->delete();

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'job_order.deleted',
            module: 'job_orders',
            description: "Soft-deleted job order {$jobOrderNumber}.",
            oldValues: $before
        );

        return response()->json([
            'message' => "Job Order {$jobOrderNumber} deleted successfully.",
        ]);
    }

    public function restore(Request $request, string $jobOrderId)
    {
        $this->authorizeAdmin($request);
        $shopId = $this->currentShopId($request);

        $jobOrder = JobOrder::onlyTrashed()
            ->where('shop_id', $shopId)
            ->findOrFail($jobOrderId);

        if ($jobOrder->repairHasStarted()) {
            throw ValidationException::withMessages([
                'job_order' => 'This job order cannot be permanently deleted because the approved repair has already started.',
            ]);
        }

        $jobOrderNumber = $jobOrder->job_order_number;
        $jobOrder->restore();

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'job_order.restored',
            module: 'job_orders',
            record: $jobOrder->fresh(),
            description: "Restored job order {$jobOrderNumber}.",
            newValues: $jobOrder->fresh()->only(['job_order_number', 'customer_name', 'current_status', 'payment_status'])
        );

        return response()->json([
            'message' => "Job Order {$jobOrderNumber} restored successfully.",
        ]);
    }

    public function forceDelete(Request $request, string $jobOrderId)
    {
        $this->authorizeAdmin($request);
        $shopId = $this->currentShopId($request);

        $jobOrder = JobOrder::onlyTrashed()
            ->where('shop_id', $shopId)
            ->findOrFail($jobOrderId);
        if ((float) $jobOrder->amount_paid > 0 || $jobOrder->payments()->exists() || $jobOrder->refunds()->exists()) {
            throw ValidationException::withMessages([
                'job_order' => 'Job orders with payment or refund history cannot be permanently deleted.',
            ]);
        }
        if ($jobOrder->partOrders()->exists()) {
            throw ValidationException::withMessages([
                'job_order' => 'Job orders with customer parts-order history cannot be permanently deleted.',
            ]);
        }
        $jobOrderNumber = $jobOrder->job_order_number;
        $before = $jobOrder->only(['job_order_number', 'customer_name', 'current_status', 'payment_status', 'amount_paid', 'total_cost']);
        $jobOrder->forceDelete();

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'job_order.force_deleted',
            module: 'job_orders',
            description: "Permanently deleted job order {$jobOrderNumber}.",
            oldValues: $before
        );

        return response()->json([
            'message' => "Job Order {$jobOrderNumber} permanently deleted.",
        ]);
    }

    public function updateStatus(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        $validated = $request->validate([
            'status' => 'required|in:RECEIVED,DIAGNOSING,WAITING_PARTS,IN_PROGRESS,READY_FOR_PICKUP,RTO',
            'note' => 'nullable|string',
        ]);

        [$jobOrder, $previousStatus] = DB::transaction(function () use ($request, $jobOrder, $validated) {
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $this->currentShopId($request))
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);

            if ($lockedJobOrder->current_status === $validated['status']) {
                return [$lockedJobOrder, null];
            }

            if (in_array($lockedJobOrder->current_status, ['PICKED_UP', 'RTO_RELEASED', 'RTO'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'This job order cannot return to a repair workflow from its current status.',
                ]);
            }

            $previousStatus = $lockedJobOrder->current_status;
            $lockedJobOrder->update(['current_status' => $validated['status']]);

            StatusHistory::create([
                'shop_id' => $lockedJobOrder->shop_id,
                'job_order_id' => $lockedJobOrder->id,
                'status' => $validated['status'],
                'note' => $validated['note'] ?? null,
            ]);

            if ($validated['status'] === 'RTO') {
                $this->createRtoRefundObligations($lockedJobOrder, $request);
                AuditLogger::log(
                    request: $request,
                    user: $request->user(),
                    action: 'job_order.status_updated',
                    module: 'job_orders',
                    record: $lockedJobOrder,
                    description: "Updated {$lockedJobOrder->job_order_number} status from {$previousStatus} to {$validated['status']}.",
                    oldValues: ['current_status' => $previousStatus],
                    newValues: ['current_status' => $validated['status'], 'note' => $validated['note'] ?? null]
                );
            }

            return [$lockedJobOrder->fresh(), $previousStatus];
        });

        if ($previousStatus === null) {
            return response()->json([
                'message' => 'Status is already set. No changes made.',
                'jobOrder' => $jobOrder,
            ]);
        }

        $this->notifyCustomer($jobOrder, $validated['status'], $validated['note'] ?? null);

        if ($validated['status'] !== 'RTO') {
            AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'job_order.status_updated',
            module: 'job_orders',
            record: $jobOrder,
            description: "Updated {$jobOrder->job_order_number} status from {$previousStatus} to {$validated['status']}.",
            oldValues: ['current_status' => $previousStatus],
            newValues: ['current_status' => $validated['status'], 'note' => $validated['note'] ?? null]
            );
        }

        return response()->json([
            'message' => 'Status updated successfully.',
            'jobOrder' => $jobOrder->fresh(),
        ]);
    }

    public function markPickedUp(Request $request, JobOrder $jobOrder)
    {
        $user = $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        $validated = $request->validate([
            'picked_up_by' => 'required|string|max:100',
            'override_unpaid_balance' => 'nullable|boolean',
            'override_reason' => 'nullable|string|max:1000',
        ]);

        [$jobOrder, $hasUnpaidBalance] = DB::transaction(function () use ($request, $jobOrder, $validated, $user) {
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $this->currentShopId($request))
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);

            if ($lockedJobOrder->current_status !== 'READY_FOR_PICKUP' || $lockedJobOrder->picked_up_at) {
                throw ValidationException::withMessages([
                    'picked_up_by' => 'Only job orders marked ready for pickup can be released.',
                ]);
            }

            $hasUnpaidBalance = $lockedJobOrder->balance > 0;
            $isAdmin = $user->hasAnyRole(User::adminRoles());
            $isOverride = $request->boolean('override_unpaid_balance');

            if ($hasUnpaidBalance && (!$isAdmin || !$isOverride)) {
                throw ValidationException::withMessages([
                    'picked_up_by' => 'This job order has an unpaid balance. An admin must explicitly approve the release override.',
                ]);
            }

            if ($hasUnpaidBalance && empty(trim((string) ($validated['override_reason'] ?? '')))) {
                throw ValidationException::withMessages([
                    'override_reason' => 'An override reason is required when releasing a device with an unpaid balance.',
                ]);
            }

            $lockedJobOrder->update([
                'picked_up_at' => now(),
                'picked_up_by' => $validated['picked_up_by'],
                'pickup_override_by' => $hasUnpaidBalance ? $user->name : null,
                'pickup_override_reason' => $hasUnpaidBalance ? $validated['override_reason'] : null,
                'current_status' => 'PICKED_UP',
            ]);

            StatusHistory::create([
                'shop_id' => $lockedJobOrder->shop_id,
                'job_order_id' => $lockedJobOrder->id,
                'status' => 'PICKED_UP',
                'note' => "Device released to {$validated['picked_up_by']}",
            ]);

            return [$lockedJobOrder->fresh(), $hasUnpaidBalance];
        });

        $this->notifyCustomer($jobOrder, 'PICKED_UP', "Device released to {$validated['picked_up_by']}");

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'job_order.picked_up',
            module: 'job_orders',
            record: $jobOrder,
            description: "Released {$jobOrder->job_order_number}.",
            oldValues: ['current_status' => 'READY_FOR_PICKUP', 'picked_up_at' => null, 'picked_up_by' => null],
            newValues: [
                'current_status' => 'PICKED_UP',
                'picked_up_by' => $validated['picked_up_by'],
                'pickup_override_by' => $hasUnpaidBalance ? $user->name : null,
                'pickup_override_reason' => $hasUnpaidBalance ? $validated['override_reason'] : null,
            ]
        );

        return response()->json([
            'message' => 'Device released.',
            'jobOrder' => $jobOrder->fresh(),
        ]);
    }

    public function releaseRto(Request $request, JobOrder $jobOrder)
    {
        $user = $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        $validated = $request->validate([
            'picked_up_by' => 'required|string|max:100',
        ]);

        [$jobOrder, $previousStatus] = DB::transaction(function () use ($request, $jobOrder, $validated) {
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $this->currentShopId($request))
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);

            if ($lockedJobOrder->current_status !== 'RTO' || $lockedJobOrder->picked_up_at) {
                throw ValidationException::withMessages([
                    'picked_up_by' => 'Only RTO job orders that have not yet been released can be returned to the customer.',
                ]);
            }

            $this->createRtoRefundObligations($lockedJobOrder, $request);
            $previousStatus = $lockedJobOrder->current_status;
            $lockedJobOrder->update([
                'current_status' => 'RTO_RELEASED',
                'picked_up_at' => now(),
                'picked_up_by' => $validated['picked_up_by'],
                'pickup_override_by' => null,
                'pickup_override_reason' => null,
            ]);

            StatusHistory::create([
                'shop_id' => $lockedJobOrder->shop_id,
                'job_order_id' => $lockedJobOrder->id,
                'status' => 'RTO_RELEASED',
                'note' => "RTO device released to {$validated['picked_up_by']}",
            ]);

            return [$lockedJobOrder->fresh(), $previousStatus];
        });

        $this->notifyCustomer($jobOrder, 'RTO_RELEASED', "RTO device released to {$validated['picked_up_by']}");

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'job_order.rto_released',
            module: 'job_orders',
            record: $jobOrder,
            description: "Released RTO device for {$jobOrder->job_order_number}.",
            oldValues: ['current_status' => $previousStatus, 'picked_up_at' => null, 'picked_up_by' => null],
            newValues: [
                'current_status' => 'RTO_RELEASED',
                'picked_up_by' => $validated['picked_up_by'],
                'pickup_override_by' => null,
                'pickup_override_reason' => null,
            ]
        );

        return response()->json([
            'message' => 'RTO device released.',
            'jobOrder' => $jobOrder->fresh(),
        ]);
    }

    public function prepareRtoRefunds(Request $request, JobOrder $jobOrder)
    {
        $user = $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        [$refunds, $createdCount] = DB::transaction(function () use ($request, $jobOrder, $user) {
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $this->currentShopId($request))
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);

            if (!in_array($lockedJobOrder->current_status, ['RTO', 'RTO_RELEASED'], true)) {
                throw ValidationException::withMessages([
                    'job_order' => 'Refund obligations can only be prepared for an RTO job order.',
                ]);
            }

            $createdCount = $this->createRtoRefundObligations($lockedJobOrder, $request);

            AuditLogger::log(
                request: $request,
                user: $user,
                action: 'job_order.rto_refunds_prepared',
                module: 'payments',
                record: $lockedJobOrder,
                description: "Prepared RTO refund obligations for {$lockedJobOrder->job_order_number}.",
                newValues: ['refunds_created' => $createdCount]
            );

            return [$lockedJobOrder->refunds()->latest('initiated_at')->get(), $createdCount];
        });

        return response()->json([
            'message' => $createdCount ? 'Refund obligations prepared.' : 'No additional refund obligations were needed.',
            'refunds' => $refunds,
        ]);
    }

    public function reconcileLegacyRtoRefund(Request $request, JobOrder $jobOrder)
    {
        $user = $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'reconciliation_reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $refund = DB::transaction(function () use ($request, $jobOrder, $validated, $user) {
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $this->currentShopId($request))
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);

            if (!in_array($lockedJobOrder->current_status, ['RTO', 'RTO_RELEASED'], true)) {
                throw ValidationException::withMessages([
                    'job_order' => 'Legacy refund reconciliation is only available for an RTO job order.',
                ]);
            }

            $amount = round((float) $validated['amount'], 2);
            $available = $this->legacyRefundReconciliationAmount($lockedJobOrder);
            if ($amount > $available) {
                throw ValidationException::withMessages([
                    'amount' => 'The refund exceeds the unmatched legacy payment amount.',
                ]);
            }

            $refund = JobOrderRefund::create([
                'shop_id' => $lockedJobOrder->shop_id,
                'job_order_id' => $lockedJobOrder->id,
                'job_order_payment_id' => null,
                'amount' => $amount,
                'status' => JobOrderRefund::STATUS_PENDING,
                'reason' => 'RTO / Repair unsuccessful',
                'reconciliation_reason' => $validated['reconciliation_reason'],
                'initiated_by' => $user->id,
                'initiated_at' => now(),
            ]);

            AuditLogger::log(
                request: $request,
                user: $user,
                action: 'job_order.legacy_rto_refund_reconciled',
                module: 'payments',
                record: $lockedJobOrder,
                description: "Reconciled a legacy RTO refund for {$lockedJobOrder->job_order_number}.",
                newValues: $refund->only(['id', 'amount', 'reason', 'reconciliation_reason', 'status'])
            );

            return $refund;
        });

        return response()->json([
            'message' => 'Legacy refund obligation recorded.',
            'refund' => $refund,
        ], 201);
    }

    public function issueRtoRefund(Request $request, JobOrder $jobOrder, JobOrderRefund $refund)
    {
        $user = $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        $validated = $request->validate([
            'refund_method' => ['required', Rule::in(['CASH', 'GCASH', 'BANK_TRANSFER', 'CARD'])],
            'reference_number' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validated['refund_method'] !== 'CASH' && empty($validated['reference_number'])) {
            throw ValidationException::withMessages([
                'reference_number' => 'A reference number is required for non-cash refunds.',
            ]);
        }

        $issuedRefund = DB::transaction(function () use ($request, $jobOrder, $refund, $validated, $user) {
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $this->currentShopId($request))
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);
            $lockedRefund = JobOrderRefund::query()
                ->where('shop_id', $lockedJobOrder->shop_id)
                ->where('job_order_id', $lockedJobOrder->id)
                ->lockForUpdate()
                ->findOrFail($refund->id);

            if (!in_array($lockedJobOrder->current_status, ['RTO', 'RTO_RELEASED'], true)) {
                throw ValidationException::withMessages([
                    'refund' => 'Only RTO refunds can be issued.',
                ]);
            }
            if ($lockedRefund->status !== JobOrderRefund::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'refund' => 'This refund has already been issued.',
                ]);
            }

            $lockedRefund->update([
                'status' => JobOrderRefund::STATUS_ISSUED,
                'refund_method' => $validated['refund_method'],
                'reference_number' => $validated['reference_number'] ?? null,
                'issued_by' => $user->id,
                'issued_at' => now(),
            ]);

            AuditLogger::log(
                request: $request,
                user: $user,
                action: 'job_order.rto_refund_issued',
                module: 'payments',
                record: $lockedJobOrder,
                description: "Issued an RTO refund for {$lockedJobOrder->job_order_number}.",
                newValues: $lockedRefund->fresh()->only(['id', 'amount', 'status', 'refund_method', 'reference_number', 'issued_by', 'issued_at'])
            );

            return $lockedRefund->fresh();
        });

        return response()->json([
            'message' => 'Refund issued and recorded.',
            'refund' => $issuedRefund,
        ]);
    }

    public function recordPayment(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        $validated = $request->validate([
            'amount_paid' => 'required|numeric|min:0.01',
            'idempotency_key' => ['required', 'uuid'],
            'payment_type' => 'nullable|in:PARTIAL_PAYMENT,FINAL_PAYMENT',
            'payment_method' => 'nullable|string|max:40',
            'reference_number' => 'nullable|string|max:100',
            'received_at' => 'nullable|date',
            'payment_notes' => 'nullable|string',
        ]);

        [$jobOrder, $payment, $paymentStatus, $amountPaid] = DB::transaction(function () use ($request, $jobOrder, $validated) {
            $shopId = $this->currentShopId($request);
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);
            $this->ensureJobOrderIsNotRto($lockedJobOrder);
            $before = $lockedJobOrder->only(['amount_paid', 'payment_status', 'payment_date', 'payment_notes']);

            $existingPayment = JobOrderPayment::query()
                ->where('idempotency_key', $validated['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existingPayment) {
                $samePaymentIntent = (float) $existingPayment->amount === (float) $validated['amount_paid']
                    && (!$request->filled('payment_type') || $existingPayment->payment_type === $validated['payment_type'])
                    && ($existingPayment->payment_method ?? null) === ($validated['payment_method'] ?? null)
                    && ($existingPayment->reference_number ?? null) === ($validated['reference_number'] ?? null)
                    && ($existingPayment->notes ?? null) === ($validated['payment_notes'] ?? null)
                    && ((int) $existingPayment->job_order_id === (int) $lockedJobOrder->id)
                    && ((int) $existingPayment->shop_id === (int) $lockedJobOrder->shop_id);

                if (!$samePaymentIntent) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'This idempotency key was already used with different payment data.',
                    ]);
                }

                return [$lockedJobOrder->fresh(), $existingPayment->fresh(), $lockedJobOrder->payment_status, (float) $existingPayment->amount];
            }

            if ($lockedJobOrder->balance <= 0) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'Payment already settled. No balance remaining.',
                ]);
            }

            if ((float) $validated['amount_paid'] > $lockedJobOrder->balance) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'Amount exceeds remaining balance.',
                ]);
            }

            $amountPaid = (float) $validated['amount_paid'];
            $newAmountPaid = (float) $lockedJobOrder->amount_paid + $amountPaid;
            $paymentStatus = 'PARTIAL';

            if ($newAmountPaid >= $lockedJobOrder->total_cost) {
                $paymentStatus = 'PAID';
                $newAmountPaid = $lockedJobOrder->total_cost;
            }

            $paymentType = $paymentStatus === 'PAID' ? 'FINAL_PAYMENT' : 'PARTIAL_PAYMENT';

            if (isset($validated['payment_type']) && $validated['payment_type'] !== $paymentType) {
                throw ValidationException::withMessages([
                    'payment_type' => 'Payment type must match the resulting payment status.',
                ]);
            }

            $payment = JobOrderPayment::create([
                'shop_id' => $lockedJobOrder->shop_id,
                'job_order_id' => $lockedJobOrder->id,
                'idempotency_key' => $validated['idempotency_key'],
                'amount' => $amountPaid,
                'payment_type' => $paymentType,
                'payment_method' => $validated['payment_method'] ?? null,
                'reference_number' => $validated['reference_number'] ?? null,
                'notes' => $validated['payment_notes'] ?? null,
                'received_at' => $validated['received_at'] ?? now(),
                'received_by' => $request->user()?->name,
            ]);

            $lockedJobOrder->update([
                'amount_paid' => $newAmountPaid,
                'payment_status' => $paymentStatus,
                'payment_date' => now(),
                'payment_notes' => $validated['payment_notes'] ?? null,
            ]);

            AuditLogger::log(
                request: $request,
                user: $request->user(),
                action: 'job_order.payment_recorded',
                module: 'payments',
                record: $lockedJobOrder,
                description: "Recorded payment for {$lockedJobOrder->job_order_number}.",
                oldValues: $before,
                newValues: [
                    'amount_paid' => $lockedJobOrder->fresh()->amount_paid,
                    'payment_status' => $lockedJobOrder->fresh()->payment_status,
                    'payment_type' => $paymentType,
                    'payment_amount' => $amountPaid,
                    'payment_method' => $validated['payment_method'] ?? null,
                    'payment_id' => $payment->id,
                    'idempotency_key' => $payment->idempotency_key,
                ]
            );

            return [$lockedJobOrder->fresh(), $payment->fresh(), $paymentStatus, $amountPaid];
        });

        return response()->json([
            'message' => "Payment recorded. Status: {$paymentStatus}",
            'jobOrder' => $jobOrder->fresh(),
            'payment' => $this->mapPayment($payment),
        ]);
    }

    public function updateCosts(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        $validated = $request->validate([
            'parts_cost' => 'required|numeric|min:0',
            'labor_cost' => 'required|numeric|min:0',
        ]);

        $jobOrder = DB::transaction(function () use ($request, $jobOrder, $validated) {
            $shopId = $this->currentShopId($request);
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);
            $this->ensureJobOrderIsNotRto($lockedJobOrder);
            $before = $lockedJobOrder->only(['parts_cost', 'labor_cost', 'total_cost', 'payment_status']);
            $partsTotal = $lockedJobOrder->parts()->exists()
                ? $lockedJobOrder->parts()->sum('total_cost')
                : $validated['parts_cost'];
            $totalCost = $partsTotal + $validated['labor_cost'];

            $this->ensureTotalCoversCollectedAmount($totalCost, $lockedJobOrder->amount_paid);

            $lockedJobOrder->update([
                'parts_cost' => $partsTotal,
                'labor_cost' => $validated['labor_cost'],
                'total_cost' => $totalCost,
                'payment_status' => $lockedJobOrder->amount_paid <= 0
                    ? 'PENDING'
                    : ($lockedJobOrder->amount_paid >= $totalCost ? 'PAID' : 'PARTIAL'),
            ]);

            AuditLogger::log(
                request: $request,
                user: $request->user(),
                action: 'job_order.costs_updated',
                module: 'job_orders',
                record: $lockedJobOrder,
                description: "Updated cost breakdown for {$lockedJobOrder->job_order_number}.",
                oldValues: $before,
                newValues: $lockedJobOrder->fresh()->only(['parts_cost', 'labor_cost', 'total_cost', 'payment_status'])
            );

            return $lockedJobOrder->fresh();
        });

        return response()->json([
            'message' => 'Costs updated successfully.',
            'jobOrder' => $jobOrder->fresh(),
        ]);
    }

    public function rateTechnician(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        $validated = $request->validate([
            'technician_rating' => 'required|integer|min:1|max:5',
        ]);

        $jobOrder->update([
            'technician_rating' => $validated['technician_rating'],
        ]);

        return response()->json([
            'message' => 'Technician rated successfully.',
            'jobOrder' => $jobOrder->fresh(),
        ]);
    }

    public function addPart(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);
        $shopId = $this->currentShopId($request);

        $validated = $request->validate([
            'spare_part_id' => [
                'nullable',
                Rule::exists('spare_parts', 'id')->where(fn ($query) => $query->where('shop_id', $shopId)),
            ],
            'part_name' => 'nullable|string|max:150|required_without:spare_part_id',
            'quantity' => 'required|integer|min:1',
            'unit_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:255',
        ]);

        [$jobOrder, $part] = DB::transaction(function () use ($validated, $jobOrder, $request, $shopId) {
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);
            $this->ensureJobOrderIsNotRto($lockedJobOrder);

            $sparePart = null;
            if (!empty($validated['spare_part_id'])) {
                $sparePart = SparePart::query()
                    ->where('shop_id', $shopId)
                    ->lockForUpdate()
                    ->findOrFail($validated['spare_part_id']);

                if (!$sparePart->is_active) {
                    throw ValidationException::withMessages([
                        'spare_part_id' => 'Selected spare part is inactive.',
                    ]);
                }

                if ($sparePart->stock_quantity < $validated['quantity']) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Insufficient stock for selected spare part.',
                    ]);
                }
            }

            $unitCost = $validated['unit_cost'] ?? ($sparePart?->unit_cost ?? 0);
            $partName = $sparePart?->name ?? $validated['part_name'];
            $total = $validated['quantity'] * $unitCost;
            $partsTotal = (float) $lockedJobOrder->parts()->sum('total_cost') + $total;
            $this->ensureTotalCoversCollectedAmount($partsTotal + (float) $lockedJobOrder->labor_cost, $lockedJobOrder->amount_paid);

            $part = JobOrderPart::create([
                'shop_id' => $lockedJobOrder->shop_id,
                'job_order_id' => $lockedJobOrder->id,
                'spare_part_id' => $sparePart?->id,
                'part_name' => $partName,
                'quantity' => $validated['quantity'],
                'unit_cost' => $unitCost,
                'total_cost' => $total,
                'notes' => $validated['notes'] ?? null,
            ]);

            if ($sparePart) {
                $sparePart->decrement('stock_quantity', $validated['quantity']);
                $sparePart->refresh();
                $this->recordStockMovement(
                    sparePart: $sparePart,
                    type: 'OUT',
                    quantityChange: -$validated['quantity'],
                    note: "Used for job order {$jobOrder->job_order_number}",
                    jobOrderId: $jobOrder->id,
                    jobOrderPartId: $part->id,
                    createdBy: $request->user()?->name
                );
            }

            $lockedJobOrder->update([
                'parts_cost' => $partsTotal,
                'total_cost' => $partsTotal + (float) $lockedJobOrder->labor_cost,
                'payment_status' => $lockedJobOrder->amount_paid <= 0
                    ? 'PENDING'
                    : ($lockedJobOrder->amount_paid >= ($partsTotal + (float) $lockedJobOrder->labor_cost) ? 'PAID' : 'PARTIAL'),
            ]);

            return [$lockedJobOrder->fresh(), $part];
        });

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'job_order.part_added',
            module: 'job_orders',
            record: $jobOrder,
            description: "Added part {$part->part_name} to {$jobOrder->job_order_number}.",
            newValues: $part->only(['part_name', 'quantity', 'unit_cost', 'total_cost', 'spare_part_id'])
        );

        return response()->json([
            'message' => 'Part added successfully.',
            'part' => $part->load('sparePart:id,name,stock_quantity,reorder_level,unit'),
            'jobOrder' => $jobOrder->fresh(),
        ], 201);
    }

    public function updatePart(Request $request, JobOrder $jobOrder, JobOrderPart $part)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);
        $shopId = $this->currentShopId($request);

        if ($part->job_order_id !== $jobOrder->id || (int) $part->shop_id !== (int) $jobOrder->shop_id) {
            abort(Response::HTTP_NOT_FOUND);
        }
        if ($part->job_order_part_order_id) {
            throw ValidationException::withMessages([
                'part' => 'A part issued from a customer parts order cannot be edited separately from its order and inventory history.',
            ]);
        }

        $validated = $request->validate([
            'spare_part_id' => 'nullable|exists:spare_parts,id',
            'part_name' => 'nullable|string|max:150|required_without:spare_part_id',
            'quantity' => 'required|integer|min:1',
            'unit_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:255',
        ]);

        $before = $part->only(['part_name', 'quantity', 'unit_cost', 'total_cost', 'spare_part_id', 'notes']);

        [$jobOrder, $part] = DB::transaction(function () use ($validated, $part, $jobOrder, $request, $shopId) {
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);
            $this->ensureJobOrderIsNotRto($lockedJobOrder);
            $existingPart = JobOrderPart::query()
                ->where('job_order_id', $lockedJobOrder->id)
                ->where('shop_id', $lockedJobOrder->shop_id)
                ->lockForUpdate()
                ->findOrFail($part->id);
            if ($existingPart->job_order_part_order_id) {
                throw ValidationException::withMessages([
                    'part' => 'A part issued from a customer parts order cannot be edited separately from its order and inventory history.',
                ]);
            }

            $oldSparePart = null;
            if ($existingPart->spare_part_id) {
                $oldSparePart = SparePart::query()
                    ->where('shop_id', $shopId)
                    ->lockForUpdate()
                    ->find($existingPart->spare_part_id);
            }

            $newSparePart = null;
            if (!empty($validated['spare_part_id'])) {
                $newSparePart = SparePart::query()
                    ->where('shop_id', $shopId)
                    ->lockForUpdate()
                    ->findOrFail($validated['spare_part_id']);

                if (!$newSparePart->is_active) {
                    throw ValidationException::withMessages([
                        'spare_part_id' => 'Selected spare part is inactive.',
                    ]);
                }
            }

            if ($oldSparePart) {
                $oldSparePart->increment('stock_quantity', $existingPart->quantity);
                $oldSparePart->refresh();
                $this->recordStockMovement(
                    sparePart: $oldSparePart,
                    type: 'IN',
                    quantityChange: $existingPart->quantity,
                    note: "Reversal from job order {$jobOrder->job_order_number} part update",
                    jobOrderId: $jobOrder->id,
                    jobOrderPartId: $existingPart->id,
                    createdBy: $request->user()?->name
                );
            }

            if ($newSparePart && $newSparePart->stock_quantity < $validated['quantity']) {
                throw ValidationException::withMessages([
                    'quantity' => 'Insufficient stock for selected spare part.',
                ]);
            }

            $unitCost = $validated['unit_cost'] ?? ($newSparePart?->unit_cost ?? 0);
            $partName = $newSparePart?->name ?? $validated['part_name'];
            $total = $validated['quantity'] * $unitCost;
            $partsTotal = (float) $lockedJobOrder->parts()->sum('total_cost')
                - (float) $existingPart->total_cost
                + $total;
            $this->ensureTotalCoversCollectedAmount($partsTotal + (float) $lockedJobOrder->labor_cost, $lockedJobOrder->amount_paid);

            $existingPart->update([
                'spare_part_id' => $newSparePart?->id,
                'part_name' => $partName,
                'quantity' => $validated['quantity'],
                'unit_cost' => $unitCost,
                'total_cost' => $total,
                'notes' => $validated['notes'] ?? null,
            ]);

            if ($newSparePart) {
                $newSparePart->decrement('stock_quantity', $validated['quantity']);
                $newSparePart->refresh();
                $this->recordStockMovement(
                    sparePart: $newSparePart,
                    type: 'OUT',
                    quantityChange: -$validated['quantity'],
                    note: "Used for job order {$jobOrder->job_order_number}",
                    jobOrderId: $jobOrder->id,
                    jobOrderPartId: $existingPart->id,
                    createdBy: $request->user()?->name
                );
            }

            $lockedJobOrder->update([
                'parts_cost' => $partsTotal,
                'total_cost' => $partsTotal + (float) $lockedJobOrder->labor_cost,
                'payment_status' => $lockedJobOrder->amount_paid <= 0
                    ? 'PENDING'
                    : ($lockedJobOrder->amount_paid >= ($partsTotal + (float) $lockedJobOrder->labor_cost) ? 'PAID' : 'PARTIAL'),
            ]);

            return [$lockedJobOrder->fresh(), $existingPart->fresh()];
        });

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'job_order.part_updated',
            module: 'job_orders',
            record: $jobOrder,
            description: "Updated part {$part->fresh()->part_name} in {$jobOrder->job_order_number}.",
            oldValues: $before,
            newValues: $part->fresh()->only(['part_name', 'quantity', 'unit_cost', 'total_cost', 'spare_part_id', 'notes'])
        );

        return response()->json([
            'message' => 'Part updated successfully.',
            'part' => $part->fresh()->load('sparePart:id,name,stock_quantity,reorder_level,unit'),
            'jobOrder' => $jobOrder->fresh(),
        ]);
    }

    public function deletePart(Request $request, JobOrder $jobOrder, JobOrderPart $part)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);
        $shopId = $this->currentShopId($request);

        if ($part->job_order_id !== $jobOrder->id || (int) $part->shop_id !== (int) $jobOrder->shop_id) {
            abort(Response::HTTP_NOT_FOUND);
        }
        if ($part->job_order_part_order_id) {
            throw ValidationException::withMessages([
                'part' => 'A part issued from a customer parts order cannot be removed separately from its order and inventory history.',
            ]);
        }

        $before = $part->only(['part_name', 'quantity', 'unit_cost', 'total_cost', 'spare_part_id', 'notes']);

        [$jobOrder, $before] = DB::transaction(function () use ($part, $jobOrder, $request, $shopId) {
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);
            $this->ensureJobOrderIsNotRto($lockedJobOrder);
            $existingPart = JobOrderPart::query()
                ->where('job_order_id', $lockedJobOrder->id)
                ->where('shop_id', $lockedJobOrder->shop_id)
                ->lockForUpdate()
                ->findOrFail($part->id);
            if ($existingPart->job_order_part_order_id) {
                throw ValidationException::withMessages([
                    'part' => 'A part issued from a customer parts order cannot be removed separately from its order and inventory history.',
                ]);
            }
            $before = $existingPart->only(['part_name', 'quantity', 'unit_cost', 'total_cost', 'spare_part_id', 'notes']);
            $partsTotal = (float) $lockedJobOrder->parts()->sum('total_cost') - (float) $existingPart->total_cost;
            $this->ensureTotalCoversCollectedAmount($partsTotal + (float) $lockedJobOrder->labor_cost, $lockedJobOrder->amount_paid);

            if ($existingPart->spare_part_id) {
                $sparePart = SparePart::query()
                    ->where('shop_id', $shopId)
                    ->lockForUpdate()
                    ->find($existingPart->spare_part_id);
                if ($sparePart) {
                    $sparePart->increment('stock_quantity', $existingPart->quantity);
                    $sparePart->refresh();
                    $this->recordStockMovement(
                        sparePart: $sparePart,
                        type: 'IN',
                        quantityChange: $existingPart->quantity,
                        note: "Returned from job order {$jobOrder->job_order_number}",
                        jobOrderId: $jobOrder->id,
                        jobOrderPartId: $existingPart->id,
                        createdBy: $request->user()?->name
                    );
                }
            }

            $existingPart->delete();

            $lockedJobOrder->update([
                'parts_cost' => $partsTotal,
                'total_cost' => $partsTotal + (float) $lockedJobOrder->labor_cost,
                'payment_status' => $lockedJobOrder->amount_paid <= 0
                    ? 'PENDING'
                    : ($lockedJobOrder->amount_paid >= ($partsTotal + (float) $lockedJobOrder->labor_cost) ? 'PAID' : 'PARTIAL'),
            ]);

            return [$lockedJobOrder->fresh(), $before];
        });

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'job_order.part_deleted',
            module: 'job_orders',
            record: $jobOrder,
            description: "Removed part {$before['part_name']} from {$jobOrder->job_order_number}.",
            oldValues: $before
        );

        return response()->json([
            'message' => 'Part removed.',
            'jobOrder' => $jobOrder->fresh(),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
    }

    private function ensureTotalCoversCollectedAmount(float|int|string $totalCost, float|int|string $amountPaid): void
    {
        if ((float) $totalCost < (float) $amountPaid) {
            throw ValidationException::withMessages([
                'total_cost' => 'Total cost cannot be lower than the amount already paid.',
            ]);
        }
    }

    private function ensureJobOrderIsNotRto(JobOrder $jobOrder): void
    {
        if (in_array($jobOrder->current_status, ['RTO', 'RTO_RELEASED'], true)) {
            throw ValidationException::withMessages([
                'job_order' => 'Payments and repair costs cannot be changed after a job order becomes RTO.',
            ]);
        }
    }

    private function createRtoRefundObligations(JobOrder $jobOrder, Request $request): int
    {
        $created = 0;
        $payments = $jobOrder->payments()->lockForUpdate()->orderBy('id')->get();

        foreach ($payments as $payment) {
            $alreadyRefunded = JobOrderRefund::query()
                ->where('job_order_payment_id', $payment->id)
                ->exists();
            if ($alreadyRefunded || (float) $payment->amount <= 0) {
                continue;
            }

            $refund = JobOrderRefund::create([
                'shop_id' => $jobOrder->shop_id,
                'job_order_id' => $jobOrder->id,
                'job_order_payment_id' => $payment->id,
                'amount' => $payment->amount,
                'status' => JobOrderRefund::STATUS_PENDING,
                'reason' => 'RTO / Repair unsuccessful',
                'initiated_by' => $request->user()?->id,
                'initiated_at' => now(),
            ]);

            AuditLogger::log(
                request: $request,
                user: $request->user(),
                action: 'job_order.rto_refund_obligation_created',
                module: 'payments',
                record: $jobOrder,
                description: "Created an RTO refund obligation for {$jobOrder->job_order_number}.",
                newValues: $refund->only(['id', 'job_order_payment_id', 'amount', 'reason', 'status'])
            );
            $created++;
        }

        return $created;
    }

    private function legacyRefundReconciliationAmount(JobOrder $jobOrder): float
    {
        $receiptTotal = (float) $jobOrder->payments()->sum('amount');
        $reconciledTotal = (float) $jobOrder->refunds()
            ->whereNull('job_order_payment_id')
            ->sum('amount');

        return round(max((float) $jobOrder->amount_paid - $receiptTotal - $reconciledTotal, 0), 2);
    }

    private function createCoveredWarrantyService(
        JobOrder $jobOrder,
        Request $request,
        User $user,
        string $reportedIssue,
        ?string $checkedBy = null,
        ?string $assessmentNotes = null
    ): WarrantyAssessment {
        $warrantyJobOrder = JobOrder::create([
            'shop_id' => $jobOrder->shop_id,
            'job_order_number' => $this->generateJobOrderNumber('WTY'),
            'service_type' => 'WARRANTY_SERVICE',
            'warranty_parent_job_order_id' => $jobOrder->id,
            'customer_name' => $jobOrder->customer_name,
            'customer_contact' => $jobOrder->customer_contact,
            'customer_email' => $jobOrder->customer_email,
            'customer_phone' => $jobOrder->customer_phone,
            'device_type' => $jobOrder->device_type,
            'service_category_id' => $jobOrder->service_category_id,
            'service_item_id' => $jobOrder->service_item_id,
            'brand' => $jobOrder->brand,
            'model' => $jobOrder->model,
            'serial_number' => $jobOrder->serial_number,
            'problem_description' => $reportedIssue,
            'received_by' => $request->user()?->name,
            'included_accessories' => $jobOrder->included_accessories,
            'current_status' => 'RECEIVED',
            'device_location' => 'AT_SHOP',
            'priority' => 'STANDARD',
            'estimated_cost' => 0,
            'parts_cost' => 0,
            'labor_cost' => 0,
            'total_cost' => 0,
            'amount_paid' => 0,
            'payment_status' => 'PAID',
            'warranty_days' => 0,
            'internal_notes' => "Warranty service linked to {$jobOrder->job_order_number}.",
        ]);

        StatusHistory::create([
            'shop_id' => $jobOrder->shop_id,
            'job_order_id' => $warrantyJobOrder->id,
            'status' => 'RECEIVED',
            'note' => "Warranty service issued from {$jobOrder->job_order_number}.",
        ]);

        $assessment = WarrantyAssessment::create([
            'shop_id' => $jobOrder->shop_id,
            'job_order_id' => $jobOrder->id,
            'warranty_job_order_id' => $warrantyJobOrder->id,
            'reported_issue' => $reportedIssue,
            'brought_at' => now(),
            'checked_by' => $checkedBy,
            'result' => WarrantyAssessment::RESULT_COVERED,
            'assessment_notes' => $assessmentNotes,
            'created_by' => $user->name,
        ]);

        StatusHistory::create([
            'shop_id' => $jobOrder->shop_id,
            'job_order_id' => $jobOrder->id,
            'status' => $jobOrder->current_status,
            'note' => "Warranty service issued: {$warrantyJobOrder->job_order_number}.",
        ]);

        return $assessment;
    }

    private function assertCanIssueWarrantyService(JobOrder $jobOrder): void
    {
        if ($jobOrder->current_status !== 'PICKED_UP' || !$jobOrder->picked_up_at) {
            throw ValidationException::withMessages([
                'warranty_service' => 'Warranty service can only be issued after the original unit is released to the customer.',
            ]);
        }

        if ($jobOrder->payment_status !== 'PAID' || (float) $jobOrder->balance > 0) {
            throw ValidationException::withMessages([
                'warranty_service' => 'Warranty service can only be issued after the original job order is fully paid.',
            ]);
        }

        if ((int) ($jobOrder->warranty_days ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'warranty_service' => 'This job order has no warranty coverage recorded.',
            ]);
        }

        $warrantyExpiresAt = $jobOrder->picked_up_at->copy()->addDays((int) $jobOrder->warranty_days);

        if (now()->greaterThan($warrantyExpiresAt)) {
            throw ValidationException::withMessages([
                'warranty_service' => 'This job order warranty has already expired.',
            ]);
        }
    }

    private function assertNoActiveWarrantyService(JobOrder $jobOrder): void
    {
        $existingWarrantyJob = JobOrder::query()
            ->where('shop_id', $jobOrder->shop_id)
            ->where('warranty_parent_job_order_id', $jobOrder->id)
            ->where('service_type', 'WARRANTY_SERVICE')
            ->whereNull('picked_up_at')
            ->whereNot('current_status', 'RTO_RELEASED')
            ->first();

        if ($existingWarrantyJob) {
            throw ValidationException::withMessages([
                'warranty_service' => "Warranty service {$existingWarrantyJob->job_order_number} is still active.",
            ]);
        }
    }

    private function mapJobOrderSummary(JobOrder $job): array
    {
        $isOverdue = $this->isJobOverdue($job);

        return [
            'id' => $job->id,
            'job_order_number' => $job->job_order_number,
            'service_type' => $job->service_type ?? 'STANDARD',
            'warranty_parent_job_order_id' => $job->warranty_parent_job_order_id,
            'warranty_parent_job_order_number' => $job->warrantyParentJobOrder?->job_order_number,
            'is_warranty_service' => ($job->service_type ?? 'STANDARD') === 'WARRANTY_SERVICE',
            'customer_name' => $job->customer_name,
            'customer_phone' => $job->customer_phone,
            'device_type' => $job->device_type,
            'service_category_id' => $job->service_category_id,
            'service_category_name' => $job->serviceCategory?->name,
            'service_item_id' => $job->service_item_id,
            'service_item_name' => $job->serviceItem?->name,
            'brand' => $job->brand,
            'model' => $job->model,
            'current_status' => $job->current_status,
            'priority' => $job->priority,
            'payment_status' => $job->payment_status,
            'total_cost' => $job->total_cost,
            'amount_paid' => $job->amount_paid,
            'balance' => $job->balance,
            'technician_name' => $job->technician?->name,
            'estimated_completion' => $job->estimated_completion,
            'estimated_completion_formatted' => $job->estimated_completion?->format('M d, Y h:i A'),
            'is_overdue' => $isOverdue,
            'repair_started' => in_array($job->current_status, ['IN_PROGRESS', 'READY_FOR_PICKUP', 'PICKED_UP', 'RTO', 'RTO_RELEASED'], true)
                || (bool) ($job->has_started_repair ?? false),
            'deleted_at' => $job->deleted_at,
            'created_at_formatted' => $job->created_at?->format('M d, Y'),
        ];
    }

    private function normalizeServiceSelection(int $shopId, array $validated): array
    {
        $categoryId = $validated['service_category_id'] ?? null;
        $serviceId = $validated['service_item_id'] ?? null;
        $service = null;

        if ($serviceId) {
            $service = ServiceItem::query()
                ->where('shop_id', $shopId)
                ->where('is_active', true)
                ->find($serviceId);

            if (!$service) {
                throw ValidationException::withMessages([
                    'service_item_id' => 'Selected service is not available for this shop.',
                ]);
            }

            if ($categoryId && (int) $service->service_category_id !== (int) $categoryId) {
                throw ValidationException::withMessages([
                    'service_item_id' => 'Selected service does not belong to the selected device category.',
                ]);
            }

            $categoryId = $service->service_category_id;
        }

        if ($categoryId) {
            $category = ServiceCategory::query()
                ->where('shop_id', $shopId)
                ->where('is_active', true)
                ->find($categoryId);

            if (!$category) {
                throw ValidationException::withMessages([
                    'service_category_id' => 'Selected device category is not available for this shop.',
                ]);
            }

            $validated['service_category_id'] = $category->id;
            $validated['service_item_id'] = $service?->id;
            $validated['device_type'] = $category->name;
        } else {
            $validated['service_category_id'] = null;
            $validated['service_item_id'] = null;
        }

        return $validated;
    }

    private function activeServiceCatalog(int $shopId): array
    {
        return ServiceCategory::query()
            ->where('shop_id', $shopId)
            ->where('is_active', true)
            ->with(['services' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'services' => $category->services->map(fn (ServiceItem $service) => [
                    'id' => $service->id,
                    'service_category_id' => $service->service_category_id,
                    'name' => $service->name,
                    'description' => $service->description,
                ])->all(),
            ])
            ->all();
    }

    private function isJobOverdue(JobOrder $job): bool
    {
        if (!$job->estimated_completion) {
            return false;
        }

        if (in_array($job->current_status, ['READY_FOR_PICKUP', 'PICKED_UP', 'RTO', 'RTO_RELEASED'], true)) {
            return false;
        }

        return $job->estimated_completion->isPast();
    }

    private function mapPayment(JobOrderPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'payment_type' => $payment->payment_type === 'DOWN_PAYMENT' ? 'PARTIAL_PAYMENT' : $payment->payment_type,
            'payment_method' => $payment->payment_method,
            'reference_number' => $payment->reference_number,
            'notes' => $payment->notes,
            'received_at' => $payment->received_at,
            'received_at_formatted' => $payment->received_at?->format('M d, Y h:i A'),
            'received_by' => $payment->received_by,
            'created_at' => $payment->created_at,
        ];
    }

    private function mapPhoto(RepairPhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'photo_type' => $photo->photo_type,
            'photo_type_label' => str_replace('_', ' ', $photo->photo_type),
            'url' => route('admin.job-order.photos.file', ['jobOrder' => $photo->job_order_id, 'photo' => $photo->id]),
            'description' => $photo->description,
            'visible_to_customer' => (bool) $photo->visible_to_customer,
            'original_name' => $photo->original_name,
            'uploaded_by' => $photo->uploaded_by,
            'uploaded_by_role' => $photo->uploaded_by_role,
            'created_at' => $photo->created_at,
            'created_at_formatted' => $photo->created_at?->format('M d, Y h:i A'),
        ];
    }

    private function mapNotification(CustomerNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'channel' => $notification->channel,
            'channel_label' => strtoupper($notification->channel),
            'recipient' => $notification->recipient,
            'subject' => $notification->subject,
            'message' => $notification->message,
            'message_preview' => mb_strimwidth($notification->message, 0, 140, '...'),
            'status' => $notification->status,
            'status_label' => strtoupper($notification->status),
            'status_help' => $this->notificationStatusHelp($notification),
            'can_retry' => $notification->status === CustomerNotification::STATUS_FAILED,
            'trigger' => $notification->trigger,
            'error_message' => $notification->error_message,
            'queued_at' => $notification->queued_at,
            'sent_at' => $notification->sent_at,
            'failed_at' => $notification->failed_at,
            'created_at' => $notification->created_at,
            'created_at_formatted' => $notification->created_at?->format('M d, Y h:i A'),
            'queued_at_formatted' => $notification->queued_at?->format('M d, Y h:i A'),
            'sent_at_formatted' => $notification->sent_at?->format('M d, Y h:i A'),
            'failed_at_formatted' => $notification->failed_at?->format('M d, Y h:i A'),
        ];
    }

    private function notificationStatusHelp(CustomerNotification $notification): string
    {
        if ($notification->status === CustomerNotification::STATUS_SENT) {
            return 'Delivered successfully.';
        }

        if ($notification->status === CustomerNotification::STATUS_FAILED) {
            return $notification->channel === CustomerNotification::CHANNEL_SMS
                ? 'SMS failed. Check SMS provider setup or retry after fixing the issue.'
                : 'Email failed. Check mail settings or retry after fixing the issue.';
        }

        return 'Waiting for the queue worker to send.';
    }

    private function mapWarrantyAssessment(WarrantyAssessment $assessment): array
    {
        return [
            'id' => $assessment->id,
            'reported_issue' => $assessment->reported_issue,
            'brought_at' => $assessment->brought_at,
            'brought_at_formatted' => $assessment->brought_at?->format('M d, Y h:i A'),
            'checked_by' => $assessment->checked_by,
            'result' => $assessment->result,
            'result_label' => str_replace('_', ' ', $assessment->result),
            'assessment_notes' => $assessment->assessment_notes,
            'created_by' => $assessment->created_by,
            'created_at' => $assessment->created_at,
            'created_at_formatted' => $assessment->created_at?->format('M d, Y h:i A'),
            'warranty_job_order' => $assessment->warrantyJobOrder ? [
                'id' => $assessment->warrantyJobOrder->id,
                'job_order_number' => $assessment->warrantyJobOrder->job_order_number,
                'current_status' => $assessment->warrantyJobOrder->current_status,
            ] : null,
        ];
    }

    private function buildPrintableData(JobOrder $jobOrder): array
    {
        $trackUrl = $jobOrder->shop?->slug
            ? url('/shops/' . $jobOrder->shop->slug . '/track/' . $jobOrder->job_order_number)
            : url('/track/' . $jobOrder->job_order_number);
        $warrantyDays = (int) ($jobOrder->warranty_days ?? 0);
        $pickedUpAt = $jobOrder->picked_up_at;
        $warrantyExpiresAt = $warrantyDays > 0 && $pickedUpAt
            ? $pickedUpAt->copy()->addDays($warrantyDays)
            : null;

        return [
            'shop' => [
                'name' => $jobOrder->shop?->name ?? config('app.name', 'Repair Service Management System'),
                'tagline' => config('repair.tagline', 'Electronics Repair Service Management'),
                'phone' => $jobOrder->shop?->phone,
                'address' => $jobOrder->shop?->address,
                'business_hours' => $jobOrder->shop?->business_hours,
                'logo_url' => $jobOrder->shop?->logoUrl(),
            ],
            'jobOrder' => $jobOrder,
            'customer' => [
                'name' => $jobOrder->customer_name,
                'phone' => $jobOrder->customer_phone,
                'email' => $jobOrder->customer_email,
                'contact' => $jobOrder->customer_phone ?: ($jobOrder->customer_email ?: $jobOrder->customer_contact),
            ],
            'device' => [
                'type' => $jobOrder->device_type,
                'brand' => $jobOrder->brand,
                'model' => $jobOrder->model,
                'serial_number' => $jobOrder->serial_number,
                'problem_description' => $jobOrder->problem_description,
                'included_accessories' => $jobOrder->included_accessories,
                'label' => trim(collect([$jobOrder->brand, $jobOrder->model, $jobOrder->device_type])->filter()->join(' ')),
            ],
            'service' => [
                'priority' => $jobOrder->priority,
                'current_status' => $jobOrder->current_status,
                'technician' => $jobOrder->technician?->name,
                'received_at' => $jobOrder->created_at,
                'received_at_formatted' => $jobOrder->created_at?->format('M d, Y h:i A'),
                'estimated_completion_formatted' => $jobOrder->estimated_completion?->format('M d, Y h:i A'),
                'picked_up_at_formatted' => $pickedUpAt?->format('M d, Y h:i A'),
                'picked_up_by' => $jobOrder->picked_up_by,
                'received_by' => $jobOrder->received_by,
                'service_performed' => $jobOrder->service_performed,
                'repair_approved_at_formatted' => $jobOrder->repair_approved_at?->format('M d, Y h:i A'),
                'repair_approved_by_customer' => $jobOrder->repair_approved_by_customer,
                'repair_approval_recorded_by' => $jobOrder->repair_approval_recorded_by,
                'repair_approval_notes' => $jobOrder->repair_approval_notes,
            ],
            'costs' => [
                'estimated_cost' => $jobOrder->estimated_cost,
                'total_cost' => $jobOrder->total_cost,
                'amount_paid' => $jobOrder->amount_paid,
                'balance' => $jobOrder->balance,
            ],
            'warranty' => [
                'is_available' => $warrantyDays > 0,
                'days' => $warrantyDays,
                'details' => $jobOrder->warranty_details,
                'starts_at_formatted' => $pickedUpAt?->format('M d, Y'),
                'expires_at_formatted' => $warrantyExpiresAt?->format('M d, Y'),
            ],
            'tracking' => [
                'url' => $trackUrl,
                'qr_value' => $trackUrl,
                'claim_code' => $jobOrder->job_order_number,
            ],
            'claim_terms' => $this->configuredTerms($jobOrder->shop?->claim_terms, [
                'Check-up and diagnosis are free of charge.',
                'No repair or additional cost will proceed without the customer\'s approval.',
                'Once the approved repair work has started, cancellation is no longer accepted and the customer remains responsible for authorized work and parts, except where required by law or agreed by the shop.',
                'The customer confirms that the reported problem and included accessories recorded on this claim stab are correct.',
                'The shop is not responsible for undisclosed pre-existing defects or data loss not caused by the shop\'s negligence. Customers are advised to back up important data before repair.',
                'Only the customer or an authorized representative may claim the device. This claim stab or valid identification may be required.',
                'Customer contact information will only be used for repair updates, device tracking, and related service communication.',
            ]),
            'warranty_terms' => $this->configuredTerms($jobOrder->shop?->warranty_terms, [
                'Warranty covers only the specific repair or replacement listed on this slip.',
                'Warranty does not cover misuse, liquid or physical damage, power surges, software issues, or unrelated faults.',
                'Warranty is void if the device is opened, altered, or repaired by another party.',
                'Present this warranty slip and job order record when requesting warranty service.',
                'Warranty claims remain subject to inspection and the stated coverage period.',
            ]),
            'generated_at' => now()->format('M d, Y h:i A'),
        ];
    }

    private function configuredTerms(?string $configuredTerms, array $defaults): array
    {
        if (!$configuredTerms) {
            return $defaults;
        }

        $terms = collect(preg_split('/\r\n|\r|\n/', $configuredTerms) ?: [])
            ->map(fn (string $term) => trim($term))
            ->filter()
            ->values()
            ->all();

        return $terms ?: $defaults;
    }

    private function generateJobOrderNumber(?string $suffix = null): string
    {
        $prefix = strtoupper((string) config('repair.job_order_prefix', 'RSMS'));
        if ($suffix) {
            $prefix .= '-' . strtoupper($suffix);
        }

        do {
            $jobOrderNumber = $prefix . '-' . date('Y') . '-' . strtoupper(Str::random(6));
        } while (JobOrder::where('job_order_number', $jobOrderNumber)->exists());

        return $jobOrderNumber;
    }

    private function findRecentDuplicate(int $shopId, array $validated): ?JobOrder
    {
        $query = JobOrder::query()
            ->where('shop_id', $shopId)
            ->where('customer_name', $validated['customer_name'])
            ->where('device_type', $validated['device_type'])
            ->whereDate('created_at', '>=', Carbon::now()->subDays(30));

        if (!empty($validated['serial_number'])) {
            $query->where('serial_number', $validated['serial_number']);
        } elseif (!empty($validated['brand']) || !empty($validated['model'])) {
            $query->where('brand', $validated['brand'] ?? '')
                ->where('model', $validated['model'] ?? '');
        }

        return $query->latest()->first();
    }

    private function recalculateCosts(JobOrder $jobOrder): void
    {
        $partsTotal = $jobOrder->parts()->sum('total_cost');
        $laborCost = $jobOrder->labor_cost;

        $jobOrder->update([
            'parts_cost' => $partsTotal,
            'total_cost' => $partsTotal + $laborCost,
            'payment_status' => $jobOrder->amount_paid <= 0
                ? 'PENDING'
                : ($jobOrder->amount_paid >= ($partsTotal + $laborCost) ? 'PAID' : 'PARTIAL'),
        ]);
    }

    private function recordStockMovement(
        SparePart $sparePart,
        string $type,
        int $quantityChange,
        ?string $note = null,
        ?int $jobOrderId = null,
        ?int $jobOrderPartId = null,
        ?string $createdBy = null
    ): void {
        StockMovement::create([
            'shop_id' => $sparePart->shop_id,
            'spare_part_id' => $sparePart->id,
            'job_order_id' => $jobOrderId,
            'job_order_part_id' => $jobOrderPartId,
            'type' => $type,
            'quantity_change' => $quantityChange,
            'balance_after' => $sparePart->stock_quantity,
            'note' => $note,
            'created_by' => $createdBy,
        ]);
    }

    private function notifyCustomer(JobOrder $jobOrder, string $status, ?string $note = null): void
    {
        $settings = $this->notificationSettings($jobOrder);

        if (!($settings['events'][$status] ?? true)) {
            return;
        }

        $email = $jobOrder->customer_email;
        $phone = $jobOrder->customer_phone;

        if (!$email && !$phone && $jobOrder->customer_contact) {
            $contact = trim($jobOrder->customer_contact);
            if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
                $email = $contact;
            } else {
                $phone = $contact;
            }
        }

        if (!$email && !$phone) {
            return;
        }

        $statusLabel = $this->statusLabelForNotification($status);
        $trackBase = $jobOrder->shop?->slug
            ? '/shops/' . $jobOrder->shop->slug . '/track/'
            : '/track/';
        $trackUrl = url($trackBase . $jobOrder->job_order_number);
        $noteText = $note ? ' Note: ' . mb_strimwidth($note, 0, 120, '...') : '';
        $appName = $jobOrder->shop?->name ?? config('app.name', 'Repair Service Management System');

        $message = "{$appName}: Your device {$jobOrder->job_order_number} is now {$statusLabel}." . $noteText . " Track: {$trackUrl}";
        $subject = "{$appName} Update: {$jobOrder->job_order_number} - {$statusLabel}";

        $trigger = 'status:' . $status;

        if (($settings['email_enabled'] ?? true) && $email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $notification = CustomerNotification::create([
                'shop_id' => $jobOrder->shop_id,
                'job_order_id' => $jobOrder->id,
                'channel' => CustomerNotification::CHANNEL_EMAIL,
                'recipient' => $email,
                'subject' => $subject,
                'message' => $message,
                'status' => CustomerNotification::STATUS_QUEUED,
                'trigger' => $trigger,
                'queued_at' => now(),
            ]);

            SendCustomerNotification::dispatch(
                $email,
                null,
                $subject,
                $message,
                $notification->id
            );
        }

        if (($settings['sms_enabled'] ?? false) && $phone && preg_match('/^\+?[0-9\-\s\(\)]+$/', $phone)) {
            $notification = CustomerNotification::create([
                'shop_id' => $jobOrder->shop_id,
                'job_order_id' => $jobOrder->id,
                'channel' => CustomerNotification::CHANNEL_SMS,
                'recipient' => $phone,
                'subject' => null,
                'message' => $message,
                'status' => CustomerNotification::STATUS_QUEUED,
                'trigger' => $trigger,
                'queued_at' => now(),
            ]);

            SendCustomerNotification::dispatch(
                null,
                $phone,
                $subject,
                $message,
                $notification->id
            );
        }
    }

    private function notificationSettings(JobOrder $jobOrder): array
    {
        $settings = $jobOrder->shop?->notification_settings ?? [];
        $inputEvents = is_array($settings['events'] ?? null) ? $settings['events'] : [];
        $events = [
            'RECEIVED',
            'DIAGNOSING',
            'WAITING_PARTS',
            'IN_PROGRESS',
            'READY_FOR_PICKUP',
            'PICKED_UP',
            'RTO',
            'RTO_RELEASED',
        ];

        return [
            'email_enabled' => array_key_exists('email_enabled', $settings) ? (bool) $settings['email_enabled'] : true,
            'sms_enabled' => array_key_exists('sms_enabled', $settings) ? (bool) $settings['sms_enabled'] : false,
            'events' => collect($events)
                ->mapWithKeys(fn (string $event) => [
                    $event => array_key_exists($event, $inputEvents) ? (bool) $inputEvents[$event] : true,
                ])
                ->all(),
        ];
    }

    private function statusLabelForNotification(string $status): string
    {
        return match ($status) {
            'PICKED_UP' => 'RELEASED',
            'RTO_RELEASED' => 'RTO RELEASED',
            'RTO' => 'RTO',
            default => str_replace('_', ' ', $status),
        };
    }
}
