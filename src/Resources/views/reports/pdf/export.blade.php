<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }} Export</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 9px;
            color: #333;
            line-height: 1.4;
            padding: 15px;
        }

        .report-header {
            text-align: center;
            border-bottom: 3px solid #2c3e50;
            padding-bottom: 12px;
            margin-bottom: 15px;
        }

        .report-header h1 {
            font-size: 18px;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .report-header .period {
            font-size: 11px;
            color: #7f8c8d;
        }

        .summary-bar {
            background: #ecf0f1;
            border-radius: 4px;
            padding: 8px 15px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
        }

        .summary-bar span {
            font-size: 10px;
        }

        .summary-bar strong {
            color: #2c3e50;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            page-break-inside: auto;
        }

        thead {
            display: table-header-group;
        }

        tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }

        th {
            background: #2c3e50;
            color: white;
            padding: 5px 6px;
            text-align: left;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        td {
            padding: 4px 6px;
            border-bottom: 1px solid #ecf0f1;
            font-size: 9px;
        }

        tr:nth-child(even) {
            background: #f8f9fa;
        }

        .text-right {
            text-align: right;
        }

        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #bdc3c7;
            text-align: center;
            font-size: 8px;
            color: #95a5a6;
        }

        .no-data {
            text-align: center;
            padding: 30px;
            color: #95a5a6;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="report-header">
        <h1>{{ $title }}</h1>
        <div class="period">
            {{ $startDate->format('M d, Y') }} &mdash; {{ $endDate->format('M d, Y') }}
            ({{ $startDate->diffInDays($endDate) }} days)
        </div>
    </div>

    <div class="summary-bar">
        <span><strong>Total Records:</strong> {{ number_format(count($rows)) }}</span>
        <span><strong>Export Type:</strong> {{ $title }}</span>
        <span><strong>Generated:</strong> {{ $generatedAt->format('M d, Y H:i') }} UTC</span>
    </div>

    @if(count($rows) > 0)
    <table>
        <thead>
            <tr>
                <th>#</th>
                @foreach($columns as $column)
                <th>{{ ucwords(str_replace('_', ' ', $column)) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $index => $row)
            <tr>
                <td>{{ $index + 1 }}</td>
                @foreach($columns as $column)
                <td @if(is_numeric($row[$column] ?? '')) class="text-right" @endif>
                    @if(is_numeric($row[$column] ?? ''))
                        {{ number_format((float)$row[$column], str_contains((string)($row[$column] ?? ''), '.') ? 2 : 0) }}
                    @else
                        {{ $row[$column] ?? '-' }}
                    @endif
                </td>
                @endforeach
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <div class="no-data">
        No data found for the selected period.
    </div>
    @endif

    <div class="footer">
        Mining Manager &bull; Generated {{ $generatedAt->format('M d, Y H:i:s') }} UTC
    </div>
</body>
</html>
