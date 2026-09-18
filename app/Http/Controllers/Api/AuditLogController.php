<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
        $shopId = $this->currentShopId($request);

        $query = AuditLog::query()
            ->where('shop_id', $shopId)
            ->latest();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('user_name', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('module', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($module = $request->query('module')) {
            $query->where('module', $module);
        }

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        if ($role = $request->query('role')) {
            $query->where('user_role', $role);
        }

        if ($startDate = $request->query('start_date')) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate = $request->query('end_date')) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $logs = $query->paginate((int) $request->query('per_page', 20))->withQueryString();
        $logs->getCollection()->transform(function (AuditLog $log) {
            return [
                'id' => $log->id,
                'user_name' => $log->user_name,
                'user_role' => $log->user_role,
                'action' => $log->action,
                'module' => $log->module,
                'record_type' => $log->record_type,
                'record_id' => $log->record_id,
                'description' => $log->description,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at,
                'created_at_formatted' => $log->created_at?->format('M d, Y h:i A'),
            ];
        });

        return response()->json([
            'logs' => $logs,
            'filters' => [
                'roles' => User::availableRoles(),
                'modules' => AuditLog::query()->where('shop_id', $shopId)->select('module')->distinct()->orderBy('module')->pluck('module'),
                'actions' => AuditLog::query()->where('shop_id', $shopId)->select('action')->distinct()->orderBy('action')->pluck('action'),
            ],
        ]);
    }
}
