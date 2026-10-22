<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;

/**
 * Resolves one frozen not_configured requirement for one in-progress session.
 *
 * The caller passes a Laravel users.id. This service stores the app_user_id
 * returned by ResolveDomainActor and does not accept an actor id of its own.
 * It does not call auth(), read instrument-type masters, confirm configuration,
 * or change frozen_mode. Staging acquisition stays outside this service and
 * outside Pest. SQLite compiles lockForUpdate() without the PostgreSQL freeze
 * trigger or a real row lock.
 */
class ResolveCalibrationRequirement
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
        private ResolveDomainActor $actors,
    ) {}

    public function resolve(
        int $calibrationId,
        string $requirementCategory,
        string $resolvedMode,
        string $reason,
        int $laravelUserId,
        Connection $connection,
    ): void {
        $connection = $this->sessionConnection->resolve($connection);

        if ($calibrationId < 1) {
            throw ResolveCalibrationRequirementException::invalidCalibrationId();
        }

        $category = $this->category($requirementCategory);
        $resolvedMode = $this->mode($resolvedMode);
        $reason = $this->reason($reason);

        $connection->transaction(function () use ($connection, $calibrationId, $category, $resolvedMode, $reason, $laravelUserId): void {
            $calibration = $connection->table('calibration')
                ->where('calibration_id', $calibrationId)
                ->lockForUpdate()
                ->first();

            if ($calibration === null) {
                throw ResolveCalibrationRequirementException::missingCalibration();
            }

            if ($calibration->session_status !== Calibration::STATUS_IN_PROGRESS) {
                throw ResolveCalibrationRequirementException::invalidSessionStatus();
            }

            $snapshot = $connection->table('calibration_requirement_snapshot')
                ->where('calibration_id', $calibrationId)
                ->where('requirement_category', $category)
                ->lockForUpdate()
                ->first();

            if ($snapshot === null) {
                throw ResolveCalibrationRequirementException::missingSnapshot();
            }

            if ($snapshot->frozen_mode !== InstrumentTypeRequirement::MODE_NOT_CONFIGURED) {
                throw ResolveCalibrationRequirementException::frozenModeNotEligible();
            }

            if ($snapshot->resolved_mode !== null) {
                throw ResolveCalibrationRequirementException::alreadyResolved();
            }

            if ($category === InstrumentTypeRequirement::CATEGORY_ENVIRONMENT
                && $resolvedMode === InstrumentTypeRequirement::MODE_REQUIRED
                && ! $this->hasRequiredEnvironmentParameterSnapshot($connection, $calibrationId)) {
                throw ResolveCalibrationRequirementException::missingRequiredEnvironmentParameter();
            }

            $appUserId = $this->actors->resolve($laravelUserId, $connection);

            $updated = $connection->table('calibration_requirement_snapshot')
                ->where('calibration_id', $calibrationId)
                ->where('requirement_category', $category)
                ->update([
                    'resolved_mode' => $resolvedMode,
                    'resolved_at' => now(),
                    'resolved_reason' => $reason,
                    'resolved_by_user_id' => $appUserId,
                ]);

            if ($updated !== 1) {
                throw ResolveCalibrationRequirementException::missingSnapshot();
            }
        });
    }

    private function category(string $requirementCategory): string
    {
        if (! in_array($requirementCategory, InstrumentTypeRequirement::CATEGORIES, true)) {
            throw ResolveCalibrationRequirementException::invalidCategory();
        }

        return $requirementCategory;
    }

    private function mode(string $resolvedMode): string
    {
        if (! in_array($resolvedMode, [
            InstrumentTypeRequirement::MODE_REQUIRED,
            InstrumentTypeRequirement::MODE_OPTIONAL,
        ], true)) {
            throw ResolveCalibrationRequirementException::invalidMode();
        }

        return $resolvedMode;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ResolveCalibrationRequirementException::emptyReason();
        }

        return $reason;
    }

    private function hasRequiredEnvironmentParameterSnapshot(Connection $connection, int $calibrationId): bool
    {
        return $connection->table('calibration_environment_parameter_snapshot')
            ->where('calibration_id', $calibrationId)
            ->where('requirement_mode', InstrumentTypeRequirement::MODE_REQUIRED)
            ->where('inclusion_confirmed', true)
            ->exists();
    }
}
