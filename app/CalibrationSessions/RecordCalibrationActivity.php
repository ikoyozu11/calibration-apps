<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Adds, updates, and removes catalog activities for one in-progress internal session.
 *
 * New rows come from an active calibration_activity_master record. Add copies
 * activity_name into both activity_name and activity_type. Update changes only
 * activity_description and activity_result. Remove renumbers the remaining rows,
 * including legacy free-text rows. Effective mode follows the PostgreSQL CASE.
 * This service does not call CalibrationActivityMasterWriter, auth(),
 * Model::on(), or the default connection. SQLite compiles lockForUpdate()
 * without a real row lock or trg_calibration_activity_mutable.
 */
class RecordCalibrationActivity
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    /**
     * @throws RecordCalibrationActivityException
     * @throws CalibrationSessionConnectionException
     * @throws QueryException
     */
    public function add(
        int $calibrationId,
        int $activityId,
        ?string $activityDescription,
        ?string $activityResult,
        Connection $connection,
    ): void {
        $connection = $this->open($connection, $calibrationId);

        if ($activityId < 1) {
            throw RecordCalibrationActivityException::invalidActivityId();
        }

        $connection->transaction(function () use (
            $connection,
            $calibrationId,
            $activityId,
            $activityDescription,
            $activityResult,
        ): void {
            $locked = $this->lockSession($connection, $calibrationId);
            $this->assertDistinctActivity($locked['activities'], $activityId);
            $name = $this->activeMasterName($this->lockMaster($connection, $activityId));

            $payload = [
                'calibration_id' => $calibrationId,
                'activity_type' => $name,
                'activity_id' => $activityId,
                'activity_name' => $name,
                'activity_description' => $activityDescription,
                'activity_result' => $activityResult,
                'activity_order' => $this->nextActivityOrder($locked['activities']),
                'created_at' => now(),
            ];

            if ($connection->getDriverName() !== 'pgsql') {
                $payload['calibration_activity_id'] = ((int) $connection->table('calibration_activity')->max('calibration_activity_id')) + 1;
            }

            $connection->table('calibration_activity')->insert($payload);
        });
    }

    /**
     * @throws RecordCalibrationActivityException
     * @throws CalibrationSessionConnectionException
     * @throws QueryException
     */
    public function update(
        int $calibrationId,
        int $calibrationActivityId,
        ?string $activityDescription,
        ?string $activityResult,
        Connection $connection,
    ): void {
        $connection = $this->open($connection, $calibrationId);

        if ($calibrationActivityId < 1) {
            throw RecordCalibrationActivityException::invalidCalibrationActivityId();
        }

        $connection->transaction(function () use (
            $connection,
            $calibrationId,
            $calibrationActivityId,
            $activityDescription,
            $activityResult,
        ): void {
            $locked = $this->lockSession($connection, $calibrationId);
            $this->ownedActivity($connection, $calibrationActivityId, $locked['activities']);

            $connection->table('calibration_activity')
                ->where('calibration_activity_id', $calibrationActivityId)
                ->where('calibration_id', $calibrationId)
                ->update([
                    'activity_description' => $activityDescription,
                    'activity_result' => $activityResult,
                ]);
        });
    }

    /**
     * @throws RecordCalibrationActivityException
     * @throws CalibrationSessionConnectionException
     * @throws QueryException
     */
    public function remove(
        int $calibrationId,
        int $calibrationActivityId,
        Connection $connection,
    ): void {
        $connection = $this->open($connection, $calibrationId);

        if ($calibrationActivityId < 1) {
            throw RecordCalibrationActivityException::invalidCalibrationActivityId();
        }

        $connection->transaction(function () use ($connection, $calibrationId, $calibrationActivityId): void {
            $locked = $this->lockSession($connection, $calibrationId);
            $this->ownedActivity($connection, $calibrationActivityId, $locked['activities']);

            $deleted = $connection->table('calibration_activity')
                ->where('calibration_activity_id', $calibrationActivityId)
                ->where('calibration_id', $calibrationId)
                ->delete();

            if ($deleted !== 1) {
                throw RecordCalibrationActivityException::missingSessionActivity();
            }

            $order = 1;

            foreach ($locked['activities'] as $activity) {
                if ((int) $activity->calibration_activity_id === $calibrationActivityId) {
                    continue;
                }

                if ((int) $activity->activity_order !== $order) {
                    $connection->table('calibration_activity')
                        ->where('calibration_activity_id', $activity->calibration_activity_id)
                        ->where('calibration_id', $calibrationId)
                        ->update(['activity_order' => $order]);
                }

                $order++;
            }
        });
    }

    private function open(Connection $connection, int $calibrationId): Connection
    {
        $connection = $this->sessionConnection->resolve($connection);

        if ($calibrationId < 1) {
            throw RecordCalibrationActivityException::invalidCalibrationId();
        }

        return $connection;
    }

    /**
     * @return array{calibration: object, activities: Collection<int, object>}
     */
    private function lockSession(Connection $connection, int $calibrationId): array
    {
        $calibration = $connection->table('calibration')
            ->where('calibration_id', $calibrationId)
            ->lockForUpdate()
            ->first();

        if ($calibration === null) {
            throw RecordCalibrationActivityException::missingCalibration();
        }

        if ((string) $calibration->calibration_method !== Calibration::METHOD_INTERNAL) {
            throw RecordCalibrationActivityException::unsupportedCalibrationMethod();
        }

        if ((string) $calibration->session_status !== Calibration::STATUS_IN_PROGRESS) {
            throw RecordCalibrationActivityException::invalidSessionStatus();
        }

        $snapshot = $connection->table('calibration_requirement_snapshot')
            ->where('calibration_id', $calibrationId)
            ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ACTIVITY)
            ->orderBy('calibration_requirement_snapshot_id')
            ->lockForUpdate()
            ->first();

        if ($snapshot === null) {
            throw RecordCalibrationActivityException::missingActivityRequirement();
        }

        $this->assertOperableMode($snapshot);

        $activities = $connection->table('calibration_activity')
            ->where('calibration_id', $calibrationId)
            ->orderBy('activity_order')
            ->orderBy('calibration_activity_id')
            ->lockForUpdate()
            ->get();

        return [
            'calibration' => $calibration,
            'activities' => $activities,
        ];
    }

    /**
     * @param  list<int|string>  $activityIds
     *
     * @throws RecordCalibrationActivityException
     * @throws CalibrationSessionConnectionException
     * @throws QueryException
     */
    public function sync(
        int $calibrationId,
        array $activityIds,
        Connection $connection,
    ): void {
        $connection = $this->open($connection, $calibrationId);
        $desired = $this->uniqueActivityIds($activityIds);

        $connection->transaction(function () use ($connection, $calibrationId, $desired): void {
            $locked = $this->lockSession($connection, $calibrationId);
            $current = [];

            foreach ($locked['activities'] as $activity) {
                if ($activity->activity_id !== null) {
                    $current[(int) $activity->activity_id] = (int) $activity->calibration_activity_id;
                }
            }

            foreach ($current as $activityId => $rowId) {
                if (! in_array($activityId, $desired, true)) {
                    $this->deleteOwnedActivity($connection, $calibrationId, $rowId);
                }
            }

            $activities = $this->lockedActivities($connection, $calibrationId);

            foreach ($desired as $activityId) {
                if (isset($current[$activityId])) {
                    continue;
                }

                $this->assertDistinctActivity($activities, $activityId);
                $name = $this->activeMasterName($this->lockMaster($connection, $activityId));
                $this->insertCatalogActivity($connection, $calibrationId, $activityId, $name, $activities);
                $activities = $this->lockedActivities($connection, $calibrationId);
            }

            $this->renumberActivities($connection, $calibrationId, $this->lockedActivities($connection, $calibrationId));
        });
    }

    private function assertOperableMode(object $snapshot): void
    {
        $frozen = (string) $snapshot->frozen_mode;

        if (in_array($frozen, [
            InstrumentTypeRequirement::MODE_REQUIRED,
            InstrumentTypeRequirement::MODE_OPTIONAL,
            InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
        ], true)) {
            return;
        }

        throw RecordCalibrationActivityException::invalidEffectiveMode();
    }

    /**
     * PostgreSQL calibration_requirement_effective_mode CASE.
     */
    private function effectiveMode(string $frozenMode, ?string $resolvedMode): string
    {
        if (in_array($frozenMode, [InstrumentTypeRequirement::MODE_REQUIRED, InstrumentTypeRequirement::MODE_OPTIONAL], true)) {
            return $frozenMode;
        }

        if (
            $frozenMode === InstrumentTypeRequirement::MODE_NOT_CONFIGURED
            && in_array($resolvedMode, [InstrumentTypeRequirement::MODE_REQUIRED, InstrumentTypeRequirement::MODE_OPTIONAL], true)
        ) {
            return $resolvedMode;
        }

        return InstrumentTypeRequirement::MODE_NOT_CONFIGURED;
    }

    /**
     * @param  Collection<int, object>  $activities
     */
    private function assertDistinctActivity(Collection $activities, int $activityId): void
    {
        foreach ($activities as $activity) {
            if ($activity->activity_id !== null && (int) $activity->activity_id === $activityId) {
                throw RecordCalibrationActivityException::duplicateActivity();
            }
        }
    }

    private function lockMaster(Connection $connection, int $activityId): object
    {
        $master = $connection->table('calibration_activity_master')
            ->where('activity_id', $activityId)
            ->lockForUpdate()
            ->first();

        if ($master === null) {
            throw RecordCalibrationActivityException::missingActivityMaster();
        }

        return $master;
    }

    private function activeMasterName(object $master): string
    {
        if (! $this->isActive($master->is_active)) {
            throw RecordCalibrationActivityException::inactiveActivityMaster();
        }

        $name = $master->activity_name;

        if (! is_string($name) || trim($name) === '') {
            throw RecordCalibrationActivityException::invalidMasterActivityName();
        }

        return $name;
    }

    private function isActive(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * @param  Collection<int, object>  $activities
     */
    private function nextActivityOrder(Collection $activities): int
    {
        if ($activities->isEmpty()) {
            return 1;
        }

        return ((int) $activities->max(fn (object $activity): int => (int) $activity->activity_order)) + 1;
    }

    /**
     * @param  Collection<int, object>  $activities
     */
    private function ownedActivity(Connection $connection, int $calibrationActivityId, Collection $activities): object
    {
        foreach ($activities as $activity) {
            if ((int) $activity->calibration_activity_id === $calibrationActivityId) {
                return $activity;
            }
        }

        $owner = $connection->table('calibration_activity')
            ->where('calibration_activity_id', $calibrationActivityId)
            ->value('calibration_id');

        if ($owner === null) {
            throw RecordCalibrationActivityException::missingSessionActivity();
        }

        throw RecordCalibrationActivityException::foreignSessionActivity();
    }

    /**
     * @param  list<int|string>  $activityIds
     * @return list<int>
     */
    private function uniqueActivityIds(array $activityIds): array
    {
        $desired = [];

        foreach ($activityIds as $activityId) {
            $id = (int) $activityId;

            if ($id < 1) {
                throw RecordCalibrationActivityException::invalidActivityId();
            }

            if (! in_array($id, $desired, true)) {
                $desired[] = $id;
            }
        }

        return $desired;
    }

    /**
     * @return Collection<int, object>
     */
    private function lockedActivities(Connection $connection, int $calibrationId): Collection
    {
        return $connection->table('calibration_activity')
            ->where('calibration_id', $calibrationId)
            ->orderBy('activity_order')
            ->orderBy('calibration_activity_id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, object>  $activities
     */
    private function insertCatalogActivity(
        Connection $connection,
        int $calibrationId,
        int $activityId,
        string $name,
        Collection $activities,
    ): void {
        $payload = [
            'calibration_id' => $calibrationId,
            'activity_type' => $name,
            'activity_id' => $activityId,
            'activity_name' => $name,
            'activity_description' => null,
            'activity_result' => null,
            'activity_order' => $this->nextActivityOrder($activities),
            'created_at' => now(),
        ];

        if ($connection->getDriverName() !== 'pgsql') {
            $payload['calibration_activity_id'] = ((int) $connection->table('calibration_activity')->max('calibration_activity_id')) + 1;
        }

        $connection->table('calibration_activity')->insert($payload);
    }

    private function deleteOwnedActivity(Connection $connection, int $calibrationId, int $calibrationActivityId): void
    {
        $deleted = $connection->table('calibration_activity')
            ->where('calibration_activity_id', $calibrationActivityId)
            ->where('calibration_id', $calibrationId)
            ->delete();

        if ($deleted !== 1) {
            throw RecordCalibrationActivityException::missingSessionActivity();
        }
    }

    /**
     * @param  Collection<int, object>  $activities
     */
    private function renumberActivities(Connection $connection, int $calibrationId, Collection $activities): void
    {
        $order = 1;

        foreach ($activities as $activity) {
            if ((int) $activity->activity_order !== $order) {
                $connection->table('calibration_activity')
                    ->where('calibration_activity_id', $activity->calibration_activity_id)
                    ->where('calibration_id', $calibrationId)
                    ->update(['activity_order' => $order]);
            }

            $order++;
        }
    }
}
