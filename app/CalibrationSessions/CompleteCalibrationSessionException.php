<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class CompleteCalibrationSessionException extends RuntimeException implements ShouldntReport
{
    public static function invalidCalibrationId(): self
    {
        return new self('The calibration id must be at least 1.');
    }

    public static function invalidDates(): self
    {
        return new self('The calibration date and calibration due must be valid dates in YYYY-MM-DD format.');
    }

    public static function dueBeforeCalibrationDate(): self
    {
        return new self('Calibration due must be on or after the calibration date.');
    }

    public static function missingCalibration(): self
    {
        return new self('The calibration does not exist.');
    }

    public static function invalidSessionStatus(): self
    {
        return new self('Only an in-progress calibration session can be completed.');
    }

    public static function unsupportedMethod(): self
    {
        return new self('Only an internal or external calibration session can be completed.');
    }

    public static function missingFrozenIdentity(): self
    {
        return new self('Complete requires a frozen instrument identity and location snapshot.');
    }

    public static function missingInternalProvider(): self
    {
        return new self('Internal complete requires Internal Calibration Team.');
    }

    public static function missingRequirementSnapshot(): self
    {
        return new self('All four requirement categories must be frozen at start.');
    }

    public static function unresolvedRequirementMode(string $category): self
    {
        return new self("The {$category} requirement is not configured.");
    }

    public static function missingRequiredStandards(): self
    {
        return new self('Required standards need at least one standard.');
    }

    public static function missingRequiredActivity(): self
    {
        return new self('Required calibration activity needs at least one activity.');
    }

    public static function unconfirmedEnvironment(): self
    {
        return new self('Environment requires explicit configuration confirmation before complete.');
    }

    public static function unconfirmedEnvironmentInclusion(): self
    {
        return new self('Unconfirmed environment parameter inclusions block complete.');
    }

    public static function incompleteEnvironment(): self
    {
        return new self('Required environment parameters are missing values.');
    }

    public static function missingRequiredEnvironmentParameter(): self
    {
        return new self('Required environment needs at least one confirmed required parameter.');
    }

    public static function missingRequiredPin(): self
    {
        return new self('Required test profile must be pinned.');
    }

    public static function incompleteTestComponents(string $reason): self
    {
        return new self($reason);
    }

    public static function invalidActivePdfCount(): self
    {
        return new self('External complete requires exactly one active vendor PDF.');
    }
}
