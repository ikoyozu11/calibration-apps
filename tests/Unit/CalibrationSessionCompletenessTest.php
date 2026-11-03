<?php

use App\CalibrationSessions\CalibrationSessionCompleteness;
use App\Models\EnvironmentParameter;

/**
 * @param  array<string, array<string, mixed>>  $overrides
 * @return list<array{parameter_code: string, requirement_mode: string, value: mixed, inclusion_confirmed?: bool}>
 */
function roomEnvironmentParameters(array $overrides = []): array
{
    $temperature = array_merge([
        'parameter_code' => EnvironmentParameter::CODE_TEMPERATURE,
        'requirement_mode' => 'required',
        'value' => 21.5,
    ], $overrides['temperature'] ?? []);
    $humidity = array_merge([
        'parameter_code' => EnvironmentParameter::CODE_RELATIVE_HUMIDITY,
        'requirement_mode' => 'required',
        'value' => 55,
    ], $overrides['humidity'] ?? []);
    $extra = $overrides['extra'] ?? [];

    return $extra === []
        ? [$temperature, $humidity]
        : array_values(array_merge([$temperature, $humidity], $extra));
}

it('keeps frozen required and optional modes and resolves not configured separately', function () {
    expect(CalibrationSessionCompleteness::effectiveMode('required', 'optional'))->toBe('required')
        ->and(CalibrationSessionCompleteness::effectiveMode('optional', 'required'))->toBe('optional')
        ->and(CalibrationSessionCompleteness::effectiveMode('not_configured', null))->toBe('not_configured')
        ->and(CalibrationSessionCompleteness::effectiveMode('not_configured', 'optional'))->toBe('optional');
});

it('blocks internal complete when environment is not configured', function () {
    expect(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
        'not_configured',
        roomEnvironmentParameters(),
    ))->toBeFalse();
});

it('does not require configuration confirmation when temperature and relative humidity values exist', function () {
    expect(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
        'required',
        roomEnvironmentParameters(),
    ))->toBeTrue()
        ->and(CalibrationSessionCompleteness::environmentReadyForInternalComplete('optional', roomEnvironmentParameters()))->toBeTrue();
});

it('does not allow optional environment to bypass temperature and relative humidity', function () {
    expect(CalibrationSessionCompleteness::environmentReadyForInternalComplete('optional', []))->toBeFalse()
        ->and(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
            'optional',
            roomEnvironmentParameters(['temperature' => ['requirement_mode' => 'optional']]),
        ))->toBeFalse()
        ->and(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
            'optional',
            roomEnvironmentParameters(['humidity' => ['value' => null]]),
        ))->toBeFalse();
});

it('still validates extra required parameters on an optional environment', function () {
    expect(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
        'optional',
        roomEnvironmentParameters([
            'extra' => [['parameter_code' => 'pressure', 'requirement_mode' => 'required', 'value' => null]],
        ]),
    ))->toBeFalse()
        ->and(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
            'optional',
            roomEnvironmentParameters([
                'extra' => [['parameter_code' => 'condition', 'requirement_mode' => 'optional', 'value' => null]],
            ]),
        ))->toBeTrue();
});

it('requires temperature and relative humidity when environment is required', function () {
    expect(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
        'required',
        [['requirement_mode' => 'optional', 'value' => null]],
    ))->toBeFalse()
        ->and(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
            'required',
            roomEnvironmentParameters(['humidity' => ['parameter_code' => 'other_humidity']]),
        ))->toBeFalse();
});

it('does not let a ready configuration skip required environment values', function () {
    expect(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
        'required',
        roomEnvironmentParameters(['temperature' => ['value' => null]]),
    ))->toBeFalse()
        ->and(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
            'required',
            roomEnvironmentParameters([
                'extra' => [['parameter_code' => 'condition', 'requirement_mode' => 'optional', 'value' => null]],
            ]),
        ))->toBeTrue();
});

it('blocks complete when a parameter inclusion is unconfirmed', function () {
    expect(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
        'required',
        roomEnvironmentParameters([
            'temperature' => ['inclusion_confirmed' => false],
        ]),
    ))->toBeFalse()
        ->and(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
            'optional',
            roomEnvironmentParameters([
                'extra' => [[
                    'parameter_code' => 'condition',
                    'requirement_mode' => 'optional',
                    'value' => null,
                    'inclusion_confirmed' => false,
                ]],
            ]),
        ))->toBeFalse();
});

it('treats a not configured test component as optional', function () {
    expect(CalibrationSessionCompleteness::testComponentsReady([
        ['completion_rule' => 'required', 'value' => '1.0'],
        ['completion_rule' => 'not_configured', 'value' => null],
    ]))->toBeTrue();
});

it('allows optional test component cells to remain empty', function () {
    expect(CalibrationSessionCompleteness::testComponentsReady([
        ['completion_rule' => 'required', 'value' => '1.0'],
        ['completion_rule' => 'optional', 'value' => null],
    ]))->toBeTrue();
});

it('treats a missing completion rule as not configured', function () {
    expect(CalibrationSessionCompleteness::testComponentsReady([
        ['value' => '1.0'],
    ]))->toBeTrue();
});
