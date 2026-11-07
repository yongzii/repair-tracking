<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name', 'Repair Service Management System') }} Reports</title>
    <style>
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            margin: 0 0 32px;
            color: #111827;
            font-size: 11px;
        }
        h1 {
            margin: 0;
            color: #0f172a;
            font-size: 22px;
        }
        h2 {
            margin: 22px 0 6px;
            padding-bottom: 5px;
            border-bottom: 2px solid #0f766e;
            color: #0f172a;
            font-size: 15px;
        }
        h3, h4 {
            margin: 16px 0 5px;
            color: #334155;
        }
        .meta {
            margin: 5px 0 18px;
            font-size: 10px;
            color: #6b7280;
            line-height: 1.5;
        }
        .report-header {
            border-bottom: 3px solid #0f766e;
            margin-bottom: 14px;
            padding-bottom: 8px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin: 16px 0 20px;
        }
        .card {
            border: 1px solid #e5e7eb;
            padding: 9px;
            background: #f8fafc;
            page-break-inside: avoid;
        }
        .card strong {
            display: block;
            color: #475569;
            font-size: 10px;
        }
        .card div {
            margin-top: 3px;
            font-size: 14px;
            font-weight: 700;
            text-align: right;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            table-layout: fixed;
            page-break-inside: auto;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 5px 6px;
            font-size: 9px;
            text-align: left;
            overflow-wrap: anywhere;
            vertical-align: top;
        }
        th {
            background: #e2e8f0;
            color: #0f172a;
            font-weight: 700;
        }
        td:nth-last-child(-n+3), th:nth-last-child(-n+3) {
            text-align: right;
        }
        .footer {
            position: fixed;
            right: 0;
            bottom: -7mm;
            left: 0;
            border-top: 1px solid #cbd5e1;
            padding-top: 3px;
            color: #64748b;
            font-size: 9px;
            text-align: center;
        }
        @media print {
            @page { size: A4 landscape; margin: 10mm; }
            .no-print { display: none; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
            .footer { display: block; }
        }
    </style>
</head>
<body>
    @php
        $reportType = $reportType ?? 'all';
        $includeOperations = in_array($reportType, ['operations', 'all'], true);
        $includeInventory = in_array($reportType, ['inventory', 'operations', 'all'], true);
        $includePayments = in_array($reportType, ['payments', 'all'], true);
    @endphp

    <div class="report-header">
    <h1>{{ $shop_name ?? config('app.name', 'Repair Service Management System') }}</h1>
    <div>{{ ucfirst($reportType) }} Reports</div>
    <div class="meta">
        Generated: {{ now()->format('Y-m-d H:i') }} ·
        Date range: {{ $filters['start_date'] ?: 'All dates' }} to {{ $filters['end_date'] ?: 'All dates' }}<br>
        Filters: Status {{ $filters['status_label'] ?? 'ALL' }} · Service type {{ $filters['service_type_label'] ?? 'ALL' }} · Category {{ $filters['service_category_label'] ?? 'ALL' }} · Service {{ $filters['service_item_label'] ?? 'ALL' }} · Payment method {{ $filters['payment_method'] ?? 'ALL' }} · Payment type {{ $filters['payment_type'] ?? 'ALL' }}
    </div>
    </div>
    <div class="no-print" style="margin-bottom: 16px;">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
    </div>

    @if ($includeOperations)
    <div class="grid">
        <div class="card">
            <strong>Jobs This Month</strong>
            <div>{{ $thisMonth }}</div>
        </div>
        <div class="card">
            <strong>Jobs Last Month</strong>
            <div>{{ $lastMonth }}</div>
        </div>
        <div class="card">
            <strong>Month Growth</strong>
            <div>{{ $monthGrowth }}%</div>
        </div>
        <div class="card">
            <strong>Completed Repairs</strong>
            <div>{{ $totalCompleted }}</div>
            <small>Date basis: job creation date (created_at). Date scope: applied date range when provided; otherwise, all dates.</small>
        </div>
        <div class="card">
            <strong>Pending Jobs</strong>
            <div>{{ $totalPending }}</div>
        </div>
        <div class="card">
            <strong>Avg Turnaround</strong>
            <div>{{ $avgTurnaround }} days</div>
        </div>
    </div>
    @endif

    @if ($includePayments)
    <div class="grid">
        <div class="card">
            <strong>Customer Job Order Value</strong>
            <div>₱{{ number_format($totalRevenue, 2) }}</div>
        </div>
        <div class="card">
            <strong>Total Collected</strong>
            <div>₱{{ number_format($totalCollected, 2) }}</div>
        </div>
        <div class="card">
            <strong>Pending Receivables</strong>
            <div>₱{{ number_format($pendingReceivables, 2) }}</div>
        </div>
        <div class="card">
            <strong>Pending RTO Refund Obligations</strong>
            <div>₱{{ number_format($pendingRtoRefunds, 2) }}</div>
        </div>
        <div class="card">
            <strong>RTO Refunds Issued</strong>
            <div>₱{{ number_format($issuedRtoRefunds, 2) }}</div>
        </div>
        <div class="card">
            <strong>Net Retained</strong>
            <div>₱{{ number_format($netRetained, 2) }}</div>
        </div>
    </div>
    @endif

    @if ($includeOperations)
    <h2>Technician Performance (Current Assignment)</h2>
    <p>Results reflect the currently stored technician assignment, not historical assignment. Successful jobs use pickup date (picked_up_at); RTO outcomes use job creation date (created_at). Date scope: applied date range when provided; otherwise, all dates.</p>
    <table>
        <thead>
            <tr>
                <th>Technician</th>
                <th>Successful Jobs</th>
                <th>Avg Turnaround (days)</th>
                <th>RTO</th>
                <th>RTO Released</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($technicianPerformance as $technician)
                <tr>
                    <td>{{ $technician['technician_name'] }}</td>
                    <td>{{ $technician['successful_jobs'] }}</td>
                    <td>{{ $technician['average_turnaround_days'] }}</td>
                    <td>{{ $technician['rto_jobs'] }}</td>
                    <td>{{ $technician['rto_released_jobs'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">No technician records are available for this shop.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    @if (count($technicianPerformance) > 0 && !collect($technicianPerformance)->contains(fn ($technician) => $technician['successful_jobs'] > 0 || $technician['rto_jobs'] > 0 || $technician['rto_released_jobs'] > 0))
        <p>No assigned jobs match the selected services and reporting period.</p>
    @endif

    <h2>Current Technician Workload</h2>
    <p>Current snapshot; excludes picked-up and both RTO terminal statuses. Date filters do not apply.</p>
    <table>
        <thead>
            <tr>
                <th>Technician</th>
                <th>Currently Assigned Jobs</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($currentTechnicianWorkload as $technician)
                <tr>
                    <td>{{ $technician['technician_name'] }}</td>
                    <td>{{ $technician['current_jobs'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="2">No technician records are available for this shop.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    @if (count($currentTechnicianWorkload) > 0 && !collect($currentTechnicianWorkload)->contains(fn ($technician) => $technician['current_jobs'] > 0))
        <p>No non-terminal assigned jobs match the selected services.</p>
    @endif

    @endif

    @if ($includeInventory)
    <h2>Inventory Reports</h2>
    <h3>Current Stock Snapshot</h3>
    <p>Current inventory snapshot; report date filters do not apply. Low stock uses stock_quantity ≤ reorder_level and includes inactive items.</p>
    <div class="grid">
        <div class="card"><strong>Total Inventory Items</strong><div>{{ $inventoryReport['summary']['total_items'] }}</div></div>
        <div class="card"><strong>Active Inventory Items</strong><div>{{ $inventoryReport['summary']['active_items'] }}</div></div>
        <div class="card"><strong>Inactive Inventory Items</strong><div>{{ $inventoryReport['summary']['inactive_items'] }}</div></div>
        <div class="card"><strong>Low Stock Items</strong><div>{{ $inventoryReport['summary']['low_stock_items'] }}</div></div>
    </div>
    <h4>Total Current Stock Quantity by Unit</h4>
    <table>
        <thead><tr><th>Unit</th><th>Quantity</th></tr></thead>
        <tbody>
            @forelse ($inventoryReport['summary']['stock_quantity_by_unit'] as $unitTotal)
                <tr><td>{{ $unitTotal['unit'] }}</td><td>{{ $unitTotal['quantity'] }}</td></tr>
            @empty
                <tr><td colspan="2">No inventory quantities to summarize.</td></tr>
            @endforelse
        </tbody>
    </table>
    <table>
        <thead>
            <tr>
                <th>Part Name</th><th>Part Number</th><th>Category</th><th>Unit</th>
                <th>Current Stock</th><th>Reorder Level</th><th>Stock Status</th><th>Active Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($inventoryReport['items'] as $item)
                <tr>
                    <td>{{ $item['part_name'] }}</td><td>{{ $item['part_number'] ?? '—' }}</td>
                    <td>{{ $item['category'] ?? '—' }}</td><td>{{ $item['unit'] }}</td>
                    <td>{{ $item['stock_quantity'] }}</td><td>{{ $item['reorder_level'] }}</td>
                    <td>{{ $item['stock_status'] }}</td><td>{{ $item['active_status'] }}</td>
                </tr>
            @empty
                <tr><td colspan="8">No inventory items found for this shop.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3>Stock Movement History</h3>
    <p>Movement date basis: stock_movements.created_at. Applied report date range when provided; otherwise, all dates.</p>
    <p>OUT quantities are recorded issues, not proof of actual consumption. IN movements may include corrections or reversals; they are not treated as purchases and no net usage is calculated.</p>
    <table>
        <thead><tr><th>Recorded OUT Category</th><th>Movements</th><th>Quantity (units)</th></tr></thead>
        <tbody>
            <tr>
                <td>Recorded Parts Issued to Repairs (valid same-shop jobs)</td>
                <td>{{ $inventoryReport['movement_summary']['repair_linked_out']['movement_count'] }}</td>
                <td>{{ $inventoryReport['movement_summary']['repair_linked_out']['quantity'] }}</td>
            </tr>
            <tr>
                <td>Unlinked / Manual OUT</td>
                <td>{{ $inventoryReport['movement_summary']['unlinked_manual_out']['movement_count'] }}</td>
                <td>{{ $inventoryReport['movement_summary']['unlinked_manual_out']['quantity'] }}</td>
            </tr>
            <tr>
                <td>OUT with unavailable job reference</td>
                <td>{{ $inventoryReport['movement_summary']['invalid_job_reference_out']['movement_count'] }}</td>
                <td>{{ $inventoryReport['movement_summary']['invalid_job_reference_out']['quantity'] }}</td>
            </tr>
        </tbody>
    </table>
    <table>
        <thead>
            <tr>
                <th>Movement Date</th><th>Part Name</th><th>Part Number</th><th>Type</th>
                <th>OUT Classification</th><th>Signed Quantity Change</th><th>Balance After</th>
                <th>Related Job Order</th><th>Recorded By</th><th>Note</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($inventoryReport['movements'] as $movement)
                <tr>
                    <td>{{ $movement['movement_date'] }}</td><td>{{ $movement['part_name'] }}</td>
                    <td>{{ $movement['part_number'] ?? '—' }}</td><td>{{ $movement['type'] }}</td>
                    <td>{{ $movement['out_classification'] }}</td><td>{{ $movement['quantity_change'] }}</td>
                    <td>{{ $movement['balance_after'] }}</td><td>{{ $movement['job_order_reference'] ?? '—' }}</td>
                    <td>{{ $movement['recorded_by'] ?? '—' }}</td><td>{{ $movement['note'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="10">No stock movements found for the applied date scope.</td></tr>
            @endforelse
        </tbody>
    </table>

    @endif

    @if ($includeOperations)
    <h2>Filtered Job Orders</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Job Order</th>
                <th>Customer</th>
                <th>Phone</th>
                <th>Device</th>
                <th>Service</th>
                <th>Status</th>
                <th>Service Type</th>
                <th>Technician</th>
                <th>Due</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($filteredJobOrders as $jobOrder)
                <tr>
                    <td>{{ $jobOrder['created_at_formatted'] }}</td>
                    <td>{{ $jobOrder['job_order_number'] }}</td>
                    <td>{{ $jobOrder['customer_name'] }}</td>
                    <td>{{ $jobOrder['customer_phone'] ?? '-' }}</td>
                    <td>{{ $jobOrder['device_label'] }}</td>
                    <td>
                        {{ $jobOrder['service_category_name'] ?? $jobOrder['device_type'] }}<br>
                        <small>{{ $jobOrder['service_item_name'] ?? 'Unspecified service' }}</small>
                    </td>
                    <td>{{ $jobOrder['current_status_label'] ?? str_replace('_', ' ', $jobOrder['current_status']) }}</td>
                    <td>{{ $jobOrder['service_type_label'] ?? str_replace('_', ' ', $jobOrder['service_type'] ?? 'STANDARD') }}</td>
                    <td>{{ $jobOrder['technician_name'] ?? 'Unassigned' }}</td>
                    <td>{{ $jobOrder['estimated_completion_formatted'] ?? 'Not set' }}</td>
                    <td>₱{{ number_format($jobOrder['total_cost'], 2) }}</td>
                    <td>₱{{ number_format($jobOrder['amount_paid'], 2) }}</td>
                    <td>₱{{ number_format($jobOrder['balance'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="13">No job orders match the selected filters.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Top Services</h2>
    <table>
        <thead>
            <tr>
                <th>Device Category</th>
                <th>Service</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($topServices as $service)
                <tr>
                    <td>{{ $service['service_category_name'] }}</td>
                    <td>{{ $service['service_item_name'] }}</td>
                    <td>{{ $service['total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Service Category Breakdown</h2>
    <table>
        <thead>
            <tr>
                <th>Device Category</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($serviceCategoryBreakdown as $category)
                <tr>
                    <td>{{ $category['service_category_name'] }}</td>
                    <td>{{ $category['total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if ($includePayments)
    <h2>Customer Collections</h2>
    <p>Recorded customer collections are the sum of payment transaction amounts and are not verified bank settlements. Collections and payment history use received_at. If received_at is blank, created_at is shown for display only.</p>
    <div class="grid">
        <div class="card">
            <strong>Recorded Customer Collections</strong>
            <div>₱{{ number_format($paymentSummary['total_amount'], 2) }}</div>
        </div>
        <div class="card">
            <strong>Payment Records</strong>
            <div>{{ $paymentSummary['total_records'] }}</div>
        </div>
        <div class="card">
            <strong>Partial Payments</strong>
            <div>₱{{ number_format($paymentSummary['partial_payment_total'], 2) }}</div>
        </div>
        <div class="card">
            <strong>Held Partial Payments</strong>
            <div>₱{{ number_format($paymentSummary['held_partial_payments'], 2) }}</div>
        </div>
        <div class="card">
            <strong>Applied Partial Payments</strong>
            <div>₱{{ number_format($paymentSummary['applied_partial_payments'], 2) }}</div>
        </div>
        <div class="card">
            <strong>Needs Review</strong>
            <div>₱{{ number_format($paymentSummary['review_partial_payments'], 2) }}</div>
        </div>
    </div>

    <h2>Current Pending Customer Balances</h2>
    <p>Current snapshot only: sum of max(total_cost - amount_paid, 0), excluding RTO and RTO_RELEASED jobs. Independent of report date filters; this does not reconstruct historical balances.</p>
    <div class="card">
        <strong>Current Pending Balances ({{ $paymentSummary['current_pending_balance_jobs'] }} jobs)</strong>
        <div>₱{{ number_format($paymentSummary['current_pending_balances'], 2) }}</div>
    </div>

    <h2>Transaction Summary</h2>
    <p>Payments are dated by received_at; issued refunds are dated by issued_at. Refund status reflects shop records, not external settlement. Pending obligations are not completed transactions and are not subtracted from recorded collections.</p>
    <div class="grid">
        <div class="card">
            <strong>Payments Received (received_at date basis)</strong>
            <div>₱{{ number_format($paymentSummary['total_amount'], 2) }}</div>
        </div>
        <div class="card">
            <strong>Refunds Issued (issued_at date basis)</strong>
            <div>₱{{ number_format($paymentSummary['issued_refunds'], 2) }}</div>
        </div>
        <div class="card">
            <strong>Net Retained</strong>
            <div>₱{{ number_format($paymentSummary['net_cash_retained'], 2) }}</div>
        </div>
        <div class="card">
            <strong>Pending Refund Obligations (initiated_at date basis)</strong>
            <div>₱{{ number_format($paymentSummary['pending_refund_obligations'], 2) }}</div>
        </div>
    </div>

    <h2>Transaction History (Latest 30)</h2>
    <p>Summary totals include all matching records. Payments are dated by received_at, with payment record created_at shown only as a display fallback; issued refunds are dated by issued_at. Pending refund obligations are not included as outgoing transactions. Receipts are positive and issued refunds are negative.</p>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Transaction</th>
                <th>Job Order</th>
                <th>Customer</th>
                <th>Amount</th>
                <th>Method</th>
                <th>Reference</th>
                <th>Date Basis</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($transactionRecords as $transaction)
                <tr>
                    <td>{{ $transaction['transaction_date'] }}</td>
                    <td>{{ $transaction['transaction_label'] }}</td>
                    <td>{{ $transaction['job_order_number'] ?? '—' }}</td>
                    <td>{{ $transaction['customer_name'] ?? '—' }}</td>
                    <td>{{ $transaction['amount'] < 0 ? '- ' : '+ ' }}₱{{ number_format(abs($transaction['amount']), 2) }}</td>
                    <td>{{ str_replace('_', ' ', $transaction['method'] ?? '—') }}</td>
                    <td>{{ $transaction['reference_number'] ?? '—' }}</td>
                    <td>{{ $transaction['date_basis'] }}</td>
                </tr>
            @empty
                <tr><td colspan="8">No transactions found for the applied report range.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Collections by Method</h2>
    <table>
        <thead>
            <tr>
                <th>Method</th>
                <th>Records</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($paymentMethodBreakdown as $method)
                <tr>
                    <td>{{ str_replace('_', ' ', $method['payment_method']) }}</td>
                    <td>{{ $method['records'] }}</td>
                    <td>₱{{ number_format($method['amount'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Collections by Type</h2>
    <table>
        <thead>
            <tr>
                <th>Type</th>
                <th>Records</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($paymentTypeBreakdown as $type)
                <tr>
                    <td>{{ str_replace('_', ' ', $type['payment_type']) }}</td>
                    <td>{{ $type['records'] }}</td>
                    <td>₱{{ number_format($type['amount'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Partial Payment Watchlist</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Job Order</th>
                <th>Customer</th>
                <th>Method</th>
                <th>Amount</th>
                <th>State</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($partialPaymentRecords as $payment)
                <tr>
                    <td>{{ $payment['received_at_formatted'] }}</td>
                    <td>{{ $payment['job_order_number'] }}</td>
                    <td>{{ $payment['customer_name'] }}</td>
                    <td>{{ str_replace('_', ' ', $payment['payment_method']) }}</td>
                    <td>₱{{ number_format($payment['amount'], 2) }}</td>
                    <td>{{ str_replace('_', ' ', $payment['partial_payment_state'] ?? '-') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if ($includeOperations)
    <h2>Device Breakdown</h2>
    <table>
        <thead>
            <tr>
                <th>Device Type</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($deviceBreakdown as $device)
                <tr>
                    <td>{{ $device['device_type'] }}</td>
                    <td>{{ $device['total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Status Breakdown</h2>
    <table>
        <thead>
            <tr>
                <th>Status</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($statusBreakdown as $status)
                <tr>
                    <td>{{ $status['current_status_label'] ?? str_replace('_', ' ', $status['current_status']) }}</td>
                    <td>{{ $status['total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Monthly Trend (Last 6 Months)</h2>
    <table>
        <thead>
            <tr>
                <th>Month</th>
                <th>Count</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($monthlyTrend as $trend)
                <tr>
                    <td>{{ $trend['month'] }}</td>
                    <td>{{ $trend['count'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="footer">{{ $shop_name ?? config('app.name', 'Repair Service Management System') }} · {{ ucfirst($reportType) }} Reports · Generated {{ now()->format('Y-m-d H:i') }}</div>
</body>
</html>
