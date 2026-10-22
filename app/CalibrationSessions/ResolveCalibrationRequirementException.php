<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class ResolveCalibrationRequirementException extends RuntimeException implements ShouldntReport
{
    public static function invalidCalibrationId(): self
    {
        return new self('The calibration id must be at least 1.');
    }

    public static function invalidCategory(): self
    {
        return new self('The requirement category is not supported.');
    }

    public static function invalidMode(): self
    {
        return new self('The resolved requirement mode must be required or optional.');
    }

    public static function emptyReason(): self
    {
        return new self('The resolution reason is required.');
    }

    public static function missingCalibration(): self
    {
        return new self('The calibration does not exist.');
    }

    public static function invalidSessionStatus(): self
    {
        return new self('Only an in-progress calibration session can resolve a requirement.');
    }

    public static function missingSnapshot(): self
    {
        return new self('The requirement snapshot does not exist.');
    }

    public static function frozenModeNotEligible(): self
    {
        return new self('Only a not-configured requirement can be resolved.');
    }

    public static function alreadyResolved(): self
    {
        return new self('This requirement has already been resolved.');
    }

    public static function missingRequiredEnvironmentParameter(): self
    {
        return new self('Required environment needs at least one required environment parameter snapshot.');
    }
}
