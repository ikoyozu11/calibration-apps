<?php

namespace App\TestColumns;

use App\CalibrationSessions\CalibrationSessionConnection;
use App\Models\Calibration;
use App\Models\CalibrationScope;
use App\Models\InstrumentType;
use App\Models\TestProfile;
use App\Models\TestProfileVersion;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pins Active Test Tables onto an existing calibration as frozen scopes.
 *
 * Lock order is compatible with Publish: calibration, then test_profile rows
 * by test_profile_id, then test_profile_version rows by version id. Publish
 * locks one profile then its draft and active versions, so these paths cannot
 * wait on each other in reverse order.
 *
 * Results of ActiveTestTableScopeResolver are not trusted across requests.
 * This service always locks, then resolves again inside the same transaction.
 *
 * Resolver is the source of Active candidates. This service locks profiles,
 * then resolves again in the same transaction so an archive that commits
 * before the lock cannot be pinned from a stale plan.
 */
class CalibrationScopePinning
{
    public function __construct(
        private ActiveTestTableScopeResolver $resolver,
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    /**
     * @return Collection<int, CalibrationScope>
     */
    public function pin(
        Calibration $calibration,
        InstrumentType $instrumentType,
        ?Connection $connection = null,
    ): Collection {
        $connection = $this->sessionConnection->resolve($connection);
        $connectionName = $connection->getName();

        return $connection->transaction(function () use ($connection, $connectionName, $calibration, $instrumentType): Collection {
            $calibration = Calibration::on($connectionName)
                ->whereKey($calibration->getKey())
                ->lockForUpdate()
                ->first();

            if ($calibration === null) {
                throw new CalibrationScopePinException('The calibration does not exist.');
            }

            $instrumentType = InstrumentType::on($connectionName)
                ->whereKey($instrumentType->getKey())
                ->first();

            if ($instrumentType === null) {
                throw new CalibrationScopePinException('The instrument type does not exist.');
            }

            if ((int) $calibration->instrument_id !== (int) $instrumentType->instrument_id) {
                throw new CalibrationScopePinException('This instrument type does not belong to the calibration instrument.');
            }

            $typeId = (int) $instrumentType->instrument_type_id;

            $this->lockTablesForType($connectionName, $typeId);

            $existing = $this->scopesForType($connectionName, (int) $calibration->calibration_id, $typeId);

            if ($existing->isNotEmpty()) {
                if ($this->allPinned($existing)) {
                    return $existing;
                }

                throw new CalibrationScopePinException('This calibration already has unpinned scopes for this instrument type.');
            }

            $plan = $this->resolver->resolve($instrumentType, $connection);

            if (! $plan->hasReadyTemplates()) {
                throw new CalibrationScopePinException('No active Test Table is available for this instrument type.');
            }

            $this->insertCandidates($connection, $calibration, $plan);

            return $this->scopesForType($connectionName, (int) $calibration->calibration_id, $typeId);
        });
    }

    private function lockTablesForType(string $connectionName, int $typeId): void
    {
        $profileIds = TestProfile::on($connectionName)
            ->where('instrument_type_id', $typeId)
            ->orderBy('test_profile_id')
            ->lockForUpdate()
            ->pluck('test_profile_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($profileIds === []) {
            return;
        }

        TestProfileVersion::on($connectionName)
            ->whereIn('test_profile_id', $profileIds)
            ->orderBy('test_profile_id')
            ->orderBy('test_profile_version_id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @return Collection<int, CalibrationScope>
     */
    private function scopesForType(string $connectionName, int $calibrationId, int $typeId): Collection
    {
        return CalibrationScope::on($connectionName)
            ->where('calibration_id', $calibrationId)
            ->where('instrument_type_id', $typeId)
            ->orderBy('scope_order')
            ->orderBy('calibration_scope_id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, CalibrationScope>  $scopes
     */
    private function allPinned(Collection $scopes): bool
    {
        return $scopes->every(
            fn (CalibrationScope $scope): bool => $scope->test_profile_version_id !== null
                && is_array($scope->test_table_snapshot)
                && $scope->test_table_snapshot !== [],
        );
    }

    private function insertCandidates(Connection $connection, Calibration $calibration, ActiveTestTableScopePlan $plan): void
    {
        $baseOrder = (int) $connection->table('calibration_scope')
            ->where('calibration_id', $calibration->calibration_id)
            ->max('scope_order');

        foreach ($plan->candidates as $candidate) {
            $payload = [
                'calibration_id' => (int) $calibration->calibration_id,
                'instrument_type_id' => $candidate->instrumentTypeId,
                'test_profile_id' => $candidate->testProfileId,
                'test_profile_version_id' => $candidate->testProfileVersionId,
                'test_table_snapshot' => json_encode($candidate->snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'scope_order' => $baseOrder + $candidate->scopeOrder,
                'remarks' => null,
                'created_at' => now(),
            ];

            if ($connection->getDriverName() !== 'pgsql') {
                $payload['calibration_scope_id'] = ((int) $connection->table('calibration_scope')->max('calibration_scope_id')) + 1;
            }

            $connection->table('calibration_scope')->insert($payload);
        }
    }
}
