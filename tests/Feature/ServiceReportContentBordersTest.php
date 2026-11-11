<?php

it('adds non-layout content borders to service report sections 01 through 04', function () {
    $template = file_get_contents(base_path('resources/views/certificates/service-report-v2.blade.php'));
    $renderer = file_get_contents(base_path('app/Certificates/ServiceReportPdfRenderer.php'));
    $presenter = file_get_contents(base_path('app/Certificates/ServiceReport.php'));

    expect(substr_count($template, 'class="field-grid content-area-border"'))->toBe(1)
        ->and(substr_count($template, 'class="identity-columns content-area-border"'))->toBe(1)
        ->and(substr_count($template, 'content-panel">'))->toBe(2)
        ->and($template)->toContain('.content-area-border { margin-top: 0; outline: 0.5pt solid #d1d5db; padding: 0 6pt 4pt; }')
        ->toContain('.content-panel { background: #ffffff; border: 0.5pt solid #d1d5db; padding: 2pt 6pt 5pt; }')
        ->toContain('.columns { border-collapse: collapse; table-layout: fixed; width: 100%; }')
        ->toContain('.activity-columns > tbody > tr > td { padding: 0; vertical-align: top; width: 50%; }')
        ->toContain('<td class="panel-gap"></td>')
        ->toContain('.columns > tbody > tr.content-row > td.environment { padding: 2pt 6pt 5pt; vertical-align: top; }')
        ->toContain('.columns > tbody > tr.content-row > td.activity { padding: 2pt 6pt 5pt; vertical-align: top; }')
        ->toContain('.activity-list td { border: 0; padding: 0 2pt 1pt 0; vertical-align: top; }')
        ->toContain('.activity-list .activity-number { color: #171717; font-size: 7.4pt; font-weight: 600; width: 14pt; }')
        ->toContain('.instrument-section { margin-top: 8pt; }')
        ->toContain('.environment-section { margin-top: 8pt; }')
        ->toContain('<tr class="content-row">')
        ->toContain('.standard-section { margin-top: 8pt; }')
        ->toContain('01</span>GENERAL INFORMATION')
        ->toContain('02</span>INSTRUMENT IDENTIFICATION')
        ->toContain('03</span>ENVIRONMENT')
        ->toContain('04</span>ACTIVITY')
        ->toContain('.logo { display: block; height: 39.2pt;')
        ->toContain('.header-title { color: #171717; font-size: 15pt; font-weight: 700; left: 0;')
        ->toContain('.header-meta-group { color: #525252; font-size: 8.4pt;')
        ->toContain('Document Number: {{ $report->documentNumber() }}')
        ->not->toContain('Certificate Number')
        ->not->toContain('Certificate No.')
        ->toContain('Calibration Date : {{ $report->calibrationDateShort() }}')
        ->not->toContain('section-bar content-area-border');

    expect($renderer)
        ->toContain('$headerNumberSize = 8.4;')
        ->toContain('$headerLineHeight = 12;')
        ->toContain("'Document Number: '.\$certificate->certificate_number")
        ->not->toContain('Certificate Number')
        ->not->toContain('Certificate No.')
        ->toContain('$canvas->text(($pageWidth - $headerTitleWidth) / 2')
        ->and($presenter)->toContain("->format('d M Y')");
});
