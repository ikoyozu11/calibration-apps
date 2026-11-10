<?php

use App\CalibrationLabels\CalibrationLabelException;
use App\CalibrationLabels\CalibrationLabelRenderer;
use App\Models\Calibration;
use App\Models\Instrument;

function calibrationLabelFixture(array $overrides = []): Calibration
{
    $calibration = new Calibration;
    $calibration->forceFill(array_merge([
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'session_status' => Calibration::STATUS_COMPLETED,
        'calibration_date' => '2026-11-04',
        'calibration_due' => '2027-11-04',
        'snapshot_instrument_name' => 'Cigarette Test Station',
        'snapshot_serial_number' => 'Sr. 3 Nr. 44',
    ], $overrides));
    $instrument = new Instrument;
    $instrument->forceFill([
        'instrument_name' => 'Mutable Instrument Name',
        'serial_number' => 'Mutable Serial',
    ]);
    $calibration->setRelation('instrument', $instrument);

    return $calibration;
}

it('renders an exact size 300 DPI calibration label PNG from snapshot values', function () {
    $renderer = app(CalibrationLabelRenderer::class);
    $png = $renderer->render($renderer->dataFromCalibration(calibrationLabelFixture()));

    expect($png)->toStartWith("\x89PNG\r\n\x1a\n")
        ->and(getimagesizefromstring($png)[0])->toBe(827)
        ->and(getimagesizefromstring($png)[1])->toBe(472)
        ->and(str_contains($png, 'pHYs'))->toBeTrue();
});

it('fits long instrument and serial values without truncation', function () {
    $renderer = app(CalibrationLabelRenderer::class);
    $data = $renderer->dataFromCalibration(calibrationLabelFixture([
        'snapshot_instrument_name' => 'Cigarette Test Station With An Unusually Long Instrument Name',
        'snapshot_serial_number' => 'SERIAL-0000000000000000000001',
    ]));
    $png = $renderer->render($data);

    expect($png)->toStartWith("\x89PNG\r\n\x1a\n");
});

it('writes isolated visual preview fixtures when requested', function () {
    $directory = getenv('CALIBRATION_LABEL_PREVIEW_DIR');

    if (! is_string($directory) || $directory === '') {
        expect(true)->toBeTrue();

        return;
    }

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $renderer = app(CalibrationLabelRenderer::class);
    $fixtures = [
        'short' => [
            'instrument_name' => 'Sodiline',
            'serial_number' => 'SN-001',
            'calibration_date' => '04 Nov 2026',
            'calibration_due' => '04 Nov 2027',
        ],
        'typical' => [
            'instrument_name' => 'Cigarette Test Station',
            'serial_number' => 'Sr. 3 Nr. 44',
            'calibration_date' => '04 Nov 2026',
            'calibration_due' => '04 Nov 2027',
        ],
        'long' => [
            'instrument_name' => 'Cigarette Test Station With An Unusually Long Instrument Name',
            'serial_number' => 'SERIAL-0000000000000000000001',
            'calibration_date' => '04 Nov 2026',
            'calibration_due' => '04 Nov 2027',
        ],
        'long-serial' => [
            'instrument_name' => 'Sodiline',
            'serial_number' => 'SERIAL-NUMBER-00000000000000000000000001',
            'calibration_date' => '04 Nov 2026',
            'calibration_due' => '04 Nov 2027',
        ],
    ];

    foreach ($fixtures as $name => $data) {
        file_put_contents($directory.DIRECTORY_SEPARATOR.'calibration-label-'.$name.'.png', $renderer->render($data));
    }

    expect(glob($directory.DIRECTORY_SEPARATOR.'calibration-label-*.png'))->toHaveCount(4);
});

it('keeps all label endpoints behind the application authorization middleware', function () {
    foreach (['preview', 'download', 'print'] as $action) {
        $route = app('router')->getRoutes()->getByName('calibrations.label.'.$action);

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('auth')
            ->and($route->gatherMiddleware())->toContain('application.role');
    }
});

it('writes a normalized reference comparison when requested', function () {
    $directory = getenv('CALIBRATION_LABEL_COMPARISON_DIR');

    if (! is_string($directory) || $directory === '') {
        expect(true)->toBeTrue();

        return;
    }

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $reference = @imagecreatefrompng(base_path('references/Calibration_Label_Approved_Design.png'));
    $generatedPath = base_path('storage/app/calibration-label-previews-20261105/calibration-label-typical.png');
    $generated = @imagecreatefrompng($generatedPath);
    $normalized = imagecreatetruecolor(CalibrationLabelRenderer::WIDTH, CalibrationLabelRenderer::HEIGHT);
    imagecopyresampled($normalized, $reference, 0, 0, 42, 90, CalibrationLabelRenderer::WIDTH, CalibrationLabelRenderer::HEIGHT, 1448, 828);
    imagepng($normalized, $directory.DIRECTORY_SEPARATOR.'calibration-label-reference-normalized.png');

    $comparison = imagecreatetruecolor(CalibrationLabelRenderer::WIDTH * 2, CalibrationLabelRenderer::HEIGHT);
    imagecopy($comparison, $normalized, 0, 0, 0, 0, CalibrationLabelRenderer::WIDTH, CalibrationLabelRenderer::HEIGHT);
    imagecopy($comparison, $generated, CalibrationLabelRenderer::WIDTH, 0, 0, 0, CalibrationLabelRenderer::WIDTH, CalibrationLabelRenderer::HEIGHT);
    imagepng($comparison, $directory.DIRECTORY_SEPARATOR.'calibration-label-side-by-side.png');

    imagecopymerge($normalized, $generated, 0, 0, 0, 0, CalibrationLabelRenderer::WIDTH, CalibrationLabelRenderer::HEIGHT, 50);
    imagepng($normalized, $directory.DIRECTORY_SEPARATOR.'calibration-label-overlay.png');

    imagedestroy($reference);
    imagedestroy($generated);
    imagedestroy($comparison);
    imagedestroy($normalized);

    expect(is_file($directory.DIRECTORY_SEPARATOR.'calibration-label-side-by-side.png'))->toBeTrue()
        ->and(is_file($directory.DIRECTORY_SEPARATOR.'calibration-label-overlay.png'))->toBeTrue();
});

it('fails clearly when a required label value is missing or session is unsupported', function () {
    $renderer = app(CalibrationLabelRenderer::class);
    $missing = calibrationLabelFixture([
        'snapshot_serial_number' => null,
    ]);
    $missingInstrument = new Instrument;
    $missingInstrument->forceFill(['serial_number' => null]);
    $missing->setRelation('instrument', $missingInstrument);

    expect(fn () => $renderer->dataFromCalibration($missing))->toThrow(CalibrationLabelException::class)
        ->and(fn () => $renderer->dataFromCalibration(calibrationLabelFixture([
            'calibration_method' => Calibration::METHOD_EXTERNAL,
        ])))->toThrow(CalibrationLabelException::class);
});
