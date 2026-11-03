<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class ReadCalibrationSessionHttpException extends RuntimeException implements ShouldntReport
{
    public static function readsUnavailable(): self
    {
        return new self('Reading this calibration document is not available in this environment.');
    }

    public static function sessionReadsUnavailable(): self
    {
        return new self('Reading this calibration session is not available in this environment.');
    }
}
