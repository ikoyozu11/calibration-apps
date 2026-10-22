<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use Illuminate\Database\Connection;

/**
 * Cancels one in-progress calibration session, once.
 *
 * The caller passes a Laravel users.id. This service stores the app_user_id
 * returned by ResolveDomainActor and does not accept an actor id of its own.
 * It updates only the cancellation columns on that calibration row. It does
 * not call auth(), check completeness, delete related records, or change
 * masters, due dates, or certificates. Staging acquisition stays outside this
 * service and outside Pest. SQLite compiles lockForUpdate() without the
 * PostgreSQL terminal trigger or a real row lock.
 */
class CancelCalibrationSession
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
        private ResolveDomainActor $actors,
    ) {}

    public function cancel(
        int $calibrationId,
        string $reason,
        int $laravelUserId,
        Connection $connection,
    ): void {
        $connection = $this->sessionConnection->resolve($connection);

        if ($calibrationId < 1) {
            throw CancelCalibrationSessionException::invalidCalibrationId();
        }

        $reason = $this->reason($reason);

        $connection->transaction(function () use ($connection, $calibrationId, $reason, $laravelUserId): void {
            $calibration = $connection->table('calibration')
                ->where('calibration_id', $calibrationId)
                ->lockForUpdate()
                ->first();

            if ($calibration === null) {
                throw CancelCalibrationSessionException::missingCalibration();
            }

            $this->assertCancellable($calibration);

            $appUserId = $this->actors->resolve($laravelUserId, $connection);

            $updated = $connection->table('calibration')
                ->where('calibration_id', $calibrationId)
                ->where('session_status', Calibration::STATUS_IN_PROGRESS)
                ->whereNull('cancelled_at')
                ->whereNull('cancelled_reason')
                ->whereNull('cancelled_by_user_id')
                ->update([
                    'session_status' => Calibration::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancelled_reason' => $reason,
                    'cancelled_by_user_id' => $appUserId,
                ]);

            if ($updated !== 1) {
                throw CancelCalibrationSessionException::alreadyCancelled();
            }
        });
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CancelCalibrationSessionException::emptyReason();
        }

        return $reason;
    }

    private function assertCancellable(object $calibration): void
    {
        if ($calibration->session_status === Calibration::STATUS_CANCELLED) {
            throw CancelCalibrationSessionException::alreadyCancelled();
        }

        if ($calibration->session_status !== Calibration::STATUS_IN_PROGRESS) {
            throw CancelCalibrationSessionException::invalidSessionStatus();
        }

        foreach ([
            'cancelled_at',
            'cancelled_reason',
            'cancelled_by_user_id',
        ] as $field) {
            if ($calibration->{$field} !== null) {
                throw CancelCalibrationSessionException::inconsistentCancellation();
            }
        }
    }
}
