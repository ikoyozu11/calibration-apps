<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class RemoveExternalCalibrationDocumentException extends RuntimeException implements ShouldntReport
{
    public static function invalidCalibrationId(): self
    {
        return new self('The calibration id must be at least 1.');
    }

    public static function invalidDocumentId(): self
    {
        return new self('The document id must be at least 1.');
    }

    public static function missingCalibration(): self
    {
        return new self('The calibration does not exist.');
    }

    public static function invalidMethod(): self
    {
        return new self('Vendor PDFs can be removed only for an external calibration session.');
    }

    public static function invalidSessionStatus(): self
    {
        return new self('Only an in-progress calibration session can remove a vendor PDF.');
    }

    public static function missingDocument(): self
    {
        return new self('The vendor PDF does not exist.');
    }

    public static function foreignSessionDocument(): self
    {
        return new self('The vendor PDF does not belong to this calibration session.');
    }

    public static function alreadyRemoved(): self
    {
        return new self('The vendor PDF has already been removed.');
    }

    public static function supersededDocument(): self
    {
        return new self('A replaced vendor PDF cannot be removed.');
    }

    public static function invalidDocumentState(): self
    {
        return new self('The vendor PDF record is inconsistent and cannot be removed.');
    }

    public static function concurrentRemoval(): self
    {
        return new self('The vendor PDF could not be removed because its state changed.');
    }
}
