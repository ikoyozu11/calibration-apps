<?php

it('uses the resume font and a centered vector checkbox for the final statement', function () {
    $renderer = file_get_contents(base_path('app/Certificates/ServiceReportPdfRenderer.php'));
    $template = file_get_contents(base_path('resources/views/certificates/service-report-v2.blade.php'));

    expect($renderer)->toContain('$statementSize = 6.8;')
        ->toContain("\$statement = 'Electronically generated and approved. No wet signature is required.';")
        ->toContain('$statementColor = $footerColor;')
        ->toContain('$canvas->line($groupLeft, $checkboxTop')
        ->toContain('$groupLeft + ($checkboxSize * 0.2),')
        ->toContain('$checkboxVerticalOffset = 2;')
        ->and($template)->toContain('.resume-box { color: #171717; font-size: 8.6pt;')
        ->not->toContain('DIGITAL DOCUMENT STATEMENT')
        ->not->toContain('background: #d6e4f0');
});

it('keeps the statement restricted to the final page while retaining every-page footers', function () {
    $renderer = file_get_contents(base_path('app/Certificates/ServiceReportPdfRenderer.php'));

    expect($renderer)->toContain('if ($pageNumber === $pageCount)')
        ->toContain('$canvas->line($leftMargin, $footerSeparatorY')
        ->toContain('$pageNumberText = "Page {$pageNumber} of {$pageCount}"');
});
