<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class ConfirmEnvironmentConfigurationException extends RuntimeException implements ShouldntReport
{
    public static function invalidCalibrationId(): self
    {
        return new self('The calibration id must be at least 1.');
    }

    public static function emptyReason(): self
    {
        return new self('The confirmation reason is required.');
    }

    public static function missingCalibration(): self
    {
        return new self('The calibration does not exist.');
    }

    public static function invalidSessionStatus(): self
    {
        return new self('Only an in-progress calibration session can confirm environment configuration.');
    }

    public static function missingSnapshot(): self
    {
        return new self('The environment requirement snapshot does not exist.');
    }

    public static function unresolvedMode(): self
    {
        return new self('An unresolved not-configured environment cannot be confirmed.');
    }

    public static function invalidEffectiveMode(): self
    {
        return new self('The environment requirement mode cannot be confirmed.');
    }

    public static function alreadyConfirmed(): self
    {
        return new self('Environment configuration has already been confirmed.');
    }

    public static function inconsistentConfirmation(): self
    {
        return new self('Environment configuration confirmation is partially recorded and cannot be repaired.');
    }
}
