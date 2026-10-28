<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\JobOrderPart;
use App\Models\SparePart;
use App\Models\StatusHistory;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BlockedJobPartController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_TECHNICIAN]);
        $shopId = $this->currentShopId($request);

        $requests = JobOrder::query()
            ->with([
                'technician:id,name',
                'statusHistories' => function ($query) {
                    $query
                        ->where('status', 'WAITING_PARTS')
                        ->where('note', 'like', 'Parts requested:%')
                        ->latest('created_at');
                },
            ])
            ->where('shop_id', $shopId)
            ->where('current_status', 'WAITING_PARTS')
            ->latest()
            ->get()
            ->map(function (JobOrder $jobOrder) use ($shopId) {
                $history = $jobOrder->statusHistories->first();
                $parsed = $history ? $this->parseRequestNote($history) : null;

                if (!$history || !$parsed) {
                    return null;
                }

                return [
                    'job_id' => $jobOrder->id,
                    'job_order_number' => $jobOrder->job_order_number,
                    'technician_name' => $jobOrder->technician?->name,
                    'requested_part' => $parsed['part_name'],
                    'requested_quantity' => $parsed['quantity'],
                    'request_note' => $parsed['note'],
                    'requested_at_formatted' => $history->created_at?->format('M d, Y h:i A'),
                    'inventory_matches' => $this->findInventoryMatches($shopId, $parsed['part_name']),
                ];
            })
            ->filter()
            ->values();

        return response()->json(['requests' => $requests]);
    }

    public function store(Request $request, JobOrder $jobOrder)
    {
        $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
        $technician = $this->resolveTechnician($user);
        $this->ensureSameShop($request, $jobOrder);
        $this->ensureAssignedToTechnician($jobOrder, $technician);

        $validated = $request->validate([
            'part_name' => ['required', 'string', 'max:150'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $statusNote = "Parts requested: {$validated['part_name']} (Qty {$validated['quantity']})";
        $note = trim((string) ($validated['note'] ?? ''));
        if ($note !== '') {
            $statusNote .= ". Note: {$note}";
        }

        $previousStatus = DB::transaction(function () use ($request, $jobOrder, $technician, $statusNote) {
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $this->currentShopId($request))
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);
            $this->ensureAssignedToTechnician($lockedJobOrder, $technician);

            if (in_array($lockedJobOrder->current_status, ['READY_FOR_PICKUP', 'PICKED_UP', 'RTO', 'RTO_RELEASED'], true)) {
                throw ValidationException::withMessages([
                    'job_order' => 'This repair can no longer be blocked for parts in its current status.',
                ]);
            }

            $previousStatus = $lockedJobOrder->current_status;
            if ($previousStatus !== 'WAITING_PARTS') {
                $lockedJobOrder->update(['current_status' => 'WAITING_PARTS']);
            }
            StatusHistory::create([
                'shop_id' => $lockedJobOrder->shop_id,
                'job_order_id' => $lockedJobOrder->id,
                'status' => 'WAITING_PARTS',
                'note' => $statusNote,
            ]);

            return $previousStatus;
        });

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'blocked_job_part.requested',
            module: 'job_orders',
            record: $jobOrder,
            description: "{$technician->name} reported {$validated['part_name']} unavailable for {$jobOrder->job_order_number}.",
            oldValues: ['current_status' => $previousStatus],
            newValues: [
                'current_status' => 'WAITING_PARTS',
                'requested_part' => $validated['part_name'],
                'quantity' => $validated['quantity'],
                'note' => $note !== '' ? $note : null,
            ]
        );

        return response()->json([
            'message' => 'This repair is marked Waiting for Parts. The request is linked to this Job Order.',
            'job' => $jobOrder->fresh(),
        ], 201);
    }

    public function fulfill(Request $request, JobOrder $jobOrder)
    {
        $user = $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $this->ensureSameShop($request, $jobOrder);
        $shopId = $this->currentShopId($request);

        $history = $jobOrder->statusHistories()
            ->where('status', 'WAITING_PARTS')
            ->where('note', 'like', 'Parts requested:%')
            ->latest('created_at')
            ->first();

        $parsed = $history ? $this->parseRequestNote($history) : null;
        if (!$parsed || $jobOrder->current_status !== 'WAITING_PARTS') {
            return response()->json([
                'message' => 'This job does not have an active, job-linked parts request.',
            ], 422);
        }

        $validated = $request->validate([
            'spare_part_id' => [
                'required',
                Rule::exists('spare_parts', 'id')->where(fn ($query) => $query->where('shop_id', $shopId)),
            ],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $quantity = (int) ($validated['quantity'] ?? $parsed['quantity']);
        $operatorNote = trim((string) ($validated['note'] ?? ''));

        [$part, $previousStatus] = DB::transaction(function () use ($validated, $quantity, $jobOrder, $request, $operatorNote, $shopId) {
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);

            if ($lockedJobOrder->current_status !== 'WAITING_PARTS') {
                throw ValidationException::withMessages([
                    'job_order' => 'This job-linked parts request is no longer active.',
                ]);
            }

            $sparePart = SparePart::query()
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($validated['spare_part_id']);

            if (!$sparePart->is_active) {
                throw ValidationException::withMessages([
                    'spare_part_id' => 'Selected spare part is inactive.',
                ]);
            }

            if ((int) $sparePart->stock_quantity < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'Selected inventory part does not have enough stock.',
                ]);
            }

            $total = $quantity * (float) $sparePart->unit_cost;
            $partsTotal = (float) $lockedJobOrder->parts()->sum('total_cost') + $total;
            $newTotal = $partsTotal + (float) $lockedJobOrder->labor_cost;
            $this->ensureTotalCoversCollectedAmount($newTotal, $lockedJobOrder->amount_paid);

            $part = JobOrderPart::create([
                'shop_id' => $lockedJobOrder->shop_id,
                'job_order_id' => $lockedJobOrder->id,
                'spare_part_id' => $sparePart->id,
                'part_name' => $sparePart->name,
                'quantity' => $quantity,
                'unit_cost' => $sparePart->unit_cost,
                'total_cost' => $total,
                'notes' => $operatorNote !== '' ? $operatorNote : 'Issued for a job-linked blocked parts request',
            ]);

            $sparePart->decrement('stock_quantity', $quantity);
            $sparePart->refresh();
            StockMovement::create([
                'shop_id' => $shopId,
                'spare_part_id' => $sparePart->id,
                'job_order_id' => $lockedJobOrder->id,
                'job_order_part_id' => $part->id,
                'type' => 'OUT',
                'quantity_change' => -$quantity,
                'balance_after' => $sparePart->stock_quantity,
                'note' => "Issued for job order {$lockedJobOrder->job_order_number}",
                'created_by' => $request->user()?->name,
            ]);

            $previousStatus = $lockedJobOrder->current_status;
            $lockedJobOrder->update([
                'parts_cost' => $partsTotal,
                'total_cost' => $newTotal,
                'payment_status' => $this->paymentStatusForTotal($lockedJobOrder->amount_paid, $newTotal),
                'current_status' => 'IN_PROGRESS',
            ]);
            StatusHistory::create([
                'shop_id' => $shopId,
                'job_order_id' => $lockedJobOrder->id,
                'status' => 'IN_PROGRESS',
                'note' => "Job-linked parts request fulfilled using {$part->part_name} (Qty {$quantity}).",
            ]);

            return [$part, $previousStatus];
        });

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'blocked_job_part.fulfilled',
            module: 'job_orders',
            record: $jobOrder,
            description: "{$user->name} issued {$part->part_name} to {$jobOrder->job_order_number}.",
            oldValues: ['current_status' => $previousStatus, 'requested_part' => $parsed['part_name']],
            newValues: ['current_status' => 'IN_PROGRESS', 'fulfilled_part' => $part->part_name, 'fulfilled_quantity' => $quantity]
        );

        return response()->json([
            'message' => 'Job-linked part issued from inventory and the repair returned to progress.',
        ]);
    }

    private function parseRequestNote(StatusHistory $history): ?array
    {
        if (!preg_match(
            '/^Parts requested:\s*(.+?)\s*\(Qty\s+(\d+)\)(?:\.\s*Note:\s*(.+))?$/',
            trim((string) $history->note),
            $matches
        )) {
            return null;
        }

        return [
            'part_name' => trim($matches[1]),
            'quantity' => (int) $matches[2],
            'note' => isset($matches[3]) ? trim($matches[3]) : null,
        ];
    }

    private function findInventoryMatches(int $shopId, string $requestedPart): array
    {
        $terms = collect(preg_split('/\s+/', strtolower($requestedPart)) ?: [])
            ->map(fn ($term) => preg_replace('/[^a-z0-9]/', '', $term))
            ->filter(fn ($term) => strlen($term) >= 3)
            ->take(4);

        $query = SparePart::query()
            ->where('shop_id', $shopId)
            ->where('is_active', true);

        if ($terms->isNotEmpty()) {
            $query->where(function ($builder) use ($terms, $requestedPart) {
                $builder->where('name', 'like', "%{$requestedPart}%")
                    ->orWhere('part_number', 'like', "%{$requestedPart}%");

                foreach ($terms as $term) {
                    $builder->orWhere('name', 'like', "%{$term}%")
                        ->orWhere('part_number', 'like', "%{$term}%");
                }
            });
        } else {
            $query->where('name', 'like', "%{$requestedPart}%");
        }

        return $query
            ->orderByDesc('stock_quantity')
            ->orderBy('name')
            ->limit(3)
            ->get(['id', 'name', 'part_number', 'stock_quantity', 'unit'])
            ->map(fn (SparePart $part) => [
                'id' => $part->id,
                'name' => $part->name,
                'part_number' => $part->part_number,
                'stock_quantity' => (int) $part->stock_quantity,
                'unit' => $part->unit,
            ])
            ->all();
    }

    private function ensureTotalCoversCollectedAmount(float|int|string $totalCost, float|int|string $amountPaid): void
    {
        if ((float) $totalCost < (float) $amountPaid) {
            throw ValidationException::withMessages([
                'total_cost' => 'Total cost cannot be lower than the amount already paid.',
            ]);
        }
    }

    private function paymentStatusForTotal(float|int|string $amountPaid, float|int|string $totalCost): string
    {
        return (float) $amountPaid <= 0
            ? 'PENDING'
            : ((float) $amountPaid >= (float) $totalCost ? 'PAID' : 'PARTIAL');
    }
}
