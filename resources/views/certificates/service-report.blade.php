<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SERVICE REPORT {{ $report->documentNumber() }}</title>
    <style>
        @page { size: A4 portrait; margin: 14mm 12.4mm 22mm; }
        * { box-sizing: border-box; }
        body { color: #171717; font-family: DejaVu Sans, sans-serif; font-size: 8.3pt; line-height: 1.25; margin: 0; }
        .header { border-bottom: 1pt solid #a3a3a3; page-break-inside: avoid; padding: 1mm 0 2mm; width: 100%; }
        .header td { vertical-align: middle; }
        .logo { height: 17mm; width: auto; }
        .header-title { color: #171717; font-size: 15pt; font-weight: bold; text-align: right; }
        .header-meta { color: #525252; font-size: 8.6pt; padding-top: 1.5mm; text-align: right; }
        .header-meta-separator { color: #a3a3a3; padding: 0 1.5mm; }
        .label { color: #666666; font-size: 6.8pt; font-weight: bold; text-transform: uppercase; }
        .value { color: #171717; font-weight: bold; }
        .section-title { background: #eeeeee; color: #171717; font-size: 8.8pt; font-weight: bold; margin: 3.5mm 0 2mm; padding: 1.7mm 2.5mm; page-break-after: avoid; }
        .section-title .number { color: #171717; margin-right: 1mm; }
        .field-grid { border-collapse: collapse; margin: 0; width: 100%; }
        .field-grid td { padding: 0 4mm 2.2mm 2.5mm; vertical-align: top; width: 50%; }
        .field-grid tr:last-child td { padding-bottom: 0; }
        .field-label { color: #666666; font-size: 7.5pt; line-height: 1.15; }
        .field-value { color: #171717; font-size: 8.8pt; font-weight: bold; line-height: 1.2; margin-top: 0.8mm; }
        table.data { border-collapse: collapse; margin-top: 0; page-break-inside: auto; width: 100%; }
        .data th, .data td { border-bottom: 0.45pt solid #bdbdbd; padding: 1mm 1.8mm; vertical-align: middle; }
        .data tr:first-child th, .data tr:first-child td { border-top: 0.45pt solid #bdbdbd; }
        .data th { color: #666666; font-size: 6.8pt; font-weight: normal; text-align: left; width: 20%; }
        .data td { color: #171717; }
        .data .striped td, .data .striped th { background: #f7f7f7; }
        .data thead { display: table-header-group; }
        .section-columns { border-collapse: collapse; page-break-inside: avoid; width: 100%; }
        .section-columns > tbody > tr > td { padding-right: 2.2mm; vertical-align: top; width: 40%; }
        .section-columns > tbody > tr > td + td { padding-left: 2.2mm; padding-right: 0; width: 60%; }
        .section-columns .section-title { margin-top: 0; }
        .activity th { color: #666666; width: 6%; }
        .activity { border-collapse: collapse; width: 100%; }
        .activity th, .activity td { border: 0; padding: 0 1.5mm 1.8mm 0; vertical-align: top; }
        .activity th { color: #666666; font-size: 8pt; font-weight: bold; text-align: left; width: 12%; }
        .activity td.activity-name { color: #171717; font-size: 8.8pt; line-height: 1.2; width: 38%; }
        .activity .striped td, .activity .striped th { background: transparent; }
        .standard th { background: #eeeeee; color: #171717; font-weight: bold; text-align: left; width: 33.33%; }
        .standard td { width: 33.33%; }
        .scope { color: #171717; font-size: 7.8pt; font-weight: bold; margin: 2mm 0 1mm; }
        .test-table th { background: #eeeeee; color: #171717; font-size: 6.8pt; font-weight: bold; text-align: center; }
        .test-table td { text-align: center; }
        .test-group { margin-top: 2mm; }
        .test-group.compact { page-break-inside: avoid; }
        .test-columns { border-collapse: collapse; page-break-inside: avoid; width: 100%; }
        .test-columns > tbody > tr > td { padding-right: 1.5mm; vertical-align: top; width: 50%; }
        .test-columns > tbody > tr > td + td { padding-left: 1.5mm; padding-right: 0; }
        .test-columns .test-group { margin-top: 0; }
        .test-group-title th { background: #f7f7f7; color: #171717; font-size: 7.8pt; text-align: left; }
        .test-group-notes { page-break-inside: avoid; }
        .note { color: #666666; font-size: 6.8pt; margin: 1mm 0 0; }
        .resume { background: #ffffff; border: 0.45pt solid #bdbdbd; color: #171717; font-size: 8.8pt; line-height: 1.25; padding: 2.5mm; }
        .avoid-break { page-break-inside: avoid; }
    </style>
</head>
<body>
    @php($identity = $report->instrumentIdentity())
    @php($activities = $report->activities())
    @php($standards = $report->standards())
    @php($testGroups = $report->testResultGroups())
    @php($activityColumnSize = max(1, (int) ceil(count($activities) / 2)))
    @php($shortAddress = $report->customerAddress() !== null && mb_strlen($report->customerAddress()) <= 90)
    @php($compactTestGroup = static function (array $group): bool {
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
    })
    @php($testLayouts = [])
    @php($testIndex = 0)
    @while ($testIndex < count($testGroups))
        @php($currentTestGroup = $testGroups[$testIndex])
        @if ($compactTestGroup($currentTestGroup) && isset($testGroups[$testIndex + 1]) && $compactTestGroup($testGroups[$testIndex + 1]))
            @php($testLayouts[] = ['mode' => 'pair', 'groups' => [$currentTestGroup, $testGroups[$testIndex + 1]]])
            @php($testIndex += 2)
        @else
            @php($testLayouts[] = ['mode' => 'full', 'groups' => [$currentTestGroup]])
            @php($testIndex++)
        @endif
    @endwhile

    <table class="header">
        <tr>
            <td style="width: 35%;"><img class="logo" src="{{ public_path('images/vision-teknik-logo.png') }}" alt="PT Vision Teknik"></td>
            <td style="width: 65%;">
                <div class="header-title">SERVICE REPORT</div>
                <div class="header-meta">
                    {{ $report->documentNumber() }}<span class="header-meta-separator">·</span>{{ $report->issuedDate() }}
                </div>
            </td>
        </tr>
    </table>

    <div class="section-title"><span class="number">01</span>GENERAL INFORMATION</div>
    <table class="field-grid avoid-break">
        <tr class="striped">
            <td><div class="field-label">Customer</div><div class="field-value">{{ $report->customerName() }}</div></td>
            @if ($shortAddress)
                <td><div class="field-label">Address</div><div class="field-value">{{ $report->customerAddress() }}</div></td>
            @endif
        </tr>
        @if (!$shortAddress && $report->customerAddress() !== null)
            <tr><td colspan="2"><div class="field-label">Address</div><div class="field-value">{{ $report->customerAddress() }}</div></td></tr>
        @endif
    </table>

    <div class="section-title"><span class="number">02</span>INSTRUMENT IDENTIFICATION</div>
    <table class="field-grid avoid-break">
        <tr>
            <td><div class="field-label">Instrument</div><div class="field-value">{{ $identity['name'] }}</div></td>
            <td><div class="field-label">Instrument Type</div><div class="field-value">{{ $identity['type'] }}</div></td>
        </tr>
        <tr>
            <td><div class="field-label">Brand</div><div class="field-value">{{ $identity['brand'] }}</div></td>
            <td><div class="field-label">Serial Number</div><div class="field-value">{{ $identity['serial_number'] }}</div></td>
        </tr>
        <tr>
            <td><div class="field-label">Location</div><div class="field-value">{{ $report->location() }}</div></td>
            <td><div class="field-label">Detail Location</div><div class="field-value">{{ $report->detailLocation() }}</div></td>
        </tr>
        @if ($report->hasActionDate())
            <tr><td colspan="2"><div class="field-label">Action Date</div><div class="field-value">{{ $report->actionDate() }}</div></td></tr>
        @endif
    </table>

    @if ($activities !== [] && count($activities) <= 6)
        <table class="section-columns">
            <tr>
                <td>
                    <div class="section-title"><span class="number">03</span>ENVIRONMENT</div>
                    <table class="field-grid avoid-break">
                        <tr>
                            <td><div class="field-label">Temperature</div><div class="field-value">{{ $report->hasTemperature() ? $report->temperature() : '' }}</div></td>
                            <td><div class="field-label">Relative Humidity</div><div class="field-value">{{ $report->hasHumidity() ? $report->humidity() : '' }}</div></td>
                        </tr>
                    </table>
                </td>
                <td>
                    <div class="section-title"><span class="number">04</span>ACTIVITY</div>
                    <table class="data activity avoid-break"><tbody>
                        @for ($row = 0; $row < $activityColumnSize; $row++)
                            @php($leftIndex = $row)
                            @php($rightIndex = $activityColumnSize + $row)
                            <tr class="{{ $row % 2 === 0 ? 'striped' : '' }}">
                                <th>{{ isset($activities[$leftIndex]) ? sprintf('%02d', $leftIndex + 1) : '' }}</th>
                                <td class="activity-name">{{ $activities[$leftIndex] ?? '' }}</td>
                                <th>{{ isset($activities[$rightIndex]) ? sprintf('%02d', $rightIndex + 1) : '' }}</th>
                                <td class="activity-name">{{ $activities[$rightIndex] ?? '' }}</td>
                            </tr>
                        @endfor
                    </tbody></table>
                </td>
            </tr>
        </table>
    @else
        <div class="section-title"><span class="number">03</span>ENVIRONMENT</div>
        <table class="field-grid avoid-break">
            <tr>
                <td><div class="field-label">Temperature</div><div class="field-value">{{ $report->hasTemperature() ? $report->temperature() : '' }}</div></td>
                <td><div class="field-label">Relative Humidity</div><div class="field-value">{{ $report->hasHumidity() ? $report->humidity() : '' }}</div></td>
            </tr>
        </table>

        @if ($activities !== [])
            <div class="section-title"><span class="number">04</span>ACTIVITY</div>
            <table class="data activity avoid-break"><tbody>
                @for ($row = 0; $row < $activityColumnSize; $row++)
                    @php($leftIndex = $row)
                    @php($rightIndex = $activityColumnSize + $row)
                    <tr class="{{ $row % 2 === 0 ? 'striped' : '' }}">
                        <th>{{ isset($activities[$leftIndex]) ? sprintf('%02d', $leftIndex + 1) : '' }}</th>
                        <td class="activity-name">{{ $activities[$leftIndex] ?? '' }}</td>
                        <th>{{ isset($activities[$rightIndex]) ? sprintf('%02d', $rightIndex + 1) : '' }}</th>
                        <td class="activity-name">{{ $activities[$rightIndex] ?? '' }}</td>
                    </tr>
                @endfor
            </tbody></table>
        @endif
    @endif

    @if ($standards !== [])
        <div class="section-title"><span class="number">05</span>STANDARD(S) USED</div>
        <table class="data standard avoid-break">
            <thead><tr><th>Instrument Name</th><th>Type</th><th>Serial Number</th></tr></thead>
            <tbody>
                @foreach ($standards as $standard)
                    <tr><td>{{ $standard['name'] }}</td><td>{{ $standard['type'] }}</td><td>{{ $standard['serial_number'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($testLayouts !== [])
        <div class="section-title"><span class="number">06</span>TEST RESULT</div>
        @foreach ($testLayouts as $layout)
            @if ($layout['mode'] === 'pair')
                <table class="test-columns">
                    <tr>
                        @foreach ($layout['groups'] as $group)
                            <td>
                                <div class="test-group compact">
                                    <table class="data test-table">
                                        <thead>
                                            <tr class="test-group-title"><th colspan="{{ count($group['columns']) }}">{{ $group['label'] }}</th></tr>
                                            <tr>@foreach ($group['columns'] as $column)<th>{{ $column['label'] }}@if ($column['unit'] !== '')<br>({{ $column['unit'] }})@endif</th>@endforeach</tr>
                                        </thead>
                                        <tbody>@foreach ($group['rows'] as $row)<tr>@foreach ($row['cells'] as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody>
                                    </table>
                                    <div class="test-group-notes">
                                        @if ($group['remarks'] !== '')<div class="note">{{ $group['remarks'] }}</div>@endif
                                        @if ($group['tolerance'] !== null)<div class="note">Reference remark: Correction Tolerance: {{ $group['tolerance'] }} from STD Value.</div>@endif
                                    </div>
                                </div>
                            </td>
                        @endforeach
                    </tr>
                </table>
            @else
                @foreach ($layout['groups'] as $group)
                    <div class="test-group {{ $report->testResultGroupIsCompact($group) ? 'compact' : 'long' }}">
                        <table class="data test-table">
                            <thead>
                                <tr class="test-group-title"><th colspan="{{ count($group['columns']) }}">{{ $group['label'] }}</th></tr>
                                <tr>@foreach ($group['columns'] as $column)<th>{{ $column['label'] }}@if ($column['unit'] !== '')<br>({{ $column['unit'] }})@endif</th>@endforeach</tr>
                            </thead>
                            <tbody>@foreach ($group['rows'] as $row)<tr>@foreach ($row['cells'] as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody>
                        </table>
                        <div class="test-group-notes">
                            @if ($group['remarks'] !== '')<div class="note">{{ $group['remarks'] }}</div>@endif
                            @if ($group['tolerance'] !== null)<div class="note">Reference remark: Correction Tolerance: {{ $group['tolerance'] }} from STD Value.</div>@endif
                        </div>
                    </div>
                @endforeach
            @endif
        @endforeach
    @endif

    @if ($report->hasResume())
        <div class="section-title"><span class="number">07</span>RESUME</div>
        <div class="resume">{{ $report->resume() }}</div>
    @endif

</body>
</html>
