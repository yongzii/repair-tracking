<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\JobOrderPayment;
use App\Models\JobOrderRefund;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
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
            $appName = $this->sanitizeCsvText((string) config('app.name', 'Repair Service Management System'));
            $includeOperations = in_array($reportType, ['operations', 'all'], true);
            $includePayments = in_array($reportType, ['payments', 'all'], true);

            fputcsv($handle, ["{$appName} " . ucfirst($reportType) . ' Reports']);
            fputcsv($handle, ['Generated At', now()->toDateTimeString()]);
            fputcsv($handle, ['Filters', sprintf('start=%s, end=%s, status=%s, service_type=%s, category=%s, service=%s, payment_method=%s, payment_type=%s', $filters['start_date'] ?? '-', $filters['end_date'] ?? '-', $filters['status_label'] ?? 'ALL', $filters['service_type_label'] ?? 'ALL', $filters['service_category_label'] ?? 'ALL', $filters['service_item_label'] ?? 'ALL', $filters['payment_method'] ?? 'ALL', $filters['payment_type'] ?? 'ALL')]);
            fputcsv($handle, []);

            if ($includeOperations) {
                fputcsv($handle, ['Operations Summary']);
                fputcsv($handle, ['Jobs This Month', $report['thisMonth']]);
                fputcsv($handle, ['Jobs Last Month', $report['lastMonth']]);
                fputcsv($handle, ['Month Growth (%)', $report['monthGrowth']]);
                fputcsv($handle, ['Completed Repairs', $report['totalCompleted']]);
                fputcsv($handle, ['Pending Jobs', $report['totalPending']]);
                fputcsv($handle, ['Avg Turnaround (days)', $report['avgTurnaround']]);
                fputcsv($handle, ['Fastest (days)', $report['minTurnaround']]);
                fputcsv($handle, ['Slowest (days)', $report['maxTurnaround']]);
                fputcsv($handle, []);

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
                fputcsv($handle, ['Original Repair Costs', $report['totalRevenue']]);
                fputcsv($handle, ['Total Collected', $report['totalCollected']]);
                fputcsv($handle, ['Pending RTO Refund Obligations', $report['pendingRtoRefunds']]);
                fputcsv($handle, ['RTO Refunds Issued', $report['issuedRtoRefunds']]);
                fputcsv($handle, ['Net Retained After Issued Refunds', $report['netRetained']]);
                fputcsv($handle, ['Pending Receivables', $report['pendingReceivables']]);
                fputcsv($handle, ['Estimated Gross Profit', $report['estimatedGrossProfit']]);
                fputcsv($handle, ['Gross Profit Margin (%)', $report['grossProfitMargin']]);
                fputcsv($handle, []);

                fputcsv($handle, ['Payment Collections']);
                fputcsv($handle, ['Total Payments Recorded', $report['paymentSummary']['total_amount']]);
                fputcsv($handle, ['Payment Records', $report['paymentSummary']['total_records']]);
                fputcsv($handle, ['Partial Payments', $report['paymentSummary']['partial_payment_total']]);
                fputcsv($handle, ['Held Partial Payments', $report['paymentSummary']['held_partial_payments']]);
                fputcsv($handle, ['Applied Partial Payments', $report['paymentSummary']['applied_partial_payments']]);
                fputcsv($handle, ['Partial Payments Needing Review', $report['paymentSummary']['review_partial_payments']]);
                fputcsv($handle, ['Pending Refund Obligations', $report['paymentSummary']['pending_refund_obligations']]);
                fputcsv($handle, ['Refunds Issued', $report['paymentSummary']['issued_refunds']]);
                fputcsv($handle, ['Net Cash Retained in Payment Date Range', $report['paymentSummary']['net_cash_retained']]);
                fputcsv($handle, []);

                fputcsv($handle, ['RTO Refund Ledger']);
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

                fputcsv($handle, ['Recent Payment Records']);
                fputcsv($handle, ['Date', 'Job Order', 'Customer', 'Type', 'Method', 'Amount', 'Reference', 'Received By', 'Notes']);
                foreach ($report['paymentRecords'] as $payment) {
                    fputcsv($handle, [
                        $payment['received_at_formatted'],
                        $payment['job_order_number'],
                        $payment['customer_name'],
                        $payment['payment_type'],
                        $payment['payment_method'],
                        $payment['amount'],
                        $payment['reference_number'],
                        $payment['received_by'],
                        $payment['notes'],
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

        return in_array($type, ['operations', 'payments', 'all'], true) ? $type : 'all';
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

        if ($startDate) {
            $baseQuery->whereDate('job_orders.created_at', '>=', $startDate);
        }
        if ($endDate) {
            $baseQuery->whereDate('job_orders.created_at', '<=', $endDate);
        }
        if ($statusFilter === 'OPEN') {
            $baseQuery->whereNotIn('job_orders.current_status', ['PICKED_UP', 'RTO', 'RTO_RELEASED', 'UNREPAIRABLE']);
        } elseif ($statusFilter) {
            $baseQuery->where('job_orders.current_status', $statusFilter);
        }
        if ($serviceTypeFilter) {
            $baseQuery->where('job_orders.service_type', $serviceTypeFilter);
        }
        if ($serviceCategoryFilter) {
            $baseQuery->where('job_orders.service_category_id', $serviceCategoryFilter);
        }
        if ($serviceItemFilter) {
            $baseQuery->where('job_orders.service_item_id', $serviceItemFilter);
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

        $totalCompleted = (clone $baseQuery)->whereIn('current_status', ['PICKED_UP', 'RTO', 'RTO_RELEASED', 'UNREPAIRABLE'])->count();
        $totalPending = (clone $baseQuery)->whereNotIn('current_status', ['PICKED_UP', 'RTO', 'RTO_RELEASED', 'UNREPAIRABLE'])->count();

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

        $turnaroundStats = (clone $baseQuery)->whereIn('current_status', ['PICKED_UP', 'RTO', 'RTO_RELEASED', 'UNREPAIRABLE'])
            ->get()
            ->map(fn ($job) => $job->created_at->diffInDays($job->updated_at, true))
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
            ->whereNotIn('current_status', ['RTO', 'UNREPAIRABLE', 'RTO_RELEASED'])
            ->sum(DB::raw('CASE WHEN total_cost > amount_paid THEN total_cost - amount_paid ELSE 0 END')), 2);
        $partsCostTotal = round((float) (clone $baseQuery)->sum('parts_cost'), 2);
        $estimatedGrossProfit = round($totalRevenue - $partsCostTotal, 2);
        $grossProfitMargin = $totalRevenue > 0 ? round(($estimatedGrossProfit / $totalRevenue) * 100, 1) : 0;
        $filteredJobOrders = (clone $baseQuery)
            ->with(['technician:id,name', 'serviceCategory:id,name', 'serviceItem:id,name'])
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

        return [
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
            'estimatedGrossProfit' => $estimatedGrossProfit,
            'grossProfitMargin' => $grossProfitMargin,
            'filteredJobOrders' => $filteredJobOrders,
            ...$paymentReport,
        ];
    }

    private function buildPaymentReport(
        int $shopId,
        ?string $startDate,
        ?string $endDate,
        ?string $paymentMethodFilter,
        ?string $paymentTypeFilter
    ): array {
        $payments = JobOrderPayment::query()
            ->where('shop_id', $shopId)
            ->with('jobOrder:id,job_order_number,customer_name,current_status,total_cost,amount_paid,picked_up_at');

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

        $paymentMethodBreakdown = $allPayments
            ->groupBy(fn (JobOrderPayment $payment) => $payment->payment_method ?: 'UNSPECIFIED')
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
            ->filter(fn (JobOrderPayment $payment) => !in_array($payment->jobOrder?->current_status, ['PICKED_UP', 'RTO', 'RTO_RELEASED', 'UNREPAIRABLE'], true))
            ->sum('amount'), 2);
        $appliedPartialPayments = round((float) $partialPayments
            ->filter(fn (JobOrderPayment $payment) => $payment->jobOrder?->current_status === 'PICKED_UP')
            ->sum('amount'), 2);
        $reviewPartialPayments = round((float) $partialPayments
            ->filter(fn (JobOrderPayment $payment) => in_array($payment->jobOrder?->current_status, ['RTO', 'RTO_RELEASED', 'UNREPAIRABLE'], true))
            ->sum('amount'), 2);

        $refunds = JobOrderRefund::query()
            ->where('shop_id', $shopId)
            ->with('jobOrder:id,job_order_number,customer_name')
            ->when($startDate, fn ($query) => $query->whereDate('initiated_at', '>=', $startDate))
            ->when($endDate, fn ($query) => $query->whereDate('initiated_at', '<=', $endDate));
        $pendingRefunds = (clone $refunds)->where('status', JobOrderRefund::STATUS_PENDING);
        $issuedRefunds = JobOrderRefund::query()
            ->where('shop_id', $shopId)
            ->where('status', JobOrderRefund::STATUS_ISSUED)
            ->when($startDate, fn ($query) => $query->whereDate('issued_at', '>=', $startDate))
            ->when($endDate, fn ($query) => $query->whereDate('issued_at', '<=', $endDate));
        $issuedRefundTotal = round((float) (clone $issuedRefunds)->sum('amount'), 2);

        return [
            'paymentSummary' => [
                'total_records' => $allPayments->count(),
                'total_amount' => round((float) $allPayments->sum('amount'), 2),
                'partial_payment_total' => $partialPaymentTotal,
                'held_partial_payments' => $heldPartialPayments,
                'applied_partial_payments' => $appliedPartialPayments,
                'review_partial_payments' => $reviewPartialPayments,
                'down_payment_total' => $partialPaymentTotal,
                'held_down_payments' => $heldPartialPayments,
                'applied_down_payments' => $appliedPartialPayments,
                'review_down_payments' => $reviewPartialPayments,
                'pending_refund_obligations' => round((float) $pendingRefunds->sum('amount'), 2),
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
            'refundRecords' => (clone $refunds)
                ->latest('initiated_at')
                ->take(30)
                ->get()
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
                ->all(),
        ];
    }

    private function mapPaymentRecord(JobOrderPayment $payment): array
    {
        $status = $payment->jobOrder?->current_status;
        $partialPaymentState = null;

        if ($this->normalizedPaymentType($payment) === 'PARTIAL_PAYMENT') {
            $partialPaymentState = match ($status) {
                'PICKED_UP' => 'APPLIED',
                'UNREPAIRABLE', 'RTO', 'RTO_RELEASED' => 'NEEDS_REVIEW',
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
            'payment_method' => $payment->payment_method ?: 'UNSPECIFIED',
            'reference_number' => $payment->reference_number,
            'notes' => $payment->notes,
            'received_by' => $payment->received_by,
            'received_at' => $payment->received_at,
            'received_at_formatted' => $payment->received_at?->format('M d, Y h:i A') ?? $payment->created_at?->format('M d, Y h:i A'),
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
            'refunds_pending' => round((float) $jobOrder->refunds()->where('status', JobOrderRefund::STATUS_PENDING)->sum('amount'), 2),
            'refunds_issued' => round((float) $jobOrder->refunds()->where('status', JobOrderRefund::STATUS_ISSUED)->sum('amount'), 2),
        ];
    }

    private function normalizedPaymentType(JobOrderPayment $payment): string
    {
        return $payment->payment_type === 'DOWN_PAYMENT'
            ? 'PARTIAL_PAYMENT'
            : ($payment->payment_type ?: 'UNSPECIFIED');
    }

    private function formatStatusLabel(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return $status;
        }

        if ($status === 'UNREPAIRABLE') {
            return 'RTO';
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
