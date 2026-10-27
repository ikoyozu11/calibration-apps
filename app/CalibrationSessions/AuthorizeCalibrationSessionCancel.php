<?php

namespace App\CalibrationSessions;

use App\Authorization\ApplicationRoleName;
use App\Authorization\ResolveApplicationRole;

/**
 * Owner-aware cancel authorization for Calibration Sessions.
 *
 * session.cancel is intentionally NOT granted as a global Gate capability.
 * Engineer access requires started_by_app_user_id to match the caller's
 * resolved app_user_id. Administrator may cancel any in-progress session,
 * including legacy NULL owners. Viewer and roleless/invalid roles are denied.
 *
 * Role storage is read from the Laravel auth database via ResolveApplicationRole.
 * Ownership comparison uses domain values already resolved on the session
 * Connection (started_by_app_user_id + ResolveDomainActor result).
 */
final class AuthorizeCalibrationSessionCancel
{
    public function __construct(
        private readonly ResolveApplicationRole $roles,
    ) {}

    /**
     * @throws CancelCalibrationSessionException
     */
    public function assertAllowed(int $laravelUserId, ?int $startedByAppUserId, int $actorAppUserId): void
    {
        $role = $this->roles->tryResolve($laravelUserId);

        if ($role === null) {
            throw CancelCalibrationSessionException::forbidden();
        }

        if ($role === ApplicationRoleName::Administrator) {
            return;
        }

        if ($role === ApplicationRoleName::Engineer) {
            if ($startedByAppUserId === null || $startedByAppUserId !== $actorAppUserId) {
                throw CancelCalibrationSessionException::forbidden();
            }

            return;
        }

        throw CancelCalibrationSessionException::forbidden();
    }
}
