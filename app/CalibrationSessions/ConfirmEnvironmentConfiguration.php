<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;

/**
 * Confirms the environment requirement for one in-progress session, once.
 *
 * The caller passes a Laravel users.id. This service stores the app_user_id
 * returned by ResolveDomainActor and does not accept an actor id of its own.
 * It does not call auth(), resolve a not-configured mode, change parameter
 * inclusion, or record measurements. PostgreSQL does not yet reject a second
 * confirmation; this service does. Staging acquisition stays outside this
 * service and outside Pest. SQLite compiles lockForUpdate() without a real
 * row lock.
 */
class ConfirmEnvironmentConfiguration
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
        private ResolveDomainActor $actors,
    ) {}

    public function confirm(
        int $calibrationId,
        string $reason,
        int $laravelUserId,
        Connection $connection,
    ): void {
        $connection = $this->sessionConnection->resolve($connection);

        if ($calibrationId < 1) {
            throw ConfirmEnvironmentConfigurationException::invalidCalibrationId();
        }

        $reason = $this->reason($reason);

        $connection->transaction(function () use ($connection, $calibrationId, $reason, $laravelUserId): void {
            $calibration = $connection->table('calibration')
                ->where('calibration_id', $calibrationId)
                ->lockForUpdate()
                ->first();

            if ($calibration === null) {
                throw ConfirmEnvironmentConfigurationException::missingCalibration();
            }

            if ($calibration->session_status !== Calibration::STATUS_IN_PROGRESS) {
                throw ConfirmEnvironmentConfigurationException::invalidSessionStatus();
            }

            $snapshot = $connection->table('calibration_requirement_snapshot')
                ->where('calibration_id', $calibrationId)
                ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
                ->lockForUpdate()
                ->first();

            if ($snapshot === null) {
                throw ConfirmEnvironmentConfigurationException::missingSnapshot();
            }

            $this->assertConfirmable($snapshot);

            $appUserId = $this->actors->resolve($laravelUserId, $connection);

            $updated = $connection->table('calibration_requirement_snapshot')
                ->where('calibration_id', $calibrationId)
                ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
                ->whereNull('configuration_confirmed_at')
                ->whereNull('configuration_confirmed_reason')
                ->whereNull('configuration_confirmed_by_user_id')
                ->update([
                    'configuration_confirmed_at' => now(),
                    'configuration_confirmed_reason' => $reason,
                    'configuration_confirmed_by_user_id' => $appUserId,
                ]);

            if ($updated !== 1) {
                throw ConfirmEnvironmentConfigurationException::alreadyConfirmed();
            }
        });
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ConfirmEnvironmentConfigurationException::emptyReason();
        }

        return $reason;
    }

    private function assertConfirmable(object $snapshot): void
    {
        $populated = 0;

        foreach ([
            'configuration_confirmed_at',
            'configuration_confirmed_reason',
            'configuration_confirmed_by_user_id',
        ] as $field) {
            if ($snapshot->{$field} !== null) {
                $populated++;
            }
        }

        if ($populated === 3) {
            throw ConfirmEnvironmentConfigurationException::alreadyConfirmed();
        }

        if ($populated > 0) {
            throw ConfirmEnvironmentConfigurationException::inconsistentConfirmation();
        }

        $resolvedMode = $snapshot->resolved_mode !== null ? (string) $snapshot->resolved_mode : null;
        $effectiveMode = CalibrationSessionCompleteness::effectiveMode(
            (string) $snapshot->frozen_mode,
            $resolvedMode,
        );

        if ($effectiveMode === CalibrationSessionCompleteness::MODE_NOT_CONFIGURED) {
            throw ConfirmEnvironmentConfigurationException::unresolvedMode();
        }

        if (! in_array($effectiveMode, [
            CalibrationSessionCompleteness::MODE_REQUIRED,
            CalibrationSessionCompleteness::MODE_OPTIONAL,
        ], true)) {
            throw ConfirmEnvironmentConfigurationException::invalidEffectiveMode();
        }
    }
}
