<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class AssignInstrumentTypeEnvironmentParameterException extends RuntimeException implements ShouldntReport
{
    public static function instrumentTypeMissing(): self
    {
        return new self('The instrument type does not exist.');
    }

    public static function parameterMissing(): self
    {
        return new self('The environment parameter does not exist.');
    }

    public static function assignmentMissing(): self
    {
        return new self('The environment parameter is not assigned to this instrument type.');
    }

    public static function duplicateAssignment(): self
    {
        return new self('This environment parameter is already assigned to the instrument type.');
    }

    public static function unknownMode(): self
    {
        return new self('The assignment mode is not supported.');
    }

    public static function invalidDisplayOrder(): self
    {
        return new self('Display order must be an integer from 1 through 2147483647.');
    }

    public static function displayOrderTaken(): self
    {
        return new self('That display order is already used by another environment parameter.');
    }

    public static function lastRequiredParameter(): self
    {
        return new self('Environment required needs at least one required environment parameter.');
    }

    public static function reorderIncomplete(): self
    {
        return new self('Reorder must include every current environment parameter assignment once.');
    }

    public static function reorderDuplicate(): self
    {
        return new self('Reorder contains a duplicate environment parameter.');
    }

    public static function reorderUnknown(): self
    {
        return new self('Reorder contains an environment parameter that is not assigned.');
    }

    public static function reorderOrderExhausted(): self
    {
        return new self('Display order cannot be rearranged inside the integer range.');
    }
}
