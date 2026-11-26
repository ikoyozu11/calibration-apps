<?php

namespace App\CalibrationDueNotifications;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;

final class CurrentCalibrationNotificationCandidateDiscovery implements CalibrationPairStateDiscovery
{
    public function __construct(
        private CurrentCalibrationDueResolver $dueResolver,
        private CalibrationNotificationCandidateEngine $engine,
    ) {}

    /**
     * Discover current pair-level rows through the existing guarded resolver.
     * This adapter only reads calibration data and performs no persistence.
     *
     * @return Collection<int, CalibrationNotificationCandidate>
     */
    public function discover(Connection $connection, CarbonInterface $evaluationDate): Collection
    {
        return $this->engine->fromSources(
            $this->dueResolver->resolve($connection),
            $evaluationDate,
        );
    }

    public function discoverPair(
        Connection $connection,
        int $instrumentId,
        int $instrumentTypeId,
        CarbonInterface $evaluationDate,
    ): CalibrationNotificationPairState {
        $source = $this->dueResolver->resolvePair($connection, $instrumentId, $instrumentTypeId);

        if ($source === null) {
            return CalibrationNotificationPairState::targetMissing($instrumentId, $instrumentTypeId);
        }

        if ($this->positiveInteger($source->latest_calibration_id ?? null) === null
            || $source->calibration_due === null) {
            return CalibrationNotificationPairState::notCalibrated($instrumentId, $instrumentTypeId);
        }

        $candidate = $this->engine->fromSource($source, $evaluationDate);

        if ($candidate !== null) {
            return CalibrationNotificationPairState::fromCandidate($candidate);
        }

        if (! $this->validDueDate($source->calibration_due)
            || ! is_string($source->instrument_name ?? null)
            || trim($source->instrument_name) === ''
            || ! is_string($source->instrument_type ?? null)
            || trim($source->instrument_type) === '') {
            return CalibrationNotificationPairState::sourceInvalid($instrumentId, $instrumentTypeId);
        }

        return CalibrationNotificationPairState::onTrack($instrumentId, $instrumentTypeId);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function validDueDate(mixed $value): bool
    {
        try {
            CarbonImmutable::parse((string) $value, 'Asia/Jakarta');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
