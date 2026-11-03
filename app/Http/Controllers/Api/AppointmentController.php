<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ServiceCategory;
use App\Models\Shop;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
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
            abort(402, 'This shop is not accepting new consultation requests until its subscription is renewed.');
        }

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['nullable', 'email', 'max:150', 'required_without:customer_phone'],
            'customer_phone' => ['nullable', 'string', 'max:30', 'required_without:customer_email'],
            'booking_type' => ['required', Rule::in(['CONSULTATION'])],
            'device_type' => ['required', 'string', 'max:80'],
            'service_category_id' => ['nullable', 'integer'],
            'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'concern' => ['required', 'string', 'min:10', 'max:3000'],
            'problem_started' => ['nullable', 'string', 'max:200'],
            'previous_incidents' => ['nullable', 'string', 'max:500'],
            'previous_repairs' => ['nullable', 'string', 'max:500'],
            'troubleshooting_attempted' => ['nullable', 'string', 'max:1000'],
            'additional_details' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $validated['concern'] = $this->buildConsultationConcern($validated);
        unset(
            $validated['problem_started'],
            $validated['previous_incidents'],
            $validated['previous_repairs'],
            $validated['troubleshooting_attempted'],
            $validated['additional_details']
        );
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
            'message' => 'Consultation request submitted.',
            'appointment' => $this->mapAppointment($appointment, $this->publicPhotoUrl($shop, $appointment)),
        ], 201);
    }

    public function index(Request $request)
    {
        $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $shopId = $this->currentShopId($request);

        $query = Appointment::query()
            ->where('shop_id', $shopId)
            ->where('booking_type', 'CONSULTATION')
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
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
                'message' => 'Consultation request not found for this shop.',
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

        if ($appointment->booking_type !== 'CONSULTATION') {
            abort(404, 'Consultation request not found.');
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['PENDING', 'CANCELLED', 'COMPLETED'])],
            'staff_note' => ['nullable', 'string', 'max:2000'],
            'customer_response' => [
                $request->input('status') !== 'CANCELLED' ? 'required' : 'nullable',
                'string',
                'max:3000',
            ],
            'recommended_action' => ['nullable', Rule::in(array_keys($this->recommendedActions()))],
        ]);
        if ($request->input('status') !== 'CANCELLED' && !strlen(trim((string) ($validated['customer_response'] ?? '')))) {
            return response()->json([
                'message' => 'A non-empty reply is required for this consultation.',
                'errors' => ['customer_response' => ['Please enter a reply to the customer.']],
            ], 422);
        }

        $before = $appointment->only(['status', 'staff_note', 'customer_response', 'recommended_action']);
        $appointment->update([
            'status' => $validated['status'],
            'staff_note' => array_key_exists('staff_note', $validated)
                ? $validated['staff_note']
                : $appointment->staff_note,
            'customer_response' => isset($validated['customer_response'])
                ? (strlen(trim($validated['customer_response'])) ? trim($validated['customer_response']) : null)
                : $appointment->customer_response,
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
            newValues: $appointment->fresh()->only(['status', 'staff_note', 'customer_response', 'recommended_action'])
        );

        return response()->json([
            'message' => 'Appointment updated successfully.',
            'appointment' => $this->mapAppointment($appointment->fresh(), $this->staffPhotoUrl($appointment)),
        ]);
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

        if ($categoryId) {
            $category = ServiceCategory::query()
                ->where('shop_id', $shopId)
                ->where('is_active', true)
                ->find($categoryId);

            if (!$category) {
                abort(422, 'Selected device category is not available for this shop.');
            }

            $validated['service_category_id'] = $category->id;
            $validated['device_type'] = $category->name;
        } else {
            $validated['service_category_id'] = null;
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

}
