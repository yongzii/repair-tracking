<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendCustomerNotification;
use App\Models\JobOrder;
use App\Models\JobOrderPayment;
use App\Models\JobOrderPart;
use App\Models\SparePart;
use App\Models\StatusHistory;
use App\Models\StockMovement;
use App\Models\Technician;
use App\Models\User;
use App\Support\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->with('technician:id,name');

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

        if ($priority = $request->query('priority')) {
            $jobOrders->where('priority', $priority);
        }

        if ($paymentStatus = $request->query('payment_status')) {
            $jobOrders->where('payment_status', $paymentStatus);
        }

        if ($technicianId = $request->query('technician_id')) {
            $jobOrders->where('technician_id', $technicianId);
        }

        if ($request->boolean('overdue_only')) {
            $jobOrders
                ->whereNotIn('current_status', ['READY_FOR_PICKUP', 'PICKED_UP', 'UNREPAIRABLE'])
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
                'UNREPAIRABLE',
            ],
            'priorities' => ['STANDARD', 'EXPRESS', 'EMERGENCY'],
            'paymentStatuses' => ['PENDING', 'PARTIAL', 'PAID'],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeStaffOrAdmin($request);
        $shopId = $this->currentShopId($request);

        $validated = $request->validate([
            'customer_name' => 'required|string|max:100',
            'customer_email' => 'nullable|email|max:150|required_without:customer_phone',
            'customer_phone' => 'nullable|string|max:30|required_without:customer_email',
            'device_type' => 'required|string|max:50',
            'brand' => 'nullable|string|max:50',
            'model' => 'nullable|string|max:50',
            'serial_number' => 'nullable|string|max:100',
            'problem_description' => 'required|string',
            'priority' => 'nullable|in:STANDARD,EXPRESS,EMERGENCY',
            'estimated_cost' => 'nullable|numeric|min:0',
            'down_payment' => 'nullable|numeric|min:0',
            'warranty_days' => 'nullable|integer|min:0',
            'estimated_completion' => 'nullable|date',
            'technician_id' => [
                'nullable',
                Rule::exists('technicians', 'id')->where(fn ($query) => $query->where('shop_id', $shopId)),
            ],
        ]);

        $estimatedCost = (float) ($validated['estimated_cost'] ?? 0);
        $downPayment = (float) ($validated['down_payment'] ?? 0);

        if ($downPayment > 0 && $estimatedCost <= 0) {
            throw ValidationException::withMessages([
                'estimated_cost' => 'Estimated cost is required when down payment is entered.',
            ]);
        }

        if ($estimatedCost > 0 && $downPayment > $estimatedCost) {
            throw ValidationException::withMessages([
                'down_payment' => 'Down payment cannot be greater than estimated cost.',
            ]);
        }

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

        $jobOrderNumber = $this->generateJobOrderNumber();

        $jobOrder = DB::transaction(function () use ($validated, $jobOrderNumber, $downPayment, $estimatedCost, $request, $shopId) {
            $jobOrder = JobOrder::create([
                ...$validated,
                'shop_id' => $shopId,
                'job_order_number' => $jobOrderNumber,
                'current_status' => 'RECEIVED',
                'priority' => $validated['priority'] ?? 'STANDARD',
                'warranty_days' => $validated['warranty_days'] ?? ($request->user()?->shop?->default_warranty_days ?? 30),
                'payment_status' => $downPayment > 0
                    ? ($downPayment >= $estimatedCost ? 'PAID' : 'PARTIAL')
                    : 'PENDING',
                'parts_cost' => 0,
                'labor_cost' => 0,
                'total_cost' => $estimatedCost,
                'amount_paid' => $downPayment,
                'payment_date' => $downPayment > 0 ? now() : null,
                'payment_notes' => $downPayment > 0 ? 'Initial down payment upon intake.' : null,
            ]);

            if ($downPayment > 0) {
                JobOrderPayment::create([
                    'shop_id' => $shopId,
                    'job_order_id' => $jobOrder->id,
                    'amount' => $downPayment,
                    'payment_type' => 'DOWN_PAYMENT',
                    'payment_method' => 'CASH',
                    'notes' => 'Initial down payment upon intake.',
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

        $jobOrder->load(['technician', 'statusHistories', 'parts', 'payments']);

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
        $downPayment = (float) $jobOrder->payments()
            ->where('payment_type', 'DOWN_PAYMENT')
            ->sum('amount');

        if ($downPayment <= 0 && (float) $jobOrder->amount_paid > 0 && (float) $jobOrder->estimated_cost > 0) {
            $downPayment = min((float) $jobOrder->amount_paid, (float) $jobOrder->estimated_cost);
        }

        return response()->json([
            'shop' => [
                'name' => $jobOrder->shop?->name ?? config('app.name', 'Repair Service Management System'),
                'tagline' => config('repair.tagline', 'Electronics Repair Service Management'),
                'slug' => $jobOrder->shop?->slug,
                'phone' => $jobOrder->shop?->phone,
                'address' => $jobOrder->shop?->address,
            ],
            'invoice' => [
                'invoice_number' => 'INV-' . str_pad((string) $jobOrder->id, 6, '0', STR_PAD_LEFT),
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
                'down_payment' => $downPayment,
                'estimated_balance' => max((float) $jobOrder->estimated_cost - $downPayment, 0),
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
            'brand',
            'model',
            'serial_number',
            'problem_description',
            'priority',
            'warranty_days',
            'estimated_completion',
            'technician_id',
        ]);

        $validated = $request->validate([
            'customer_name' => 'required|string|max:100',
            'customer_email' => 'nullable|email|max:150|required_without:customer_phone',
            'customer_phone' => 'nullable|string|max:30|required_without:customer_email',
            'device_type' => 'required|string|max:50',
            'brand' => 'nullable|string|max:50',
            'model' => 'nullable|string|max:50',
            'serial_number' => 'nullable|string|max:100',
            'problem_description' => 'required|string',
            'priority' => 'nullable|in:STANDARD,EXPRESS,EMERGENCY',
            'warranty_days' => 'nullable|integer|min:0',
            'estimated_completion' => 'nullable|date',
            'technician_id' => [
                'nullable',
                Rule::exists('technicians', 'id')->where(fn ($query) => $query->where('shop_id', $shopId)),
            ],
        ]);

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

    public function destroy(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

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
        $previousStatus = $jobOrder->current_status;

        $validated = $request->validate([
            'status' => 'required|in:RECEIVED,DIAGNOSING,WAITING_PARTS,IN_PROGRESS,READY_FOR_PICKUP,UNREPAIRABLE',
            'note' => 'nullable|string',
        ]);

        if ($jobOrder->current_status === $validated['status']) {
            return response()->json([
                'message' => 'Status is already set. No changes made.',
                'jobOrder' => $jobOrder,
            ]);
        }

        $jobOrder->update(['current_status' => $validated['status']]);

        StatusHistory::create([
            'shop_id' => $jobOrder->shop_id,
            'job_order_id' => $jobOrder->id,
            'status' => $validated['status'],
            'note' => $validated['note'],
        ]);

        $this->notifyCustomer($jobOrder, $validated['status'], $validated['note'] ?? null);

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

        return response()->json([
            'message' => 'Status updated successfully.',
            'jobOrder' => $jobOrder->fresh(),
        ]);
    }

    public function markPickedUp(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);

        $validated = $request->validate([
            'picked_up_by' => 'required|string|max:100',
        ]);

        $jobOrder->update([
            'picked_up_at' => now(),
            'picked_up_by' => $validated['picked_up_by'],
            'current_status' => 'PICKED_UP',
        ]);

        StatusHistory::create([
            'shop_id' => $jobOrder->shop_id,
            'job_order_id' => $jobOrder->id,
            'status' => 'PICKED_UP',
            'note' => "Device picked up by {$validated['picked_up_by']}",
        ]);

        $this->notifyCustomer($jobOrder, 'PICKED_UP', "Device picked up by {$validated['picked_up_by']}");

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'job_order.picked_up',
            module: 'job_orders',
            record: $jobOrder,
            description: "Marked {$jobOrder->job_order_number} as picked up.",
            oldValues: ['current_status' => 'READY_FOR_PICKUP', 'picked_up_at' => null, 'picked_up_by' => null],
            newValues: ['current_status' => 'PICKED_UP', 'picked_up_by' => $validated['picked_up_by']]
        );

        return response()->json([
            'message' => 'Device marked as picked up.',
            'jobOrder' => $jobOrder->fresh(),
        ]);
    }

    public function recordPayment(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $jobOrder);
        $before = $jobOrder->only(['amount_paid', 'payment_status', 'payment_date', 'payment_notes']);

        $validated = $request->validate([
            'amount_paid' => 'required|numeric|min:0.01',
            'payment_type' => 'nullable|in:DOWN_PAYMENT,PARTIAL_PAYMENT,FINAL_PAYMENT',
            'payment_method' => 'nullable|string|max:40',
            'reference_number' => 'nullable|string|max:100',
            'received_at' => 'nullable|date',
            'payment_notes' => 'nullable|string',
        ]);

        if ($jobOrder->balance <= 0) {
            return response()->json([
                'message' => 'Payment already settled. No balance remaining.',
            ], 422);
        }

        if ($validated['amount_paid'] > $jobOrder->balance) {
            return response()->json([
                'message' => 'Amount exceeds remaining balance.',
            ], 422);
        }

        $amountPaid = (float) $validated['amount_paid'];
        $newAmountPaid = (float) $jobOrder->amount_paid + $amountPaid;
        $paymentStatus = 'PARTIAL';

        if ($newAmountPaid >= $jobOrder->total_cost) {
            $paymentStatus = 'PAID';
            $newAmountPaid = $jobOrder->total_cost;
        }

        $paymentType = $validated['payment_type']
            ?? ($paymentStatus === 'PAID' ? 'FINAL_PAYMENT' : 'PARTIAL_PAYMENT');

        $payment = JobOrderPayment::create([
            'shop_id' => $jobOrder->shop_id,
            'job_order_id' => $jobOrder->id,
            'amount' => $amountPaid,
            'payment_type' => $paymentType,
            'payment_method' => $validated['payment_method'] ?? null,
            'reference_number' => $validated['reference_number'] ?? null,
            'notes' => $validated['payment_notes'] ?? null,
            'received_at' => $validated['received_at'] ?? now(),
            'received_by' => $request->user()?->name,
        ]);

        $jobOrder->update([
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
            record: $jobOrder,
            description: "Recorded payment for {$jobOrder->job_order_number}.",
            oldValues: $before,
            newValues: [
                'amount_paid' => $jobOrder->fresh()->amount_paid,
                'payment_status' => $jobOrder->fresh()->payment_status,
                'payment_type' => $paymentType,
                'payment_amount' => $amountPaid,
                'payment_method' => $validated['payment_method'] ?? null,
            ]
        );

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
        $before = $jobOrder->only(['parts_cost', 'labor_cost', 'total_cost', 'payment_status']);

        $validated = $request->validate([
            'parts_cost' => 'required|numeric|min:0',
            'labor_cost' => 'required|numeric|min:0',
        ]);

        $partsTotal = $jobOrder->parts()->exists()
            ? $jobOrder->parts()->sum('total_cost')
            : $validated['parts_cost'];

        $totalCost = $partsTotal + $validated['labor_cost'];

        $jobOrder->update([
            'parts_cost' => $partsTotal,
            'labor_cost' => $validated['labor_cost'],
            'total_cost' => $totalCost,
            'payment_status' => $jobOrder->amount_paid <= 0
                ? 'PENDING'
                : ($jobOrder->amount_paid >= $totalCost ? 'PAID' : 'PARTIAL'),
        ]);

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'job_order.costs_updated',
            module: 'job_orders',
            record: $jobOrder,
            description: "Updated cost breakdown for {$jobOrder->job_order_number}.",
            oldValues: $before,
            newValues: $jobOrder->fresh()->only(['parts_cost', 'labor_cost', 'total_cost', 'payment_status'])
        );

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
            'spare_part_id' => 'nullable|exists:spare_parts,id',
            'part_name' => 'nullable|string|max:150|required_without:spare_part_id',
            'quantity' => 'required|integer|min:1',
            'unit_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:255',
        ]);

        $part = DB::transaction(function () use ($validated, $jobOrder, $request, $shopId) {
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

            $part = JobOrderPart::create([
                'shop_id' => $jobOrder->shop_id,
                'job_order_id' => $jobOrder->id,
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

            return $part;
        });

        $this->recalculateCosts($jobOrder->fresh());

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

        if ($part->job_order_id !== $jobOrder->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'spare_part_id' => 'nullable|exists:spare_parts,id',
            'part_name' => 'nullable|string|max:150|required_without:spare_part_id',
            'quantity' => 'required|integer|min:1',
            'unit_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:255',
        ]);

        $before = $part->only(['part_name', 'quantity', 'unit_cost', 'total_cost', 'spare_part_id', 'notes']);

        DB::transaction(function () use ($validated, $part, $jobOrder, $request, $shopId) {
            $existingPart = JobOrderPart::query()->lockForUpdate()->findOrFail($part->id);

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
        });

        $this->recalculateCosts($jobOrder->fresh());

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

        if ($part->job_order_id !== $jobOrder->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $before = $part->only(['part_name', 'quantity', 'unit_cost', 'total_cost', 'spare_part_id', 'notes']);

        DB::transaction(function () use ($part, $jobOrder, $request, $shopId) {
            $existingPart = JobOrderPart::query()->lockForUpdate()->findOrFail($part->id);

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
        });

        $this->recalculateCosts($jobOrder->fresh());

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

    private function authorizeStaffOrAdmin(Request $request): void
    {
        $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
    }

    private function mapJobOrderSummary(JobOrder $job): array
    {
        $isOverdue = $this->isJobOverdue($job);

        return [
            'id' => $job->id,
            'job_order_number' => $job->job_order_number,
            'customer_name' => $job->customer_name,
            'customer_phone' => $job->customer_phone,
            'device_type' => $job->device_type,
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
            'deleted_at' => $job->deleted_at,
            'created_at_formatted' => $job->created_at?->format('M d, Y'),
        ];
    }

    private function isJobOverdue(JobOrder $job): bool
    {
        if (!$job->estimated_completion) {
            return false;
        }

        if (in_array($job->current_status, ['READY_FOR_PICKUP', 'PICKED_UP', 'UNREPAIRABLE'], true)) {
            return false;
        }

        return $job->estimated_completion->isPast();
    }

    private function mapPayment(JobOrderPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'payment_type' => $payment->payment_type,
            'payment_method' => $payment->payment_method,
            'reference_number' => $payment->reference_number,
            'notes' => $payment->notes,
            'received_at' => $payment->received_at,
            'received_at_formatted' => $payment->received_at?->format('M d, Y h:i A'),
            'received_by' => $payment->received_by,
            'created_at' => $payment->created_at,
        ];
    }

    private function generateJobOrderNumber(): string
    {
        $prefix = strtoupper((string) config('repair.job_order_prefix', 'RSMS'));

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

        $statusLabel = str_replace('_', ' ', $status);
        $trackBase = $jobOrder->shop?->slug
            ? '/shops/' . $jobOrder->shop->slug . '/track/'
            : '/track/';
        $trackUrl = url($trackBase . $jobOrder->job_order_number);
        $noteText = $note ? ' Note: ' . mb_strimwidth($note, 0, 120, '...') : '';
        $appName = $jobOrder->shop?->name ?? config('app.name', 'Repair Service Management System');

        $message = "{$appName}: Your device {$jobOrder->job_order_number} is now {$statusLabel}." . $noteText . " Track: {$trackUrl}";
        $subject = "{$appName} Update: {$jobOrder->job_order_number} - {$statusLabel}";

        SendCustomerNotification::dispatch(
            $email,
            $phone,
            $subject,
            $message
        );
    }
}
