<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;

final class CalibrationSessionRequirementPolicy
{
    public static function effectiveMode(
        string $category,
        string $frozenMode,
        ?string $resolvedMode,
        string $calibrationMethod,
    ): string {
        if (
            $calibrationMethod === Calibration::METHOD_INTERNAL
            && $category === InstrumentTypeRequirement::CATEGORY_ACTIVITY
        ) {
            return InstrumentTypeRequirement::MODE_OPTIONAL;
        }

        if (in_array($frozenMode, [
            InstrumentTypeRequirement::MODE_REQUIRED,
            InstrumentTypeRequirement::MODE_OPTIONAL,
        ], true)) {
            return $frozenMode;
        }

        if (in_array($resolvedMode, [
            InstrumentTypeRequirement::MODE_REQUIRED,
            InstrumentTypeRequirement::MODE_OPTIONAL,
        ], true)) {
            return $resolvedMode;
        }

        if ($calibrationMethod === Calibration::METHOD_INTERNAL) {
            if ($category === InstrumentTypeRequirement::CATEGORY_ENVIRONMENT) {
                return InstrumentTypeRequirement::MODE_REQUIRED;
            }

            if (in_array($category, [
                InstrumentTypeRequirement::CATEGORY_ACTIVITY,
                InstrumentTypeRequirement::CATEGORY_STANDARDS,
                InstrumentTypeRequirement::CATEGORY_TEST_PROFILE,
            ], true)) {
                return InstrumentTypeRequirement::MODE_OPTIONAL;
            }
        }

        return InstrumentTypeRequirement::MODE_NOT_CONFIGURED;
    }
}
