<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class RecordCalibrationTestResultException extends RuntimeException implements ShouldntReport
{
    public static function invalidCalibrationId(): self
    {
        return new self('The calibration id must be at least 1.');
    }

    public static function invalidScopeId(): self
    {
        return new self('The calibration scope id must be at least 1.');
    }

    public static function invalidTestPointId(): self
    {
        return new self('The test point id must be at least 1.');
    }

    public static function invalidColumnKey(): self
    {
        return new self('The column key is not a valid result identity.');
    }

    public static function missingCalibration(): self
    {
        return new self('The calibration does not exist.');
    }

    public static function unsupportedCalibrationMethod(): self
    {
        return new self('Test results can be recorded only for an internal calibration session.');
    }

    public static function invalidSessionStatus(): self
    {
        return new self('Only an in-progress calibration session can record test results.');
    }

    public static function missingTestProfileRequirement(): self
    {
        return new self('The test profile requirement snapshot does not exist.');
    }

    public static function unresolvedTestProfileMode(): self
    {
        return new self('An unresolved not-configured test profile requirement cannot record a test result.');
    }

    public static function invalidEffectiveMode(): self
    {
        return new self('The test profile requirement mode cannot record a test result.');
    }

    public static function unknownScope(): self
    {
        return new self('This calibration scope does not belong to this calibration session.');
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

    public static function unknownFrozenRow(): self
    {
        return new self('This test point is not in the frozen test table.');
    }

    public static function unknownFrozenColumn(): self
    {
        return new self('This column is not in the frozen test table.');
    }

    public static function ambiguousFrozenIdentity(): self
    {
        return new self('The frozen test table identity is ambiguous.');
    }

    public static function templateColumnNotEditable(): self
    {
        return new self('A template column cannot be entered manually.');
    }

    public static function unsupportedValueSource(): self
    {
        return new self('The frozen column value source cannot be entered manually.');
    }

    public static function notConfiguredColumn(): self
    {
        return new self('A not-configured test component cannot be entered.');
    }

    public static function unsupportedCompletionRule(): self
    {
        return new self('The frozen column completion rule cannot be entered.');
    }

    public static function unsupportedDataType(): self
    {
        return new self('The frozen column data type is not supported.');
    }

    public static function invalidNumericValue(): self
    {
        return new self('The test result must be a decimal number.');
    }

    public static function invalidTextValue(): self
    {
        return new self('The test result text must not be blank.');
    }

    public static function invalidDurationValue(): self
    {
        return new self('The test result duration must be a whole number of seconds.');
    }

    public static function invalidFrozenUnit(): self
    {
        return new self('The frozen column unit cannot be stored on a test result.');
    }

    public static function duplicateParentResultRows(): self
    {
        return new self('This test point has more than one result row.');
    }

    public static function duplicateResultCells(): self
    {
        return new self('This test result cell is duplicated.');
    }

    public static function unsafeParentRowDeletion(): self
    {
        return new self('The result row still holds remarks, readings, or legacy values and cannot be removed.');
    }
}
