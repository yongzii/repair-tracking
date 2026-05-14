<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Technician;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TechnicianController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAdmin($request);
        $shopId = $this->currentShopId($request);

        $technicians = Technician::where('shop_id', $shopId)->latest()->paginate(15);

        return response()->json([
            'technicians' => $technicians,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);
        $shopId = $this->currentShopId($request);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => [
                'required',
                'email',
                Rule::unique('technicians', 'email')->where(fn ($query) => $query->where('shop_id', $shopId)),
            ],
            'phone' => 'nullable|string|max:20',
            'specialties' => 'nullable|array',
        ]);

        $technician = Technician::create([
            ...$validated,
            'shop_id' => $shopId,
        ]);

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'technician.created',
            module: 'technicians',
            record: $technician,
            description: "Created technician {$technician->name}.",
            newValues: $technician->only(['name', 'email', 'phone', 'specialties', 'is_active'])
        );

        return response()->json([
            'message' => 'Technician created successfully.',
            'technician' => $technician,
        ], 201);
    }

    public function show(Request $request, Technician $technician)
    {
        $this->authorizeAdmin($request);
        $this->ensureSameShop($request, $technician);

        $jobOrders = $technician->jobOrders()->latest()->paginate(15);

        return response()->json([
            'technician' => $technician,
            'jobOrders' => $jobOrders,
            'currentJobsCount' => $technician->currentJobsCount(),
            'completedJobsCount' => $technician->completedJobsCount(),
            'averageRating' => $technician->averageRating(),
        ]);
    }

    public function update(Request $request, Technician $technician)
    {
        $this->authorizeAdmin($request);
        $this->ensureSameShop($request, $technician);
        $shopId = $this->currentShopId($request);
        $before = $technician->only(['name', 'email', 'phone', 'specialties', 'is_active']);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => [
                'required',
                'email',
                Rule::unique('technicians', 'email')
                    ->where(fn ($query) => $query->where('shop_id', $shopId))
                    ->ignore($technician->id),
            ],
            'phone' => 'nullable|string|max:20',
            'specialties' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $technician->update($validated);

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'technician.updated',
            module: 'technicians',
            record: $technician,
            description: "Updated technician {$technician->name}.",
            oldValues: $before,
            newValues: $technician->fresh()->only(['name', 'email', 'phone', 'specialties', 'is_active'])
        );

        return response()->json([
            'message' => 'Technician updated successfully.',
            'technician' => $technician->fresh(),
        ]);
    }

    public function destroy(Request $request, Technician $technician)
    {
        $this->authorizeAdmin($request);
        $this->ensureSameShop($request, $technician);

        $name = $technician->name;
        $before = $technician->only(['name', 'email', 'phone', 'specialties', 'is_active']);
        $technician->delete();

        AuditLogger::log(
            request: $request,
            user: $request->user(),
            action: 'technician.deleted',
            module: 'technicians',
            description: "{$name} deleted successfully.",
            oldValues: $before
        );

        return response()->json([
            'message' => "{$name} deleted successfully.",
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
    }
}
