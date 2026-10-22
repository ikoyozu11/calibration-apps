<?php

use App\CalibrationSessions\CalibrationSessionCompleteness;

it('keeps frozen required and optional modes and resolves not configured separately', function () {
    expect(CalibrationSessionCompleteness::effectiveMode('required', 'optional'))->toBe('required')
        ->and(CalibrationSessionCompleteness::effectiveMode('optional', 'required'))->toBe('optional')
        ->and(CalibrationSessionCompleteness::effectiveMode('not_configured', null))->toBe('not_configured')
        ->and(CalibrationSessionCompleteness::effectiveMode('not_configured', 'optional'))->toBe('optional');
});

it('blocks internal complete when environment is not configured', function () {
    expect(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
        'not_configured',
        true,
        [['requirement_mode' => 'required', 'value' => 21.5]],
    ))->toBeFalse();
});

it('requires explicit confirmation for required and optional environment', function () {
    expect(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
        'required',
        false,
        [['requirement_mode' => 'required', 'value' => 21.5]],
    ))->toBeFalse()
        ->and(CalibrationSessionCompleteness::environmentReadyForInternalComplete('optional', false, []))->toBeFalse();
});

it('allows optional environment with zero values only after confirmation', function () {
    expect(CalibrationSessionCompleteness::environmentReadyForInternalComplete('optional', true, []))->toBeTrue();
});

it('still validates required parameters on an optional environment', function () {
    expect(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
        'optional',
        true,
        [['requirement_mode' => 'required', 'value' => null]],
    ))->toBeFalse()
        ->and(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
            'optional',
            true,
            [
                ['requirement_mode' => 'required', 'value' => 21.5],
                ['requirement_mode' => 'optional', 'value' => null],
            ],
        ))->toBeTrue();
});

it('requires at least one required parameter when environment is required', function () {
    expect(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
        'required',
        true,
        [['requirement_mode' => 'optional', 'value' => null]],
    ))->toBeFalse();
});

it('does not let configuration confirmation skip required environment values', function () {
    expect(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
        'required',
        true,
        [
            ['requirement_mode' => 'required', 'value' => null],
            ['requirement_mode' => 'optional', 'value' => null],
        ],
    ))->toBeFalse()
        ->and(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
            'required',
            true,
            [
                ['requirement_mode' => 'required', 'value' => 21.5],
                ['requirement_mode' => 'optional', 'value' => null],
            ],
        ))->toBeTrue();
});

it('blocks complete when a parameter inclusion is unconfirmed', function () {
    expect(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
        'required',
        true,
        [[
            'requirement_mode' => 'required',
            'value' => 21.5,
            'inclusion_confirmed' => false,
        ]],
    ))->toBeFalse()
        ->and(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
            'optional',
            true,
            [[
                'requirement_mode' => 'optional',
                'value' => null,
                'inclusion_confirmed' => false,
            ]],
        ))->toBeFalse();
});

it('blocks a used test profile when any cell is not configured', function () {
    expect(CalibrationSessionCompleteness::testComponentsReady([
        ['completion_rule' => 'required', 'value' => '1.0'],
        ['completion_rule' => 'not_configured', 'value' => null],
    ]))->toBeFalse();
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
    ]))->toBeFalse();
});
