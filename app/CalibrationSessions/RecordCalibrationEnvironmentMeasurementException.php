<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class RecordCalibrationEnvironmentMeasurementException extends RuntimeException implements ShouldntReport
{
    public static function invalidCalibrationId(): self
    {
        return new self('The calibration id must be at least 1.');
    }

    public static function invalidParameterId(): self
    {
        return new self('The parameter id must be at least 1.');
    }

    public static function missingCalibration(): self
    {
        return new self('The calibration does not exist.');
    }

    public static function unsupportedCalibrationMethod(): self
    {
        return new self('Environment measurements can be recorded only for an internal calibration session.');
    }

    public static function invalidSessionStatus(): self
    {
        return new self('Only an in-progress calibration session can record environment measurements.');
    }

    public static function missingEnvironmentRequirement(): self
    {
        return new self('The environment requirement snapshot does not exist.');
    }

    public static function unresolvedEnvironmentMode(): self
    {
        return new self('An unresolved not-configured environment requirement cannot record a measurement.');
    }

    public static function invalidEffectiveMode(): self
    {
        return new self('The environment requirement mode cannot record a measurement.');
    }

    public static function unknownSessionParameter(): self
    {
        return new self('This parameter is not frozen on this calibration session.');
    }

    public static function unsupportedParameterDataType(): self
    {
        return new self('The frozen environment parameter data type is not supported.');
    }

    public static function invalidNumericValue(): self
    {
        return new self('The environment measurement must be a decimal number.');
    }

    public static function invalidTextValue(): self
    {
        return new self('The environment measurement text must not be blank.');
    }
}
