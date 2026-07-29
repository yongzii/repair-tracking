<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\JobOrder;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\Shop;
use App\Models\StatusHistory;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function storeForShop(Request $request, Shop $shop)
    {
        if (!$shop->is_active) {
            abort(404, 'Shop not found.');
        }

        if (!$shop->hasActiveAccess()) {
            abort(402, 'This shop is not accepting new appointment requests until its subscription is renewed.');
        }

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['nullable', 'email', 'max:150', 'required_without:customer_phone'],
            'customer_phone' => ['nullable', 'string', 'max:30', 'required_without:customer_email'],
            'booking_type' => ['required', Rule::in(['CONSULTATION', 'DROP_OFF'])],
            'device_type' => ['required', 'string', 'max:80'],
            'service_category_id' => ['nullable', 'integer'],
            'service_item_id' => ['nullable', 'integer'],
            'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'concern' => ['required', 'string', 'max:3000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'preferred_at' => ['required', 'date', 'after:now'],
        ]);
        $validated = $this->normalizeServiceSelection($shop->id, $validated);

        $photoPath = null;
        $photoOriginalName = null;
        if ($request->hasFile('photo')) {
            $photo = $request->file('photo');
            $photoPath = $photo->store("appointments/{$shop->id}", 'public');
            $photoOriginalName = $photo->getClientOriginalName();
        }

        $appointment = Appointment::create([
            ...collect($validated)->except('photo')->all(),
            'shop_id' => $shop->id,
            'confirmation_code' => $this->generateConfirmationCode(),
            'photo_path' => $photoPath,
            'photo_original_name' => $photoOriginalName,
            'status' => 'PENDING',
        ]);

        return response()->json([
            'message' => 'Appointment request submitted.',
            'appointment' => $this->mapAppointment($appointment),
        ], 201);
    }

    public function index(Request $request)
    {
        $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $shopId = $this->currentShopId($request);

        $query = Appointment::query()
            ->where('shop_id', $shopId)
            ->latest('preferred_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->query('booking_type')) {
            $query->where('booking_type', $type);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('confirmation_code', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('device_type', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%");
            });
        }

        $appointments = $query
            ->paginate((int) $request->query('per_page', 15))
            ->withQueryString();

        $appointments->getCollection()->transform(fn (Appointment $appointment) => $this->mapAppointment($appointment));

        return response()->json([
            'appointments' => $appointments,
            'summary' => $this->summary($shopId),
            'statuses' => ['PENDING', 'CONFIRMED', 'RESCHEDULED', 'CANCELLED', 'COMPLETED'],
            'bookingTypes' => ['CONSULTATION', 'DROP_OFF'],
            'recommendedActions' => $this->recommendedActions(),
        ]);
    }

    public function showForShop(Shop $shop, string $confirmationCode)
    {
        if (!$shop->is_active) {
            abort(404, 'Shop not found.');
        }

        $appointment = Appointment::query()
            ->where('shop_id', $shop->id)
            ->where('confirmation_code', Str::upper($confirmationCode))
            ->first();

        if (!$appointment) {
            return response()->json([
                'message' => 'Appointment request not found for this shop.',
            ], 404);
        }

        return response()->json([
            'appointment' => $this->mapAppointment($appointment),
        ]);
    }

    public function show(Request $request, Appointment $appointment)
    {
        $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $this->ensureSameShop($request, $appointment);

        return response()->json([
            'appointment' => $this->mapAppointment($appointment),
        ]);
    }

    public function updateStatus(Request $request, Appointment $appointment)
    {
        $user = $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $this->ensureSameShop($request, $appointment);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['PENDING', 'CONFIRMED', 'RESCHEDULED', 'CANCELLED', 'COMPLETED'])],
            'scheduled_at' => ['nullable', 'date', 'required_if:status,CONFIRMED,RESCHEDULED'],
            'staff_note' => ['nullable', 'string', 'max:2000'],
            'customer_response' => ['nullable', 'string', 'max:3000'],
            'recommended_action' => ['nullable', Rule::in(array_keys($this->recommendedActions()))],
        ]);

        $before = $appointment->only(['status', 'scheduled_at', 'staff_note', 'customer_response', 'recommended_action']);
        $appointment->update([
            'status' => $validated['status'],
            'scheduled_at' => $validated['scheduled_at'] ?? $appointment->scheduled_at,
            'staff_note' => $validated['staff_note'] ?? $appointment->staff_note,
            'customer_response' => $validated['customer_response'] ?? $appointment->customer_response,
            'recommended_action' => $validated['recommended_action'] ?? $appointment->recommended_action,
            'responded_at' => now(),
            'responded_by' => $user->name,
        ]);

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'appointment.status_updated',
            module: 'appointments',
            record: $appointment,
            description: "Updated appointment {$appointment->confirmation_code} to {$appointment->status}.",
            oldValues: $before,
            newValues: $appointment->fresh()->only(['status', 'scheduled_at', 'staff_note', 'customer_response', 'recommended_action'])
        );

        return response()->json([
            'message' => 'Appointment updated successfully.',
            'appointment' => $this->mapAppointment($appointment->fresh()),
        ]);
    }

    public function convertToJobOrder(Request $request, Appointment $appointment)
    {
        $user = $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $this->ensureSameShop($request, $appointment);
        $this->ensureShopCanStartNewWork($request);

        if ($appointment->converted_job_order_id) {
            return response()->json([
                'message' => 'This appointment is already linked to a job order.',
                'jobOrder' => $appointment->convertedJobOrder,
            ], 422);
        }

        if ($appointment->status === 'CANCELLED') {
            return response()->json([
                'message' => 'Cancelled appointments cannot be converted to job orders.',
            ], 422);
        }

        $validated = $request->validate([
            'priority' => ['nullable', Rule::in(['STANDARD', 'EXPRESS', 'EMERGENCY'])],
            'estimated_completion' => ['nullable', 'date'],
        ]);

        $jobOrder = DB::transaction(function () use ($appointment, $request, $validated) {
            $jobOrder = JobOrder::create([
                'shop_id' => $appointment->shop_id,
                'job_order_number' => $this->generateJobOrderNumber(),
                'customer_name' => $appointment->customer_name,
                'customer_contact' => $appointment->customer_phone ?: $appointment->customer_email,
                'customer_email' => $appointment->customer_email,
                'customer_phone' => $appointment->customer_phone,
                'device_type' => $appointment->device_type,
                'service_category_id' => $appointment->service_category_id,
                'service_item_id' => $appointment->service_item_id,
                'brand' => $appointment->brand,
                'model' => $appointment->model,
                'problem_description' => $appointment->concern,
                'included_accessories' => null,
                'received_by' => $user?->name,
                'current_status' => 'RECEIVED',
                'priority' => $validated['priority'] ?? 'STANDARD',
                'warranty_days' => 0,
                'payment_status' => 'PENDING',
                'parts_cost' => 0,
                'labor_cost' => 0,
                'total_cost' => 0,
                'amount_paid' => 0,
                'estimated_completion' => $validated['estimated_completion'] ?? null,
                'internal_notes' => "Created from appointment {$appointment->confirmation_code}.",
            ]);

            StatusHistory::create([
                'shop_id' => $appointment->shop_id,
                'job_order_id' => $jobOrder->id,
                'status' => 'RECEIVED',
                'note' => "Converted from appointment {$appointment->confirmation_code}.",
            ]);

            $appointment->update([
                'status' => 'COMPLETED',
                'recommended_action' => 'JOB_ORDER_CREATED',
                'converted_job_order_id' => $jobOrder->id,
                'responded_at' => now(),
                'responded_by' => $request->user()?->name,
            ]);

            return $jobOrder;
        });

        AuditLogger::log(
            request: $request,
            user: $user,
            action: 'appointment.converted_to_job_order',
            module: 'appointments',
            record: $appointment,
            description: "Converted appointment {$appointment->confirmation_code} to job order {$jobOrder->job_order_number}.",
            newValues: [
                'job_order_id' => $jobOrder->id,
                'job_order_number' => $jobOrder->job_order_number,
            ]
        );

        return response()->json([
            'message' => 'Appointment converted to job order.',
            'appointment' => $this->mapAppointment($appointment->fresh()),
            'jobOrder' => [
                'id' => $jobOrder->id,
                'job_order_number' => $jobOrder->job_order_number,
            ],
        ], 201);
    }

    private function summary(int $shopId): array
    {
        return [
            'pending' => Appointment::where('shop_id', $shopId)->where('status', 'PENDING')->count(),
            'confirmed' => Appointment::where('shop_id', $shopId)->whereIn('status', ['CONFIRMED', 'RESCHEDULED'])->count(),
            'today' => Appointment::where('shop_id', $shopId)
                ->whereIn('status', ['CONFIRMED', 'RESCHEDULED'])
                ->whereDate('scheduled_at', today())
                ->count(),
        ];
    }

    private function mapAppointment(Appointment $appointment): array
    {
        return [
            'id' => $appointment->id,
            'confirmation_code' => $appointment->confirmation_code,
            'customer_name' => $appointment->customer_name,
            'customer_email' => $appointment->customer_email,
            'customer_phone' => $appointment->customer_phone,
            'booking_type' => $appointment->booking_type,
            'device_type' => $appointment->device_type,
            'service_category_id' => $appointment->service_category_id,
            'service_category_name' => $appointment->serviceCategory?->name,
            'service_item_id' => $appointment->service_item_id,
            'service_item_name' => $appointment->serviceItem?->name,
            'brand' => $appointment->brand,
            'model' => $appointment->model,
            'concern' => $appointment->concern,
            'photo_url' => $appointment->photo_path ? '/storage/' . ltrim($appointment->photo_path, '/') : null,
            'photo_original_name' => $appointment->photo_original_name,
            'preferred_at' => $appointment->preferred_at,
            'preferred_at_formatted' => $appointment->preferred_at?->format('M d, Y h:i A'),
            'scheduled_at' => $appointment->scheduled_at,
            'scheduled_at_formatted' => $appointment->scheduled_at?->format('M d, Y h:i A'),
            'status' => $appointment->status,
            'staff_note' => $appointment->staff_note,
            'customer_response' => $appointment->customer_response,
            'recommended_action' => $appointment->recommended_action,
            'recommended_action_label' => $appointment->recommended_action
                ? ($this->recommendedActions()[$appointment->recommended_action] ?? $appointment->recommended_action)
                : null,
            'converted_job_order_id' => $appointment->converted_job_order_id,
            'converted_job_order_number' => $appointment->convertedJobOrder?->job_order_number,
            'responded_at' => $appointment->responded_at,
            'responded_at_formatted' => $appointment->responded_at?->format('M d, Y h:i A'),
            'responded_by' => $appointment->responded_by,
            'created_at' => $appointment->created_at,
            'created_at_formatted' => $appointment->created_at?->format('M d, Y h:i A'),
        ];
    }

    private function generateConfirmationCode(): string
    {
        do {
            $code = 'APT-' . now()->format('Y') . '-' . Str::upper(Str::random(6));
        } while (Appointment::where('confirmation_code', $code)->exists());

        return $code;
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
                abort(422, 'Selected service is not available for this shop.');
            }

            if ($categoryId && (int) $service->service_category_id !== (int) $categoryId) {
                abort(422, 'Selected service does not belong to the selected device category.');
            }

            $categoryId = $service->service_category_id;
        }

        if ($categoryId) {
            $category = ServiceCategory::query()
                ->where('shop_id', $shopId)
                ->where('is_active', true)
                ->find($categoryId);

            if (!$category) {
                abort(422, 'Selected device category is not available for this shop.');
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

    /**
     * @return array<string, string>
     */
    private function recommendedActions(): array
    {
        return [
            'BRING_TO_SHOP' => 'Bring the device to the shop',
            'REMOTE_ADVICE' => 'Follow remote advice first',
            'BRING_ACCESSORY' => 'Bring charger/accessories for checking',
            'WAIT_FOR_PARTS' => 'Wait for parts availability',
            'NOT_SERVICEABLE' => 'Not serviceable by this shop',
            'JOB_ORDER_CREATED' => 'Converted to job order',
        ];
    }

    private function generateJobOrderNumber(): string
    {
        $prefix = strtoupper((string) config('repair.job_order_prefix', 'RSMS'));

        do {
            $jobOrderNumber = $prefix . '-' . date('Y') . '-' . Str::upper(Str::random(6));
        } while (JobOrder::where('job_order_number', $jobOrderNumber)->exists());

        return $jobOrderNumber;
    }
}
