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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PartRequestController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
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
            ->map(function (JobOrder $jobOrder) {
                $history = $jobOrder->statusHistories->first();
                if (!$history) {
                    return null;
                }

                $parsed = $this->parseRequestNote($history);
                if (!$parsed) {
                    return null;
                }

                return [
                    'job_id' => $jobOrder->id,
                    'job_order_number' => $jobOrder->job_order_number,
                    'customer_name' => $jobOrder->customer_name,
                    'device_type' => $jobOrder->device_type,
                    'technician_name' => $jobOrder->technician?->name,
                    'current_status' => $jobOrder->current_status,
                    'estimated_completion_formatted' => $jobOrder->estimated_completion?->format('M d, Y h:i A'),
                    'requested_part' => $parsed['part_name'],
                    'requested_quantity' => $parsed['quantity'],
                    'request_note' => $parsed['note'],
                    'requested_at' => $history->created_at,
                    'requested_at_formatted' => $history->created_at?->format('M d, Y h:i A'),
                    'inventory_matches' => $this->findInventoryMatches($shopId, $parsed['part_name']),
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'requests' => $requests,
            'summary' => [
                'total' => $requests->count(),
                'withStockMatches' => $requests->filter(fn ($request) => count($request['inventory_matches']) > 0)->count(),
                'withoutStockMatches' => $requests->filter(fn ($request) => count($request['inventory_matches']) === 0)->count(),
            ],
        ]);
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

        if (!$history || $jobOrder->current_status !== 'WAITING_PARTS') {
            return response()->json([
                'message' => 'This job does not have an active parts request that can be fulfilled from the queue.',
            ], 422);
        }

        $parsed = $this->parseRequestNote($history);
        if (!$parsed) {
            return response()->json([
                'message' => 'The latest parts request note could not be parsed.',
            ], 422);
        }

        $validated = $request->validate([
            'spare_part_id' => 'required|exists:spare_parts,id',
            'quantity' => 'nullable|integer|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        $quantity = (int) ($validated['quantity'] ?? $parsed['quantity']);
        $operatorNote = trim((string) ($validated['note'] ?? ''));

        $part = DB::transaction(function () use ($validated, $quantity, $jobOrder, $request, $operatorNote, $shopId) {
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
                    'quantity' => 'Selected inventory part does not have enough stock to fulfill this request.',
                ]);
            }

            $unitCost = (float) $sparePart->unit_cost;
            $total = $quantity * $unitCost;

            $part = JobOrderPart::create([
                'shop_id' => $jobOrder->shop_id,
                'job_order_id' => $jobOrder->id,
                'spare_part_id' => $sparePart->id,
                'part_name' => $sparePart->name,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $total,
                'notes' => $operatorNote !== '' ? $operatorNote : 'Fulfilled from parts request queue',
            ]);

            $sparePart->decrement('stock_quantity', $quantity);
            $sparePart->refresh();

            StockMovement::create([
                'shop_id' => $jobOrder->shop_id,
                'spare_part_id' => $sparePart->id,
                'job_order_id' => $jobOrder->id,
                'job_order_part_id' => $part->id,
                'type' => 'OUT',
                'quantity_change' => -$quantity,
                'balance_after' => $sparePart->stock_quantity,
                'note' => "Fulfilled from parts request queue for job order {$jobOrder->job_order_number}",
                'created_by' => $request->user()?->name,
            ]);

            return $part;
        });

        $this->recalculateCosts($jobOrder->fresh());

        $previousStatus = $jobOrder->fresh()->current_status;
        $jobOrder->update(['current_status' => 'IN_PROGRESS']);

        StatusHistory::create([
            'shop_id' => $jobOrder->shop_id,
            'job_order_id' => $jobOrder->id,
            'status' => 'IN_PROGRESS',
            'note' => "Parts request fulfilled using {$part->part_name} (Qty {$quantity})." . ($operatorNote !== '' ? " Note: {$operatorNote}" : ''),
        ]);

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'part_request.fulfilled',
            module: 'part_requests',
            record: $jobOrder,
            description: "{$user->name} fulfilled a parts request for {$jobOrder->job_order_number} using {$part->part_name}.",
            oldValues: [
                'current_status' => $previousStatus,
                'requested_part' => $parsed['part_name'],
                'requested_quantity' => $parsed['quantity'],
            ],
            newValues: [
                'current_status' => 'IN_PROGRESS',
                'fulfilled_part' => $part->part_name,
                'fulfilled_quantity' => $quantity,
                'spare_part_id' => $part->spare_part_id,
            ]
        );

        return response()->json([
            'message' => 'Parts request fulfilled. Stock was deducted and the job is back in progress.',
        ]);
    }

    private function parseRequestNote(StatusHistory $history): ?array
    {
        $note = trim((string) $history->note);

        if (!preg_match('/^Parts requested:\s*(.+?)\s*\(Qty\s+(\d+)\)(?:\.\s*Note:\s*(.+))?$/', $note, $matches)) {
            return null;
        }

        return [
            'part_name' => trim($matches[1]),
            'quantity' => (int) $matches[2],
            'note' => isset($matches[3]) ? trim($matches[3]) : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findInventoryMatches(int $shopId, string $requestedPart): array
    {
        $searchTerms = collect(preg_split('/\s+/', Str::lower($requestedPart)) ?: [])
            ->map(fn ($term) => preg_replace('/[^a-z0-9]/', '', $term))
            ->filter(fn ($term) => strlen($term) >= 3)
            ->take(4)
            ->values();

        $query = SparePart::query()
            ->where('shop_id', $shopId)
            ->where('is_active', true)
            ->orderByDesc('stock_quantity')
            ->orderBy('name');

        if ($searchTerms->isNotEmpty()) {
            $query->where(function ($builder) use ($searchTerms, $requestedPart) {
                $builder->where('name', 'like', '%' . $requestedPart . '%')
                    ->orWhere('part_number', 'like', '%' . $requestedPart . '%');

                foreach ($searchTerms as $term) {
                    $builder->orWhere('name', 'like', '%' . $term . '%')
                        ->orWhere('part_number', 'like', '%' . $term . '%');
                }
            });
        } else {
            $query->where('name', 'like', '%' . $requestedPart . '%');
        }

        return $query
            ->limit(3)
            ->get(['id', 'name', 'part_number', 'stock_quantity', 'unit', 'unit_cost'])
            ->map(fn (SparePart $part) => [
                'id' => $part->id,
                'name' => $part->name,
                'part_number' => $part->part_number,
                'stock_quantity' => $part->stock_quantity,
                'unit' => $part->unit,
                'unit_cost' => $part->unit_cost,
                'has_stock' => (int) $part->stock_quantity > 0,
            ])
            ->all();
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
}
