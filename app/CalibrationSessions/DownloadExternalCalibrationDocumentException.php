<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class DownloadExternalCalibrationDocumentException extends RuntimeException implements ShouldntReport
{
    public static function invalidCalibrationId(): self
    {
        return new self('The calibration does not exist.');
    }

    public static function invalidDocumentId(): self
    {
        return new self('The calibration document does not exist.');
    }

    public static function missingCalibration(): self
    {
        return new self('The calibration does not exist.');
    }

    public static function missingDocument(): self
    {
        return new self('The calibration document does not exist.');
    }

    public static function unsupportedCalibrationMethod(): self
    {
        return new self('The calibration document does not exist.');
    }

    public static function invalidStoredPath(): self
    {
        return new self('The calibration document does not exist.');
    }

    public static function missingStoredFile(): self
    {
        return new self('The calibration document does not exist.');
    }

    public static function unreadableStoredFile(): self
    {
        return new self('The calibration document does not exist.');
    }
}
