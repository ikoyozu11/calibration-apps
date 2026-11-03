<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class CalibrationHttpConnectionException extends RuntimeException implements ShouldntReport
{
    public static function unavailable(): self
    {
        return new self('This calibration page is not available in this environment.');
    }
}
