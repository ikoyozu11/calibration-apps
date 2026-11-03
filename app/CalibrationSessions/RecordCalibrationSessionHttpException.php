<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class RecordCalibrationSessionHttpException extends RuntimeException implements ShouldntReport
{
    public static function writesUnavailable(): self
    {
        return new self('Recording this calibration session is not available in this environment.');
    }
}
