<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PartRequest;
use App\Models\SparePart;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PartRequestController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $shopId = $this->currentShopId($request);

        $requests = PartRequest::query()
            ->with([
                'jobOrder:id,job_order_number',
                'requestedBy:id,name',
                'updatedBy:id,name',
                'receivedBy:id,name',
                'inventoryPart:id,name,stock_quantity',
            ])
            ->where('shop_id', $shopId)
            ->latest()
            ->get()
            ->map(fn (PartRequest $partRequest) => $this->mapRequest($partRequest));

        $checklist = collect()
            ->merge(SparePart::query()
                ->where('shop_id', $shopId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['name', 'category'])
                ->map(fn (SparePart $part) => [
                    'part_name' => trim($part->name),
                    'category' => $part->category,
                ]))
            ->merge(PartRequest::query()
                ->where('shop_id', $shopId)
                ->whereNull('job_order_id')
                ->orderBy('part_name')
                ->get(['part_name', 'category'])
                ->map(fn (PartRequest $partRequest) => [
                    'part_name' => trim($partRequest->part_name),
                    'category' => $partRequest->category,
                ]))
            ->filter(fn (array $part) => $part['part_name'] !== '')
            ->unique(fn (array $part) => mb_strtolower($part['part_name']))
            ->values()
            ->all();

        return response()->json([
            'requests' => $requests,
            'checklist' => $checklist,
            'summary' => [
                'total' => $requests->count(),
                'pending' => $requests->where('status', PartRequest::STATUS_PENDING)->count(),
                'approved' => $requests->where('status', PartRequest::STATUS_APPROVED)->count(),
                'ordered' => $requests->where('status', PartRequest::STATUS_ORDERED)->count(),
                'received' => $requests->where('status', PartRequest::STATUS_RECEIVED)->count(),
                'cancelled' => $requests->where('status', PartRequest::STATUS_CANCELLED)->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
        $shopId = $this->currentShopId($request);
        $this->resolveTechnician($user);

        $validated = $request->validate([
            'job_order_id' => ['prohibited'],
            'idempotency_key' => ['nullable', 'uuid'],
            'part_name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        [$partRequest, $created] = $this->createPartRequest($request, $user, $validated, $shopId);

        if ($created) {
            AuditLogger::log(
            request: $request,
            user: $user,
            action: 'part_request.created',
            module: 'part_requests',
            record: $partRequest,
            description: "{$user->name} requested {$partRequest->quantity} {$partRequest->part_name} for shop purchasing.",
            newValues: $partRequest->only(['part_name', 'quantity', 'note', 'status'])
            );
        }

        return response()->json([
            'message' => 'Internal purchasing request submitted.',
            'request' => $this->mapRequest($partRequest->load(['jobOrder', 'requestedBy', 'updatedBy'])),
        ], $created ? 201 : 200);
    }

    public function storeForShop(Request $request)
    {
        $user = $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $shopId = $this->currentShopId($request);

        $validated = $request->validate([
            'job_order_id' => ['prohibited'],
            'idempotency_key' => ['nullable', 'uuid'],
            'register_part' => ['sometimes', 'boolean'],
            'part_name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([PartRequest::STATUS_PENDING, PartRequest::STATUS_ORDERED])],
        ]);

        if (($validated['register_part'] ?? false)
            && (PartRequest::query()
                ->where('shop_id', $shopId)
                ->whereNull('job_order_id')
                ->whereRaw('LOWER(TRIM(part_name)) = ?', [mb_strtolower(trim($validated['part_name']))])
                ->exists()
                || SparePart::query()
                    ->where('shop_id', $shopId)
                    ->where('is_active', true)
                    ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($validated['part_name']))])
                    ->exists())) {
            throw ValidationException::withMessages([
                'part_name' => 'This part is already in the reusable checklist.',
            ]);
        }

        [$partRequest, $created] = $this->createPartRequest(
            $request,
            $user,
            $validated,
            $shopId,
            $validated['status'] ?? PartRequest::STATUS_PENDING,
        );

        if ($created) {
            AuditLogger::log(
            request: $request,
            user: $user,
            action: 'part_request.created',
            module: 'part_requests',
            record: $partRequest,
            description: "{$user->name} requested {$partRequest->quantity} {$partRequest->part_name} for shop purchasing.",
            newValues: $partRequest->only(['part_name', 'quantity', 'note', 'status'])
            );
        }

        return response()->json([
            'message' => 'Part added to the shop ordering queue.',
            'request' => $this->mapRequest($partRequest->load(['jobOrder', 'requestedBy', 'updatedBy', 'inventoryPart', 'receivedBy'])),
        ], $created ? 201 : 200);
    }

    public function updateStatus(Request $request, PartRequest $partRequest)
    {
        $user = $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $this->ensureSameShop($request, $partRequest);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                PartRequest::STATUS_PENDING,
                PartRequest::STATUS_APPROVED,
                PartRequest::STATUS_ORDERED,
                PartRequest::STATUS_RECEIVED,
                PartRequest::STATUS_CANCELLED,
            ])],
        ]);

        $previousStatus = $partRequest->status;
        $nextStatus = $validated['status'];

        if ($previousStatus === $nextStatus) {
            return response()->json([
                'message' => 'Part request status is already up to date.',
                'request' => $this->mapRequest($partRequest->load(['jobOrder', 'requestedBy', 'updatedBy'])),
            ]);
        }

        $allowedTransitions = [
            PartRequest::STATUS_PENDING => [PartRequest::STATUS_APPROVED, PartRequest::STATUS_ORDERED, PartRequest::STATUS_CANCELLED],
            PartRequest::STATUS_APPROVED => [PartRequest::STATUS_ORDERED, PartRequest::STATUS_CANCELLED],
            PartRequest::STATUS_ORDERED => [PartRequest::STATUS_CANCELLED],
            PartRequest::STATUS_RECEIVED => [],
            PartRequest::STATUS_CANCELLED => [],
        ];
        if ($nextStatus === PartRequest::STATUS_RECEIVED) {
            throw ValidationException::withMessages([
                'status' => 'Use the inventory receiving action to mark a request received.',
            ]);
        }

        if (!in_array($nextStatus, $allowedTransitions[$previousStatus] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => "A part request cannot move from {$previousStatus} to {$nextStatus}.",
            ]);
        }

        DB::transaction(function () use ($partRequest, $user, $nextStatus): void {
            $lockedRequest = PartRequest::query()
                ->where('shop_id', $partRequest->shop_id)
                ->lockForUpdate()
                ->findOrFail($partRequest->id);

            if ($lockedRequest->status !== $partRequest->status) {
                throw ValidationException::withMessages([
                    'status' => 'This part request was updated by another user. Refresh and try again.',
                ]);
            }

            $lockedRequest->update([
                'status' => $nextStatus,
                'updated_by_user_id' => $user->id,
            ]);
        });

        $partRequest->refresh();

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'part_request.status_updated',
            module: 'part_requests',
            record: $partRequest,
            description: "Updated part request status from {$previousStatus} to {$nextStatus}.",
            oldValues: ['status' => $previousStatus],
            newValues: ['status' => $nextStatus]
        );

        return response()->json([
            'message' => 'Part request status updated.',
            'request' => $this->mapRequest($partRequest->load(['jobOrder', 'requestedBy', 'updatedBy'])),
        ]);
    }

    public function update(Request $request, PartRequest $partRequest)
    {
        $user = $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $this->ensureSameShop($request, $partRequest);

        if (!in_array($partRequest->status, [PartRequest::STATUS_PENDING, PartRequest::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages([
                'part' => 'Only pending or approved parts can be edited before ordering.',
            ]);
        }

        $validated = $request->validate([
            'part_name' => ['required', 'string', 'max:150'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $previous = $partRequest->only(['part_name', 'quantity', 'note', 'status']);

        DB::transaction(function () use ($partRequest, $user, $validated): void {
            $lockedRequest = PartRequest::query()
                ->where('shop_id', $partRequest->shop_id)
                ->lockForUpdate()
                ->findOrFail($partRequest->id);

            if (!in_array($lockedRequest->status, [PartRequest::STATUS_PENDING, PartRequest::STATUS_APPROVED], true)) {
                throw ValidationException::withMessages([
                    'part' => 'This part cannot be edited because its order workflow has already progressed.',
                ]);
            }

            $lockedRequest->update([
                'part_name' => trim($validated['part_name']),
                'quantity' => $validated['quantity'],
                'note' => $validated['note'] ?? null,
                'updated_by_user_id' => $user->id,
            ]);
        });

        $partRequest->refresh();

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'part_request.updated',
            module: 'part_requests',
            record: $partRequest,
            description: "Updated the For Order request for {$partRequest->part_name}.",
            oldValues: $previous,
            newValues: $partRequest->only(['part_name', 'quantity', 'note', 'status'])
        );

        return response()->json([
            'message' => 'For Order item updated.',
            'request' => $this->mapRequest($partRequest->load(['jobOrder', 'requestedBy', 'updatedBy', 'inventoryPart', 'receivedBy'])),
        ]);
    }

    public function receive(Request $request, PartRequest $partRequest)
    {
        $user = $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $this->ensureSameShop($request, $partRequest);
        $shopId = $this->currentShopId($request);

        $validated = $request->validate([
            'spare_part_id' => [
                'required',
                Rule::exists('spare_parts', 'id')->where(fn ($query) => $query->where('shop_id', $shopId)),
            ],
        ]);

        [$updated, $changed] = DB::transaction(function () use ($partRequest, $validated, $shopId, $user) {
            $lockedRequest = PartRequest::query()
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($partRequest->id);

            if ($lockedRequest->status === PartRequest::STATUS_RECEIVED) {
                if ((int) $lockedRequest->inventory_spare_part_id !== (int) $validated['spare_part_id']) {
                    throw ValidationException::withMessages([
                        'spare_part_id' => 'This request has already been received into a different inventory item.',
                    ]);
                }

                return [$lockedRequest->fresh()->load(['jobOrder', 'requestedBy', 'updatedBy', 'inventoryPart', 'receivedBy']), false];
            }

            if ($lockedRequest->status !== PartRequest::STATUS_ORDERED) {
                throw ValidationException::withMessages([
                    'status' => 'Only an ordered part request can be received.',
                ]);
            }

            $inventoryPart = SparePart::query()
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($validated['spare_part_id']);
            if (!$inventoryPart->is_active || strcasecmp(trim($inventoryPart->name), trim($lockedRequest->part_name)) !== 0) {
                throw ValidationException::withMessages([
                    'spare_part_id' => 'Select an active inventory item whose name matches the requested part.',
                ]);
            }

            $inventoryPart->increment('stock_quantity', (int) $lockedRequest->quantity);
            $inventoryPart->refresh();
            $lockedRequest->update([
                'status' => PartRequest::STATUS_RECEIVED,
                'inventory_spare_part_id' => $inventoryPart->id,
                'received_at' => now(),
                'received_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
            ]);
            StockMovement::create([
                'shop_id' => $shopId,
                'spare_part_id' => $inventoryPart->id,
                'type' => 'IN',
                'quantity_change' => (int) $lockedRequest->quantity,
                'balance_after' => $inventoryPart->stock_quantity,
                'note' => "Received {$lockedRequest->quantity} for general part request #{$lockedRequest->id}.",
                'created_by' => $user->name,
            ]);

            return [$lockedRequest->fresh()->load(['jobOrder', 'requestedBy', 'updatedBy', 'inventoryPart', 'receivedBy']), true];
        });

        if ($changed) {
            AuditLogger::log(
                request: $request,
                user: $user,
                action: 'part_request.received',
                module: 'part_requests',
                record: $updated,
                description: "Received {$updated->quantity} {$updated->part_name} into inventory.",
                newValues: $updated->only(['status', 'inventory_spare_part_id', 'received_at', 'received_by_user_id'])
            );
        }

        return response()->json([
            'message' => 'Part request received into inventory.',
            'request' => $this->mapRequest($updated),
        ]);
    }

    private function createPartRequest(
        Request $request,
        User $user,
        array $validated,
        int $shopId,
        string $status = PartRequest::STATUS_PENDING,
    ): array
    {
        $idempotencyKey = $validated['idempotency_key'] ?? null;
        $partName = trim($validated['part_name']);
        $category = isset($validated['category']) ? trim($validated['category']) : null;
        $quantity = (int) $validated['quantity'];
        $note = isset($validated['note']) ? trim($validated['note']) : null;
        $payload = [
            'part_name' => $partName,
            'category' => $category,
            'quantity' => $quantity,
            'note' => $note,
        ];
        if ($status !== PartRequest::STATUS_PENDING) {
            $payload['status'] = $status;
        }
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($idempotencyKey, $payloadHash, $shopId, $user, $partName, $category, $quantity, $note, $status) {
                if ($idempotencyKey !== null) {
                    $existing = PartRequest::query()
                        ->where('shop_id', $shopId)
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        if ($existing->idempotency_payload_hash !== $payloadHash) {
                            abort(409, 'This idempotency key was already used with different request data.');
                        }

                        return [$existing, false];
                    }
                }

                return [PartRequest::create([
                    'shop_id' => $shopId,
                    'requested_by_user_id' => $user->id,
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_payload_hash' => $payloadHash,
                    'part_name' => $partName,
                    'category' => $category,
                    'quantity' => $quantity,
                    'note' => $note,
                    'status' => $status,
                ]), true];
            }, 3);
        } catch (QueryException $exception) {
            if (!$this->isIdempotencyConstraintViolation($exception, 'preq_shop_idempotency_unique') || $idempotencyKey === null) {
                throw $exception;
            }

            $existing = PartRequest::query()
                ->where('shop_id', $shopId)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
            if ($existing->idempotency_payload_hash !== $payloadHash) {
                abort(409, 'This idempotency key was already used with different request data.');
            }

            return [$existing, false];
        }
    }

    private function isIdempotencyConstraintViolation(QueryException $exception, string $constraint): bool
    {
        return $exception->getCode() === '23000'
            && str_contains($exception->getMessage(), $constraint);
    }

    private function mapRequest(PartRequest $partRequest): array
    {
        return [
            'id' => $partRequest->id,
            'part_name' => $partRequest->part_name,
            'category' => $partRequest->category,
            'quantity' => $partRequest->quantity,
            'note' => $partRequest->note,
            'status' => $partRequest->status,
            'shop_id' => $partRequest->shop_id,
            'inventory_spare_part' => $partRequest->inventoryPart ? [
                'id' => $partRequest->inventoryPart->id,
                'name' => $partRequest->inventoryPart->name,
                'stock_quantity' => $partRequest->inventoryPart->stock_quantity,
            ] : null,
            'received_at' => $partRequest->received_at,
            'received_by' => $partRequest->receivedBy?->name,
            'job_order' => $partRequest->jobOrder ? [
                'id' => $partRequest->jobOrder->id,
                'job_order_number' => $partRequest->jobOrder->job_order_number,
            ] : null,
            'requested_by' => $partRequest->requestedBy?->name,
            'updated_by' => $partRequest->updatedBy?->name,
            'created_at' => $partRequest->created_at,
            'created_at_formatted' => $partRequest->created_at?->format('M d, Y h:i A'),
            'updated_at' => $partRequest->updated_at,
            'updated_at_formatted' => $partRequest->updated_at?->format('M d, Y h:i A'),
        ];
    }
}
