<?php

it('uses the shared Calibration Detail tab design across tabbed pages', function () {
    $calibrationsBlade = file_get_contents(base_path('resources/views/pages/⚡calibrations.blade.php'));
    $calibrationDetailBlade = file_get_contents(base_path('resources/views/pages/calibrations/⚡show.blade.php'));
    $css = file_get_contents(base_path('resources/css/app.css'));

    expect($css)->toContain('.desk-tabs')
        ->toContain('.desk-tab')
        ->toContain('.desk-tab-active')
        ->toContain('.desk-tab-inactive')
        ->and($calibrationsBlade)->toContain('class="desk-tabs"')
        ->toContain('class="desk-tab {{ $tab === CalibrationAnnualOverview::TAB_REPORTS ? \'desk-tab-active\' : \'desk-tab-inactive\' }}"')
        ->and($calibrationDetailBlade)->toContain('class="desk-tabs"')
        ->toContain('class="desk-tab {{ $this->tab === \'detail\' ? \'desk-tab-active\' : \'desk-tab-inactive\' }}"')
        ->toContain('Calibration Detail')
        ->toContain('Certificate');
});

it('keeps all tab controls keyboard and responsive friendly', function () {
    $css = file_get_contents(base_path('resources/css/app.css'));

    expect($css)->toContain('overflow-x-auto')
        ->toContain('whitespace-nowrap')
        ->toContain('focus-visible:outline');
});
