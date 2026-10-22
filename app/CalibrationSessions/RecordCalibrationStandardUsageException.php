<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class RecordCalibrationStandardUsageException extends RuntimeException implements ShouldntReport
{
    public static function invalidCalibrationId(): self
    {
        return new self('The calibration id must be at least 1.');
    }

    public static function invalidStandardId(): self
    {
        return new self('The standard id must be at least 1.');
    }

    public static function invalidUsageId(): self
    {
        return new self('The usage id must be at least 1.');
    }

    public static function missingCalibration(): self
    {
        return new self('The calibration does not exist.');
    }

    public static function invalidCalibrationMethod(): self
    {
        return new self('Standard usage can be recorded only for an internal calibration session.');
    }

    public static function invalidCalibrationStatus(): self
    {
        return new self('Only an in-progress calibration session can record standard usage.');
    }

    public static function missingStandardsRequirement(): self
    {
        return new self('The standards requirement snapshot does not exist.');
    }

    public static function unresolvedStandardsMode(): self
    {
        return new self('An unresolved not-configured standards requirement cannot record standard usage.');
    }

    public static function invalidEffectiveMode(): self
    {
        return new self('The standards requirement mode cannot record standard usage.');
    }

    public static function missingAssignment(): self
    {
        return new self('This standard is not assigned to the session instrument type.');
    }

    public static function missingMasterStandard(): self
    {
        return new self('The standard master record does not exist.');
    }

    public static function duplicateStandardUsage(): self
    {
        return new self('This standard is already used on this calibration session.');
    }

    public static function missingUsage(): self
    {
        return new self('The standard usage row does not exist.');
    }

    public static function foreignSessionUsage(): self
    {
        return new self('The standard usage row belongs to another calibration session.');
    }
}
