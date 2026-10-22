<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class RecordCalibrationScopeRemarksException extends RuntimeException implements ShouldntReport
{
    public static function invalidCalibrationId(): self
    {
        return new self('The calibration id must be at least 1.');
    }

    public static function invalidScopeId(): self
    {
        return new self('The calibration scope id must be at least 1.');
    }

    public static function invalidRemarks(): self
    {
        return new self('The scope remarks must not be blank.');
    }

    public static function missingCalibration(): self
    {
        return new self('The calibration does not exist.');
    }

    public static function unsupportedCalibrationMethod(): self
    {
        return new self('Scope remarks can be recorded only for an internal calibration session.');
    }

    public static function invalidSessionStatus(): self
    {
        return new self('Only an in-progress calibration session can record scope remarks.');
    }

    public static function missingTestProfileRequirement(): self
    {
        return new self('The test profile requirement snapshot does not exist.');
    }

    public static function unresolvedTestProfileMode(): self
    {
        return new self('An unresolved not-configured test profile requirement cannot record scope remarks.');
    }

    public static function invalidEffectiveMode(): self
    {
        return new self('The test profile requirement mode cannot record scope remarks.');
    }

    public static function unknownScope(): self
    {
        return new self('This calibration scope does not belong to this calibration session.');
    }

    public static function foreignScope(): self
    {
        return new self('This calibration scope belongs to another calibration session.');
    }

    public static function instrumentTypeMismatch(): self
    {
        return new self('The calibration scope instrument type does not match the calibration.');
    }

    public static function unpinnedScope(): self
    {
        return new self('The calibration scope is not fully pinned.');
    }

    public static function malformedFrozenSnapshot(): self
    {
        return new self('The frozen test table snapshot is malformed.');
    }

    public static function unsupportedSchemaVersion(): self
    {
        return new self('The frozen test table snapshot is not schema version 2.');
    }

    public static function snapshotVersionMismatch(): self
    {
        return new self('The frozen test table snapshot does not match the pinned version.');
    }
}
