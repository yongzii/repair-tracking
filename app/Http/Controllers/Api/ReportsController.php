<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\JobOrderPayment;
use App\Models\JobOrderRefund;
use App\Models\SparePart;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\Technician;
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

        $report = $this->sanitizeCsvValues($this->buildReportData($request));
        $filters = $report['filters'];
        $reportType = $this->reportType($request);
        $filename = "{$reportType}-reports-" . now()->format('Ymd-Hi') . '.csv';

        return response()->streamDownload(function () use ($report, $filters, $reportType) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            $includeOperations = in_array($reportType, ['operations', 'inventory', 'all'], true);
            $includeInventory = in_array($reportType, ['inventory', 'operations', 'all'], true);
            $includePayments = in_array($reportType, ['payments', 'all'], true);

            fputcsv($handle, [$report['shop_name'], ucfirst($reportType) . ' Reports']);
            fputcsv($handle, ['Generated At', now()->format('Y-m-d H:i:s')]);
            fputcsv($handle, ['Date Range', ($filters['start_date'] ?: 'All dates') . ' to ' . ($filters['end_date'] ?: 'All dates')]);
            fputcsv($handle, ['Filters', sprintf('Status: %s; Service type: %s; Category: %s; Service: %s; Payment method: %s; Payment type: %s', $filters['status_label'] ?? 'ALL', $filters['service_type_label'] ?? 'ALL', $filters['service_category_label'] ?? 'ALL', $filters['service_item_label'] ?? 'ALL', $filters['payment_method'] ?? 'ALL', $filters['payment_type'] ?? 'ALL')]);
            fputcsv($handle, []);

            if ($includeOperations) {
                fputcsv($handle, ['Operations Summary']);
                fputcsv($handle, ['Jobs This Month', $report['thisMonth']]);
                fputcsv($handle, ['Jobs Last Month', $report['lastMonth']]);
                fputcsv($handle, ['Month Growth (%)', $report['monthGrowth']]);
                fputcsv($handle, ['Completed Repairs', $report['totalCompleted']]);
                fputcsv($handle, ['Completed Repairs Date Basis', 'Date basis: job creation date (created_at). Date scope: applied date range when provided; otherwise, all dates.']);
                fputcsv($handle, ['RTO Outcomes', $report['totalRto']]);
                fputcsv($handle, ['Pending Jobs', $report['totalPending']]);
                fputcsv($handle, ['Avg Turnaround (days)', $report['avgTurnaround']]);
                fputcsv($handle, ['Fastest (days)', $report['minTurnaround']]);
                fputcsv($handle, ['Slowest (days)', $report['maxTurnaround']]);
                fputcsv($handle, []);

                fputcsv($handle, ['Technician Performance (Current Assignment)']);
                fputcsv($handle, ['Results reflect the currently stored technician assignment; not historical assignment']);
                fputcsv($handle, ['Successful jobs use pickup date (picked_up_at); RTO outcomes use job creation date (created_at). Date scope: applied date range when provided; otherwise, all dates.']);
                fputcsv($handle, ['Technician', 'Successful Jobs', 'Avg Turnaround (days)', 'RTO', 'RTO Released']);
                foreach ($report['technicianPerformance'] as $technician) {
                    fputcsv($handle, [
                        $technician['technician_name'],
                        $technician['successful_jobs'],
                        $technician['average_turnaround_days'],
                        $technician['rto_jobs'],
                        $technician['rto_released_jobs'],
                    ]);
                }
                fputcsv($handle, []);

                fputcsv($handle, ['Current Technician Workload']);
                fputcsv($handle, ['Current snapshot; excludes picked-up and both RTO terminal statuses. Date filters do not apply.']);
                fputcsv($handle, ['Technician', 'Currently Assigned Jobs']);
                foreach ($report['currentTechnicianWorkload'] as $technician) {
                    fputcsv($handle, [$technician['technician_name'], $technician['current_jobs']]);
                }
                fputcsv($handle, []);

                if ($includeInventory) {
                $inventory = $report['inventoryReport'];
                fputcsv($handle, ['Inventory Reports']);
                fputcsv($handle, ['Current Stock Snapshot', 'Current inventory; report date filters do not apply.']);
                fputcsv($handle, ['Total Inventory Items', $inventory['summary']['total_items']]);
                fputcsv($handle, ['Active Inventory Items', $inventory['summary']['active_items']]);
                fputcsv($handle, ['Inactive Inventory Items', $inventory['summary']['inactive_items']]);
                fputcsv($handle, ['Low Stock Items', $inventory['summary']['low_stock_items'], 'Includes inactive items; stock_quantity <= reorder_level.']);
                fputcsv($handle, ['Total Current Stock Quantity by Unit']);
                fputcsv($handle, ['Unit', 'Quantity']);
                foreach ($inventory['summary']['stock_quantity_by_unit'] as $unitTotal) {
                    fputcsv($handle, [$unitTotal['unit'], $unitTotal['quantity']]);
                }
                fputcsv($handle, ['Current Inventory Items']);
                fputcsv($handle, ['Part Name', 'Part Number', 'Category', 'Unit', 'Current Stock', 'Reorder Level', 'Stock Status', 'Active Status']);
                foreach ($inventory['items'] as $item) {
                    fputcsv($handle, [
                        $item['part_name'],
                        $item['part_number'],
                        $item['category'],
                        $item['unit'],
                        $item['stock_quantity'],
                        $item['reorder_level'],
                        $item['stock_status'],
                        $item['active_status'],
                    ]);
                }
                if ($inventory['items'] === []) {
                    fputcsv($handle, ['No inventory items found for this shop.']);
                }
                fputcsv($handle, []);

                fputcsv($handle, ['Stock Movement History']);
                fputcsv($handle, ['Date basis', 'Movement created_at; applied report date range when provided, otherwise, all dates.']);
                fputcsv($handle, ['Recorded Parts Issued to Repairs', $inventory['movement_summary']['repair_linked_out']['movement_count'], 'movement(s)', $inventory['movement_summary']['repair_linked_out']['quantity'], 'unit(s)']);
                fputcsv($handle, ['Unlinked/Manual OUT', $inventory['movement_summary']['unlinked_manual_out']['movement_count'], 'movement(s)', $inventory['movement_summary']['unlinked_manual_out']['quantity'], 'unit(s)']);
                fputcsv($handle, ['OUT with unavailable/cross-shop job reference', $inventory['movement_summary']['invalid_job_reference_out']['movement_count'], 'movement(s)', $inventory['movement_summary']['invalid_job_reference_out']['quantity'], 'unit(s)']);
                fputcsv($handle, ['OUT records are recorded issues, not proof of actual consumption. IN movements may include corrections/reversals and are not treated as purchases; no net usage is calculated.']);
                fputcsv($handle, ['Movement Date', 'Part Name', 'Part Number', 'Type', 'OUT Classification', 'Signed Quantity Change', 'Balance After', 'Related Job Order', 'Recorded By', 'Note']);
                foreach ($inventory['movements'] as $movement) {
                    fputcsv($handle, [
                        $movement['movement_date'],
                        $movement['part_name'],
                        $movement['part_number'],
                        $movement['type'],
                        $movement['out_classification'],
                        $movement['quantity_change'],
                        $movement['balance_after'],
                        $movement['job_order_reference'],
                        $movement['recorded_by'],
                        $movement['note'],
                    ]);
                }
                if ($inventory['movements'] === []) {
                    fputcsv($handle, ['No stock movements found for the applied date scope.']);
                }
                fputcsv($handle, []);
                }

                fputcsv($handle, ['Filtered Job Orders']);
                fputcsv($handle, ['Date', 'Job Order', 'Customer', 'Phone', 'Device', 'Service Category', 'Service Needed', 'Status', 'Service Type', 'Technician', 'Estimated Completion', 'Total', 'Paid', 'Balance']);
                foreach ($report['filteredJobOrders'] as $jobOrder) {
                    fputcsv($handle, [
                        $jobOrder['created_at_formatted'],
                        $jobOrder['job_order_number'],
                        $jobOrder['customer_name'],
                        $jobOrder['customer_phone'],
                        $jobOrder['device_label'],
                        $jobOrder['service_category_name'],
                        $jobOrder['service_item_name'],
                        $jobOrder['current_status_label'],
                        $jobOrder['service_type_label'],
                        $jobOrder['technician_name'],
                        $jobOrder['estimated_completion_formatted'],
                        $jobOrder['total_cost'],
                        $jobOrder['amount_paid'],
                        $jobOrder['balance'],
                    ]);
                }
                fputcsv($handle, []);

                fputcsv($handle, ['Top Services']);
                fputcsv($handle, ['Device Category', 'Service', 'Total']);
                foreach ($report['topServices'] as $service) {
                    fputcsv($handle, [$service['service_category_name'], $service['service_item_name'], $service['total']]);
                }
                fputcsv($handle, []);

                fputcsv($handle, ['Service Category Breakdown']);
                fputcsv($handle, ['Device Category', 'Total']);
                foreach ($report['serviceCategoryBreakdown'] as $category) {
                    fputcsv($handle, [$category['service_category_name'], $category['total']]);
                }
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
                    fputcsv($handle, [$status['current_status_label'], $status['total']]);
                }
                fputcsv($handle, []);

                fputcsv($handle, ['Monthly Trend (Last 6 Months)']);
                fputcsv($handle, ['Month', 'Count']);
                foreach ($report['monthlyTrend'] as $trend) {
                    fputcsv($handle, [$trend['month'], $trend['count']]);
                }
                fputcsv($handle, []);
            }

            if ($includePayments) {
                fputcsv($handle, ['Financial Summary']);
                fputcsv($handle, ['Customer Job Order Value', $report['totalRevenue']]);
                fputcsv($handle, ['Total Collected', $report['totalCollected']]);
                fputcsv($handle, ['Pending RTO Refund Obligations', $report['pendingRtoRefunds']]);
                fputcsv($handle, ['RTO Refunds Issued', $report['issuedRtoRefunds']]);
                fputcsv($handle, ['Net Retained After Issued Refunds', $report['netRetained']]);
                fputcsv($handle, ['Pending Receivables', $report['pendingReceivables']]);
                fputcsv($handle, []);

                fputcsv($handle, ['Customer Collections']);
                fputcsv($handle, ['Recorded customer collections are the sum of payment transaction amounts; they are not verified bank settlements.']);
                fputcsv($handle, ['Payment collections and history use received_at. If received_at is blank, created_at is shown for display only.']);
                fputcsv($handle, ['Total Payments Recorded', $report['paymentSummary']['total_amount']]);
                fputcsv($handle, ['Payment Records', $report['paymentSummary']['total_records']]);
                fputcsv($handle, ['Current Pending Customer Balances', $report['paymentSummary']['current_pending_balances']]);
                fputcsv($handle, ['Jobs with Current Pending Balances', $report['paymentSummary']['current_pending_balance_jobs']]);
                fputcsv($handle, ['Current balances use max(total_cost - amount_paid, 0), exclude RTO and RTO_RELEASED, and are independent of report dates.']);
                fputcsv($handle, ['Partial Payments', $report['paymentSummary']['partial_payment_total']]);
                fputcsv($handle, ['Held Partial Payments', $report['paymentSummary']['held_partial_payments']]);
                fputcsv($handle, ['Applied Partial Payments', $report['paymentSummary']['applied_partial_payments']]);
                fputcsv($handle, ['Partial Payments Needing Review', $report['paymentSummary']['review_partial_payments']]);
                fputcsv($handle, ['Pending Refund Obligations', $report['paymentSummary']['pending_refund_obligations']]);
                fputcsv($handle, ['Refunds Issued', $report['paymentSummary']['issued_refunds']]);
                fputcsv($handle, ['Net Cash Retained in Payment Date Range', $report['paymentSummary']['net_cash_retained']]);
                fputcsv($handle, ['Pending means an obligation was recorded; issued means staff recorded the refund as issued and does not prove external settlement. Pending refunds are not subtracted from collections.']);
                fputcsv($handle, []);

                fputcsv($handle, ['RTO Refund Ledger']);
                fputcsv($handle, ['Pending refunds are filtered by initiated_at; issued refunds are filtered by issued_at. Blank date filters include all applicable records.']);
                fputcsv($handle, ['Job Order', 'Customer', 'Reason', 'Status', 'Amount', 'Method', 'Reference', 'Initiated', 'Issued']);
                foreach ($report['refundRecords'] as $refund) {
                    fputcsv($handle, [
                        $refund['job_order_number'],
                        $refund['customer_name'],
                        $refund['reason'],
                        $refund['status'],
                        $refund['amount'],
                        $refund['refund_method'],
                        $refund['reference_number'],
                        $refund['initiated_at'],
                        $refund['issued_at'],
                    ]);
                }
                fputcsv($handle, []);

                fputcsv($handle, ['Payment Methods']);
                fputcsv($handle, ['Method', 'Records', 'Amount']);
                foreach ($report['paymentMethodBreakdown'] as $method) {
                    fputcsv($handle, [$method['payment_method'], $method['records'], $method['amount']]);
                }
                fputcsv($handle, []);

                fputcsv($handle, ['Payment Types']);
                fputcsv($handle, ['Type', 'Records', 'Amount']);
                foreach ($report['paymentTypeBreakdown'] as $type) {
                    fputcsv($handle, [$type['payment_type'], $type['records'], $type['amount']]);
                }
                fputcsv($handle, []);

                fputcsv($handle, ['Partial Payment Watchlist']);
                fputcsv($handle, ['Date', 'Job Order', 'Customer', 'Method', 'Amount', 'State']);
                foreach ($report['partialPaymentRecords'] as $payment) {
                    fputcsv($handle, [
                        $payment['received_at_formatted'],
                        $payment['job_order_number'],
                        $payment['customer_name'],
                        $payment['payment_method'],
                        $payment['amount'],
                        $payment['partial_payment_state'],
                    ]);
                }
                fputcsv($handle, []);

                fputcsv($handle, ['Payment Transaction History (Latest 30)']);
                fputcsv($handle, ['The list is limited to the latest 30 records; summary totals include all matching transactions. Payment date uses received_at; created_at is a display-only fallback when received_at is blank.']);
                fputcsv($handle, ['Payment Date', 'Date Basis', 'Job Order', 'Customer', 'Amount', 'Method', 'Type', 'Reference', 'Notes', 'Received By']);
                foreach ($report['paymentRecords'] as $payment) {
                    fputcsv($handle, [
                        $payment['received_at_formatted'],
                        $payment['payment_date_basis'],
                        $payment['job_order_number'],
                        $payment['customer_name'],
                        $payment['amount'],
                        $payment['payment_method'],
                        $payment['payment_type'],
                        $payment['reference_number'],
                        $payment['notes'],
                        $payment['received_by'],
                    ]);
                }
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
        $report['reportType'] = $this->reportType($request);

        return response()->view('reports.export', $report);
    }

    private function authorizeAdmin(Request $request): void
    {
        $this->authorizeRoles($request, User::ROLE_ADMIN);
    }

    private function reportType(Request $request): string
    {
        $type = $request->query('report_type', 'all');

        return in_array($type, ['operations', 'inventory', 'payments', 'all'], true) ? $type : 'all';
    }

    private function sanitizeCsvValues(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->sanitizeCsvValues($item);
            }

            return $value;
        }

        return is_string($value) ? $this->sanitizeCsvText($value) : $value;
    }

    private function sanitizeCsvText(string $value): string
    {
        if (is_numeric($value)) {
            return $value;
        }

        $dangerousPrefix = preg_match('/^[\p{C}\p{Z}\s]*[=+\-@]/u', $value);
        if ($dangerousPrefix === false) {
            $dangerousPrefix = preg_match('/^[\x00-\x20\x7F]*[=+\-@]/', $value);
        }

        return $dangerousPrefix === 1 ? "'" . $value : $value;
    }

    private function buildReportData(Request $request): array
    {
        $shopId = $this->currentShopId($request);
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $statusFilter = $request->query('status');
        $serviceTypeFilter = $request->query('service_type');
        $serviceCategoryFilter = $request->query('service_category_id');
        $serviceItemFilter = $request->query('service_item_id');
        $paymentMethodFilter = $request->query('payment_method');
        $paymentTypeFilter = $request->query('payment_type');

        $baseQuery = JobOrder::query()->where('job_orders.shop_id', $shopId);
        $comparisonQuery = JobOrder::query()->where('job_orders.shop_id', $shopId);

        if ($startDate) {
            $baseQuery->whereDate('job_orders.created_at', '>=', $startDate);
        }
        if ($endDate) {
            $baseQuery->whereDate('job_orders.created_at', '<=', $endDate);
        }
        $applyReportFilters = function ($query) use (
            $statusFilter,
            $serviceTypeFilter,
            $serviceCategoryFilter,
            $serviceItemFilter
        ): void {
            if ($statusFilter === 'OPEN') {
                $query->whereNotIn('job_orders.current_status', ['PICKED_UP', 'RTO', 'RTO_RELEASED']);
            } elseif ($statusFilter) {
                $query->where('job_orders.current_status', $statusFilter);
            }
            if ($serviceTypeFilter) {
                $query->where('job_orders.service_type', $serviceTypeFilter);
            }
            if ($serviceCategoryFilter) {
                $query->where('job_orders.service_category_id', $serviceCategoryFilter);
            }
            if ($serviceItemFilter) {
                $query->where('job_orders.service_item_id', $serviceItemFilter);
            }
        };
        $applyReportFilters($baseQuery);
        $applyReportFilters($comparisonQuery);

        if ($startDate && $endDate) {
            $rangeStart = Carbon::parse($startDate)->startOfDay();
            $rangeEnd = Carbon::parse($endDate)->endOfDay();
            $calendarStart = Carbon::parse($startDate)->startOfDay();
            $calendarEnd = Carbon::parse($endDate)->startOfDay();
            $days = $calendarStart->diffInDays($calendarEnd) + 1;

            $thisMonth = (clone $baseQuery)->whereBetween('created_at', [$rangeStart, $rangeEnd])->count();
            $previousStart = (clone $rangeStart)->subDays($days);
            $previousEnd = (clone $rangeEnd)->subDays($days);
            $lastMonth = (clone $comparisonQuery)->whereBetween('created_at', [$previousStart, $previousEnd])->count();
        } else {
            $currentPeriodStart = Carbon::now()->startOfMonth();
            $currentPeriodEnd = Carbon::now()->endOfMonth();
            $previousPeriodStart = $currentPeriodStart->copy()->subMonthNoOverflow()->startOfMonth();
            $previousPeriodEnd = $previousPeriodStart->copy()->endOfMonth();

            $thisMonth = (clone $baseQuery)->whereBetween('created_at', [$currentPeriodStart, $currentPeriodEnd])
                ->count();

            $lastMonth = (clone $comparisonQuery)->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
                ->count();
        }

        $monthGrowth = $lastMonth > 0 ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1) : 0;

        $totalCompleted = (clone $baseQuery)->where('current_status', 'PICKED_UP')->count();
        $totalRto = (clone $baseQuery)->whereIn('current_status', ['RTO', 'RTO_RELEASED'])->count();
        $totalPending = (clone $baseQuery)->whereNotIn('current_status', ['PICKED_UP', 'RTO', 'RTO_RELEASED'])->count();

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
            ->map(fn ($row) => [
                'current_status' => $row->current_status,
                'current_status_label' => $this->formatStatusLabel($row->current_status),
                'total' => $row->total,
            ])
            ->values()
            ->all();

        $serviceCategoryBreakdown = (clone $baseQuery)
            ->leftJoin('service_categories', 'job_orders.service_category_id', '=', 'service_categories.id')
            ->groupBy('job_orders.service_category_id', 'service_categories.name', 'job_orders.device_type')
            ->select(
                'job_orders.service_category_id',
                DB::raw('COALESCE(service_categories.name, job_orders.device_type, "Uncategorized") as service_category_name'),
                DB::raw('count(*) as total')
            )
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'service_category_id' => $row->service_category_id,
                'service_category_name' => $row->service_category_name,
                'total' => $row->total,
            ])
            ->values()
            ->all();

        $topServices = (clone $baseQuery)
            ->leftJoin('service_categories', 'job_orders.service_category_id', '=', 'service_categories.id')
            ->leftJoin('service_items', 'job_orders.service_item_id', '=', 'service_items.id')
            ->groupBy('job_orders.service_category_id', 'service_categories.name', 'job_orders.service_item_id', 'service_items.name', 'job_orders.device_type')
            ->select(
                'job_orders.service_category_id',
                DB::raw('COALESCE(service_categories.name, job_orders.device_type, "Uncategorized") as service_category_name'),
                'job_orders.service_item_id',
                DB::raw('COALESCE(service_items.name, "Unspecified Service") as service_item_name'),
                DB::raw('count(*) as total')
            )
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'service_category_id' => $row->service_category_id,
                'service_category_name' => $row->service_category_name,
                'service_item_id' => $row->service_item_id,
                'service_item_name' => $row->service_item_name,
                'total' => $row->total,
            ])
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

        $turnaroundStats = (clone $baseQuery)->where('current_status', 'PICKED_UP')
            ->whereNotNull('picked_up_at')
            ->get()
            ->map(fn ($job) => $job->created_at->diffInDays($job->picked_up_at, true))
            ->filter(fn ($days) => $days !== null);

        $avgTurnaround = $turnaroundStats->count() > 0 ? round($turnaroundStats->avg(), 1) : 0;
        $minTurnaround = $turnaroundStats->count() > 0 ? $turnaroundStats->min() : 0;
        $maxTurnaround = $turnaroundStats->count() > 0 ? $turnaroundStats->max() : 0;

        $totalRevenue = round((float) (clone $baseQuery)->sum('total_cost'), 2);
        $totalCollected = round((float) (clone $baseQuery)->sum('amount_paid'), 2);
        $filteredJobOrderIds = (clone $baseQuery)->select('job_orders.id');
        $pendingRtoRefunds = round((float) JobOrderRefund::query()
            ->where('shop_id', $shopId)
            ->whereIn('job_order_id', $filteredJobOrderIds)
            ->where('status', JobOrderRefund::STATUS_PENDING)
            ->sum('amount'), 2);
        $issuedRtoRefunds = round((float) JobOrderRefund::query()
            ->where('shop_id', $shopId)
            ->whereIn('job_order_id', $filteredJobOrderIds)
            ->where('status', JobOrderRefund::STATUS_ISSUED)
            ->sum('amount'), 2);
        $netRetained = round($totalCollected - $issuedRtoRefunds, 2);
        $pendingReceivables = round((float) (clone $baseQuery)
            ->whereNotIn('current_status', ['RTO', 'RTO_RELEASED'])
            ->sum(DB::raw('CASE WHEN total_cost > amount_paid THEN total_cost - amount_paid ELSE 0 END')), 2);
        $technicianAnalytics = $this->buildTechnicianAnalytics(
            $shopId,
            $startDate,
            $endDate,
            $serviceTypeFilter,
            $serviceCategoryFilter,
            $serviceItemFilter
        );
        $filteredJobOrders = (clone $baseQuery)
            ->with([
                'technician:id,name',
                'serviceCategory:id,name',
                'serviceItem:id,name',
                'parts:id,job_order_id,total_cost',
                'payments' => fn ($query) => $query
                    ->where('shop_id', $shopId)
                    ->select(['id', 'shop_id', 'job_order_id', 'payment_method']),
            ])
            ->latest()
            ->get()
            ->map(fn (JobOrder $jobOrder) => $this->mapJobOrderRecord($jobOrder))
            ->values()
            ->all();
        $paymentReport = $this->buildPaymentReport(
            shopId: $shopId,
            startDate: $startDate,
            endDate: $endDate,
            paymentMethodFilter: $paymentMethodFilter,
            paymentTypeFilter: $paymentTypeFilter
        );
        $inventoryReport = $this->buildInventoryReport($shopId, $startDate, $endDate);

        return [
            'shop_name' => $request->user()->loadMissing('shop')->shop?->name ?? config('app.name', 'Repair Service Management System'),
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $statusFilter,
                'status_label' => $statusFilter === 'OPEN' ? 'OPEN / PENDING JOBS' : $this->formatStatusLabel($statusFilter),
                'service_type' => $serviceTypeFilter,
                'service_type_label' => $this->formatServiceTypeLabel($serviceTypeFilter),
                'service_category_id' => $serviceCategoryFilter,
                'service_category_label' => $this->serviceCategoryLabel($shopId, $serviceCategoryFilter),
                'service_item_id' => $serviceItemFilter,
                'service_item_label' => $this->serviceItemLabel($shopId, $serviceItemFilter),
                'payment_method' => $paymentMethodFilter,
                'payment_type' => $paymentTypeFilter,
            ],
            'serviceCatalog' => $this->activeServiceCatalog($shopId),
            'thisMonth' => $thisMonth,
            'lastMonth' => $lastMonth,
            'monthGrowth' => $monthGrowth,
            'totalCompleted' => $totalCompleted,
            'totalRto' => $totalRto,
            'totalPending' => $totalPending,
            'deviceBreakdown' => $deviceBreakdown,
            'serviceCategoryBreakdown' => $serviceCategoryBreakdown,
            'topServices' => $topServices,
            'statusBreakdown' => $statusBreakdown,
            'monthlyTrend' => $monthlyTrend,
            'avgTurnaround' => $avgTurnaround,
            'minTurnaround' => $minTurnaround,
            'maxTurnaround' => $maxTurnaround,
            'totalRevenue' => $totalRevenue,
            'totalCollected' => $totalCollected,
            'pendingRtoRefunds' => $pendingRtoRefunds,
            'issuedRtoRefunds' => $issuedRtoRefunds,
            'netRetained' => $netRetained,
            'pendingReceivables' => $pendingReceivables,
            'technicianPerformance' => $technicianAnalytics['performance'],
            'currentTechnicianWorkload' => $technicianAnalytics['workload'],
            'inventoryReport' => $inventoryReport,
            'filteredJobOrders' => $filteredJobOrders,
            ...$paymentReport,
        ];
    }

    private function buildInventoryReport(int $shopId, ?string $startDate, ?string $endDate): array
    {
        $parts = SparePart::query()
            ->where('shop_id', $shopId)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'part_number',
                'category',
                'unit',
                'stock_quantity',
                'reorder_level',
                'is_active',
            ]);

        $items = $parts->map(function (SparePart $part): array {
            $stockQuantity = (int) $part->stock_quantity;
            $reorderLevel = (int) $part->reorder_level;

            return [
                'id' => $part->id,
                'part_name' => $part->name,
                'part_number' => $part->part_number,
                'category' => $part->category,
                'unit' => $part->unit ?: 'Unspecified',
                'stock_quantity' => $stockQuantity,
                'reorder_level' => $reorderLevel,
                'stock_status' => $stockQuantity <= $reorderLevel ? 'Low Stock' : 'In Stock',
                'is_low_stock' => $stockQuantity <= $reorderLevel,
                'is_active' => (bool) $part->is_active,
                'active_status' => $part->is_active ? 'Active' : 'Inactive',
            ];
        });

        $stockQuantityByUnit = $items
            ->groupBy('unit')
            ->map(fn ($unitItems, string $unit) => [
                'unit' => $unit,
                'quantity' => $unitItems->sum('stock_quantity'),
            ])
            ->values()
            ->all();

        $movementsQuery = DB::table('stock_movements as movements')
            ->join('spare_parts as parts', function ($join) use ($shopId): void {
                $join->on('parts.id', '=', 'movements.spare_part_id')
                    ->where('parts.shop_id', '=', $shopId);
            })
            ->leftJoin('job_orders as jobs', function ($join) use ($shopId): void {
                $join->on('jobs.id', '=', 'movements.job_order_id')
                    ->where('jobs.shop_id', '=', $shopId);
            })
            ->where('movements.shop_id', $shopId);

        if ($startDate) {
            $movementsQuery->whereDate('movements.created_at', '>=', $startDate);
        }
        if ($endDate) {
            $movementsQuery->whereDate('movements.created_at', '<=', $endDate);
        }

        $movementRows = $movementsQuery
            ->orderByDesc('movements.created_at')
            ->orderByDesc('movements.id')
            ->get([
                'movements.id',
                'movements.created_at',
                'parts.name as part_name',
                'parts.part_number',
                'movements.type',
                'movements.quantity_change',
                'movements.balance_after',
                'movements.job_order_id',
                'jobs.id as valid_job_order_id',
                'jobs.job_order_number',
                'movements.created_by',
                'movements.note',
            ]);

        $movements = $movementRows->map(function ($movement): array {
            $validJobOrderId = $movement->valid_job_order_id !== null
                ? (int) $movement->valid_job_order_id
                : null;

            return [
                'id' => (int) $movement->id,
                'movement_date' => $movement->created_at,
                'part_name' => $movement->part_name,
                'part_number' => $movement->part_number,
                'type' => $movement->type,
                'quantity_change' => (int) $movement->quantity_change,
                'balance_after' => (int) $movement->balance_after,
                'job_order_reference' => $validJobOrderId === null ? null : $movement->job_order_number,
                'has_job_order_reference' => $validJobOrderId !== null,
                'has_invalid_job_order_reference' => $movement->job_order_id !== null && $validJobOrderId === null,
                'out_classification' => $movement->type !== 'OUT'
                    ? '—'
                    : ($validJobOrderId !== null
                        ? 'Repair-linked'
                        : ($movement->job_order_id === null ? 'Unlinked/manual' : 'Job reference unavailable')),
                'recorded_by' => $movement->created_by,
                'note' => $movement->note,
            ];
        });

        $outMovements = $movementRows->where('type', 'OUT');
        $repairLinkedOut = $outMovements->filter(fn ($movement) => $movement->valid_job_order_id !== null);
        $unlinkedOut = $outMovements->filter(fn ($movement) => $movement->job_order_id === null);
        $invalidJobReferenceOut = $outMovements->filter(fn ($movement) => (
            $movement->job_order_id !== null && $movement->valid_job_order_id === null
        ));

        $summarizeOutMovements = fn ($outRows): array => [
            'movement_count' => $outRows->count(),
            'quantity' => $outRows->sum(fn ($movement) => abs((int) $movement->quantity_change)),
        ];

        return [
            'summary' => [
                'total_items' => $items->count(),
                'active_items' => $items->where('is_active', true)->count(),
                'inactive_items' => $items->where('is_active', false)->count(),
                'low_stock_items' => $items->where('is_low_stock', true)->count(),
                'stock_quantity_by_unit' => $stockQuantityByUnit,
            ],
            'items' => $items->values()->all(),
            'movement_summary' => [
                'movement_count' => $movements->count(),
                'repair_linked_out' => $summarizeOutMovements($repairLinkedOut),
                'unlinked_manual_out' => $summarizeOutMovements($unlinkedOut),
                'invalid_job_reference_out' => $summarizeOutMovements($invalidJobReferenceOut),
            ],
            'movements' => $movements->values()->all(),
        ];
    }

    private function buildTechnicianAnalytics(
        int $shopId,
        ?string $startDate,
        ?string $endDate,
        ?string $serviceTypeFilter,
        ?string $serviceCategoryFilter,
        ?string $serviceItemFilter
    ): array {
        $technicians = Technician::query()
            ->where('shop_id', $shopId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Technician $technician) => [
                'technician_id' => $technician->id,
                'technician_name' => $technician->name,
                'successful_jobs' => 0,
                'average_turnaround_days' => 0,
                'rto_jobs' => 0,
                'rto_released_jobs' => 0,
                'current_jobs' => 0,
            ])
            ->keyBy('technician_id')
            ->all();

        if (empty($technicians)) {
            return ['performance' => [], 'workload' => []];
        }

        $applyServiceFilters = function ($query) use (
            $serviceTypeFilter,
            $serviceCategoryFilter,
            $serviceItemFilter
        ): void {
            if ($serviceTypeFilter) {
                $query->where('job_orders.service_type', $serviceTypeFilter);
            }
            if ($serviceCategoryFilter) {
                $query->where('job_orders.service_category_id', $serviceCategoryFilter);
            }
            if ($serviceItemFilter) {
                $query->where('job_orders.service_item_id', $serviceItemFilter);
            }
        };
        $assignedJobs = fn () => JobOrder::query()
            ->join('technicians', 'technicians.id', '=', 'job_orders.technician_id')
            ->where('job_orders.shop_id', $shopId)
            ->where('technicians.shop_id', $shopId);

        $successfulJobs = $assignedJobs()
            ->where('job_orders.current_status', 'PICKED_UP');
        $applyServiceFilters($successfulJobs);
        if ($startDate) {
            $successfulJobs->whereDate('job_orders.picked_up_at', '>=', $startDate);
        }
        if ($endDate) {
            $successfulJobs->whereDate('job_orders.picked_up_at', '<=', $endDate);
        }
        $successStats = $successfulJobs
            ->groupBy('technicians.id', 'technicians.name')
            ->select(
                'technicians.id as technician_id',
                DB::raw('COUNT(*) as successful_jobs'),
                DB::raw($this->turnaroundAverageExpression() . ' as average_turnaround_days')
            )
            ->get();

        foreach ($successStats as $stats) {
            $technicians[$stats->technician_id]['successful_jobs'] = (int) $stats->successful_jobs;
            $technicians[$stats->technician_id]['average_turnaround_days'] = round((float) ($stats->average_turnaround_days ?? 0), 1);
        }

        $rtoJobs = $assignedJobs()
            ->whereIn('job_orders.current_status', ['RTO', 'RTO_RELEASED']);
        $applyServiceFilters($rtoJobs);
        if ($startDate) {
            $rtoJobs->whereDate('job_orders.created_at', '>=', $startDate);
        }
        if ($endDate) {
            $rtoJobs->whereDate('job_orders.created_at', '<=', $endDate);
        }
        $rtoStats = $rtoJobs
            ->groupBy('technicians.id', 'job_orders.current_status')
            ->select('technicians.id as technician_id', 'job_orders.current_status', DB::raw('COUNT(*) as total'))
            ->get();

        foreach ($rtoStats as $stats) {
            $key = $stats->current_status === 'RTO' ? 'rto_jobs' : 'rto_released_jobs';
            $technicians[$stats->technician_id][$key] = (int) $stats->total;
        }

        $workload = $assignedJobs()
            ->whereNotIn('job_orders.current_status', ['PICKED_UP', 'RTO', 'RTO_RELEASED']);
        $applyServiceFilters($workload);
        $workloadStats = $workload
            ->groupBy('technicians.id')
            ->select('technicians.id as technician_id', DB::raw('COUNT(*) as current_jobs'))
            ->get();

        foreach ($workloadStats as $stats) {
            $technicians[$stats->technician_id]['current_jobs'] = (int) $stats->current_jobs;
        }

        return [
            'performance' => array_values(array_map(fn (array $technician) => [
                'technician_id' => $technician['technician_id'],
                'technician_name' => $technician['technician_name'],
                'successful_jobs' => $technician['successful_jobs'],
                'average_turnaround_days' => $technician['average_turnaround_days'],
                'rto_jobs' => $technician['rto_jobs'],
                'rto_released_jobs' => $technician['rto_released_jobs'],
            ], array_values($technicians))),
            'workload' => array_values(array_map(fn (array $technician) => [
                'technician_id' => $technician['technician_id'],
                'technician_name' => $technician['technician_name'],
                'current_jobs' => $technician['current_jobs'],
            ], array_values($technicians))),
        ];
    }

    private function turnaroundAverageExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => 'AVG(ABS(julianday(job_orders.picked_up_at) - julianday(job_orders.created_at)))',
            'mysql', 'mariadb' => 'AVG(ABS(TIMESTAMPDIFF(SECOND, job_orders.created_at, job_orders.picked_up_at)) / 86400.0)',
            'pgsql' => 'AVG(ABS(EXTRACT(EPOCH FROM (job_orders.picked_up_at - job_orders.created_at))) / 86400.0)',
            default => throw new \RuntimeException('Technician turnaround reporting is not supported by this database driver.'),
        };
    }

    private function buildPaymentReport(
        int $shopId,
        ?string $startDate,
        ?string $endDate,
        ?string $paymentMethodFilter,
        ?string $paymentTypeFilter
    ): array {
        $sameShopJobOrder = fn ($query) => $query
            ->withTrashed()
            ->where('job_orders.shop_id', $shopId);
        $jobOrderColumns = [
            'id',
            'shop_id',
            'job_order_number',
            'customer_name',
            'current_status',
            'total_cost',
            'amount_paid',
            'picked_up_at',
        ];
        $payments = JobOrderPayment::query()
            ->where('shop_id', $shopId)
            ->whereHas('jobOrder', $sameShopJobOrder)
            ->with([
                'jobOrder' => fn ($query) => $sameShopJobOrder($query)->select($jobOrderColumns),
            ]);

        if ($startDate) {
            $payments->whereDate('received_at', '>=', $startDate);
        }
        if ($endDate) {
            $payments->whereDate('received_at', '<=', $endDate);
        }
        if ($paymentMethodFilter) {
            $payments->where('payment_method', $paymentMethodFilter);
        }
        if ($paymentTypeFilter === 'PARTIAL_PAYMENT') {
            $payments->whereIn('payment_type', ['DOWN_PAYMENT', 'PARTIAL_PAYMENT']);
        } elseif ($paymentTypeFilter) {
            $payments->where('payment_type', $paymentTypeFilter);
        }

        $allPayments = (clone $payments)->get();
        $partialPayments = $allPayments->filter(fn (JobOrderPayment $payment) => $this->normalizedPaymentType($payment) === 'PARTIAL_PAYMENT');

        $currentPendingJobs = JobOrder::query()
            ->where('shop_id', $shopId)
            ->whereNotIn('current_status', ['RTO', 'RTO_RELEASED'])
            ->whereRaw('total_cost > amount_paid');
        $currentPendingBalanceJobs = (clone $currentPendingJobs)->count();
        $currentPendingBalances = round((float) (clone $currentPendingJobs)
            ->sum(DB::raw('CASE WHEN total_cost > amount_paid THEN total_cost - amount_paid ELSE 0 END')), 2);

        $paymentMethodBreakdown = $allPayments
            ->groupBy(fn (JobOrderPayment $payment) => $this->normalizedPaymentMethod($payment))
            ->map(fn ($items, $method) => [
                'payment_method' => $method,
                'records' => $items->count(),
                'amount' => round((float) $items->sum('amount'), 2),
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();

        $paymentTypeBreakdown = $allPayments
            ->groupBy(fn (JobOrderPayment $payment) => $this->normalizedPaymentType($payment))
            ->map(fn ($items, $type) => [
                'payment_type' => $type,
                'records' => $items->count(),
                'amount' => round((float) $items->sum('amount'), 2),
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();

        $partialPaymentRecords = $partialPayments
            ->map(fn (JobOrderPayment $payment) => $this->mapPaymentRecord($payment))
            ->values()
            ->all();

        $partialPaymentTotal = round((float) $partialPayments->sum('amount'), 2);
        $heldPartialPayments = round((float) $partialPayments
            ->filter(fn (JobOrderPayment $payment) => !in_array($payment->jobOrder?->current_status, ['PICKED_UP', 'RTO', 'RTO_RELEASED'], true))
            ->sum('amount'), 2);
        $appliedPartialPayments = round((float) $partialPayments
            ->filter(fn (JobOrderPayment $payment) => $payment->jobOrder?->current_status === 'PICKED_UP')
            ->sum('amount'), 2);
        $reviewPartialPayments = round((float) $partialPayments
            ->filter(fn (JobOrderPayment $payment) => in_array($payment->jobOrder?->current_status, ['RTO', 'RTO_RELEASED'], true))
            ->sum('amount'), 2);

        $refundJobOrder = fn ($query) => $query
            ->withTrashed()
            ->where('job_orders.shop_id', $shopId);
        $refundJobOrderColumns = ['id', 'shop_id', 'job_order_number', 'customer_name'];
        $pendingRefunds = JobOrderRefund::query()
            ->where('shop_id', $shopId)
            ->whereHas('jobOrder', $refundJobOrder)
            ->where('status', JobOrderRefund::STATUS_PENDING)
            ->when($startDate, fn ($query) => $query->whereDate('initiated_at', '>=', $startDate))
            ->when($endDate, fn ($query) => $query->whereDate('initiated_at', '<=', $endDate));
        $issuedRefunds = JobOrderRefund::query()
            ->where('shop_id', $shopId)
            ->whereHas('jobOrder', $refundJobOrder)
            ->where('status', JobOrderRefund::STATUS_ISSUED)
            ->when($startDate, fn ($query) => $query->whereDate('issued_at', '>=', $startDate))
            ->when($endDate, fn ($query) => $query->whereDate('issued_at', '<=', $endDate));
        $pendingRefundTotal = round((float) (clone $pendingRefunds)->sum('amount'), 2);
        $issuedRefundTotal = round((float) (clone $issuedRefunds)->sum('amount'), 2);
        $refundRecords = (clone $pendingRefunds)
            ->with([
                'jobOrder' => fn ($query) => $refundJobOrder($query)->select($refundJobOrderColumns),
            ])
            ->latest('initiated_at')
            ->get()
            ->concat((clone $issuedRefunds)
                ->with([
                    'jobOrder' => fn ($query) => $refundJobOrder($query)->select($refundJobOrderColumns),
                ])
                ->latest('issued_at')
                ->get())
            ->sortByDesc(fn (JobOrderRefund $refund) => (
                $refund->status === JobOrderRefund::STATUS_ISSUED
                    ? $refund->issued_at
                    : $refund->initiated_at
            )?->getTimestamp() ?? 0)
            ->take(30)
            ->map(fn (JobOrderRefund $refund) => [
                'id' => $refund->id,
                'job_order_number' => $refund->jobOrder?->job_order_number,
                'customer_name' => $refund->jobOrder?->customer_name,
                'amount' => (float) $refund->amount,
                'status' => $refund->status,
                'reason' => $refund->reason,
                'refund_method' => $refund->refund_method,
                'reference_number' => $refund->reference_number,
                'initiated_at' => $refund->initiated_at?->format('M d, Y h:i A'),
                'issued_at' => $refund->issued_at?->format('M d, Y h:i A'),
            ])
            ->values()
            ->all();

        return [
            'paymentSummary' => [
                'total_records' => $allPayments->count(),
                'total_amount' => round((float) $allPayments->sum('amount'), 2),
                'current_pending_balances' => $currentPendingBalances,
                'current_pending_balance_jobs' => $currentPendingBalanceJobs,
                'partial_payment_total' => $partialPaymentTotal,
                'held_partial_payments' => $heldPartialPayments,
                'applied_partial_payments' => $appliedPartialPayments,
                'review_partial_payments' => $reviewPartialPayments,
                'down_payment_total' => $partialPaymentTotal,
                'held_down_payments' => $heldPartialPayments,
                'applied_down_payments' => $appliedPartialPayments,
                'review_down_payments' => $reviewPartialPayments,
                'pending_refund_obligations' => $pendingRefundTotal,
                'issued_refunds' => $issuedRefundTotal,
                'net_cash_retained' => round((float) $allPayments->sum('amount') - $issuedRefundTotal, 2),
            ],
            'paymentMethodBreakdown' => $paymentMethodBreakdown,
            'paymentTypeBreakdown' => $paymentTypeBreakdown,
            'partialPaymentRecords' => $partialPaymentRecords,
            'downPaymentRecords' => $partialPaymentRecords,
            'paymentRecords' => (clone $payments)
                ->latest('received_at')
                ->latest('id')
                ->take(30)
                ->get()
                ->map(fn (JobOrderPayment $payment) => $this->mapPaymentRecord($payment))
                ->values()
                ->all(),
            'refundRecords' => $refundRecords,
        ];
    }

    private function mapPaymentRecord(JobOrderPayment $payment): array
    {
        $status = $payment->jobOrder?->current_status;
        $partialPaymentState = null;

        if ($this->normalizedPaymentType($payment) === 'PARTIAL_PAYMENT') {
            $partialPaymentState = match ($status) {
                'PICKED_UP' => 'APPLIED',
                'RTO', 'RTO_RELEASED' => 'NEEDS_REVIEW',
                default => 'HELD',
            };
        }

        return [
            'id' => $payment->id,
            'job_order_id' => $payment->job_order_id,
            'job_order_number' => $payment->jobOrder?->job_order_number,
            'customer_name' => $payment->jobOrder?->customer_name,
            'job_status' => $status,
            'amount' => (float) $payment->amount,
            'payment_type' => $this->normalizedPaymentType($payment),
            'payment_method' => $this->normalizedPaymentMethod($payment),
            'reference_number' => $payment->reference_number,
            'notes' => $payment->notes,
            'received_by' => $payment->received_by,
            'received_at' => $payment->received_at,
            'received_at_formatted' => $payment->received_at?->format('M d, Y h:i A') ?? $payment->created_at?->format('M d, Y h:i A'),
            'payment_date_basis' => $payment->received_at ? 'received_at' : 'created_at (display fallback)',
            'received_at_is_fallback' => $payment->received_at === null,
            'partial_payment_state' => $partialPaymentState,
            'down_payment_state' => $partialPaymentState,
        ];
    }

    private function mapJobOrderRecord(JobOrder $jobOrder): array
    {
        $deviceLabel = trim(collect([
            $jobOrder->brand,
            $jobOrder->model,
        ])->filter()->implode(' '));

        return [
            'id' => $jobOrder->id,
            'created_at_formatted' => $jobOrder->created_at?->format('M d, Y h:i A'),
            'job_order_number' => $jobOrder->job_order_number,
            'customer_name' => $jobOrder->customer_name,
            'customer_phone' => $jobOrder->customer_phone,
            'device_type' => $jobOrder->device_type,
            'service_category_id' => $jobOrder->service_category_id,
            'service_category_name' => $jobOrder->serviceCategory?->name ?? $jobOrder->device_type,
            'service_item_id' => $jobOrder->service_item_id,
            'service_item_name' => $jobOrder->serviceItem?->name,
            'device_label' => $deviceLabel !== '' ? "{$deviceLabel} ({$jobOrder->device_type})" : $jobOrder->device_type,
            'current_status' => $jobOrder->current_status,
            'current_status_label' => $this->formatStatusLabel($jobOrder->current_status),
            'service_type' => $jobOrder->service_type ?? 'STANDARD',
            'service_type_label' => $this->formatServiceTypeLabel($jobOrder->service_type ?? 'STANDARD'),
            'priority' => $jobOrder->priority,
            'technician_name' => $jobOrder->technician?->name,
            'estimated_completion_formatted' => $jobOrder->estimated_completion?->format('M d, Y h:i A'),
            'total_cost' => (float) $jobOrder->total_cost,
            'amount_paid' => (float) $jobOrder->amount_paid,
            'balance' => (float) $jobOrder->balance,
            'refunds_pending' => round((float) $jobOrder->refunds()
                ->where('shop_id', $jobOrder->shop_id)
                ->where('status', JobOrderRefund::STATUS_PENDING)
                ->sum('amount'), 2),
            'refunds_issued' => round((float) $jobOrder->refunds()
                ->where('shop_id', $jobOrder->shop_id)
                ->where('status', JobOrderRefund::STATUS_ISSUED)
                ->sum('amount'), 2),
        ];
    }

    private function normalizedPaymentType(JobOrderPayment $payment): string
    {
        if ($payment->payment_type === 'DOWN_PAYMENT') {
            return 'PARTIAL_PAYMENT';
        }

        return is_string($payment->payment_type) && trim($payment->payment_type) !== ''
            ? $payment->payment_type
            : 'UNSPECIFIED';
    }

    private function normalizedPaymentMethod(JobOrderPayment $payment): string
    {
        return is_string($payment->payment_method) && trim($payment->payment_method) !== ''
            ? $payment->payment_method
            : 'UNSPECIFIED';
    }

    private function formatPaymentMethodLabel(string $method): string
    {
        return match ($method) {
            'CASH' => 'Cash',
            'GCASH' => 'GCash',
            'BANK_TRANSFER' => 'Bank Transfer',
            'CARD' => 'Card',
            default => ucwords(strtolower(str_replace('_', ' ', $method))),
        };
    }

    private function formatStatusLabel(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return $status;
        }

        if ($status === 'RTO_RELEASED') {
            return 'RTO RELEASED';
        }

        if ($status === 'PICKED_UP') {
            return 'RELEASED';
        }

        return str_replace('_', ' ', $status);
    }

    private function formatServiceTypeLabel(?string $serviceType): ?string
    {
        if ($serviceType === null || $serviceType === '') {
            return $serviceType;
        }

        if ($serviceType === 'WARRANTY_SERVICE') {
            return 'Under Warranty';
        }

        return 'Standard Repair';
    }

    private function activeServiceCatalog(int $shopId): array
    {
        return ServiceCategory::query()
            ->where('shop_id', $shopId)
            ->where('is_active', true)
            ->with(['services' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'services' => $category->services->map(fn (ServiceItem $service) => [
                    'id' => $service->id,
                    'service_category_id' => $service->service_category_id,
                    'name' => $service->name,
                ])->all(),
            ])
            ->all();
    }

    private function serviceCategoryLabel(int $shopId, mixed $categoryId): ?string
    {
        if (!$categoryId) {
            return null;
        }

        return ServiceCategory::query()
            ->where('shop_id', $shopId)
            ->where('id', $categoryId)
            ->value('name');
    }

    private function serviceItemLabel(int $shopId, mixed $serviceId): ?string
    {
        if (!$serviceId) {
            return null;
        }

        return ServiceItem::query()
            ->where('shop_id', $shopId)
            ->where('id', $serviceId)
            ->value('name');
    }
}
