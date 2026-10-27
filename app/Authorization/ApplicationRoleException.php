<?php

namespace App\Authorization;

use RuntimeException;

/**
 * Fail-closed role resolution errors. Callers must deny access.
 */
final class ApplicationRoleException extends RuntimeException
{
    public static function missing(int $laravelUserId): self
    {
        return new self("No application role is assigned for Laravel user [{$laravelUserId}].");
    }

    public static function ambiguous(int $laravelUserId): self
    {
        return new self("Ambiguous application role assignment for Laravel user [{$laravelUserId}].");
    }

    public static function invalid(int $laravelUserId, string $roleName): self
    {
        return new self("Invalid application role [{$roleName}] for Laravel user [{$laravelUserId}].");
    }

    public static function storageFailure(int $laravelUserId, string $reason): self
    {
        return new self("Application role storage failed for Laravel user [{$laravelUserId}]: {$reason}");
    }
}
