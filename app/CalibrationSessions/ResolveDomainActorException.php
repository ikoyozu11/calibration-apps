<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class ResolveDomainActorException extends RuntimeException implements ShouldntReport
{
    public static function invalidLaravelUserId(): self
    {
        return new self('The Laravel user id must be at least 1.');
    }

    public static function unmapped(): self
    {
        return new self('The Laravel user has no domain actor mapping.');
    }

    public static function inactiveMapping(): self
    {
        return new self('The domain actor mapping is inactive.');
    }

    public static function inconsistentInactivatedAt(): self
    {
        return new self('The active domain actor mapping has an inactivation timestamp.');
    }

    public static function missingAppUser(): self
    {
        return new self('The mapped app user does not exist.');
    }

    public static function inactiveAppUser(): self
    {
        return new self('The mapped app user is inactive.');
    }

    public static function duplicateMapping(): self
    {
        return new self('The Laravel user has more than one domain actor mapping.');
    }

    public static function missingActiveTransaction(): self
    {
        return new self('Domain actor resolution requires an active transaction.');
    }
}
