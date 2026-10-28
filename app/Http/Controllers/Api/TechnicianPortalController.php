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
    public function alerts(Request $request)
    {
        $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
        $technician = $this->resolveTechnician($user);

        return response()->json([
            'open_assigned_jobs' => $technician->currentJobsCount(),
        ]);
    }

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

        $jobOrder->load([
            'deviceLocationUpdatedBy:id,name',
            'statusHistories',
            'parts',
            'payments',
            'repairPhotos',
            'partOrders.requestedBy',
            'partOrders.issuedBy',
            'partOrders.issuedJobOrderPart',
        ]);

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

        [$jobOrder, $previousStatus] = DB::transaction(function () use ($request, $jobOrder, $technician, $validated) {
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $this->currentShopId($request))
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);
            $this->ensureAssignedToTechnician($lockedJobOrder, $technician);

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
                'note' => $validated['note'],
            ]);

            return [$lockedJobOrder->fresh(), $previousStatus];
        });

        if ($previousStatus === null) {
            return response()->json([
                'message' => 'Status is already set. No changes made.',
                'job' => $this->mapJob($jobOrder, true),
            ]);
        }

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

    public function recordRepairApproval(Request $request, JobOrder $jobOrder)
    {
        $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
        $technician = $this->resolveTechnician($user);
        $this->ensureSameShop($request, $jobOrder);
        $this->ensureAssignedToTechnician($jobOrder, $technician);

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
            'repair_approval_recorded_by' => $user->name,
            'repair_approval_notes' => $validated['repair_approval_notes'] ?? null,
        ]);

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'job_order.repair_approved',
            module: 'job_orders',
            record: $jobOrder,
            description: "Recorded customer repair approval for {$jobOrder->job_order_number}.",
            oldValues: $before,
            newValues: $jobOrder->fresh()->only(array_keys($before))
        );

        return response()->json([
            'message' => 'Customer repair approval recorded.',
            'jobOrder' => $jobOrder->fresh()->only([
                'repair_approved_at',
                'repair_approved_by_customer',
                'repair_approval_recorded_by',
            ]),
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
            'mime_type' => $file->getMimeType(),
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

    public function updatePhotoVisibility(Request $request, JobOrder $jobOrder, RepairPhoto $photo)
    {
        $user = $this->authorizeRoles($request, User::ROLE_TECHNICIAN);
        $technician = $this->resolveTechnician($user);
        $this->ensureSameShop($request, $jobOrder);
        $this->ensureAssignedToTechnician($jobOrder, $technician);

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
            oldValues: [...$before, 'photo_id' => $photo->id],
            newValues: [...$photo->fresh()->only(['visible_to_customer']), 'photo_id' => $photo->id]
        );

        return response()->json([
            'message' => $photo->visible_to_customer ? 'Photo is now visible to customer.' : 'Photo is now internal only.',
            'photo' => $this->mapPhoto($photo->fresh()),
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
            'spare_part_id' => [
                'required',
                Rule::exists('spare_parts', 'id')->where(fn ($query) => $query->where('shop_id', $shopId)),
            ],
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:255',
        ]);

        [$jobOrder, $part] = DB::transaction(function () use ($validated, $jobOrder, $request, $shopId, $technician) {
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);
            $this->ensureAssignedToTechnician($lockedJobOrder, $technician);
            $this->ensureCanRecordParts($lockedJobOrder);

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
            $partsTotal = (float) $lockedJobOrder->parts()->sum('total_cost') + $total;
            $this->ensureTotalCoversCollectedAmount($partsTotal + (float) $lockedJobOrder->labor_cost, $lockedJobOrder->amount_paid);

            $part = JobOrderPart::create([
                'shop_id' => $lockedJobOrder->shop_id,
                'job_order_id' => $lockedJobOrder->id,
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

            $lockedJobOrder->update([
                'parts_cost' => $partsTotal,
                'total_cost' => $partsTotal + (float) $lockedJobOrder->labor_cost,
                'payment_status' => $this->paymentStatusForTotal($lockedJobOrder->amount_paid, $partsTotal + (float) $lockedJobOrder->labor_cost),
            ]);

            return [$lockedJobOrder->fresh(), $part];
        });

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

        if ($part->job_order_id !== $jobOrder->id || (int) $part->shop_id !== (int) $jobOrder->shop_id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if (!$this->canRecordParts($jobOrder)) {
            return response()->json([
                'message' => 'Parts can no longer be changed for this job in its current status.',
            ], 422);
        }

        $before = $part->only(['part_name', 'quantity', 'unit_cost', 'total_cost', 'spare_part_id', 'notes']);

        [$jobOrder, $before] = DB::transaction(function () use ($part, $jobOrder, $request, $shopId, $technician) {
            $lockedJobOrder = JobOrder::query()
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);
            $this->ensureAssignedToTechnician($lockedJobOrder, $technician);
            $this->ensureCanRecordParts($lockedJobOrder);
            $existingPart = JobOrderPart::query()
                ->where('job_order_id', $lockedJobOrder->id)
                ->where('shop_id', $lockedJobOrder->shop_id)
                ->lockForUpdate()
                ->findOrFail($part->id);
            if ($existingPart->job_order_part_order_id) {
                throw ValidationException::withMessages([
                    'part' => 'A part issued from a customer parts order cannot be removed here. Ask Shop Admin or Staff to reconcile the linked order and inventory history.',
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

            $lockedJobOrder->update([
                'parts_cost' => $partsTotal,
                'total_cost' => $partsTotal + (float) $lockedJobOrder->labor_cost,
                'payment_status' => $this->paymentStatusForTotal($lockedJobOrder->amount_paid, $partsTotal + (float) $lockedJobOrder->labor_cost),
            ]);

            return [$lockedJobOrder->fresh(), $before];
        });

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
            'RTO',
        ];
    }

    private function canRecordParts(JobOrder $jobOrder): bool
    {
        return !in_array($jobOrder->current_status, ['READY_FOR_PICKUP', 'PICKED_UP', 'RTO', 'RTO_RELEASED'], true);
    }

    private function ensureCanRecordParts(JobOrder $jobOrder): void
    {
        if (!$this->canRecordParts($jobOrder)) {
            throw ValidationException::withMessages([
                'job_order' => 'Parts can no longer be changed for this job in its current status.',
            ]);
        }
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
            'device_location' => $job->device_location ?? 'UNKNOWN',
            'device_location_updated_at' => $job->device_location_updated_at,
            'device_location_updated_by' => $job->relationLoaded('deviceLocationUpdatedBy')
                ? $job->deviceLocationUpdatedBy?->name
                : null,
            'priority' => $job->priority,
            'estimated_completion' => $job->estimated_completion,
            'estimated_completion_formatted' => $job->estimated_completion?->format('M d, Y h:i A'),
            'created_at' => $job->created_at,
            'created_at_formatted' => $job->created_at?->format('M d, Y'),
            'is_overdue' => $job->estimated_completion
                && !in_array($job->current_status, ['READY_FOR_PICKUP', 'PICKED_UP', 'RTO', 'RTO_RELEASED'], true)
                && $job->estimated_completion->isPast(),
            'can_record_parts' => $this->canRecordParts($job),
            'parts' => $includeDetails
                ? $job->parts()->latest()->get(['id', 'part_name', 'quantity', 'unit_cost', 'total_cost', 'notes', 'spare_part_id', 'job_order_part_order_id'])
                : null,
            'part_orders' => $includeDetails
                ? $job->partOrders->sortByDesc('created_at')->map(fn ($order) => [
                    'id' => $order->id,
                    'part_name' => $order->part_name,
                    'quantity' => (int) $order->quantity,
                    'reason' => $order->reason,
                    'estimated_unit_price' => $order->estimated_unit_price,
                    'estimated_total' => number_format((float) $order->estimated_unit_price * (int) $order->quantity, 2, '.', ''),
                    'customer_decision' => $order->customer_decision,
                    'required_downpayment_amount' => $order->required_downpayment_amount,
                    'status' => $order->status,
                    'supplier_name' => $order->supplier_name,
                    'expected_arrival_date' => $order->expected_arrival_date?->toDateString(),
                    'received_at' => $order->received_at,
                    'issued_at' => $order->issued_at,
                    'requested_by' => $order->requestedBy?->name,
                ])
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
