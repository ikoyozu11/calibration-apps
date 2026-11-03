<?php

use App\CalibrationSessions\CompleteCalibrationSession;
use App\CalibrationSessions\CompleteCalibrationSessionException;
use App\CalibrationSessions\MaterializeCalibrationTestTemplateValues;
use App\CalibrationSessions\PinCalibrationTestTables;
use App\CalibrationSessions\RecordCalibrationTestResult;
use Illuminate\Support\Collection;

function invokeTestComponentPrivateMethod(object $target, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionClass($target);
    $privateMethod = $reflection->getMethod($method);

    return $privateMethod->invokeArgs($target, $arguments);
}

function notConfiguredTestComponentSnapshot(): array
{
    return [
        'schema_version' => 2,
        'columns' => [[
            'column_key' => 'reading',
            'data_type' => 'numeric',
            'value_source' => 'engineer',
            'completion_rule' => 'not_configured',
        ]],
        'rows' => [[
            'test_point_id' => 101,
        ]],
    ];
}

it('allows pinning an applicable table with a not configured column', function () {
    $service = (new ReflectionClass(PinCalibrationTestTables::class))->newInstanceWithoutConstructor();

    expect(fn () => invokeTestComponentPrivateMethod($service, 'assertCompletableSnapshot', [
        notConfiguredTestComponentSnapshot(),
    ]))->not->toThrow(Throwable::class);
});

it('allows saving an engineer value in a not configured column', function () {
    $service = (new ReflectionClass(RecordCalibrationTestResult::class))->newInstanceWithoutConstructor();

    expect(fn () => invokeTestComponentPrivateMethod($service, 'assertWritableColumn', [[
        'value_source' => 'engineer',
        'completion_rule' => 'not_configured',
        'data_type' => 'numeric',
    ]]))->not->toThrow(Throwable::class);
});

it('materializes frozen values for a not configured template column as optional', function () {
    $service = (new ReflectionClass(MaterializeCalibrationTestTemplateValues::class))->newInstanceWithoutConstructor();

    $plan = invokeTestComponentPrivateMethod($service, 'templatePlan', [
        1,
        [
            'test_point_id' => 101,
            'template_values' => [
                'nominal' => [
                    'numeric_value' => '10.00',
                    'text_value' => null,
                    'unit' => 'V',
                ],
            ],
        ],
        [
            'column_key' => 'nominal',
            'data_type' => 'numeric',
            'value_source' => 'template',
            'completion_rule' => 'not_configured',
        ],
    ]);

    expect($plan)->toMatchArray([
        'column_key' => 'nominal',
        'numeric_value' => '10.00',
        'unit' => 'V',
    ]);
});

it('does not block completion for a pinned not configured column', function () {
    $service = (new ReflectionClass(CompleteCalibrationSession::class))->newInstanceWithoutConstructor();
    $scope = (object) [
        'calibration_scope_id' => 1,
        'test_profile_version_id' => 10,
        'test_table_snapshot' => notConfiguredTestComponentSnapshot(),
    ];

    expect(fn () => invokeTestComponentPrivateMethod($service, 'assertTestProfile', [
        'optional',
        new Collection([$scope]),
        new Collection,
        new Collection,
    ]))->not->toThrow(Throwable::class);
});

it('still requires values for required columns', function () {
    $service = (new ReflectionClass(CompleteCalibrationSession::class))->newInstanceWithoutConstructor();
    $snapshot = notConfiguredTestComponentSnapshot();
    $snapshot['columns'][0]['completion_rule'] = 'required';
    $scope = (object) [
        'calibration_scope_id' => 1,
        'test_profile_version_id' => 10,
        'test_table_snapshot' => $snapshot,
    ];

    expect(fn () => invokeTestComponentPrivateMethod($service, 'assertTestProfile', [
        'optional',
        new Collection([$scope]),
        new Collection,
        new Collection,
    ]))->toThrow(CompleteCalibrationSessionException::class);
});
