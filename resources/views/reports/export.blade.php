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
    <h1>{{ config('app.name', 'Repair Service Management System') }} Reports</h1>
    <div class="meta">
        Generated at: {{ now()->format('M d, Y h:i A') }}<br>
        Filters: start={{ $filters['start_date'] ?? '-' }}, end={{ $filters['end_date'] ?? '-' }}, status={{ $filters['status'] ?? 'ALL' }}
    </div>

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
                    <td>{{ str_replace('_', ' ', $status['current_status']) }}</td>
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

    <div class="no-print" style="margin-top: 16px;">
        <button onclick="window.print()">Print / Save as PDF</button>
    </div>
</body>
</html>
