<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class ConfigureInstrumentTypeRequirementException extends RuntimeException implements ShouldntReport
{
    public static function instrumentTypeMissing(): self
    {
        return new self('The instrument type does not exist.');
    }

    public static function unknownCategory(): self
    {
        return new self('The requirement category is not supported.');
    }

    public static function unknownMode(): self
    {
        return new self('The requirement mode is not supported.');
    }

    public static function environmentRequiredParameterMissing(): self
    {
        return new self('Environment required needs at least one required environment parameter.');
    }
}
