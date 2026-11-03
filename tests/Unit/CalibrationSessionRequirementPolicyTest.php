<?php

use App\CalibrationSessions\CalibrationSessionRequirementPolicy;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;

it('treats an internal not-configured environment as required without changing the snapshot', function () {
    expect(CalibrationSessionRequirementPolicy::effectiveMode(
        InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
        InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
        null,
        Calibration::METHOD_INTERNAL,
    ))->toBe(InstrumentTypeRequirement::MODE_REQUIRED);
});

it('treats internal not-configured routine categories as optional', function (string $category) {
    expect(CalibrationSessionRequirementPolicy::effectiveMode(
        $category,
        InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
        null,
        Calibration::METHOD_INTERNAL,
    ))->toBe(InstrumentTypeRequirement::MODE_OPTIONAL);
})->with([
    InstrumentTypeRequirement::CATEGORY_ACTIVITY,
    InstrumentTypeRequirement::CATEGORY_STANDARDS,
    InstrumentTypeRequirement::CATEGORY_TEST_PROFILE,
]);

it('preserves explicit frozen and resolved modes', function () {
    expect(CalibrationSessionRequirementPolicy::effectiveMode(
        InstrumentTypeRequirement::CATEGORY_STANDARDS,
        InstrumentTypeRequirement::MODE_REQUIRED,
        InstrumentTypeRequirement::MODE_OPTIONAL,
        Calibration::METHOD_INTERNAL,
    ))->toBe(InstrumentTypeRequirement::MODE_REQUIRED)
        ->and(CalibrationSessionRequirementPolicy::effectiveMode(
            InstrumentTypeRequirement::CATEGORY_STANDARDS,
            InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
            InstrumentTypeRequirement::MODE_REQUIRED,
            Calibration::METHOD_INTERNAL,
        ))->toBe(InstrumentTypeRequirement::MODE_REQUIRED);
});

it('keeps internal activity optional without rewriting its snapshot mode', function () {
    expect(CalibrationSessionRequirementPolicy::effectiveMode(
        InstrumentTypeRequirement::CATEGORY_ACTIVITY,
        InstrumentTypeRequirement::MODE_REQUIRED,
        null,
        Calibration::METHOD_INTERNAL,
    ))->toBe(InstrumentTypeRequirement::MODE_OPTIONAL);
});

it('does not invent an internal policy for external sessions', function () {
    expect(CalibrationSessionRequirementPolicy::effectiveMode(
        InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
        InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
        null,
        Calibration::METHOD_EXTERNAL,
    ))->toBe(InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
});
