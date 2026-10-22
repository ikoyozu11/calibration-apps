<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class CalibrationSessionConnectionException extends RuntimeException implements ShouldntReport
{
    public const REASON_PRODUCTION = 'production';

    public const REASON_UNSAFE_DEFAULT = 'unsafe_default';

    public const REASON_AMBIGUOUS = 'ambiguous';

    public const REASON_STAGING_REQUIRED = 'staging_required';

    public const REASON_IDENTITY_MISMATCH = 'identity_mismatch';

    public function __construct(
        string $message,
        public string $reason = self::REASON_AMBIGUOUS,
    ) {
        parent::__construct($message);
    }

    public static function production(): self
    {
        return new self(
            'Refusing to use the production database for a calibration session workflow.',
            self::REASON_PRODUCTION,
        );
    }

    public static function unsafeDefault(): self
    {
        return new self(
            'Refusing to use the default PostgreSQL connection for a calibration session workflow.',
            self::REASON_UNSAFE_DEFAULT,
        );
    }

    public static function ambiguous(): self
    {
        return new self(
            'Calibration session workflow requires an explicit safe database connection.',
            self::REASON_AMBIGUOUS,
        );
    }

    public static function stagingRequired(): self
    {
        return new self(
            'PostgreSQL calibration session workflow requires calibration_staging_db.',
            self::REASON_STAGING_REQUIRED,
        );
    }

    public static function identityMismatch(): self
    {
        return new self(
            'PostgreSQL connection identity does not match calibration_staging_db.',
            self::REASON_IDENTITY_MISMATCH,
        );
    }

    public function isProduction(): bool
    {
        return $this->reason === self::REASON_PRODUCTION;
    }
}
