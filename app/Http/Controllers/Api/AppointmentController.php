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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        $isConsultation = $request->input('booking_type') === 'CONSULTATION';
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
            'concern' => array_merge(
                ['required', 'string', 'max:3000'],
                $isConsultation ? ['min:10'] : []
            ),
            'problem_started' => ['nullable', 'string', 'max:200'],
            'previous_incidents' => ['nullable', 'string', 'max:500'],
            'previous_repairs' => ['nullable', 'string', 'max:500'],
            'troubleshooting_attempted' => ['nullable', 'string', 'max:1000'],
            'additional_details' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'preferred_at' => ['nullable', 'required_if:booking_type,DROP_OFF', 'date', 'after:now'],
        ]);
        if ($isConsultation) {
            $validated['concern'] = $this->buildConsultationConcern($validated);
            unset(
                $validated['problem_started'],
                $validated['previous_incidents'],
                $validated['previous_repairs'],
                $validated['troubleshooting_attempted'],
                $validated['additional_details']
            );
        }
        if ($validated['booking_type'] === 'CONSULTATION') {
            $validated['service_item_id'] = null;
        }
        $validated = $this->normalizeServiceSelection($shop->id, $validated);

        $photoPath = null;
        $photoOriginalName = null;
        if ($request->hasFile('photo')) {
            $photo = $request->file('photo');
            $photoPath = $photo->store("appointments/{$shop->id}", 'local');
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
            'appointment' => $this->mapAppointment($appointment, $this->publicPhotoUrl($shop, $appointment)),
        ], 201);
    }

    public function index(Request $request)
    {
        $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $shopId = $this->currentShopId($request);

        $query = Appointment::query()
            ->where('shop_id', $shopId)
            ->orderByRaw('COALESCE(preferred_at, created_at) DESC');

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

        $appointments->getCollection()->transform(fn (Appointment $appointment) => $this->mapAppointment(
            $appointment,
            $this->staffPhotoUrl($appointment)
        ));

        return response()->json([
            'appointments' => $appointments,
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
            'appointment' => $this->mapPublicAppointment($appointment),
        ]);
    }

    public function showPublicPhoto(Shop $shop, string $confirmationCode)
    {
        if (!$shop->is_active) {
            abort(404, 'Shop not found.');
        }

        $appointment = Appointment::query()
            ->where('shop_id', $shop->id)
            ->where('confirmation_code', Str::upper($confirmationCode))
            ->first();

        if (!$appointment || !$appointment->photo_path) {
            abort(404, 'Appointment photo not found.');
        }

        return $this->streamAppointmentPhoto($appointment);
    }

    public function showStaffPhoto(Request $request, Appointment $appointment)
    {
        $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $this->ensureSameShop($request, $appointment);

        if (!$appointment->photo_path) {
            abort(404, 'Appointment photo not found.');
        }

        return $this->streamAppointmentPhoto($appointment);
    }

    public function show(Request $request, Appointment $appointment)
    {
        $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $this->ensureSameShop($request, $appointment);

        return response()->json([
            'appointment' => $this->mapAppointment($appointment, $this->staffPhotoUrl($appointment)),
        ]);
    }

    public function updateStatus(Request $request, Appointment $appointment)
    {
        $user = $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $this->ensureSameShop($request, $appointment);

        $recommendedActions = $this->recommendedActions();
        if ($appointment->booking_type !== 'CONSULTATION') {
            unset($recommendedActions['REQUEST_INFORMATION']);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(
                $appointment->booking_type === 'CONSULTATION'
                    ? ['PENDING', 'CANCELLED', 'COMPLETED']
                    : ['PENDING', 'CONFIRMED', 'RESCHEDULED', 'CANCELLED', 'COMPLETED']
            )],
            'scheduled_at' => [
                'nullable',
                'date',
                Rule::requiredIf(fn () => $appointment->booking_type === 'DROP_OFF' && in_array($request->input('status'), ['CONFIRMED', 'RESCHEDULED'], true)),
            ],
            'staff_note' => ['nullable', 'string', 'max:2000'],
            'customer_response' => [
                $appointment->booking_type === 'CONSULTATION' && $request->input('status') !== 'CANCELLED' ? 'required' : 'nullable',
                'string',
                'max:3000',
            ],
            'recommended_action' => ['nullable', Rule::in(array_keys($recommendedActions))],
        ]);
        if (
            $appointment->booking_type === 'CONSULTATION'
            && $request->input('status') !== 'CANCELLED'
            && !strlen(trim((string) ($validated['customer_response'] ?? '')))
        ) {
            return response()->json([
                'message' => 'A non-empty reply is required for this consultation.',
                'errors' => ['customer_response' => ['Please enter a reply to the customer.']],
            ], 422);
        }

        $before = $appointment->only(['status', 'scheduled_at', 'staff_note', 'customer_response', 'recommended_action']);
        $appointment->update([
            'status' => $validated['status'],
            'scheduled_at' => $validated['scheduled_at'] ?? $appointment->scheduled_at,
            'staff_note' => $validated['staff_note'] ?? $appointment->staff_note,
            'customer_response' => isset($validated['customer_response'])
                ? (strlen(trim($validated['customer_response'])) ? trim($validated['customer_response']) : null)
                : $appointment->customer_response,
            'recommended_action' => $appointment->booking_type === 'CONSULTATION'
                ? null
                : ($validated['recommended_action'] ?? $appointment->recommended_action),
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
            'appointment' => $this->mapAppointment($appointment->fresh(), $this->staffPhotoUrl($appointment)),
        ]);
    }

    public function convertToJobOrder(Request $request, Appointment $appointment)
    {
        $user = $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $this->ensureSameShop($request, $appointment);
        $this->ensureShopCanStartNewWork($request);

        if ($appointment->booking_type === 'CONSULTATION') {
            return response()->json([
                'message' => 'Consultations are advice requests and cannot be converted to job orders. Create a separate repair booking if the customer wants to proceed.',
            ], 422);
        }

        $validated = $request->validate([
            'priority' => ['nullable', Rule::in(['STANDARD', 'EXPRESS', 'EMERGENCY'])],
            'estimated_completion' => ['nullable', 'date'],
        ]);

        $conversion = DB::transaction(function () use ($appointment, $request, $validated, $user) {
            $lockedAppointment = Appointment::query()
                ->where('shop_id', $appointment->shop_id)
                ->lockForUpdate()
                ->findOrFail($appointment->id);

            if ($lockedAppointment->converted_job_order_id) {
                return response()->json([
                    'message' => 'This appointment is already linked to a job order.',
                    'jobOrder' => $lockedAppointment->convertedJobOrder,
                ], 422);
            }

            if ($lockedAppointment->status === 'CANCELLED') {
                return response()->json([
                    'message' => 'Cancelled appointments cannot be converted to job orders.',
                ], 422);
            }

            $jobOrder = JobOrder::create([
                'shop_id' => $lockedAppointment->shop_id,
                'job_order_number' => $this->generateJobOrderNumber(),
                'customer_name' => $lockedAppointment->customer_name,
                'customer_contact' => $lockedAppointment->customer_phone ?: $lockedAppointment->customer_email,
                'customer_email' => $lockedAppointment->customer_email,
                'customer_phone' => $lockedAppointment->customer_phone,
                'device_type' => $lockedAppointment->device_type,
                'service_category_id' => $lockedAppointment->service_category_id,
                'service_item_id' => $lockedAppointment->service_item_id,
                'brand' => $lockedAppointment->brand,
                'model' => $lockedAppointment->model,
                'problem_description' => $lockedAppointment->concern,
                'included_accessories' => null,
                'received_by' => $user->name,
                'current_status' => 'RECEIVED',
                'device_location' => 'AT_SHOP',
                'priority' => $validated['priority'] ?? 'STANDARD',
                'warranty_days' => 0,
                'payment_status' => 'PENDING',
                'parts_cost' => 0,
                'labor_cost' => 0,
                'total_cost' => 0,
                'amount_paid' => 0,
                'estimated_completion' => $validated['estimated_completion'] ?? null,
                'internal_notes' => "Created from appointment {$lockedAppointment->confirmation_code}.",
            ]);

            StatusHistory::create([
                'shop_id' => $lockedAppointment->shop_id,
                'job_order_id' => $jobOrder->id,
                'status' => 'RECEIVED',
                'note' => "Converted from appointment {$lockedAppointment->confirmation_code}.",
            ]);

            $lockedAppointment->update([
                'status' => 'COMPLETED',
                'recommended_action' => 'JOB_ORDER_CREATED',
                'converted_job_order_id' => $jobOrder->id,
                'responded_at' => now(),
                'responded_by' => $user->name,
            ]);

            return ['appointment' => $lockedAppointment, 'jobOrder' => $jobOrder];
        });

        if ($conversion instanceof JsonResponse) {
            return $conversion;
        }

        $appointment = $conversion['appointment'];
        $jobOrder = $conversion['jobOrder'];

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
            'appointment' => $this->mapAppointment($appointment->fresh(), $this->staffPhotoUrl($appointment)),
            'jobOrder' => [
                'id' => $jobOrder->id,
                'job_order_number' => $jobOrder->job_order_number,
            ],
        ], 201);
    }

    /**
     * Keep consultation history readable while reusing the existing concern column.
     */
    private function buildConsultationConcern(array $validated): string
    {
        $sections = [
            'Problem description' => $validated['concern'],
            'When it started' => $validated['problem_started'] ?? null,
            'Previous damage or incidents' => $validated['previous_incidents'] ?? null,
            'Previous repairs' => $validated['previous_repairs'] ?? null,
            'Troubleshooting already attempted' => $validated['troubleshooting_attempted'] ?? null,
            'Additional information' => $validated['additional_details'] ?? null,
        ];

        $concern = collect($sections)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value, $label) => "{$label}:\n" . trim($value))
            ->implode("\n\n");

        if (mb_strlen($concern) > 3000) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'concern' => ['The problem description and additional history may not exceed 3000 characters combined.'],
            ]);
        }

        return $concern;
    }

    private function publicAppointmentStatusLabel(Appointment $appointment): string
    {
        if ($appointment->booking_type === 'CONSULTATION') {
            if ($appointment->status === 'CANCELLED') {
                return 'Cancelled';
            }
            if (strlen(trim((string) $appointment->customer_response))) {
                return 'Responded';
            }
            if ($appointment->status === 'PENDING') {
                return 'Pending';
            }
        }

        return match ($appointment->status) {
            'PENDING' => 'Waiting for shop response',
            'CONFIRMED' => 'Confirmed',
            'RESCHEDULED' => 'Rescheduled',
            'CANCELLED' => 'Cancelled',
            'COMPLETED' => 'Resolved',
            default => $appointment->status,
        };
    }

    private function mapAppointment(Appointment $appointment, ?string $photoUrl = null): array
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
            'photo_url' => $appointment->photo_path ? $photoUrl : null,
            'photo_original_name' => $appointment->photo_original_name,
            'preferred_at' => $appointment->preferred_at,
            'preferred_at_formatted' => $appointment->preferred_at?->format('M d, Y h:i A'),
            'scheduled_at' => $appointment->scheduled_at,
            'scheduled_at_formatted' => $appointment->scheduled_at?->format('M d, Y h:i A'),
            'status' => $appointment->status,
            'staff_note' => $appointment->staff_note,
            'customer_response' => strlen(trim((string) $appointment->customer_response))
                ? trim($appointment->customer_response)
                : null,
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
            'status_label' => $this->publicAppointmentStatusLabel($appointment),
            'awaiting_customer_information' => $appointment->recommended_action === 'REQUEST_INFORMATION' && $appointment->status === 'PENDING',
            'inspection_recommended' => $appointment->recommended_action === 'BRING_TO_SHOP',
        ];
    }

    private function mapPublicAppointment(Appointment $appointment): array
    {
        return [
            'confirmation_code' => $appointment->confirmation_code,
            'customer_name' => $appointment->customer_name,
            'booking_type' => $appointment->booking_type,
            'device_type' => $appointment->device_type,
            'service_category_name' => $appointment->serviceCategory?->name,
            'service_item_name' => $appointment->serviceItem?->name,
            'brand' => $appointment->brand,
            'model' => $appointment->model,
            'concern' => $appointment->concern,
            'photo_url' => $appointment->photo_path ? $this->publicPhotoUrl($appointment->shop, $appointment) : null,
            'preferred_at_formatted' => $appointment->preferred_at?->format('M d, Y h:i A'),
            'scheduled_at_formatted' => $appointment->scheduled_at?->format('M d, Y h:i A'),
            'status' => $appointment->status,
            'customer_response' => strlen(trim((string) $appointment->customer_response))
                ? trim($appointment->customer_response)
                : null,
            'status_label' => $this->publicAppointmentStatusLabel($appointment),
            'awaiting_customer_information' => $appointment->booking_type === 'CONSULTATION'
                ? false
                : ($appointment->recommended_action === 'REQUEST_INFORMATION' && $appointment->status === 'PENDING'),
            'inspection_recommended' => $appointment->booking_type !== 'CONSULTATION'
                && $appointment->recommended_action === 'BRING_TO_SHOP',
            'recommended_action_label' => $appointment->booking_type === 'CONSULTATION'
                ? null
                : ($appointment->recommended_action
                ? ($this->recommendedActions()[$appointment->recommended_action] ?? $appointment->recommended_action)
                    : null),
        ];
    }

    private function publicPhotoUrl(Shop $shop, Appointment $appointment): string
    {
        return route('public.appointment.photo', [
            'shop' => $shop->slug,
            'confirmationCode' => $appointment->confirmation_code,
        ]);
    }

    private function staffPhotoUrl(Appointment $appointment): string
    {
        return route('admin.appointment.photo', ['appointment' => $appointment->id]);
    }

    private function streamAppointmentPhoto(Appointment $appointment)
    {
        $path = (string) $appointment->photo_path;
        $expectedPrefix = "appointments/{$appointment->shop_id}/";

        if (!str_starts_with($path, $expectedPrefix) || in_array('..', explode('/', $path), true)) {
            abort(404, 'Appointment photo not found.');
        }

        $disk = Storage::disk('local');

        if (!$disk->exists($path)) {
            // Legacy appointment photos remain on the public disk until migrated.
            $disk = Storage::disk('public');
        }

        if (!$disk->exists($path)) {
            abort(404, 'Appointment photo not found.');
        }

        $mimeType = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return response()->file($disk->path($path), [
            'Content-Type' => $mimeType,
        ]);
    }

    private function generateConfirmationCode(): string
    {
        do {
            $code = 'APT-' . now()->format('Y') . '-' . Str::upper(Str::random(12));
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
            'REQUEST_INFORMATION' => 'More information needed',
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
