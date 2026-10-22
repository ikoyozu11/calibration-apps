<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class PinCalibrationTestTablesException extends RuntimeException implements ShouldntReport
{
    public static function invalidCalibrationId(): self
    {
        return new self('The calibration id must be at least 1.');
    }

    public static function missingCalibration(): self
    {
        return new self('The calibration does not exist.');
    }

    public static function invalidMethod(): self
    {
        return new self('Test tables can be pinned only for an internal calibration session.');
    }

    public static function invalidSessionStatus(): self
    {
        return new self('Only an in-progress calibration session can pin test tables.');
    }

    public static function missingSnapshot(): self
    {
        return new self('The test profile requirement snapshot does not exist.');
    }

    public static function unresolvedMode(): self
    {
        return new self('An unresolved not-configured test profile cannot be pinned.');
    }

    public static function invalidEffectiveMode(): self
    {
        return new self('The test profile requirement mode cannot be pinned.');
    }

    public static function noEligibleActiveTable(): self
    {
        return new self('No active Test Table is available for this instrument type.');
    }

    public static function ambiguousActiveVersion(): self
    {
        return new self('A Test Table has more than one active version.');
    }

    public static function partiallyPinnedScopes(): self
    {
        return new self('This calibration has partially pinned test tables and cannot be repaired.');
    }

    public static function inconsistentPinnedScopes(): self
    {
        return new self('A pinned test table snapshot does not match its frozen version.');
    }

    public static function conflictingExistingScopes(): self
    {
        return new self('Existing pinned test tables conflict with this calibration.');
    }
}
