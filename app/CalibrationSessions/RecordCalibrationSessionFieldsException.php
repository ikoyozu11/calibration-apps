<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class RecordCalibrationSessionFieldsException extends RuntimeException implements ShouldntReport
{
    public static function invalidSession(): self
    {
        return new self('The calibration session cannot be updated.');
    }

    public static function invalidDates(): self
    {
        return new self('Calibration Date and Calibration Due are required and must be valid.');
    }

    public static function dueBeforeDate(): self
    {
        return new self('Calibration Due cannot be earlier than Calibration Date.');
    }
}
