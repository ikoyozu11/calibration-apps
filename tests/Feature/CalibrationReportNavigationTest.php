<?php

it('defines a report detail route for instrument type rows without a session', function (): void {
    $route = Route::getRoutes()->getByName('calibrations.report-detail');

    expect($route)->not->toBeNull()
        ->and($route?->uri())->toBe('calibrations/report/{instrument}/{instrumentType}/{year}')
        ->and(route('calibrations.report-detail', [
            'instrument' => 37,
            'instrumentType' => 371,
            'year' => 2026,
        ], false))->toBe('/calibrations/report/37/371/2026');
});

it('links report names to the exact session or instrument type context', function (): void {
    $table = (string) file_get_contents(resource_path('views/components/calibration-reports-table.blade.php'));
    $detail = (string) file_get_contents(resource_path('views/pages/calibrations/⚡show.blade.php'));

    expect($table)->toContain("route('calibrations.show'")
        ->and($table)->toContain("'calibration' => \$row['calibration_id']")
        ->and($table)->toContain("route('calibrations.report-detail'")
        ->and($table)->toContain("'instrument' => \$row['instrument_id']")
        ->and($table)->toContain("'instrumentType' => \$row['instrument_type_id']")
        ->and($table)->toContain("'year' => \$reportYear")
        ->and($detail)->toContain('Calibration Detail')
        ->and($detail)->toContain('Instrument Information')
        ->and($detail)->toContain('Session Status')
        ->and($detail)->toContain('Certificate is not available for this completed session.')
        ->and($detail)->toContain('returnLabel');
});
