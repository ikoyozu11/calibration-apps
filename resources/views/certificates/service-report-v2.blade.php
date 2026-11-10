<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SERVICE REPORT {{ $report->documentNumber() }}</title>
    <style>
        @page { size: A4 portrait; margin: 53pt 35pt 63pt; }
        * { box-sizing: border-box; }
        body { color: #171717; font-family: 'Noto Sans', sans-serif; font-size: 8.3pt; line-height: 1.2; margin: 0; }
        .page { padding: 0; position: relative; width: auto; }
        .header { border-bottom: 0.55pt solid #a3a3a3; height: 46.2pt; margin-top: -28pt; position: relative; width: 100%; }
        .header-legacy { display: none; }
        .logo { display: block; height: 39.2pt; left: 0; position: absolute; top: 0; width: auto; }
        .header-right { padding-top: 4pt; text-align: right; }
        .header-title { color: #171717; font-size: 15pt; font-weight: 700; left: 0; line-height: 18pt; position: absolute; text-align: center; top: 10.6pt; width: 100%; }
        .header-meta { display: none; }
        .header-meta-group { color: #525252; font-size: 8.4pt; line-height: 12pt; position: absolute; right: 0; text-align: right; top: 7.6pt; white-space: nowrap; }
        .header-meta-line { height: 12pt; }
        .section { margin-top: 9pt; }
        .section:first-of-type { margin-top: 12pt; }
        .section-bar { background: #efefef; color: #171717; font-size: 8.6pt; font-weight: 700; height: 18pt; line-height: 11pt; padding: 0 7pt; }
        .section-bar.compact { font-size: 8.2pt; }
        .section-number { margin-right: 5pt; }
        .field-grid { border-collapse: collapse; margin-top: 5pt; width: 100%; }
        .content-area-border { margin-top: 0; outline: 0.5pt solid #d1d5db; padding: 0 6pt 4pt; }
        .content-panel { background: #ffffff; border: 0.5pt solid #d1d5db; padding: 2pt 6pt 5pt; }
        .field-grid td { padding: 0 7pt; vertical-align: top; width: 50%; }
        .field-grid tr:last-child td { padding-bottom: 0; }
        .field-label { color: #666; font-size: 7.2pt; line-height: 8pt; }
        .field-value { color: #171717; font-size: 8.8pt; font-weight: 600; line-height: 8pt; margin-top: 0; }
        .field-value.long { font-size: 8.4pt; }
        .columns { border-collapse: collapse; table-layout: fixed; width: 100%; }
        .columns > tbody > tr > td { padding: 0; vertical-align: top; }
        .columns > tbody > tr > td.panel-gap { width: 9pt; }
        .columns > tbody > tr.content-row > td.environment { padding: 2pt 6pt 5pt; vertical-align: top; }
        .columns > tbody > tr.content-row > td.activity { padding: 2pt 6pt 5pt; vertical-align: top; }
        .content-row .field-grid, .content-row .activity-list { margin-top: 0; }
        .activity-columns { border-collapse: collapse; margin-top: 0; width: 100%; }
        .activity-columns > tbody > tr > td { padding: 0; vertical-align: top; width: 50%; }
        .activity-list { border-collapse: collapse; margin-top: 4pt; width: 100%; }
        .activity-list td { border: 0; padding: 0 2pt 1pt 0; vertical-align: top; }
        .activity-list .activity-number { color: #171717; font-size: 7.4pt; font-weight: 600; width: 14pt; }
        .activity-list .activity-name { color: #171717; font-size: 7.8pt; line-height: 10pt; }
        .data { border-collapse: collapse; margin-top: 5pt; page-break-inside: auto; width: 100%; }
        .data thead { display: table-header-group; }
        .data th, .data td { border-bottom: 0.45pt solid #bdbdbd; height: 18pt; padding: 0 7pt; vertical-align: middle; }
        .data th { background: #efefef; color: #171717; font-size: 7.7pt; font-weight: 700; text-align: left; }
        .data td { color: #171717; font-size: 8.1pt; font-weight: 400; }
        .data tbody tr:nth-child(even) td { background: #fafafa; }
        .standard th, .standard td { width: 33.333%; }
        .standard-section { margin-top: 8pt; }
        .instrument-section { margin-top: 8pt; }
        .environment-section { margin-top: 8pt; }
        .resume-section { margin-top: 9pt; }
        .test-group { margin-top: 6pt; page-break-inside: auto; }
        .test-group:first-child { margin-top: 2pt; }
        .test-group-title { background: #fafafa; color: #171717; font-size: 8.2pt; font-weight: 700; height: 15pt; padding: 2pt 7pt; }
        .test-table th { text-align: center; }
        .test-table td { text-align: center; }
        .test-table th .unit { display: block; font-size: 7.2pt; font-weight: 700; }
        .test-note { color: #666; font-size: 7.1pt; margin: 4pt 4pt 0; }
        .test-columns { border-collapse: collapse; width: 100%; }
        .test-columns > tbody > tr > td { padding-right: 4pt; vertical-align: top; width: 50%; }
        .test-columns > tbody > tr > td + td { padding-left: 4pt; padding-right: 0; }
        .test-columns .test-group { margin-top: 5pt; }
        .test-columns .test-group-title { font-size: 7.8pt; }
        .resume-box { color: #171717; font-size: 8.6pt; font-weight: 400; line-height: 11pt; margin-top: 0; min-height: 0; padding: 5.5pt 7pt 7pt; }
        .keep-together { page-break-inside: avoid; }
        .page-break-before { page-break-before: always; }
    </style>
</head>
<body>
@php
    $identity = $report->instrumentIdentity();
    $activities = $report->activities();
    $standards = $report->standards();
    $testGroups = $report->testResultGroups();
    $shortAddress = $report->customerAddress() !== null && mb_strlen($report->customerAddress()) <= 90;
    $activitySplit = max(1, (int) ceil(count($activities) / 2));
    $testLayouts = [];
    $isCompact = static function (array $group): bool {
        if (count($group['columns']) > 3 || count($group['rows']) > 5) {
            return false;
        }

        foreach ($group['columns'] as $column) {
            if (mb_strlen((string) $column['label']) > 26 || mb_strlen((string) $column['unit']) > 12) {
                return false;
            }
        }

        foreach ($group['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                if (mb_strlen((string) $cell) > 24) {
                    return false;
                }
            }
        }

        return true;
    };
    for ($index = 0; $index < count($testGroups); $index++) {
        if ($isCompact($testGroups[$index]) && isset($testGroups[$index + 1]) && $isCompact($testGroups[$index + 1])) {
            $testLayouts[] = ['mode' => 'pair', 'groups' => [$testGroups[$index], $testGroups[++$index]]];
        } else {
            $testLayouts[] = ['mode' => 'full', 'groups' => [$testGroups[$index]]];
        }
    }
@endphp

<div class="page">
    <div class="header">
        <img class="logo" src="{{ public_path('images/vision-teknik-logo.png') }}" alt="PT Vision Teknik">
        <div class="header-title">SERVICE REPORT</div>
        <div class="header-meta-group">
            <div class="header-meta-line">Certificate No.: {{ $report->documentNumber() }}</div>
            <div class="header-meta-line">Calibration Date: {{ $report->calibrationDateShort() }}</div>
        </div>
    </div>
    <table class="header-legacy">
        <tr>
            <td style="width: 35%;"><img class="logo" src="{{ public_path('images/vision-teknik-logo.png') }}" alt="PT Vision Teknik"></td>
            <td class="header-right" style="width: 65%;">
                <div class="header-title">SERVICE REPORT</div>
                <div class="header-meta-group">
                    <div class="header-meta-line">Certificate No. : {{ $report->documentNumber() }}</div>
                    <div class="header-meta-line">Calibration Date : {{ $report->calibrationDateShort() }}</div>
                </div>
                <div class="header-meta">{{ $report->documentNumber() }}<span class="header-meta-separator">·</span>{{ $report->issuedDate() }}</div>
            </td>
        </tr>
    </table>

    <section class="section keep-together">
        <div class="section-bar"><span class="section-number">01</span>GENERAL INFORMATION</div>
        <table class="field-grid content-area-border">
            <tr>
                <td><div class="field-label">Customer</div><div class="field-value">{{ $report->customerName() }}</div></td>
                @if ($shortAddress)
                    <td><div class="field-label">Address</div><div class="field-value long">{{ $report->customerAddress() }}</div></td>
                @endif
            </tr>
            @if (!$shortAddress && $report->customerAddress() !== null)
                <tr><td colspan="2"><div class="field-label">Address</div><div class="field-value long">{{ $report->customerAddress() }}</div></td></tr>
            @endif
        </table>
    </section>

    <section class="section instrument-section keep-together">
        <div class="section-bar"><span class="section-number">02</span>INSTRUMENT IDENTIFICATION</div>
        <table class="field-grid content-area-border">
            <tr><td><div class="field-label">Instrument</div><div class="field-value">{{ $identity['name'] }}</div></td><td><div class="field-label">Instrument Type</div><div class="field-value">{{ $identity['type'] }}</div></td></tr>
            <tr><td><div class="field-label">Brand</div><div class="field-value">{{ $identity['brand'] }}</div></td><td><div class="field-label">Serial Number</div><div class="field-value">{{ $identity['serial_number'] }}</div></td></tr>
            <tr><td><div class="field-label">Location</div><div class="field-value">{{ $report->location() }}</div></td><td><div class="field-label">Detail Location</div><div class="field-value">{{ $report->detailLocation() }}</div></td></tr>
            @if ($report->hasActionDate())
                <tr><td colspan="2"><div class="field-label">Action Date</div><div class="field-value">{{ $report->actionDate() }}</div></td></tr>
            @endif
        </table>
    </section>

    <section class="section environment-section">
        <table class="columns">
            <tr>
                <td class="environment" style="width: 39.3%;">
                    <div class="section-bar compact"><span class="section-number">03</span>ENVIRONMENT</div>
                </td>
                <td class="panel-gap" style="width: 9pt;"></td>
                <td class="activity" style="width: 59%;">
                    <div class="section-bar compact"><span class="section-number">04</span>ACTIVITY</div>
                </td>
            </tr>
            <tr class="content-row">
                <td class="environment content-panel">
                    <table class="field-grid"><tr><td><div class="field-label">Temperature</div><div class="field-value">{{ $report->hasTemperature() ? $report->temperature() : '' }}</div></td><td><div class="field-label">Relative Humidity</div><div class="field-value">{{ $report->hasHumidity() ? $report->humidity() : '' }}</div></td></tr></table>
                </td>
                <td class="panel-gap"></td>
                <td class="activity content-panel">
                    <table class="activity-columns"><tr><td>
                        <table class="activity-list"><tbody>
                            @for ($index = 0; $index < $activitySplit; $index++)
                                @if (isset($activities[$index]))<tr><td class="activity-number">{{ sprintf('%02d', $index + 1) }}</td><td class="activity-name">{{ $activities[$index] }}</td></tr>@endif
                            @endfor
                        </tbody></table>
                    </td><td>
                        <table class="activity-list"><tbody>
                            @for ($index = $activitySplit; $index < count($activities); $index++)
                                <tr><td class="activity-number">{{ sprintf('%02d', $index + 1) }}</td><td class="activity-name">{{ $activities[$index] }}</td></tr>
                            @endfor
                        </tbody></table>
                    </td></tr></table>
                </td>
            </tr>
        </table>
    </section>

    @if ($standards !== [])
        <section class="section standard-section keep-together">
            <div class="section-bar"><span class="section-number">05</span>STANDARD(S) USED</div>
            <table class="data standard">
                <thead><tr><th>Instrument Name</th><th>Type</th><th>Serial Number</th></tr></thead>
                <tbody>@foreach ($standards as $standard)<tr><td>{{ $standard['name'] }}</td><td>{{ $standard['type'] }}</td><td>{{ $standard['serial_number'] }}</td></tr>@endforeach</tbody>
            </table>
        </section>
    @endif

    @if ($testLayouts !== [])
        <section class="section">
            <div class="section-bar"><span class="section-number">06</span>TEST RESULT</div>
            @foreach ($testLayouts as $layout)
                @if ($layout['mode'] === 'pair')
                    <table class="test-columns"><tr>@foreach ($layout['groups'] as $group)<td>@include('certificates.partials.service-report-test-group-v2', ['group' => $group])</td>@endforeach</tr></table>
                @else
                    @include('certificates.partials.service-report-test-group-v2', ['group' => $layout['groups'][0]])
                @endif
            @endforeach
        </section>
    @endif

    <section class="section resume-section">
        <div class="section-bar"><span class="section-number">07</span>RESUME</div>
        <div class="resume-box">{{ $report->resume() }}</div>
    </section>
</div>
</body>
</html>
