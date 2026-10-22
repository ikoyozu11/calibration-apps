<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class StoreExternalCalibrationDocumentException extends RuntimeException implements ShouldntReport
{
    public static function invalidCalibrationId(): self
    {
        return new self('The calibration id must be at least 1.');
    }

    public static function invalidFilename(): self
    {
        return new self('The original filename must be a single PDF file name.');
    }

    public static function emptyPdf(): self
    {
        return new self('The PDF contents are required.');
    }

    public static function invalidPdfSignature(): self
    {
        return new self('The PDF contents must begin with %PDF-.');
    }

    public static function fileTooLarge(): self
    {
        return new self('The PDF exceeds the configured size limit.');
    }

    public static function missingCalibration(): self
    {
        return new self('The calibration does not exist.');
    }

    public static function invalidMethod(): self
    {
        return new self('Vendor PDFs can be stored only for an external calibration session.');
    }

    public static function invalidSessionStatus(): self
    {
        return new self('Only an in-progress calibration session can store a vendor PDF.');
    }

    public static function invalidActiveDocumentState(): self
    {
        return new self('The active vendor PDF record is inconsistent.');
    }

    public static function invalidStorageDisk(): self
    {
        return new self('Vendor PDFs require the private calibration documents disk.');
    }

    public static function storageWriteFailure(): self
    {
        return new self('The vendor PDF could not be stored.');
    }
}
