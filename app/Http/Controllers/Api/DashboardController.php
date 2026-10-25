<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\Technician;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeRoles($request, [User::ROLE_ADMIN, User::ROLE_STAFF]);
        $shopId = $this->currentShopId($request);

        $activeStatuses = ['RECEIVED', 'DIAGNOSING', 'WAITING_PARTS', 'IN_PROGRESS'];
        $resolvedStatuses = ['PICKED_UP', 'RTO', 'RTO_RELEASED', 'UNREPAIRABLE'];
        $repairCompletedStatuses = ['READY_FOR_PICKUP', 'PICKED_UP', 'RTO', 'RTO_RELEASED', 'UNREPAIRABLE'];
        $jobOrders = JobOrder::query()->where('shop_id', $shopId);

        $totalJobs = (clone $jobOrders)->count();
        $activeJobs = (clone $jobOrders)->whereIn('current_status', $activeStatuses)->count();
        $inProgress = (clone $jobOrders)->whereIn('current_status', ['DIAGNOSING', 'IN_PROGRESS'])->count();
        $waitingParts = (clone $jobOrders)->where('current_status', 'WAITING_PARTS')->count();
        $readyForPickup = (clone $jobOrders)->where('current_status', 'READY_FOR_PICKUP')->count();
        $pickedUp = (clone $jobOrders)->where('current_status', 'PICKED_UP')->count();
        $unrepairable = (clone $jobOrders)->whereIn('current_status', ['RTO', 'RTO_RELEASED', 'UNREPAIRABLE'])->count();
        $warrantyServiceJobs = (clone $jobOrders)->where('service_type', 'WARRANTY_SERVICE')->count();
        $overdueJobs = (clone $jobOrders)->whereIn('current_status', $activeStatuses)
            ->whereNotNull('estimated_completion')
            ->where('estimated_completion', '<', now())
            ->count();

        $completedJobs = (clone $jobOrders)->whereIn('current_status', $resolvedStatuses)->count();
        $completionRate = $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 1) : 0;

        $completedJobsWithTime = JobOrder::with(['statusHistories' => function ($query) use ($repairCompletedStatuses) {
            $query->whereIn('status', $repairCompletedStatuses)->orderBy('created_at');
        }])
            ->where('shop_id', $shopId)
            ->whereIn('current_status', $repairCompletedStatuses)
            ->get()
            ->map(function (JobOrder $job) {
                $completionEvent = $job->statusHistories->first();

                return $job->created_at?->diffInDays($completionEvent?->created_at, true);
            })
            ->filter(fn ($days) => $days !== null)
            ->avg();
        $averageTurnaround = round($completedJobsWithTime ?? 0, 1);

        $totalRevenue = (clone $jobOrders)->sum('total_cost');
        $totalPaid = (clone $jobOrders)->sum('amount_paid');
        $pendingPayments = (clone $jobOrders)->sum(DB::raw('total_cost - amount_paid'));
        $unpaidJobs = (clone $jobOrders)->whereColumn('amount_paid', '<', 'total_cost')
            ->where('total_cost', '>', 0)
            ->count();
        $profitMargin = $totalRevenue > 0 ? round((($totalRevenue - (clone $jobOrders)->sum('parts_cost')) / $totalRevenue) * 100, 1) : 0;

        $expressJobs = (clone $jobOrders)->where('priority', 'EXPRESS')->count();
        $standardJobs = (clone $jobOrders)->where('priority', 'STANDARD')->count();

        $deviceStats = (clone $jobOrders)->groupBy('device_type')
            ->select('device_type', DB::raw('count(*) as count'))
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        $recentJobs = JobOrder::with('technician:id,name')
            ->where('shop_id', $shopId)
            ->latest()
            ->take(10)
            ->get()
            ->map(function (JobOrder $job) use ($activeStatuses) {
            return [
                'id' => $job->id,
                'job_order_number' => $job->job_order_number,
                'customer_name' => $job->customer_name,
                'device_type' => $job->device_type,
                'current_status' => $job->current_status,
                'payment_status' => $job->payment_status,
                'balance' => $job->balance,
                'technician_name' => $job->technician?->name,
                'estimated_completion_formatted' => $job->estimated_completion?->format('M d, Y h:i A'),
                'is_overdue' => $job->estimated_completion
                    && in_array($job->current_status, $activeStatuses, true)
                    && $job->estimated_completion->isPast(),
                'created_at_formatted' => $job->created_at->format('M d, Y'),
            ];
        });

        $statusDistribution = (clone $jobOrders)->groupBy('current_status')
            ->select('current_status', DB::raw('count(*) as count'))
            ->get();

        $jobsThisMonth = (clone $jobOrders)->whereYear('created_at', Carbon::now()->year)
            ->whereMonth('created_at', Carbon::now()->month)
            ->count();
        $todayIntake = (clone $jobOrders)->whereDate('created_at', today())->count();

        $attentionJobs = JobOrder::with('technician:id,name')
            ->where('shop_id', $shopId)
            ->where(function ($query) use ($activeStatuses) {
                $query
                    ->where('current_status', 'WAITING_PARTS')
                    ->orWhere('current_status', 'READY_FOR_PICKUP')
                    ->orWhere(function ($overdue) use ($activeStatuses) {
                        $overdue->whereIn('current_status', $activeStatuses)
                            ->whereNotNull('estimated_completion')
                            ->where('estimated_completion', '<', now());
                    });
            })
            ->latest()
            ->take(8)
            ->get()
            ->map(function (JobOrder $job) use ($activeStatuses) {
                return [
                    'id' => $job->id,
                    'job_order_number' => $job->job_order_number,
                    'customer_name' => $job->customer_name,
                    'current_status' => $job->current_status,
                    'technician_name' => $job->technician?->name,
                    'estimated_completion_formatted' => $job->estimated_completion?->format('M d, Y h:i A'),
                    'is_overdue' => $job->estimated_completion
                        && in_array($job->current_status, $activeStatuses, true)
                        && $job->estimated_completion->isPast(),
                ];
            });

        $partRequests = JobOrder::query()
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
            ->take(5)
            ->get()
            ->map(function (JobOrder $jobOrder) {
                $history = $jobOrder->statusHistories->first();
                if (!$history) {
                    return null;
                }

                $parsed = $this->parseRequestNote((string) $history->note);
                if (!$parsed) {
                    return null;
                }

                return [
                    'job_id' => $jobOrder->id,
                    'job_order_number' => $jobOrder->job_order_number,
                    'customer_name' => $jobOrder->customer_name,
                    'technician_name' => $jobOrder->technician?->name,
                    'requested_part' => $parsed['part_name'],
                    'requested_quantity' => $parsed['quantity'],
                    'requested_at_formatted' => $history->created_at?->format('M d, Y h:i A'),
                ];
            })
            ->filter()
            ->values();

        $technicians = Technician::where('shop_id', $shopId)
            ->where('is_active', true)
            ->get()
            ->map(function ($tech) {
                return [
                    'id' => $tech->id,
                    'name' => $tech->name,
                    'currentJobs' => $tech->currentJobsCount(),
                    'completedJobs' => $tech->completedJobsCount(),
                    'rating' => $tech->averageRating(),
                ];
            });

        return response()->json([
            'totalJobs' => $totalJobs,
            'activeJobs' => $activeJobs,
            'inProgress' => $inProgress,
            'waitingParts' => $waitingParts,
            'readyForPickup' => $readyForPickup,
            'pickedUp' => $pickedUp,
            'unrepairable' => $unrepairable,
            'warrantyServiceJobs' => $warrantyServiceJobs,
            'overdueJobs' => $overdueJobs,
            'completionRate' => $completionRate,
            'averageTurnaround' => $averageTurnaround,
            'totalRevenue' => $totalRevenue,
            'totalPaid' => $totalPaid,
            'pendingPayments' => $pendingPayments,
            'unpaidJobs' => $unpaidJobs,
            'profitMargin' => $profitMargin,
            'expressJobs' => $expressJobs,
            'standardJobs' => $standardJobs,
            'deviceStats' => $deviceStats,
            'recentJobs' => $recentJobs,
            'statusDistribution' => $statusDistribution,
            'jobsThisMonth' => $jobsThisMonth,
            'todayIntake' => $todayIntake,
            'attentionJobs' => $attentionJobs,
            'partRequests' => $partRequests,
            'technicians' => $technicians,
        ]);
    }

    private function parseRequestNote(string $note): ?array
    {
        $note = trim($note);

        if (!preg_match('/^Parts requested:\s*(.+?)\s*\(Qty\s+(\d+)\)(?:\.\s*Note:\s*(.+))?$/', $note, $matches)) {
            return null;
        }

        return [
            'part_name' => trim($matches[1]),
            'quantity' => (int) $matches[2],
            'note' => isset($matches[3]) ? trim($matches[3]) : null,
        ];
    }
}
