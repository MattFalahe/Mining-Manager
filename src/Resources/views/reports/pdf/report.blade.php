<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mining Report</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #333;
            line-height: 1.4;
            padding: 20px;
        }

        /* Header */
        .report-header {
            text-align: center;
            border-bottom: 3px solid #2c3e50;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .report-header h1 {
            font-size: 22px;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .report-header .period {
            font-size: 13px;
            color: #7f8c8d;
        }

        .report-header .generated {
            font-size: 10px;
            color: #95a5a6;
            margin-top: 5px;
        }

        /* Section */
        .section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }

        .section-title {
            font-size: 14px;
            font-weight: bold;
            color: #2c3e50;
            border-bottom: 1px solid #bdc3c7;
            padding-bottom: 5px;
            margin-bottom: 10px;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        th {
            background-color: #2c3e50;
            color: #ffffff;
            padding: 6px 8px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
        }

        td {
            padding: 5px 8px;
            border-bottom: 1px solid #ecf0f1;
            font-size: 10px;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        /* Summary Stats Grid */
        .stats-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        .stats-grid td {
            width: 33.33%;
            padding: 10px;
            text-align: center;
            border: 1px solid #ddd;
            background-color: #f8f9fa;
        }

        .stats-grid .stat-value {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
            display: block;
        }

        .stats-grid .stat-label {
            font-size: 9px;
            color: #7f8c8d;
            text-transform: uppercase;
            display: block;
            margin-top: 3px;
        }

        /* Tax Summary */
        .tax-summary {
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 12px;
        }

        .tax-row {
            display: block;
            margin-bottom: 6px;
            overflow: hidden;
        }

        .tax-label {
            float: left;
            font-weight: bold;
            color: #555;
        }

        .tax-value {
            float: right;
            color: #2c3e50;
            font-weight: bold;
        }

        .collection-rate {
            text-align: center;
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1px solid #ddd;
        }

        .rate-value {
            font-size: 20px;
            font-weight: bold;
            color: #27ae60;
        }

        .rate-label {
            font-size: 9px;
            color: #7f8c8d;
            text-transform: uppercase;
        }

        /* Footer */
        .report-footer {
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid #bdc3c7;
            text-align: center;
            font-size: 9px;
            color: #95a5a6;
        }

        /* No data */
        .no-data {
            text-align: center;
            padding: 15px;
            color: #95a5a6;
            font-style: italic;
        }
    </style>
</head>
<body>

    {{-- HEADER --}}
    <div class="report-header">
        <h1>Mining Manager Report</h1>
        @if(isset($data['period']))
        <div class="period">
            {{ $data['period']['start'] ?? '' }} &mdash; {{ $data['period']['end'] ?? '' }}
            @if(isset($data['period']['days']))
                ({{ $data['period']['days'] }} days)
            @endif
        </div>
        @endif
        <div class="generated">Generated on {{ now()->format('F j, Y \a\t H:i') }} UTC</div>
    </div>

    {{-- SUMMARY STATISTICS --}}
    @if(isset($data['summary']))
    <div class="section">
        <div class="section-title">Summary Statistics</div>
        <table class="stats-grid">
            <tr>
                <td>
                    <span class="stat-value">{{ number_format($data['summary']['total_quantity'] ?? 0) }}</span>
                    <span class="stat-label">Total Quantity Mined</span>
                </td>
                <td>
                    <span class="stat-value">{{ number_format($data['summary']['total_value'] ?? 0, 2) }}</span>
                    <span class="stat-label">Total Value (ISK)</span>
                </td>
                <td>
                    <span class="stat-value">{{ number_format($data['summary']['unique_miners'] ?? 0) }}</span>
                    <span class="stat-label">Unique Miners</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="stat-value">{{ number_format($data['summary']['average_per_miner'] ?? 0) }}</span>
                    <span class="stat-label">Avg Quantity per Miner</span>
                </td>
                <td>
                    <span class="stat-value">{{ number_format($data['summary']['average_value_per_miner'] ?? 0, 2) }}</span>
                    <span class="stat-label">Avg Value per Miner (ISK)</span>
                </td>
                <td>
                    <span class="stat-value">&nbsp;</span>
                    <span class="stat-label">&nbsp;</span>
                </td>
            </tr>
        </table>
    </div>
    @endif

    {{-- TOP MINERS --}}
    @if(isset($data['miners']['top_miners']) && count($data['miners']['top_miners']) > 0)
    <div class="section">
        <div class="section-title">Top Miners</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th>Miner Name</th>
                    <th class="text-right">Quantity</th>
                    <th class="text-right">Value (ISK)</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['miners']['top_miners'] as $index => $miner)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $miner['name'] ?? 'Unknown' }}</td>
                    <td class="text-right">{{ number_format($miner['quantity'] ?? 0) }}</td>
                    <td class="text-right">{{ number_format($miner['value'] ?? 0, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if(isset($data['miners']['total_count']))
        <div style="font-size: 9px; color: #95a5a6; text-align: right;">
            Showing top {{ count($data['miners']['top_miners']) }} of {{ $data['miners']['total_count'] }} miners
        </div>
        @endif
    </div>
    @endif

    {{-- ORE BREAKDOWN --}}
    @if(isset($data['ore_types']) && count($data['ore_types']) > 0)
    <div class="section">
        <div class="section-title">Ore Breakdown</div>
        <table>
            <thead>
                <tr>
                    <th>Ore Type</th>
                    <th class="text-right">Quantity</th>
                    <th class="text-right">Value (ISK)</th>
                    @php
                        $totalOreValue = array_sum(array_column($data['ore_types'], 'value'));
                    @endphp
                    <th class="text-right">% of Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['ore_types'] as $ore)
                <tr>
                    <td>{{ $ore['name'] ?? 'Unknown' }}</td>
                    <td class="text-right">{{ number_format($ore['quantity'] ?? 0) }}</td>
                    <td class="text-right">{{ number_format($ore['value'] ?? 0, 2) }}</td>
                    <td class="text-right">
                        {{ $totalOreValue > 0 ? number_format(($ore['value'] ?? 0) / $totalOreValue * 100, 1) : '0.0' }}%
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- TAX SUMMARY --}}
    @if(isset($data['taxes']))
    <div class="section">
        <div class="section-title">Tax Summary</div>
        <div class="tax-summary">
            <div class="tax-row">
                <span class="tax-label">Total Owed:</span>
                <span class="tax-value">{{ number_format($data['taxes']['total_owed'] ?? 0, 2) }} ISK</span>
            </div>
            <div class="tax-row">
                <span class="tax-label">Total Paid:</span>
                <span class="tax-value">{{ number_format($data['taxes']['total_paid'] ?? 0, 2) }} ISK</span>
            </div>
            <div class="tax-row">
                <span class="tax-label">Unpaid:</span>
                <span class="tax-value" style="color: #e74c3c;">{{ number_format($data['taxes']['unpaid'] ?? 0, 2) }} ISK</span>
            </div>
            <div class="tax-row">
                <span class="tax-label">Overdue:</span>
                <span class="tax-value" style="color: #c0392b;">{{ number_format($data['taxes']['overdue'] ?? 0, 2) }} ISK</span>
            </div>
            <div class="collection-rate">
                <span class="rate-value">{{ number_format($data['taxes']['collection_rate'] ?? 0, 1) }}%</span><br>
                <span class="rate-label">Collection Rate</span>
            </div>
        </div>
    </div>
    @endif

    {{-- SYSTEMS --}}
    @if(isset($data['systems']) && count($data['systems']) > 0)
    <div class="section">
        <div class="section-title">Activity by System</div>
        <table>
            <thead>
                <tr>
                    <th>System</th>
                    <th class="text-right">Quantity Mined</th>
                    <th class="text-right">Unique Miners</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['systems'] as $system)
                <tr>
                    <td>{{ $system['name'] ?? 'Unknown' }}</td>
                    <td class="text-right">{{ number_format($system['quantity'] ?? 0) }}</td>
                    <td class="text-right">{{ $system['unique_miners'] ?? 0 }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- FOOTER --}}
    <div class="report-footer">
        Mining Manager for SeAT v5 &bull; Report generated automatically
    </div>

</body>
</html>
