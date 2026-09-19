<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\JobOrderPart;
use App\Models\RepairPhoto;
use App\Models\SparePart;
use App\Models\StatusHistory;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class TechnicianPortalController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
        $technician = $this->resolveTechnician($user);

        $jobs = $technician->jobOrders()
            ->latest()
            ->get()
            ->map(fn (JobOrder $job) => $this->mapJob($job));

        return response()->json([
            'technician' => [
                'id' => $technician->id,
                'name' => $technician->name,
                'email' => $technician->email,
                'phone' => $technician->phone,
                'specialties' => $technician->specialties ?? [],
                'currentJobsCount' => $technician->currentJobsCount(),
                'completedJobsCount' => $technician->completedJobsCount(),
                'averageRating' => $technician->averageRating(),
            ],
            'jobs' => $jobs,
            'allowedStatuses' => $this->allowedStatuses(),
        ]);
    }

    public function showJob(Request $request, JobOrder $jobOrder)
    {
        $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
        $technician = $this->resolveTechnician($user);
        $this->ensureSameShop($request, $jobOrder);
        $this->ensureAssignedToTechnician($jobOrder, $technician);

        $jobOrder->load(['statusHistories', 'parts', 'payments', 'repairPhotos']);

        return response()->json([
            'job' => $this->mapJob($jobOrder, true),
            'timeline' => $jobOrder->statusHistories()
                ->orderBy('created_at')
                ->get()
                ->map(fn (StatusHistory $history) => [
                    'id' => $history->id,
                    'status' => $history->status,
                    'note' => $history->note,
                    'created_at' => $history->created_at,
                    'created_at_formatted' => $history->created_at?->format('M d, Y h:i A'),
                ])
                ->all(),
            'allowedStatuses' => $this->allowedStatuses(),
            'availableParts' => SparePart::query()
                ->where('shop_id', $this->currentShopId($request))
                ->where('is_active', true)
                ->where('stock_quantity', '>', 0)
                ->orderBy('name')
                ->get(['id', 'name', 'part_number', 'unit', 'unit_cost', 'stock_quantity', 'reorder_level'])
                ->map(function (SparePart $part) {
                    return [
                        'id' => $part->id,
                        'name' => $part->name,
                        'part_number' => $part->part_number,
                        'unit' => $part->unit,
                        'unit_cost' => $part->unit_cost,
                        'stock_quantity' => $part->stock_quantity,
                        'reorder_level' => $part->reorder_level,
                        'is_low_stock' => (int) $part->stock_quantity <= (int) $part->reorder_level,
                    ];
                })
                ->values(),
        ]);
    }

    public function updateStatus(Request $request, JobOrder $jobOrder)
    {
        $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
        $technician = $this->resolveTechnician($user);
        $this->ensureSameShop($request, $jobOrder);
        $this->ensureAssignedToTechnician($jobOrder, $technician);

        $validated = $request->validate([
            'status' => 'required|in:' . implode(',', $this->allowedStatuses()),
            'note' => 'nullable|string|max:1000',
        ]);

        if ($jobOrder->current_status === $validated['status']) {
            return response()->json([
                'message' => 'Status is already set. No changes made.',
                'job' => $this->mapJob($jobOrder, true),
            ]);
        }

        if (in_array($validated['status'], ['IN_PROGRESS', 'READY_FOR_PICKUP'], true) && !$jobOrder->repair_approved_at) {
            throw ValidationException::withMessages([
                'status' => 'Customer repair approval must be recorded before repair work or pickup preparation can proceed.',
            ]);
        }

        $previousStatus = $jobOrder->current_status;
        $jobOrder->update(['current_status' => $validated['status']]);

        StatusHistory::create([
            'shop_id' => $jobOrder->shop_id,
            'job_order_id' => $jobOrder->id,
            'status' => $validated['status'],
            'note' => $validated['note'],
        ]);

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'technician.status_updated',
            module: 'technician_portal',
            record: $jobOrder,
            description: "{$technician->name} updated {$jobOrder->job_order_number} from {$previousStatus} to {$validated['status']}.",
            oldValues: ['current_status' => $previousStatus],
            newValues: ['current_status' => $validated['status'], 'note' => $validated['note'] ?? null]
        );

        return response()->json([
            'message' => 'Job status updated successfully.',
            'job' => $this->mapJob($jobOrder->fresh(), true),
        ]);
    }

    public function storePhoto(Request $request, JobOrder $jobOrder)
    {
        $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
        $technician = $this->resolveTechnician($user);
        $this->ensureSameShop($request, $jobOrder);
        $this->ensureAssignedToTechnician($jobOrder, $technician);

        $validated = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'photo_type' => ['required', Rule::in(RepairPhoto::types())],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $file = $validated['photo'];
        $path = $file->store("repair-photos/{$jobOrder->shop_id}/{$jobOrder->id}", 'local');

        $photo = RepairPhoto::create([
            'shop_id' => $jobOrder->shop_id,
            'job_order_id' => $jobOrder->id,
            'photo_type' => $validated['photo_type'],
            'photo_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'description' => $validated['description'] ?? null,
            'uploaded_by' => $technician->name,
            'uploaded_by_role' => User::ROLE_TECHNICIAN,
        ]);

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'technician.photo_uploaded',
            module: 'technician_portal',
            record: $jobOrder,
            description: "{$technician->name} uploaded {$photo->photo_type} photo for {$jobOrder->job_order_number}.",
            newValues: $photo->only(['photo_type', 'original_name', 'description', 'uploaded_by'])
        );

        return response()->json([
            'message' => 'Photo uploaded successfully.',
            'photo' => $this->mapPhoto($photo),
        ], 201);
    }

    public function deletePhoto(Request $request, JobOrder $jobOrder, RepairPhoto $photo)
    {
        $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
        $technician = $this->resolveTechnician($user);
        $this->ensureSameShop($request, $jobOrder);
        $this->ensureAssignedToTechnician($jobOrder, $technician);

        if ((int) $photo->job_order_id !== (int) $jobOrder->id || (int) $photo->shop_id !== (int) $jobOrder->shop_id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $before = $photo->only(['photo_type', 'photo_path', 'original_name', 'description', 'uploaded_by']);

        Storage::disk('local')->delete($photo->photo_path);
        $photo->delete();

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'technician.photo_deleted',
            module: 'technician_portal',
            record: $jobOrder,
            description: "{$technician->name} deleted {$before['photo_type']} photo from {$jobOrder->job_order_number}.",
            oldValues: $before
        );

        return response()->json([
            'message' => 'Photo deleted.',
        ]);
    }

    public function addPart(Request $request, JobOrder $jobOrder)
    {
        $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
        $technician = $this->resolveTechnician($user);
        $this->ensureSameShop($request, $jobOrder);
        $shopId = $this->currentShopId($request);
        $this->ensureAssignedToTechnician($jobOrder, $technician);

        if (!$this->canRecordParts($jobOrder)) {
            return response()->json([
                'message' => 'Parts can no longer be recorded for this job in its current status.',
            ], 422);
        }

        $validated = $request->validate([
            'spare_part_id' => 'required|exists:spare_parts,id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:255',
        ]);

        $part = DB::transaction(function () use ($validated, $jobOrder, $request, $shopId) {
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

            $unitCost = (float) $sparePart->unit_cost;
            $total = $validated['quantity'] * $unitCost;

            $part = JobOrderPart::create([
                'shop_id' => $jobOrder->shop_id,
                'job_order_id' => $jobOrder->id,
                'spare_part_id' => $sparePart->id,
                'part_name' => $sparePart->name,
                'quantity' => $validated['quantity'],
                'unit_cost' => $unitCost,
                'total_cost' => $total,
                'notes' => $validated['notes'] ?? null,
            ]);

            $sparePart->decrement('stock_quantity', $validated['quantity']);
            $sparePart->refresh();

            StockMovement::create([
                'shop_id' => $jobOrder->shop_id,
                'spare_part_id' => $sparePart->id,
                'job_order_id' => $jobOrder->id,
                'job_order_part_id' => $part->id,
                'type' => 'OUT',
                'quantity_change' => -$validated['quantity'],
                'balance_after' => $sparePart->stock_quantity,
                'note' => "Used by technician for job order {$jobOrder->job_order_number}",
                'created_by' => $request->user()?->name,
            ]);

            return $part;
        });

        $this->recalculateCosts($jobOrder->fresh());

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'technician.part_added',
            module: 'technician_portal',
            record: $jobOrder,
            description: "{$technician->name} recorded part {$part->part_name} on {$jobOrder->job_order_number}.",
            newValues: $part->only(['part_name', 'quantity', 'unit_cost', 'total_cost', 'spare_part_id'])
        );

        return response()->json([
            'message' => 'Part recorded successfully and stock was updated.',
            'job' => $this->mapJob($jobOrder->fresh(), true),
        ], 201);
    }

    public function removePart(Request $request, JobOrder $jobOrder, JobOrderPart $part)
    {
        $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
        $technician = $this->resolveTechnician($user);
        $this->ensureSameShop($request, $jobOrder);
        $shopId = $this->currentShopId($request);
        $this->ensureAssignedToTechnician($jobOrder, $technician);

        if ($part->job_order_id !== $jobOrder->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if (!$this->canRecordParts($jobOrder)) {
            return response()->json([
                'message' => 'Parts can no longer be changed for this job in its current status.',
            ], 422);
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

                    StockMovement::create([
                        'shop_id' => $jobOrder->shop_id,
                        'spare_part_id' => $sparePart->id,
                        'job_order_id' => $jobOrder->id,
                        'job_order_part_id' => $existingPart->id,
                        'type' => 'IN',
                        'quantity_change' => $existingPart->quantity,
                        'balance_after' => $sparePart->stock_quantity,
                        'note' => "Technician correction for job order {$jobOrder->job_order_number}",
                        'created_by' => $request->user()?->name,
                    ]);
                }
            }

            $existingPart->delete();
        });

        $this->recalculateCosts($jobOrder->fresh());

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'technician.part_removed',
            module: 'technician_portal',
            record: $jobOrder,
            description: "{$technician->name} removed part {$before['part_name']} from {$jobOrder->job_order_number}.",
            oldValues: $before
        );

        return response()->json([
            'message' => 'Part entry removed and stock was restored.',
            'job' => $this->mapJob($jobOrder->fresh(), true),
        ]);
    }

    public function requestPart(Request $request, JobOrder $jobOrder)
    {
        $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
        $technician = $this->resolveTechnician($user);
        $this->ensureSameShop($request, $jobOrder);
        $this->ensureAssignedToTechnician($jobOrder, $technician);

        if (!$this->canRecordParts($jobOrder)) {
            return response()->json([
                'message' => 'Parts can no longer be requested for this job in its current status.',
            ], 422);
        }

        $validated = $request->validate([
            'part_name' => 'required|string|max:150',
            'quantity' => 'nullable|integer|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        $quantity = (int) ($validated['quantity'] ?? 1);
        $note = trim((string) ($validated['note'] ?? ''));
        $statusNote = "Parts requested: {$validated['part_name']} (Qty {$quantity})";
        if ($note !== '') {
            $statusNote .= ". Note: {$note}";
        }

        $previousStatus = $jobOrder->current_status;

        if ($jobOrder->current_status !== 'WAITING_PARTS') {
            $jobOrder->update(['current_status' => 'WAITING_PARTS']);
        }

        StatusHistory::create([
            'shop_id' => $jobOrder->shop_id,
            'job_order_id' => $jobOrder->id,
            'status' => 'WAITING_PARTS',
            'note' => $statusNote,
        ]);

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'technician.part_requested',
            module: 'technician_portal',
            record: $jobOrder,
            description: "{$technician->name} requested part {$validated['part_name']} for {$jobOrder->job_order_number}.",
            oldValues: ['current_status' => $previousStatus],
            newValues: [
                'current_status' => 'WAITING_PARTS',
                'requested_part' => $validated['part_name'],
                'quantity' => $quantity,
                'note' => $note !== '' ? $note : null,
            ]
        );

        return response()->json([
            'message' => 'Parts request recorded and job marked as waiting for parts.',
            'job' => $this->mapJob($jobOrder->fresh(), true),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function allowedStatuses(): array
    {
        return [
            'DIAGNOSING',
            'WAITING_PARTS',
            'IN_PROGRESS',
            'READY_FOR_PICKUP',
            'UNREPAIRABLE',
        ];
    }

    private function canRecordParts(JobOrder $jobOrder): bool
    {
        return !in_array($jobOrder->current_status, ['READY_FOR_PICKUP', 'PICKED_UP', 'UNREPAIRABLE'], true);
    }

    private function mapJob(JobOrder $job, bool $includeDetails = false): array
    {
        return [
            'id' => $job->id,
            'job_order_number' => $job->job_order_number,
            'customer_name' => $job->customer_name,
            'device_type' => $job->device_type,
            'brand' => $job->brand,
            'model' => $job->model,
            'problem_description' => $job->problem_description,
            'current_status' => $job->current_status,
            'priority' => $job->priority,
            'estimated_completion' => $job->estimated_completion,
            'estimated_completion_formatted' => $job->estimated_completion?->format('M d, Y h:i A'),
            'created_at' => $job->created_at,
            'created_at_formatted' => $job->created_at?->format('M d, Y'),
            'is_overdue' => $job->estimated_completion
                && !in_array($job->current_status, ['READY_FOR_PICKUP', 'PICKED_UP', 'UNREPAIRABLE'], true)
                && $job->estimated_completion->isPast(),
            'can_record_parts' => $this->canRecordParts($job),
            'parts' => $includeDetails
                ? $job->parts()->latest()->get(['id', 'part_name', 'quantity', 'unit_cost', 'total_cost', 'notes', 'spare_part_id'])
                : null,
            'photos' => $includeDetails
                ? $job->repairPhotos()->latest()->get()->map(fn (RepairPhoto $photo) => $this->mapPhoto($photo))
                : null,
        ];
    }

    private function mapPhoto(RepairPhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'photo_type' => $photo->photo_type,
            'photo_type_label' => str_replace('_', ' ', $photo->photo_type),
            'url' => route('technician.job-order.photos.file', ['jobOrder' => $photo->job_order_id, 'photo' => $photo->id]),
            'description' => $photo->description,
            'visible_to_customer' => (bool) $photo->visible_to_customer,
            'original_name' => $photo->original_name,
            'uploaded_by' => $photo->uploaded_by,
            'uploaded_by_role' => $photo->uploaded_by_role,
            'created_at' => $photo->created_at,
            'created_at_formatted' => $photo->created_at?->format('M d, Y h:i A'),
        ];
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
