<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class StartCalibrationException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        string $message,
        public string $field = 'instrument_type',
    ) {
        parent::__construct($message);
    }

    public static function instrumentTypeMissing(): self
    {
        return new self('The instrument type does not exist.');
    }

    public static function parentInstrumentMissing(): self
    {
        return new self('The parent instrument does not exist.');
    }

    public static function inProgressExists(): self
    {
        return new self('An in-progress calibration session already exists for this instrument type.');
    }

    public static function invalidMethod(): self
    {
        return new self('Calibration method must be INTERNAL or EXTERNAL.');
    }

    public static function internalTeamMissing(): self
    {
        return new self('Internal Calibration Team provider is not available.', 'calibration_provider');
    }

    public static function environmentParameterMissing(): self
    {
        return new self('An environment parameter configuration is missing its parameter definition.');
    }

    public static function locationMissing(): self
    {
        return new self('The instrument location does not exist.', 'instrument');
    }

    public static function incompleteLocation(): self
    {
        return new self('The instrument location is incomplete.', 'instrument');
    }

    public static function incompleteInstrumentName(): self
    {
        return new self('The instrument name is incomplete.', 'instrument');
    }

    public static function incompleteTypeName(): self
    {
        return new self('The instrument type name is incomplete.', 'instrument_type');
    }

    public static function productionDatabase(): self
    {
        return new self('Refusing to start a calibration session on the production database.', 'database');
    }
}
