<?php

namespace App\Certificates;

use RuntimeException;

final class CertificateIssuanceException extends RuntimeException
{
    public static function unsupportedCalibration(): self
    {
        return new self('Only completed internal calibrations can issue an internal certificate.');
    }

    public static function existingWithoutFile(): self
    {
        return new self('An internal certificate record already exists but its PDF is unavailable.');
    }

    public static function sequenceOverflow(): self
    {
        return new self('The annual internal certificate sequence has exceeded 999.');
    }

    public static function storageFailed(): self
    {
        return new self('The internal certificate PDF could not be stored.');
    }
}
