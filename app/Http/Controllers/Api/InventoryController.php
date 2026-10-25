<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SparePart;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $shopId = $this->currentShopId($request);

        $query = SparePart::query()
            ->where('shop_id', $shopId)
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('part_number', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        if ($request->has('active_only') && $request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        if ($request->has('low_stock') && $request->boolean('low_stock')) {
            $query->whereColumn('stock_quantity', '<=', 'reorder_level');
        }

        if ($request->boolean('all')) {
            return response()->json([
                'items' => $query->get()->map(fn (SparePart $part) => $this->formatPart($part)),
                'summary' => $this->summary($shopId),
            ]);
        }

        $perPage = (int) $request->query('per_page', 15);
        $items = $query->paginate($perPage);
        $items->getCollection()->transform(fn (SparePart $part) => $this->formatPart($part));

        return response()->json([
            'items' => $items,
            'summary' => $this->summary($shopId),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $shopId = $this->currentShopId($request);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'part_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('spare_parts', 'part_number')->where(fn ($query) => $query->where('shop_id', $shopId)),
            ],
            'category' => 'nullable|string|max:100',
            'unit' => 'nullable|string|max:30',
            'stock_quantity' => 'required|integer|min:0',
            'reorder_level' => 'required|integer|min:0',
            'unit_cost' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $part = SparePart::create([
            'shop_id' => $shopId,
            'name' => $validated['name'],
            'part_number' => $validated['part_number'] ?? null,
            'category' => $validated['category'] ?? null,
            'unit' => $validated['unit'] ?? 'pcs',
            'stock_quantity' => $validated['stock_quantity'],
            'reorder_level' => $validated['reorder_level'],
            'unit_cost' => $validated['unit_cost'],
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if ($part->stock_quantity > 0) {
            StockMovement::create([
                'shop_id' => $shopId,
                'spare_part_id' => $part->id,
                'type' => 'IN',
                'quantity_change' => $part->stock_quantity,
                'balance_after' => $part->stock_quantity,
                'note' => 'Initial stock entry',
                'created_by' => $request->user()?->name,
            ]);
        }

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'inventory.created',
            module: 'inventory',
            record: $part,
            description: "Created spare part {$part->name}.",
            newValues: $part->only(['name', 'part_number', 'category', 'unit', 'stock_quantity', 'reorder_level', 'unit_cost', 'is_active'])
        );

        return response()->json([
            'message' => 'Spare part created successfully.',
            'item' => $this->formatPart($part->fresh()),
        ], 201);
    }

    public function show(Request $request, SparePart $sparePart)
    {
        $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $this->ensureSameShop($request, $sparePart);

        $movements = $sparePart->stockMovements()
            ->latest()
            ->take(25)
            ->get();

        return response()->json([
            'item' => $this->formatPart($sparePart),
            'movements' => $movements,
        ]);
    }

    public function update(Request $request, SparePart $sparePart)
    {
        $this->authorizeAdmin($request);
        $this->ensureSameShop($request, $sparePart);
        $shopId = $this->currentShopId($request);
        $before = $sparePart->only(['name', 'part_number', 'category', 'unit', 'stock_quantity', 'reorder_level', 'unit_cost', 'is_active']);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'part_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('spare_parts', 'part_number')
                    ->where(fn ($query) => $query->where('shop_id', $shopId))
                    ->ignore($sparePart->id),
            ],
            'category' => 'nullable|string|max:100',
            'unit' => 'nullable|string|max:30',
            'stock_quantity' => 'required|integer|min:0',
            'reorder_level' => 'required|integer|min:0',
            'unit_cost' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        DB::transaction(function () use ($request, $sparePart, $validated) {
            $part = SparePart::query()
                ->where('shop_id', $sparePart->shop_id)
                ->lockForUpdate()
                ->findOrFail($sparePart->id);
            $oldStock = (int) $part->stock_quantity;

            $part->update([
                'name' => $validated['name'],
                'part_number' => $validated['part_number'] ?? null,
                'category' => $validated['category'] ?? null,
                'unit' => $validated['unit'] ?? 'pcs',
                'stock_quantity' => $validated['stock_quantity'],
                'reorder_level' => $validated['reorder_level'],
                'unit_cost' => $validated['unit_cost'],
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? $part->is_active,
            ]);

            $newStock = (int) $part->stock_quantity;
            if ($newStock !== $oldStock) {
                StockMovement::create([
                    'shop_id' => $part->shop_id,
                    'spare_part_id' => $part->id,
                    'type' => 'ADJUSTMENT',
                    'quantity_change' => $newStock - $oldStock,
                    'balance_after' => $newStock,
                    'note' => 'Stock updated from inventory form',
                    'created_by' => $request->user()?->name,
                ]);
            }
        });

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'inventory.updated',
            module: 'inventory',
            record: $sparePart,
            description: "Updated spare part {$sparePart->fresh()->name}.",
            oldValues: $before,
            newValues: $sparePart->fresh()->only(['name', 'part_number', 'category', 'unit', 'stock_quantity', 'reorder_level', 'unit_cost', 'is_active'])
        );

        return response()->json([
            'message' => 'Spare part updated successfully.',
            'item' => $this->formatPart($sparePart->fresh()),
        ]);
    }

    public function adjustStock(Request $request, SparePart $sparePart)
    {
        $user = $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $this->ensureSameShop($request, $sparePart);
        $before = $sparePart->only(['stock_quantity', 'reorder_level', 'is_active']);

        $validated = $request->validate([
            'action' => 'required|in:in,out,set',
            'quantity' => 'required|integer|min:0',
            'note' => 'nullable|string|max:255',
        ]);

        if ($user->role === User::ROLE_STAFF && $validated['action'] !== 'in') {
            abort(403, 'Staff accounts can only add stock to inventory items.');
        }

        DB::transaction(function () use ($request, $sparePart, $validated) {
            $part = SparePart::query()
                ->where('shop_id', $sparePart->shop_id)
                ->lockForUpdate()
                ->findOrFail($sparePart->id);
            $current = (int) $part->stock_quantity;
            $quantity = (int) $validated['quantity'];

            $type = 'ADJUSTMENT';
            $delta = 0;
            $next = $current;

            if ($validated['action'] === 'in') {
                $type = 'IN';
                $delta = $quantity;
                $next = $current + $quantity;
            } elseif ($validated['action'] === 'out') {
                if ($quantity > $current) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Deduction quantity exceeds available stock.',
                    ]);
                }
                $type = 'OUT';
                $delta = -$quantity;
                $next = $current - $quantity;
            } else {
                $type = 'ADJUSTMENT';
                $delta = $quantity - $current;
                $next = $quantity;
            }

            if ($delta === 0) {
                return;
            }

            $part->update(['stock_quantity' => $next]);

            StockMovement::create([
                'shop_id' => $part->shop_id,
                'spare_part_id' => $part->id,
                'type' => $type,
                'quantity_change' => $delta,
                'balance_after' => $next,
                'note' => $validated['note'] ?? 'Manual stock adjustment',
                'created_by' => $request->user()?->name,
            ]);
        });

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'inventory.adjusted',
            module: 'inventory',
            record: $sparePart,
            description: "Adjusted stock for {$sparePart->fresh()->name}.",
            oldValues: $before,
            newValues: $sparePart->fresh()->only(['stock_quantity', 'reorder_level', 'is_active'])
        );

        return response()->json([
            'message' => 'Stock updated successfully.',
            'item' => $this->formatPart($sparePart->fresh()),
        ]);
    }

    public function destroy(Request $request, SparePart $sparePart)
    {
        $this->authorizeAdmin($request);
        $this->ensureSameShop($request, $sparePart);

        if ($sparePart->jobOrderParts()->exists()) {
            return response()->json([
                'message' => 'Cannot delete spare part that is already used in job orders.',
            ], 422);
        }

        $before = $sparePart->only(['name', 'part_number', 'category', 'unit', 'stock_quantity', 'reorder_level', 'unit_cost', 'is_active']);
        $sparePart->delete();

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'inventory.deleted',
            module: 'inventory',
            description: "Deleted spare part {$before['name']}.",
            oldValues: $before
        );

        return response()->json([
            'message' => 'Spare part deleted successfully.',
        ]);
    }

    private function formatPart(SparePart $part): array
    {
        $stock = (int) $part->stock_quantity;
        $reorder = (int) $part->reorder_level;

        return [
            'id' => $part->id,
            'name' => $part->name,
            'part_number' => $part->part_number,
            'category' => $part->category,
            'unit' => $part->unit,
            'stock_quantity' => $stock,
            'reorder_level' => $reorder,
            'unit_cost' => $part->unit_cost,
            'is_active' => (bool) $part->is_active,
            'description' => $part->description,
            'is_low_stock' => $stock <= $reorder,
            'updated_at' => $part->updated_at,
        ];
    }

    private function summary(int $shopId): array
    {
        $totalParts = SparePart::where('shop_id', $shopId)->count();
        $activeParts = SparePart::where('shop_id', $shopId)->where('is_active', true)->count();
        $lowStock = SparePart::where('shop_id', $shopId)->whereColumn('stock_quantity', '<=', 'reorder_level')->count();

        return [
            'total_parts' => $totalParts,
            'active_parts' => $activeParts,
            'low_stock_parts' => $lowStock,
        ];
    }

    private function authorizeAdmin(Request $request): void
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
    }
}
