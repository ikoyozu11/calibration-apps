<?php

namespace App\CalibrationLabels;

use RuntimeException;

final class CalibrationLabelException extends RuntimeException
{
    public static function unavailableValue(string $field): self
    {
        return new self('Calibration Label cannot be generated because '.$field.' is unavailable.');
    }

    public static function unsupportedSession(): self
    {
        return new self('Calibration Label is available only for completed INTERNAL calibration sessions.');
    }

    public static function textDoesNotFit(string $field): self
    {
        return new self('Calibration Label '.$field.' cannot fit within the approved label area.');
    }
}
