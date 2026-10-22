<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class CancelCalibrationSessionException extends RuntimeException implements ShouldntReport
{
    public static function invalidCalibrationId(): self
    {
        return new self('The calibration id must be at least 1.');
    }

    public static function emptyReason(): self
    {
        return new self('The cancellation reason is required.');
    }

    public static function missingCalibration(): self
    {
        return new self('The calibration does not exist.');
    }

    public static function invalidSessionStatus(): self
    {
        return new self('Only an in-progress calibration session can be cancelled.');
    }

    public static function alreadyCancelled(): self
    {
        return new self('This calibration session has already been cancelled.');
    }

    public static function inconsistentCancellation(): self
    {
        return new self('Cancellation audit data is partially recorded and cannot be repaired.');
    }
}
