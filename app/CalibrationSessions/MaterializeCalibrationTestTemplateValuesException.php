<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class MaterializeCalibrationTestTemplateValuesException extends RuntimeException implements ShouldntReport
{
    public static function invalidCalibrationId(): self
    {
        return new self('The calibration id must be at least 1.');
    }

    public static function missingCalibration(): self
    {
        return new self('The calibration does not exist.');
    }

    public static function unsupportedCalibrationMethod(): self
    {
        return new self('Template values can be materialized only for an internal calibration session.');
    }

    public static function invalidSessionStatus(): self
    {
        return new self('Only an in-progress calibration session can materialize template values.');
    }

    public static function missingTestProfileRequirement(): self
    {
        return new self('The test profile requirement snapshot does not exist.');
    }

    public static function unresolvedTestProfileMode(): self
    {
        return new self('An unresolved not-configured test profile requirement cannot materialize template values.');
    }

    public static function invalidEffectiveMode(): self
    {
        return new self('The test profile requirement mode cannot materialize template values.');
    }

    public static function missingRequiredPinnedScope(): self
    {
        return new self('A required test profile has no pinned test table.');
    }

    public static function partiallyPinnedScope(): self
    {
        return new self('A calibration scope is not fully pinned.');
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

    public static function instrumentTypeMismatch(): self
    {
        return new self('The calibration scope instrument type does not match the calibration.');
    }

    public static function duplicateFrozenColumn(): self
    {
        return new self('The frozen test table column identity is ambiguous.');
    }

    public static function duplicateFrozenRow(): self
    {
        return new self('The frozen test table row identity is ambiguous.');
    }

    public static function notConfiguredTemplateColumn(): self
    {
        return new self('A not-configured template column cannot be materialized.');
    }

    public static function invalidTemplateEntry(): self
    {
        return new self('The frozen template entry is invalid.');
    }

    public static function missingRequiredTemplateValue(): self
    {
        return new self('A required template value is missing.');
    }

    public static function invalidNumericTemplateValue(): self
    {
        return new self('The frozen template numeric value is invalid.');
    }

    public static function invalidTextTemplateValue(): self
    {
        return new self('The frozen template text is invalid.');
    }

    public static function invalidDurationTemplateValue(): self
    {
        return new self('The frozen template duration is invalid.');
    }

    public static function invalidTemplateUnit(): self
    {
        return new self('The frozen template unit cannot be stored on a test result.');
    }

    public static function duplicateParentResultRows(): self
    {
        return new self('This test point has more than one result row.');
    }

    public static function duplicateResultCells(): self
    {
        return new self('This test result cell is duplicated.');
    }

    public static function conflictingResultCell(): self
    {
        return new self('An existing result cell conflicts with the frozen template value.');
    }
}
