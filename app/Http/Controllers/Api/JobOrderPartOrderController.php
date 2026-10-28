<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\JobOrderPart;
use App\Models\JobOrderPartOrder;
use App\Models\JobOrderPayment;
use App\Models\JobOrderRefund;
use App\Models\SparePart;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JobOrderPartOrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_TECHNICIAN]);
        $shopId = $this->currentShopId($request);
        $technician = $user->hasRole(User::ROLE_TECHNICIAN)
            ? $this->resolveTechnician($user)
            : null;

        $query = JobOrderPartOrder::query()
            ->with([
                'jobOrder.payments' => fn ($query) => $query->where('shop_id', $shopId)->with('refund'),
                'jobOrder.technician:id,name',
                'jobOrder.deviceLocationUpdatedBy:id,name',
                'requestedBy:id,name',
                'customerDecisionRecordedBy:id,name',
                'receivedBy:id,name',
                'issuedBy:id,name',
                'inventoryPart:id,name,unit',
                'issuedJobOrderPart:id,job_order_part_order_id',
            ])
            ->where('shop_id', $shopId);

        if ($technician) {
            $query->whereHas('jobOrder', fn ($jobOrderQuery) => $jobOrderQuery
                ->where('shop_id', $shopId)
                ->where('technician_id', $technician->id));
        }

        $orders = $query
            ->latest()
            ->get()
            ->map(fn (JobOrderPartOrder $order) => $technician
                ? $this->mapTechnicianOrder($order)
                : $this->mapOrder($order));

        return response()->json(['orders' => $orders]);
    }

    public function store(Request $request, JobOrder $jobOrder)
    {
        $user = $request->user();
        $isTechnician = $user?->hasRole(User::ROLE_TECHNICIAN) ?? false;
        if ($isTechnician) {
            $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
            $technician = $this->resolveTechnician($user);
        } else {
            $user = $this->authorizeStaffOrAdmin($request);
            $technician = null;
        }
        $this->ensureSameShop($request, $jobOrder);
        if ($technician) {
            $this->ensureAssignedToTechnician($jobOrder, $technician);
        }

        $rules = [
            'idempotency_key' => ['nullable', 'uuid'],
            'part_name' => ['required', 'string', 'max:150'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'reason' => ['required', 'string', 'max:2000'],
            'estimated_unit_price' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ];
        if (!$isTechnician) {
            $decision = $request->input('customer_decision');
            $rules['customer_decision'] = [
                'required',
                Rule::in([
                    JobOrderPartOrder::DECISION_PENDING,
                    JobOrderPartOrder::DECISION_APPROVED,
                    JobOrderPartOrder::DECISION_REJECTED,
                ]),
            ];
            $rules['device_location'] = ['sometimes', 'required', Rule::in(['AT_SHOP', 'WITH_CUSTOMER'])];
            $rules['device_location_note'] = ['nullable', 'string', 'max:255'];
            if ($decision === JobOrderPartOrder::DECISION_APPROVED) {
                $rules['required_downpayment_amount'] = [
                    'required',
                    'numeric',
                    'min:0.01',
                    'max:' . round((float) $request->input('estimated_unit_price', 0) * (int) $request->input('quantity', 0), 2),
                ];
            } else {
                $rules['required_downpayment_amount'] = ['prohibited'];
            }
            if (in_array($decision, [JobOrderPartOrder::DECISION_APPROVED, JobOrderPartOrder::DECISION_REJECTED], true)) {
                $rules['customer_decision_note'] = ['required', 'string', 'max:255'];
            } else {
                $rules['customer_decision_note'] = ['nullable', 'string', 'max:255'];
            }
        }
        $validated = $request->validate($rules);

        $estimatedTotal = round((float) $validated['estimated_unit_price'] * (int) $validated['quantity'], 2);
        if ($estimatedTotal > 99999999.99) {
            throw ValidationException::withMessages([
                'quantity' => 'The estimated parts total is too large to record.',
            ]);
        }

        $decision = $isTechnician
            ? JobOrderPartOrder::DECISION_PENDING
            : $validated['customer_decision'];
        $idempotencyKey = $validated['idempotency_key'] ?? null;
        $idempotencyPayloadHash = $this->partOrderPayloadHash($jobOrder->id, $validated, $isTechnician, $decision);

        try {
            [$order, $deviceLocationChange, $created] = DB::transaction(function () use ($request, $jobOrder, $technician, $user, $validated, $isTechnician, $decision, $idempotencyKey, $idempotencyPayloadHash) {
            $lockedJob = JobOrder::query()
                ->where('shop_id', $this->currentShopId($request))
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);
            if ($technician) {
                $this->ensureAssignedToTechnician($lockedJob, $technician);
            }

            if ($idempotencyKey !== null) {
                $existing = JobOrderPartOrder::query()
                    ->where('shop_id', $lockedJob->shop_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if ($existing->idempotency_payload_hash !== $idempotencyPayloadHash) {
                        abort(409, 'This idempotency key was already used with different request data.');
                    }

                    return [$existing, null, false];
                }
            }

            if (in_array($lockedJob->current_status, ['READY_FOR_PICKUP', 'PICKED_UP', 'RTO', 'RTO_RELEASED'], true)) {
                throw ValidationException::withMessages([
                    'job_order' => 'A parts order cannot be created for a completed or released repair.',
                ]);
            }

            $deviceLocationChange = null;
            if (!$isTechnician && isset($validated['device_location'])) {
                $previousLocation = $lockedJob->device_location;
                $lockedJob->update([
                    'device_location' => $validated['device_location'],
                    'device_location_updated_at' => now(),
                    'device_location_updated_by_user_id' => $user->id,
                ]);
                $deviceLocationChange = [
                    'previous' => $previousLocation,
                    'current' => $validated['device_location'],
                    'note' => isset($validated['device_location_note']) ? trim($validated['device_location_note']) : null,
                ];
            }

            $now = now();
            $requiredDownpayment = $decision === JobOrderPartOrder::DECISION_APPROVED
                ? (float) $validated['required_downpayment_amount']
                : null;
            $status = match ($decision) {
                JobOrderPartOrder::DECISION_APPROVED => JobOrderPartOrder::STATUS_AWAITING_DOWNPAYMENT,
                JobOrderPartOrder::DECISION_REJECTED => JobOrderPartOrder::STATUS_CANCELLED,
                default => JobOrderPartOrder::STATUS_AWAITING_APPROVAL,
            };
            if (
                $decision === JobOrderPartOrder::DECISION_APPROVED
                && round($this->eligiblePayments($lockedJob), 2) >= round($requiredDownpayment, 2)
            ) {
                $status = JobOrderPartOrder::STATUS_READY_TO_ORDER;
            }

            $order = JobOrderPartOrder::create([
                'shop_id' => $lockedJob->shop_id,
                'job_order_id' => $lockedJob->id,
                'idempotency_key' => $idempotencyKey,
                'idempotency_payload_hash' => $idempotencyPayloadHash,
                'requested_by_user_id' => $user->id,
                'customer_decision_recorded_by_user_id' => $decision === JobOrderPartOrder::DECISION_PENDING ? null : $user->id,
                'part_name' => trim($validated['part_name']),
                'quantity' => (int) $validated['quantity'],
                'reason' => trim($validated['reason']),
                'estimated_unit_price' => $validated['estimated_unit_price'],
                'customer_decision' => $decision,
                'customer_decision_at' => $decision === JobOrderPartOrder::DECISION_PENDING ? null : $now,
                'customer_decision_note' => $decision === JobOrderPartOrder::DECISION_PENDING
                    ? null
                    : trim($validated['customer_decision_note']),
                'required_downpayment_amount' => $requiredDownpayment,
                'status' => $status,
                'cancelled_by_user_id' => $decision === JobOrderPartOrder::DECISION_REJECTED ? $user->id : null,
                'cancelled_at' => $decision === JobOrderPartOrder::DECISION_REJECTED ? $now : null,
                'cancellation_reason' => $decision === JobOrderPartOrder::DECISION_REJECTED
                    ? 'Customer declined the proposed parts order.'
                    : null,
            ]);

            return [$order, $deviceLocationChange, true];
            }, 3);
        } catch (QueryException $exception) {
            if (!$this->isIdempotencyConstraintViolation($exception, 'jopo_shop_idempotency_unique') || $idempotencyKey === null) {
                throw $exception;
            }

            $order = JobOrderPartOrder::query()
                ->where('shop_id', $this->currentShopId($request))
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
            if ($order->idempotency_payload_hash !== $idempotencyPayloadHash) {
                abort(409, 'This idempotency key was already used with different request data.');
            }
            $deviceLocationChange = null;
            $created = false;
        }

        $order->load([
            'jobOrder.payments.refund',
            'jobOrder.technician',
            'jobOrder.deviceLocationUpdatedBy:id,name',
            'requestedBy',
            'customerDecisionRecordedBy',
            'issuedJobOrderPart',
        ]);

        if ($created) {
            AuditLogger::log(
            request: $request,
            user: $user,
            action: 'job_order_part_order.created',
            module: 'job_order_part_orders',
            record: $order,
            description: "{$user->name} requested {$order->quantity} {$order->part_name} for {$jobOrder->job_order_number}.",
            newValues: $order->only([
                'job_order_id',
                'part_name',
                'quantity',
                'reason',
                'estimated_unit_price',
                'customer_decision',
                'required_downpayment_amount',
                'status',
            ])
            );

            if ($decision !== JobOrderPartOrder::DECISION_PENDING) {
                AuditLogger::log(
                request: $request,
                user: $user,
                action: 'job_order_part_order.customer_decision_recorded',
                module: 'job_order_part_orders',
                record: $order,
                description: "Recorded customer {$decision} for the {$order->part_name} order on {$jobOrder->job_order_number}.",
                newValues: $order->only([
                    'customer_decision',
                    'customer_decision_at',
                    'customer_decision_note',
                    'required_downpayment_amount',
                    'status',
                ])
                );
            }
        }
        if ($deviceLocationChange !== null) {
            AuditLogger::log(
                request: $request,
                user: $user,
                action: 'job_order.device_location_updated',
                module: 'job_orders',
                record: $jobOrder,
                description: "Updated device location for {$jobOrder->job_order_number} to {$deviceLocationChange['current']}.",
                oldValues: ['device_location' => $deviceLocationChange['previous']],
                newValues: [
                    'device_location' => $deviceLocationChange['current'],
                    'note' => $deviceLocationChange['note'],
                ]
            );
        }

        return response()->json([
            'message' => match ($decision) {
                JobOrderPartOrder::DECISION_APPROVED => 'Customer approval recorded by staff; supplier ordering remains subject to downpayment verification.',
                JobOrderPartOrder::DECISION_REJECTED => 'Customer decline recorded. This parts order cannot be placed.',
                default => 'Customer-specific parts order submitted for customer approval.',
            },
            'order' => $this->mapOrder($order),
        ], $created ? 201 : 200);
    }

    public function decide(Request $request, JobOrderPartOrder $partOrder)
    {
        $user = $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $partOrder);

        $decision = $request->input('decision');
        if (!in_array($decision, [JobOrderPartOrder::DECISION_APPROVED, JobOrderPartOrder::DECISION_REJECTED], true)) {
            throw ValidationException::withMessages([
                'decision' => 'Record an explicit customer approval or rejection.',
            ]);
        }

        $rules = [
            'decision' => ['required', Rule::in([JobOrderPartOrder::DECISION_APPROVED, JobOrderPartOrder::DECISION_REJECTED])],
            'customer_decision_note' => ['required', 'string', 'max:255'],
        ];
        if ($decision === JobOrderPartOrder::DECISION_APPROVED) {
            $rules['required_downpayment_amount'] = [
                'required',
                'numeric',
                'min:0.01',
                'max:' . round((float) $partOrder->estimated_unit_price * (int) $partOrder->quantity, 2),
            ];
        }
        $validated = $request->validate($rules);

        $updated = DB::transaction(function () use ($request, $partOrder, $user, $decision, $validated) {
            $jobOrder = JobOrder::query()
                ->where('shop_id', $partOrder->shop_id)
                ->lockForUpdate()
                ->findOrFail($partOrder->job_order_id);
            $lockedOrder = JobOrderPartOrder::query()
                ->where('shop_id', $partOrder->shop_id)
                ->lockForUpdate()
                ->findOrFail($partOrder->id);

            if ($lockedOrder->status !== JobOrderPartOrder::STATUS_AWAITING_APPROVAL) {
                throw ValidationException::withMessages([
                    'decision' => 'This parts order is no longer awaiting a customer decision.',
                ]);
            }

            $lockedOrder->update([
                'customer_decision' => $decision,
                'customer_decision_recorded_by_user_id' => $user->id,
                'customer_decision_at' => now(),
                'customer_decision_note' => trim($validated['customer_decision_note']),
                'required_downpayment_amount' => $decision === JobOrderPartOrder::DECISION_APPROVED
                    ? $validated['required_downpayment_amount']
                    : null,
                'status' => $decision === JobOrderPartOrder::DECISION_APPROVED
                    ? JobOrderPartOrder::STATUS_AWAITING_DOWNPAYMENT
                    : JobOrderPartOrder::STATUS_CANCELLED,
                'cancelled_by_user_id' => $decision === JobOrderPartOrder::DECISION_REJECTED ? $user->id : null,
                'cancelled_at' => $decision === JobOrderPartOrder::DECISION_REJECTED ? now() : null,
                'cancellation_reason' => $decision === JobOrderPartOrder::DECISION_REJECTED
                    ? 'Customer rejected the proposed parts order.'
                    : null,
            ]);

            $this->refreshReadyStatus($lockedOrder, $jobOrder);

            return $lockedOrder->fresh()->load(['jobOrder.payments.refund', 'requestedBy', 'customerDecisionRecordedBy']);
        });

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'job_order_part_order.customer_decision_recorded',
            module: 'job_order_part_orders',
            record: $updated,
            description: "Recorded customer {$decision} for the {$updated->part_name} order on {$updated->jobOrder->job_order_number}.",
            newValues: $updated->only(['customer_decision', 'customer_decision_at', 'customer_decision_note', 'required_downpayment_amount', 'status'])
        );

        return response()->json([
            'message' => $decision === JobOrderPartOrder::DECISION_APPROVED
                ? 'Customer approval recorded; the order remains blocked until the required payment is verified.'
                : 'Customer rejection recorded. This parts order cannot be placed.',
            'order' => $this->mapOrder($updated),
        ]);
    }

    public function placeOrder(Request $request, JobOrderPartOrder $partOrder)
    {
        $user = $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $partOrder);
        $validated = $request->validate([
            'supplier_name' => ['required', 'string', 'max:150'],
            'purchase_reference' => ['nullable', 'string', 'max:100'],
            'expected_arrival_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        [$ordered, $changed] = DB::transaction(function () use ($request, $partOrder, $validated) {
            $jobOrder = JobOrder::query()
                ->where('shop_id', $partOrder->shop_id)
                ->lockForUpdate()
                ->findOrFail($partOrder->job_order_id);
            $lockedOrder = JobOrderPartOrder::query()
                ->where('shop_id', $partOrder->shop_id)
                ->lockForUpdate()
                ->findOrFail($partOrder->id);

            if (in_array($lockedOrder->status, [
                JobOrderPartOrder::STATUS_ORDERED,
                JobOrderPartOrder::STATUS_RECEIVED,
            ], true)) {
                return [$lockedOrder->fresh()->load(['jobOrder.payments.refund', 'issuedJobOrderPart']), false];
            }

            if (!in_array($lockedOrder->status, [
                JobOrderPartOrder::STATUS_AWAITING_DOWNPAYMENT,
                JobOrderPartOrder::STATUS_READY_TO_ORDER,
            ], true) || $lockedOrder->customer_decision !== JobOrderPartOrder::DECISION_APPROVED) {
                throw ValidationException::withMessages([
                    'status' => 'Only an approved parts order can be placed.',
                ]);
            }

            $eligiblePaid = $this->eligiblePayments($jobOrder);
            if (round($eligiblePaid, 2) < round((float) $lockedOrder->required_downpayment_amount, 2)) {
                throw ValidationException::withMessages([
                    'required_downpayment_amount' => 'Eligible recorded payments do not satisfy the required downpayment.',
                ]);
            }

            $lockedOrder->update([
                'status' => JobOrderPartOrder::STATUS_ORDERED,
                'supplier_name' => trim($validated['supplier_name']),
                'purchase_reference' => isset($validated['purchase_reference']) ? trim($validated['purchase_reference']) : null,
                'expected_arrival_date' => $validated['expected_arrival_date'] ?? null,
            ]);

            return [$lockedOrder->fresh()->load(['jobOrder.payments.refund', 'issuedJobOrderPart']), true];
        });

        if ($changed) {
            AuditLogger::log(
                request: $request,
                user: $user,
                action: 'job_order_part_order.ordered',
                module: 'job_order_part_orders',
                record: $ordered,
                description: "Placed a supplier order for {$ordered->part_name} on {$ordered->jobOrder->job_order_number}.",
                newValues: $ordered->only(['status', 'supplier_name', 'purchase_reference', 'expected_arrival_date'])
            );
        }

        return response()->json([
            'message' => 'Supplier order recorded.',
            'order' => $this->mapOrder($ordered),
        ]);
    }

    public function receive(Request $request, JobOrderPartOrder $partOrder)
    {
        $user = $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $partOrder);
        $shopId = $this->currentShopId($request);
        $validated = $request->validate([
            'spare_part_id' => [
                'required',
                Rule::exists('spare_parts', 'id')->where(fn ($query) => $query->where('shop_id', $shopId)),
            ],
        ]);

        [$received, $changed] = DB::transaction(function () use ($request, $partOrder, $validated, $shopId, $user) {
            $lockedOrder = JobOrderPartOrder::query()
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($partOrder->id);

            if ($lockedOrder->status === JobOrderPartOrder::STATUS_RECEIVED) {
                if ((int) $lockedOrder->inventory_spare_part_id !== (int) $validated['spare_part_id']) {
                    throw ValidationException::withMessages([
                        'spare_part_id' => 'This order has already been received into a different inventory item.',
                    ]);
                }

                return [$lockedOrder->fresh()->load(['jobOrder.payments.refund', 'inventoryPart', 'issuedJobOrderPart']), false];
            }

            if ($lockedOrder->status !== JobOrderPartOrder::STATUS_ORDERED) {
                throw ValidationException::withMessages([
                    'status' => 'Only a supplier order can be received.',
                ]);
            }

            $inventoryPart = SparePart::query()
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($validated['spare_part_id']);
            if (!$inventoryPart->is_active) {
                throw ValidationException::withMessages([
                    'spare_part_id' => 'The selected inventory item is inactive.',
                ]);
            }
            if (strcasecmp(trim($inventoryPart->name), trim($lockedOrder->part_name)) !== 0) {
                throw ValidationException::withMessages([
                    'spare_part_id' => 'Select an inventory item whose name matches the ordered part. Create the matching inventory item first if needed.',
                ]);
            }

            $inventoryPart->increment('stock_quantity', (int) $lockedOrder->quantity);
            $inventoryPart->refresh();
            $lockedOrder->update([
                'inventory_spare_part_id' => $inventoryPart->id,
                'received_by_user_id' => $user->id,
                'received_at' => now(),
                'status' => JobOrderPartOrder::STATUS_RECEIVED,
            ]);

            StockMovement::create([
                'shop_id' => $shopId,
                'spare_part_id' => $inventoryPart->id,
                'job_order_id' => $lockedOrder->job_order_id,
                'type' => 'IN',
                'quantity_change' => (int) $lockedOrder->quantity,
                'balance_after' => $inventoryPart->stock_quantity,
                'note' => "Received {$lockedOrder->quantity} for Job Order {$lockedOrder->jobOrder->job_order_number} (parts order #{$lockedOrder->id}).",
                'created_by' => $user->name,
            ]);

            return [$lockedOrder->fresh()->load(['jobOrder.payments.refund', 'inventoryPart', 'issuedJobOrderPart']), true];
        });

        if ($changed) {
            AuditLogger::log(
                request: $request,
                user: $user,
                action: 'job_order_part_order.received',
                module: 'job_order_part_orders',
                record: $received,
                description: "Received {$received->quantity} {$received->part_name} for {$received->jobOrder->job_order_number}.",
                newValues: $received->only(['status', 'inventory_spare_part_id', 'received_at', 'received_by_user_id'])
            );
        }

        return response()->json([
            'message' => 'Ordered quantity received into inventory. Issue it to the repair separately when the device is at the shop.',
            'order' => $this->mapOrder($received),
        ]);
    }

    public function cancel(Request $request, JobOrderPartOrder $partOrder)
    {
        $user = $this->authorizeStaffOrAdmin($request);
        $this->ensureSameShop($request, $partOrder);
        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:255'],
        ]);

        [$cancelled, $changed] = DB::transaction(function () use ($request, $partOrder, $user, $validated) {
            JobOrder::query()
                ->where('shop_id', $partOrder->shop_id)
                ->lockForUpdate()
                ->findOrFail($partOrder->job_order_id);
            $lockedOrder = JobOrderPartOrder::query()
                ->where('shop_id', $partOrder->shop_id)
                ->lockForUpdate()
                ->findOrFail($partOrder->id);

            if ($lockedOrder->status === JobOrderPartOrder::STATUS_CANCELLED) {
                return [$lockedOrder->fresh()->load(['jobOrder.payments.refund', 'issuedJobOrderPart']), false];
            }
            if (!in_array($lockedOrder->status, [
                JobOrderPartOrder::STATUS_AWAITING_APPROVAL,
                JobOrderPartOrder::STATUS_AWAITING_DOWNPAYMENT,
                JobOrderPartOrder::STATUS_READY_TO_ORDER,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'An order sent to a supplier cannot be cancelled here. Record the supplier cancellation and any financial reconciliation through the authorized process first.',
                ]);
            }

            $lockedOrder->update([
                'status' => JobOrderPartOrder::STATUS_CANCELLED,
                'cancelled_by_user_id' => $user->id,
                'cancelled_at' => now(),
                'cancellation_reason' => trim($validated['cancellation_reason']),
            ]);

            return [$lockedOrder->fresh()->load(['jobOrder.payments.refund', 'issuedJobOrderPart']), true];
        });

        if ($changed) {
            AuditLogger::log(
                request: $request,
                user: $user,
                action: 'job_order_part_order.cancelled',
                module: 'job_order_part_orders',
                record: $cancelled,
                description: "Cancelled the {$cancelled->part_name} order for {$cancelled->jobOrder->job_order_number}.",
                newValues: $cancelled->only(['status', 'cancelled_at', 'cancellation_reason', 'cancelled_by_user_id'])
            );
        }

        return response()->json([
            'message' => 'Parts order cancelled.',
            'order' => $this->mapOrder($cancelled),
        ]);
    }

    public function updateDeviceLocation(Request $request, JobOrder $jobOrder)
    {
        $user = $request->user();
        if ($user?->hasAnyRole([User::ROLE_ADMIN, User::ROLE_STAFF])) {
            $this->authorizeStaffOrAdmin($request);
        } elseif ($user?->hasRole(User::ROLE_TECHNICIAN)) {
            $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
            $this->ensureSameShop($request, $jobOrder);
            $this->ensureAssignedToTechnician($jobOrder, $this->resolveTechnician($user));
        } else {
            $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_TECHNICIAN]);
        }
        $this->ensureSameShop($request, $jobOrder);

        $validated = $request->validate([
            'device_location' => ['required', Rule::in(['AT_SHOP', 'WITH_CUSTOMER'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        [$updated, $previousLocation] = DB::transaction(function () use ($request, $jobOrder, $user, $validated) {
            $lockedJob = JobOrder::query()
                ->where('shop_id', $this->currentShopId($request))
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);
            $previousLocation = $lockedJob->device_location;

            if ($user->hasRole(User::ROLE_TECHNICIAN)) {
                $this->ensureAssignedToTechnician($lockedJob, $this->resolveTechnician($user));
            }

            $lockedJob->update([
                'device_location' => $validated['device_location'],
                'device_location_updated_at' => now(),
                'device_location_updated_by_user_id' => $user->id,
            ]);

            return [$lockedJob->fresh(['deviceLocationUpdatedBy:id,name']), $previousLocation];
        });

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'job_order.device_location_updated',
            module: 'job_orders',
            record: $updated,
            description: "Updated device location for {$updated->job_order_number} to {$updated->device_location}.",
            oldValues: ['device_location' => $previousLocation],
            newValues: [
                'device_location' => $updated->device_location,
                'note' => isset($validated['note']) ? trim($validated['note']) : null,
            ]
        );

        return response()->json([
            'message' => 'Device location updated.',
            'job_order' => [
                'id' => $updated->id,
                'device_location' => $updated->device_location,
                'device_location_updated_at' => $updated->device_location_updated_at,
                'device_location_updated_by' => $updated->deviceLocationUpdatedBy?->name,
            ],
        ]);
    }

    public function issueToJob(Request $request, JobOrder $jobOrder, JobOrderPartOrder $partOrder)
    {
        $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
        $technician = $this->resolveTechnician($user);
        $this->ensureSameShop($request, $jobOrder);
        $this->ensureAssignedToTechnician($jobOrder, $technician);

        [$updatedJob, $jobOrderPart] = DB::transaction(function () use ($request, $jobOrder, $partOrder, $user, $technician) {
            $shopId = $this->currentShopId($request);
            $lockedJob = JobOrder::query()
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);
            $this->ensureAssignedToTechnician($lockedJob, $technician);
            $lockedOrder = JobOrderPartOrder::query()
                ->where('shop_id', $shopId)
                ->where('job_order_id', $lockedJob->id)
                ->lockForUpdate()
                ->findOrFail($partOrder->id);

            if ($lockedOrder->status !== JobOrderPartOrder::STATUS_RECEIVED || $lockedOrder->issued_at) {
                throw ValidationException::withMessages([
                    'part_order' => 'This part order is not available to issue or has already been issued.',
                ]);
            }
            if ($lockedJob->device_location !== 'AT_SHOP') {
                throw ValidationException::withMessages([
                    'device_location' => 'Update the device location to At Shop before issuing the ordered part to the repair.',
                ]);
            }
            if (in_array($lockedJob->current_status, ['READY_FOR_PICKUP', 'PICKED_UP', 'RTO', 'RTO_RELEASED'], true)) {
                throw ValidationException::withMessages([
                    'job_order' => 'Parts can no longer be issued to this repair in its current status.',
                ]);
            }

            $inventoryPart = SparePart::query()
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($lockedOrder->inventory_spare_part_id);
            if (!$inventoryPart->is_active || (int) $inventoryPart->stock_quantity < (int) $lockedOrder->quantity) {
                throw ValidationException::withMessages([
                    'inventory' => 'The received inventory item is inactive or no longer has the required quantity available.',
                ]);
            }

            $unitPrice = (float) $lockedOrder->estimated_unit_price;
            $total = round((int) $lockedOrder->quantity * $unitPrice, 2);
            $partsTotal = (float) $lockedJob->parts()->sum('total_cost') + $total;
            $newTotal = $partsTotal + (float) $lockedJob->labor_cost;
            $this->ensureTotalCoversCollectedAmount($newTotal, $lockedJob->amount_paid);

            $jobOrderPart = JobOrderPart::create([
                'shop_id' => $shopId,
                'job_order_id' => $lockedJob->id,
                'job_order_part_order_id' => $lockedOrder->id,
                'spare_part_id' => $inventoryPart->id,
                'part_name' => $lockedOrder->part_name,
                'quantity' => $lockedOrder->quantity,
                'unit_cost' => $unitPrice,
                'total_cost' => $total,
                'notes' => "Issued from received customer parts order #{$lockedOrder->id}.",
            ]);

            $inventoryPart->decrement('stock_quantity', (int) $lockedOrder->quantity);
            $inventoryPart->refresh();
            StockMovement::create([
                'shop_id' => $shopId,
                'spare_part_id' => $inventoryPart->id,
                'job_order_id' => $lockedJob->id,
                'job_order_part_id' => $jobOrderPart->id,
                'type' => 'OUT',
                'quantity_change' => -(int) $lockedOrder->quantity,
                'balance_after' => $inventoryPart->stock_quantity,
                'note' => "Issued received parts order #{$lockedOrder->id} to Job Order {$lockedJob->job_order_number}.",
                'created_by' => $user->name,
            ]);

            $lockedJob->update([
                'parts_cost' => $partsTotal,
                'total_cost' => $newTotal,
                'payment_status' => (float) $lockedJob->amount_paid <= 0
                    ? 'PENDING'
                    : ((float) $lockedJob->amount_paid >= $newTotal ? 'PAID' : 'PARTIAL'),
            ]);
            $lockedOrder->update([
                'issued_at' => now(),
                'issued_by_user_id' => $user->id,
            ]);

            return [$lockedJob->fresh(), $jobOrderPart];
        });

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'job_order_part_order.issued_to_repair',
            module: 'job_order_part_orders',
            record: $partOrder,
            description: "Issued {$jobOrderPart->quantity} {$jobOrderPart->part_name} to {$updatedJob->job_order_number}.",
            newValues: ['job_order_part_id' => $jobOrderPart->id, 'issued_by_user_id' => $user->id]
        );

        return response()->json([
            'message' => 'Received part issued to the Job Order. The repair status was not changed.',
            'job' => $updatedJob,
            'part' => $jobOrderPart,
        ], 201);
    }

    public function mapOrder(JobOrderPartOrder $order): array
    {
        $jobOrder = $order->jobOrder;
        $eligiblePaid = $jobOrder ? $this->eligiblePayments($jobOrder) : 0.0;
        $status = $order->status;
        if (
            in_array($status, [
                JobOrderPartOrder::STATUS_AWAITING_DOWNPAYMENT,
                JobOrderPartOrder::STATUS_READY_TO_ORDER,
            ], true)
            && $order->customer_decision === JobOrderPartOrder::DECISION_APPROVED
        ) {
            $status = round($eligiblePaid, 2) >= round((float) $order->required_downpayment_amount, 2)
                ? JobOrderPartOrder::STATUS_READY_TO_ORDER
                : JobOrderPartOrder::STATUS_AWAITING_DOWNPAYMENT;
        }

        return [
            'id' => $order->id,
            'job_order_id' => $order->job_order_id,
            'job_order_number' => $jobOrder?->job_order_number,
            'customer_name' => $jobOrder?->customer_name,
            'customer_contact' => $jobOrder?->customer_contact,
            'customer_phone' => $jobOrder?->customer_phone,
            'device_location' => $jobOrder?->device_location ?? 'UNKNOWN',
            'device_location_updated_at' => $jobOrder?->device_location_updated_at,
            'device_location_updated_by' => $jobOrder?->deviceLocationUpdatedBy?->name,
            'technician_name' => $jobOrder?->technician?->name,
            'part_name' => $order->part_name,
            'quantity' => (int) $order->quantity,
            'reason' => $order->reason,
            'estimated_unit_price' => $order->estimated_unit_price,
            'estimated_total' => number_format((float) $order->estimated_unit_price * (int) $order->quantity, 2, '.', ''),
            'customer_decision' => $order->customer_decision,
            'customer_decision_at' => $order->customer_decision_at,
            'customer_decision_note' => $order->customer_decision_note,
            'customer_decision_recorded_by' => $order->customerDecisionRecordedBy?->name,
            'required_downpayment_amount' => $order->required_downpayment_amount,
            'eligible_paid_amount' => number_format($eligiblePaid, 2, '.', ''),
            'remaining_downpayment_amount' => number_format(
                max(0, (float) $order->required_downpayment_amount - $eligiblePaid),
                2,
                '.',
                ''
            ),
            'can_order' => $order->customer_decision === JobOrderPartOrder::DECISION_APPROVED
                && $order->required_downpayment_amount !== null
                && round($eligiblePaid, 2) >= round((float) $order->required_downpayment_amount, 2),
            'status' => $status,
            'display_status' => $order->status === JobOrderPartOrder::STATUS_RECEIVED && $order->issued_at
                ? 'issued_to_job_order'
                : ($order->status === JobOrderPartOrder::STATUS_CANCELLED
                    && $order->customer_decision === JobOrderPartOrder::DECISION_REJECTED
                    ? 'declined'
                    : $status),
            'supplier_name' => $order->supplier_name,
            'purchase_reference' => $order->purchase_reference,
            'expected_arrival_date' => $order->expected_arrival_date?->toDateString(),
            'inventory_spare_part' => $order->inventoryPart ? [
                'id' => $order->inventoryPart->id,
                'name' => $order->inventoryPart->name,
                'unit' => $order->inventoryPart->unit,
            ] : null,
            'received_at' => $order->received_at,
            'received_by' => $order->receivedBy?->name,
            'issued_at' => $order->issued_at,
            'issued_by' => $order->issuedBy?->name,
            'requested_by' => $order->requestedBy?->name,
            'requested_at' => $order->created_at,
            'cancelled_at' => $order->cancelled_at,
            'cancellation_reason' => $order->cancellation_reason,
        ];
    }

    private function mapTechnicianOrder(JobOrderPartOrder $order): array
    {
        $mapped = $this->mapOrder($order);

        unset(
            $mapped['customer_contact'],
            $mapped['customer_phone'],
            $mapped['estimated_unit_price'],
            $mapped['estimated_total'],
            $mapped['customer_decision_note'],
            $mapped['required_downpayment_amount'],
            $mapped['eligible_paid_amount'],
            $mapped['remaining_downpayment_amount'],
            $mapped['can_order'],
            $mapped['supplier_name'],
            $mapped['purchase_reference'],
            $mapped['expected_arrival_date'],
            $mapped['inventory_spare_part']
        );

        return $mapped;
    }

    private function eligiblePayments(JobOrder $jobOrder): float
    {
        if ($jobOrder->relationLoaded('payments')) {
            return round((float) $jobOrder->payments
                ->filter(fn (JobOrderPayment $payment) => (int) $payment->shop_id === (int) $jobOrder->shop_id
                    && $payment->received_at !== null
                    && $payment->refund === null)
                ->sum(fn (JobOrderPayment $payment) => (float) $payment->amount), 2);
        }

        return round((float) $jobOrder->payments()
            ->where('shop_id', $jobOrder->shop_id)
            ->whereNotNull('received_at')
            ->whereNotIn('id', JobOrderRefund::query()
                ->select('job_order_payment_id')
                ->whereNotNull('job_order_payment_id'))
            ->sum('amount'), 2);
    }

    private function partOrderPayloadHash(int $jobOrderId, array $validated, bool $isTechnician, string $decision): string
    {
        return hash('sha256', json_encode([
            'job_order_id' => $jobOrderId,
            'part_name' => trim($validated['part_name']),
            'quantity' => (int) $validated['quantity'],
            'reason' => trim($validated['reason']),
            'estimated_unit_price' => number_format((float) $validated['estimated_unit_price'], 2, '.', ''),
            'customer_decision' => $decision,
            'customer_decision_note' => $validated['customer_decision_note'] ?? null,
            'required_downpayment_amount' => $validated['required_downpayment_amount'] ?? null,
            'device_location' => $validated['device_location'] ?? null,
            'device_location_note' => isset($validated['device_location_note'])
                ? trim($validated['device_location_note'])
                : null,
            'technician_request' => $isTechnician,
        ], JSON_THROW_ON_ERROR));
    }

    private function isIdempotencyConstraintViolation(QueryException $exception, string $constraint): bool
    {
        return $exception->getCode() === '23000'
            && str_contains($exception->getMessage(), $constraint);
    }

    private function refreshReadyStatus(JobOrderPartOrder $order, JobOrder $jobOrder): void
    {
        if (
            $order->status === JobOrderPartOrder::STATUS_AWAITING_DOWNPAYMENT
            && round($this->eligiblePayments($jobOrder), 2) >= round((float) $order->required_downpayment_amount, 2)
        ) {
            $order->update(['status' => JobOrderPartOrder::STATUS_READY_TO_ORDER]);
        }
    }

    private function ensureTotalCoversCollectedAmount(float $totalCost, float $amountPaid): void
    {
        if ($totalCost < $amountPaid) {
            throw ValidationException::withMessages([
                'total_cost' => 'The agreed parts price would make the Job Order total lower than the amount already paid.',
            ]);
        }
    }
}
