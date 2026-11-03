<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class StartCalibrationHttpException extends RuntimeException implements ShouldntReport
{
    public static function writesUnavailable(): self
    {
        return new self('Starting a calibration session is not available in this environment.');
    }
}
