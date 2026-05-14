<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ReportsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAdmin($request);

        return response()->json($this->buildReportData($request));
    }

    public function exportCsv(Request $request)
    {
        $this->authorizeAdmin($request);

        $report = $this->buildReportData($request);
        $filters = $report['filters'];
        $filename = 'reports-' . now()->format('Ymd-Hi') . '.csv';

        return response()->streamDownload(function () use ($report, $filters) {
            $handle = fopen('php://output', 'w');
            $appName = config('app.name', 'Repair Service Management System');

            fputcsv($handle, ["{$appName} Reports"]);
            fputcsv($handle, ['Generated At', now()->toDateTimeString()]);
            fputcsv($handle, ['Filters', sprintf('start=%s, end=%s, status=%s', $filters['start_date'] ?? '-', $filters['end_date'] ?? '-', $filters['status'] ?? 'ALL')]);
            fputcsv($handle, []);

            fputcsv($handle, ['Summary']);
            fputcsv($handle, ['Jobs This Month', $report['thisMonth']]);
            fputcsv($handle, ['Jobs Last Month', $report['lastMonth']]);
            fputcsv($handle, ['Month Growth (%)', $report['monthGrowth']]);
            fputcsv($handle, ['Completed Repairs', $report['totalCompleted']]);
            fputcsv($handle, ['Pending Jobs', $report['totalPending']]);
            fputcsv($handle, ['Avg Turnaround (days)', $report['avgTurnaround']]);
            fputcsv($handle, ['Fastest (days)', $report['minTurnaround']]);
            fputcsv($handle, ['Slowest (days)', $report['maxTurnaround']]);
            fputcsv($handle, []);

            fputcsv($handle, ['Financial Summary']);
            fputcsv($handle, ['Total Revenue', $report['totalRevenue']]);
            fputcsv($handle, ['Total Collected', $report['totalCollected']]);
            fputcsv($handle, ['Pending Receivables', $report['pendingReceivables']]);
            fputcsv($handle, ['Estimated Gross Profit', $report['estimatedGrossProfit']]);
            fputcsv($handle, ['Gross Profit Margin (%)', $report['grossProfitMargin']]);
            fputcsv($handle, []);

            fputcsv($handle, ['Device Breakdown']);
            fputcsv($handle, ['Device Type', 'Total']);
            foreach ($report['deviceBreakdown'] as $device) {
                fputcsv($handle, [$device['device_type'], $device['total']]);
            }
            fputcsv($handle, []);

            fputcsv($handle, ['Status Breakdown']);
            fputcsv($handle, ['Status', 'Total']);
            foreach ($report['statusBreakdown'] as $status) {
                fputcsv($handle, [$status['current_status'], $status['total']]);
            }
            fputcsv($handle, []);

            fputcsv($handle, ['Monthly Trend (Last 6 Months)']);
            fputcsv($handle, ['Month', 'Count']);
            foreach ($report['monthlyTrend'] as $trend) {
                fputcsv($handle, [$trend['month'], $trend['count']]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function exportPdf(Request $request)
    {
        $this->authorizeAdmin($request);

        $report = $this->buildReportData($request);

        return response()->view('reports.export', $report);
    }

    private function authorizeAdmin(Request $request): void
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
    }

    private function buildReportData(Request $request): array
    {
        $shopId = $this->currentShopId($request);
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $statusFilter = $request->query('status');

        $baseQuery = JobOrder::query()->where('shop_id', $shopId);

        if ($startDate) {
            $baseQuery->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $baseQuery->whereDate('created_at', '<=', $endDate);
        }
        if ($statusFilter) {
            $baseQuery->where('current_status', $statusFilter);
        }

        if ($startDate && $endDate) {
            $rangeStart = Carbon::parse($startDate)->startOfDay();
            $rangeEnd = Carbon::parse($endDate)->endOfDay();
            $days = $rangeStart->diffInDays($rangeEnd) + 1;

            $thisMonth = (clone $baseQuery)->whereBetween('created_at', [$rangeStart, $rangeEnd])->count();
            $previousStart = (clone $rangeStart)->subDays($days);
            $previousEnd = (clone $rangeEnd)->subDays($days);
            $lastMonth = (clone $baseQuery)->whereBetween('created_at', [$previousStart, $previousEnd])->count();
        } else {
            $thisMonth = (clone $baseQuery)->whereYear('created_at', Carbon::now()->year)
                ->whereMonth('created_at', Carbon::now()->month)
                ->count();

            $lastMonth = (clone $baseQuery)->whereYear('created_at', Carbon::now()->year)
                ->whereMonth('created_at', Carbon::now()->subMonth()->month)
                ->count();
        }

        $monthGrowth = $lastMonth > 0 ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1) : 0;

        $totalCompleted = (clone $baseQuery)->whereIn('current_status', ['READY_FOR_PICKUP', 'UNREPAIRABLE'])->count();
        $totalPending = (clone $baseQuery)->whereNotIn('current_status', ['READY_FOR_PICKUP', 'UNREPAIRABLE'])->count();

        $deviceBreakdown = (clone $baseQuery)->groupBy('device_type')
            ->select('device_type', DB::raw('count(*) as total'))
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['device_type' => $row->device_type, 'total' => $row->total])
            ->values()
            ->all();

        $statusBreakdown = (clone $baseQuery)->groupBy('current_status')
            ->select('current_status', DB::raw('count(*) as total'))
            ->get()
            ->map(fn ($row) => ['current_status' => $row->current_status, 'total' => $row->total])
            ->values()
            ->all();

        $monthlyTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $count = (clone $baseQuery)->whereYear('created_at', $date->year)
                ->whereMonth('created_at', $date->month)
                ->count();
            $monthlyTrend[] = [
                'month' => $date->format('M Y'),
                'count' => $count,
            ];
        }

        $turnaroundStats = (clone $baseQuery)->whereIn('current_status', ['READY_FOR_PICKUP', 'UNREPAIRABLE'])
            ->get()
            ->map(fn ($job) => $job->created_at->diffInDays($job->updated_at, true))
            ->filter(fn ($days) => $days !== null);

        $avgTurnaround = $turnaroundStats->count() > 0 ? round($turnaroundStats->avg(), 1) : 0;
        $minTurnaround = $turnaroundStats->count() > 0 ? $turnaroundStats->min() : 0;
        $maxTurnaround = $turnaroundStats->count() > 0 ? $turnaroundStats->max() : 0;

        $totalRevenue = round((float) (clone $baseQuery)->sum('total_cost'), 2);
        $totalCollected = round((float) (clone $baseQuery)->sum('amount_paid'), 2);
        $pendingReceivables = round((float) (clone $baseQuery)->sum(DB::raw('total_cost - amount_paid')), 2);
        $partsCostTotal = round((float) (clone $baseQuery)->sum('parts_cost'), 2);
        $estimatedGrossProfit = round($totalRevenue - $partsCostTotal, 2);
        $grossProfitMargin = $totalRevenue > 0 ? round(($estimatedGrossProfit / $totalRevenue) * 100, 1) : 0;

        return [
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $statusFilter,
            ],
            'thisMonth' => $thisMonth,
            'lastMonth' => $lastMonth,
            'monthGrowth' => $monthGrowth,
            'totalCompleted' => $totalCompleted,
            'totalPending' => $totalPending,
            'deviceBreakdown' => $deviceBreakdown,
            'statusBreakdown' => $statusBreakdown,
            'monthlyTrend' => $monthlyTrend,
            'avgTurnaround' => $avgTurnaround,
            'minTurnaround' => $minTurnaround,
            'maxTurnaround' => $maxTurnaround,
            'totalRevenue' => $totalRevenue,
            'totalCollected' => $totalCollected,
            'pendingReceivables' => $pendingReceivables,
            'estimatedGrossProfit' => $estimatedGrossProfit,
            'grossProfitMargin' => $grossProfitMargin,
        ];
    }
}
