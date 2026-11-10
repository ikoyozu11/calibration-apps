<?php

it('keeps the certificate PDF preview responsive and isolated', function () {
    $blade = file_get_contents(base_path('resources/views/pages/calibrations/⚡show.blade.php'));
    $css = file_get_contents(base_path('resources/css/app.css'));

    expect($blade)->toContain('class="certificate-pdf-preview"')
        ->and($css)->toContain('.certificate-pdf-preview')
        ->and($css)->toContain('height: 80vh;')
        ->and($css)->toContain('min-height: 680px;')
        ->and($css)->toContain('max-height: 1100px;')
        ->and($css)->toContain('height: 70vh;');
});
