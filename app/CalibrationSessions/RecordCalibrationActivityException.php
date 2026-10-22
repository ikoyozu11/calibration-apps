<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class RecordCalibrationActivityException extends RuntimeException implements ShouldntReport
{
    public static function invalidCalibrationId(): self
    {
        return new self('The calibration id must be at least 1.');
    }

    public static function invalidActivityId(): self
    {
        return new self('The activity id must be at least 1.');
    }

    public static function invalidCalibrationActivityId(): self
    {
        return new self('The calibration activity id must be at least 1.');
    }

    public static function missingCalibration(): self
    {
        return new self('The calibration does not exist.');
    }

    public static function unsupportedCalibrationMethod(): self
    {
        return new self('Activity records can be changed only for an internal calibration session.');
    }

    public static function invalidSessionStatus(): self
    {
        return new self('Only an in-progress calibration session can record activity.');
    }

    public static function missingActivityRequirement(): self
    {
        return new self('The activity requirement snapshot does not exist.');
    }

    public static function unresolvedActivityMode(): self
    {
        return new self('An unresolved not-configured activity requirement cannot record activity.');
    }

    public static function invalidEffectiveMode(): self
    {
        return new self('The activity requirement mode cannot record activity.');
    }

    public static function missingActivityMaster(): self
    {
        return new self('The activity master record does not exist.');
    }

    public static function inactiveActivityMaster(): self
    {
        return new self('This activity master is inactive.');
    }

    public static function invalidMasterActivityName(): self
    {
        return new self('The activity master name is blank.');
    }

    public static function duplicateActivity(): self
    {
        return new self('This activity is already used on this calibration session.');
    }

    public static function missingSessionActivity(): self
    {
        return new self('The calibration activity row does not exist.');
    }

    public static function foreignSessionActivity(): self
    {
        return new self('The calibration activity row belongs to another calibration session.');
    }
}
