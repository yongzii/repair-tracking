<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name', 'Repair Service Management System') }} Reports</title>
    <style>
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            margin: 24px;
            color: #111827;
        }
        h1, h2 {
            margin: 0 0 8px;
        }
        .meta {
            margin-bottom: 16px;
            font-size: 12px;
            color: #6b7280;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin: 16px 0 20px;
        }
        .card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px;
            background: #f9fafb;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 8px 10px;
            font-size: 12px;
            text-align: left;
        }
        th {
            background: #f3f4f6;
        }
        @media print {
            @page { size: A4; margin: 14mm; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    @php
        $reportType = $reportType ?? 'all';
        $includeOperations = in_array($reportType, ['operations', 'all'], true);
        $includePayments = in_array($reportType, ['payments', 'all'], true);
    @endphp

    <h1>{{ config('app.name', 'Repair Service Management System') }} {{ ucfirst($reportType) }} Reports</h1>
    <div class="meta">
        Generated at: {{ now()->format('M d, Y h:i A') }}<br>
        Filters:
        start={{ $filters['start_date'] ?? '-' }},
        end={{ $filters['end_date'] ?? '-' }},
        status={{ $filters['status_label'] ?? 'ALL' }},
        service type={{ $filters['service_type_label'] ?? 'ALL' }},
        category={{ $filters['service_category_label'] ?? 'ALL' }},
        service={{ $filters['service_item_label'] ?? 'ALL' }},
        payment method={{ $filters['payment_method'] ?? 'ALL' }},
        payment type={{ $filters['payment_type'] ?? 'ALL' }}
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
            <strong>Total Revenue</strong>
            <div>PHP {{ number_format($totalRevenue, 2) }}</div>
        </div>
        <div class="card">
            <strong>Total Collected</strong>
            <div>PHP {{ number_format($totalCollected, 2) }}</div>
        </div>
        <div class="card">
            <strong>Pending Receivables</strong>
            <div>PHP {{ number_format($pendingReceivables, 2) }}</div>
        </div>
        <div class="card">
            <strong>Estimated Gross Profit</strong>
            <div>PHP {{ number_format($estimatedGrossProfit, 2) }}</div>
        </div>
        <div class="card">
            <strong>Gross Profit Margin</strong>
            <div>{{ $grossProfitMargin }}%</div>
        </div>
    </div>
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
                    <td>PHP {{ number_format($jobOrder['total_cost'], 2) }}</td>
                    <td>PHP {{ number_format($jobOrder['amount_paid'], 2) }}</td>
                    <td>PHP {{ number_format($jobOrder['balance'], 2) }}</td>
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
    <h2>Payment Collections</h2>
    <div class="grid">
        <div class="card">
            <strong>Total Payments Recorded</strong>
            <div>PHP {{ number_format($paymentSummary['total_amount'], 2) }}</div>
        </div>
        <div class="card">
            <strong>Payment Records</strong>
            <div>{{ $paymentSummary['total_records'] }}</div>
        </div>
        <div class="card">
            <strong>Partial Payments</strong>
            <div>PHP {{ number_format($paymentSummary['partial_payment_total'], 2) }}</div>
        </div>
        <div class="card">
            <strong>Held Partial Payments</strong>
            <div>PHP {{ number_format($paymentSummary['held_partial_payments'], 2) }}</div>
        </div>
        <div class="card">
            <strong>Applied Partial Payments</strong>
            <div>PHP {{ number_format($paymentSummary['applied_partial_payments'], 2) }}</div>
        </div>
        <div class="card">
            <strong>Needs Review</strong>
            <div>PHP {{ number_format($paymentSummary['review_partial_payments'], 2) }}</div>
        </div>
    </div>

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
                    <td>PHP {{ number_format($method['amount'], 2) }}</td>
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
                    <td>PHP {{ number_format($type['amount'], 2) }}</td>
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
                    <td>PHP {{ number_format($payment['amount'], 2) }}</td>
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

    <div class="no-print" style="margin-top: 16px;">
        <button onclick="window.print()">Print / Save as PDF</button>
    </div>
</body>
</html>
