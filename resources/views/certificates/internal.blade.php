<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Internal Calibration Certificate {{ $report->documentNumber() }}</title>
    <style>
        @page { margin: 24mm 18mm; }
        body { color: #171717; font-family: DejaVu Sans, sans-serif; font-size: 11px; }
        h1 { color: #171717; font-size: 22px; margin: 0; text-align: center; }
        h2 { border-bottom: 1px solid #a3a3a3; font-size: 13px; margin: 22px 0 8px; padding-bottom: 4px; }
        .muted { color: #666666; }
        .meta, .instrument, .environment { border-collapse: collapse; width: 100%; }
        .meta td, .instrument th, .instrument td, .environment th, .environment td { border: 1px solid #bdbdbd; padding: 6px; }
        .meta td:first-child { background: #f7f7f7; font-weight: bold; width: 28%; }
        .instrument th, .environment th { background: #eeeeee; color: #171717; text-align: left; }
        .center { text-align: center; }
    </style>
</head>
<body>
    <h1>INTERNAL CALIBRATION CERTIFICATE</h1>
    <p class="muted center">Certificate Number: {{ $report->documentNumber() }}</p>

    <table class="meta">
        <tr><td>Issued Date</td><td>{{ $report->issuedDate() }}</td></tr>
        <tr><td>Calibration Date</td><td>{{ $report->calibrationDate() }}</td></tr>
        <tr><td>Calibration Due</td><td>{{ $report->calibrationDue() }}</td></tr>
        <tr><td>Instrument</td><td>{{ $report->instrumentName() }}</td></tr>
        <tr><td>Location</td><td>{{ $report->location() }} — {{ $report->detailLocation() }}</td></tr>
    </table>

    <h2>Instrument Type</h2>
    <table class="instrument">
        <thead><tr><th>Type</th><th>Brand</th><th>Model</th><th>Serial Number</th><th>Asset Number</th></tr></thead>
        <tbody>
        @foreach ($report->instrumentRows() as $row)
            <tr>
                <td>{{ $row['type_name'] }}</td>
                <td>{{ $row['brand'] }}</td>
                <td>{{ $row['model'] }}</td>
                <td>{{ $row['serial_number'] }}</td>
                <td>{{ $report->assetNumber() }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <h2>Environment</h2>
    <table class="environment">
        <thead><tr><th>Temperature</th><th>Relative Humidity</th></tr></thead>
        <tbody><tr><td>{{ $report->temperature() }}</td><td>{{ $report->humidity() }}</td></tr></tbody>
    </table>

    <p class="muted">This certificate contains the frozen calibration session data recorded at completion.</p>
</body>
</html>
