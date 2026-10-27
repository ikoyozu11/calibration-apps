<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use Illuminate\Database\Connection;

/**
 * Cancels one in-progress calibration session, once.
 *
 * Trust boundary: $laravelUserId must be a trusted server-side identity
 * (future HTTP/Livewire: (int) auth()->id() after auth middleware). This
 * service does not call auth() and cannot prove that a caller-supplied
 * integer is the authenticated user — that remains an HTTP-layer duty.
 *
 * Authorization (fail-closed, owner-aware):
 * - ResolveDomainActor on the session Connection → cancelling app_user_id
 * - ResolveApplicationRole on the Laravel auth DB → Administrator | Engineer | deny
 * - Engineer may cancel only when started_by_app_user_id matches the actor
 * - Administrator may cancel any in_progress session (including NULL owner)
 * - Viewer / roleless / invalid role → forbidden
 *
 * Does not accept role strings, isAdmin flags, or browser-supplied owner IDs.
 * Does not change started_by_app_user_id. Does not grant global session.cancel.
 */
class CancelCalibrationSession
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
        private ResolveDomainActor $actors,
        private AuthorizeCalibrationSessionCancel $authorizeCancel,
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

            $actorAppUserId = $this->actors->resolve($laravelUserId, $connection);

            $startedByAppUserId = $this->nullablePositiveInt($calibration->started_by_app_user_id ?? null);

            $this->authorizeCancel->assertAllowed(
                $laravelUserId,
                $startedByAppUserId,
                $actorAppUserId,
            );

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
                    'cancelled_by_user_id' => $actorAppUserId,
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

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
