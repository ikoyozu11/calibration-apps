<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Service Report {{ $report->documentNumber() }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 7mm 8mm 8mm;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 7.5pt;
            line-height: 1.15;
            color: #111;
        }

        h1 {
            margin: 0 0 2px;
            font-size: 12pt;
            text-align: center;
            letter-spacing: 0.3px;
        }

        h2 {
            margin: 4px 0 1px;
            padding-bottom: 0;
            border-bottom: 0.5pt solid #222;
            font-size: 8pt;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            page-break-inside: avoid;
        }

        .meta td {
            padding: 0 0 2px;
            vertical-align: top;
        }

        .info td {
            padding: 1px 8px 1px 0;
            vertical-align: top;
        }

        .label {
            width: 18%;
            font-weight: bold;
        }

        .data th,
        .data td {
            border: 0.4pt solid #666;
            padding: 0 2px;
            text-align: left;
            vertical-align: top;
        }

        .data th {
            font-size: 7pt;
            background: #f2f2f2;
        }

        .scope {
            margin: 2px 0 1px;
            font-size: 7.5pt;
            font-weight: bold;
        }

        .note {
            margin: 1px 0 2px;
        }

        .signatory {
            margin-top: 6px;
        }

        .signatory .spacer {
            width: 62%;
        }

        .signatory .box {
            width: 38%;
            padding-top: 2px;
            font-weight: bold;
            text-align: center;
        }
    </style>
</head>
<body>
    <h1>SERVICE REPORT</h1>

    <table class="meta">
        <tr>
            <td>Document Number: {{ $report->documentNumber() }}</td>
            <td style="text-align: right;">Page 1 of 1</td>
        </tr>
    </table>

    <h2>GENERAL INFORMATION</h2>
    <table class="info">
        <tr>
            <td class="label">Customer</td>
            <td>{{ $report->customerName() }}</td>
            <td class="label">Issued Date</td>
            <td>{{ $report->issuedDate() }}</td>
        </tr>
        <tr>
            <td class="label">Address</td>
            <td colspan="3">{{ $report->customerAddress() }}</td>
        </tr>
    </table>

    <h2>INSTRUMENT IDENTIFICATION</h2>
    <table class="info">
        <tr>
            <td class="label">Instrument</td>
            <td>{{ $report->instrumentName() }}</td>
            <td class="label">Location</td>
            <td>{{ $report->location() }}</td>
        </tr>
        <tr>
            <td class="label">Action Date</td>
            <td colspan="3">{{ $report->actionDate() }}</td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th>Type</th>
                <th>Merk</th>
                <th>Model</th>
                <th>Serial Number</th>
                @if ($report->showsMaxCapacity())
                    <th>Max Capacity</th>
                @endif
                @if ($report->showsResolution())
                    <th>Resolution</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($report->instrumentRows() as $row)
                <tr>
                    <td>{{ $row['type_name'] }}</td>
                    <td>{{ $row['brand'] }}</td>
                    <td>{{ $row['model'] }}</td>
                    <td>{{ $row['serial_number'] }}</td>
                    @if ($report->showsMaxCapacity())
                        <td>{{ $row['max_capacity'] ?? '—' }}</td>
                    @endif
                    @if ($report->showsResolution())
                        <td>{{ $row['resolution'] ?? '—' }}</td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="4">—</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>ENVIRONMENT</h2>
    <table class="info">
        <tr>
            <td class="label">Temperature</td>
            <td>{{ $report->temperature() }}</td>
            <td class="label">Relative Humidity</td>
            <td>{{ $report->humidity() }}</td>
        </tr>
    </table>

    <h2>ACTIVITY</h2>
    <table class="data">
        <thead>
            <tr>
                <th style="width: 8%;">No.</th>
                <th>Description</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report->activities() as $activity)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $activity }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="2">—</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>STANDARD(S) USED</h2>
    <table class="data">
        <thead>
            <tr>
                <th style="width: 8%;">No.</th>
                <th>Standard</th>
                <th>Range</th>
                <th>Serial Number</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report->standards() as $standard)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $standard['name'] }}</td>
                    <td>{{ $standard['range'] }}</td>
                    <td>{{ $standard['serial_number'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">—</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>TEST RESULT</h2>
    @forelse ($report->testResultGroups() as $group)
        <div class="scope">{{ $group['label'] }}</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Standard Value</th>
                    <th>Standard Reading Avg</th>
                    <th>Correction</th>
                    <th>Unit</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($group['rows'] as $row)
                    <tr>
                        <td>{{ $row['standard_value'] }}</td>
                        <td>{{ $row['average_value'] }}</td>
                        <td>{{ $row['correction_value'] }}</td>
                        <td>{{ $row['unit'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">—</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        @if (filled($group['tolerance']))
            <div class="note">Correction Tolerance: {{ $group['tolerance'] }}</div>
        @endif
    @empty
        <div>—</div>
    @endforelse

    <h2>RESUME</h2>
    <div>{{ $report->resume() }}</div>

    <table class="signatory">
        <tr>
            <td class="spacer"></td>
            <td class="box">Authorized Signatory</td>
        </tr>
    </table>
</body>
</html>
