<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Adds, updates, and removes Standard Usage rows for one in-progress internal session.
 *
 * Eligible standards come from the instrument type on the locked calibration.
 * Identity snapshots are copied from standard_master at add time. Update clears
 * nullable detail fields when the caller passes null and does not refresh
 * identity or assignment defaults. Remove renumbers the remaining rows.
 * Effective mode follows the PostgreSQL CASE: frozen required and optional stay,
 * and frozen not_configured uses resolved only when that value is required or
 * optional. This service does not call master or assignment writers, auth(),
 * Model::on(), or the default connection. SQLite compiles lockForUpdate()
 * without a real row lock.
 */
class RecordCalibrationStandardUsage
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    /**
     * @throws RecordCalibrationStandardUsageException
     * @throws CalibrationSessionConnectionException
     * @throws QueryException
     */
    public function add(
        int $calibrationId,
        int $standardId,
        ?string $standardRange,
        ?string $standardUnit,
        ?string $usagePurpose,
        ?string $remarks,
        Connection $connection,
    ): void {
        $connection = $this->open($connection, $calibrationId);

        if ($standardId < 1) {
            throw RecordCalibrationStandardUsageException::invalidStandardId();
        }

        $connection->transaction(function () use (
            $connection,
            $calibrationId,
            $standardId,
            $standardRange,
            $standardUnit,
            $usagePurpose,
            $remarks,
        ): void {
            $locked = $this->lockSession($connection, $calibrationId);
            $this->assertDistinctStandard($locked['usages'], $standardId);
            $assignment = $this->lockAssignment($connection, $locked['calibration'], $standardId);
            $master = $this->lockMaster($connection, $assignment, $standardId);

            $payload = [
                'calibration_id' => $calibrationId,
                'standard_instrument_id' => null,
                'standard_id' => $standardId,
                'usage_order' => $this->nextUsageOrder($locked['usages']),
                'usage_purpose' => $usagePurpose,
                'remarks' => $remarks,
                'created_at' => now(),
                'standard_range' => $standardRange ?? $assignment->default_range,
                'standard_unit' => $standardUnit ?? $assignment->default_unit,
                'snapshot_standard_code' => $master->standard_code,
                'snapshot_instrument_name' => $master->instrument_name,
                'snapshot_type_specification' => $master->type_specification,
                'snapshot_serial_number' => $master->serial_number,
            ];

            if ($connection->getDriverName() !== 'pgsql') {
                $payload['calibration_standard_usage_id'] = ((int) $connection->table('calibration_standard_usage')->max('calibration_standard_usage_id')) + 1;
            }

            $connection->table('calibration_standard_usage')->insert($payload);
        });
    }

    /**
     * @throws RecordCalibrationStandardUsageException
     * @throws CalibrationSessionConnectionException
     * @throws QueryException
     */
    public function update(
        int $calibrationId,
        int $usageId,
        ?string $standardRange,
        ?string $standardUnit,
        ?string $usagePurpose,
        ?string $remarks,
        Connection $connection,
    ): void {
        $connection = $this->open($connection, $calibrationId);

        if ($usageId < 1) {
            throw RecordCalibrationStandardUsageException::invalidUsageId();
        }

        $connection->transaction(function () use (
            $connection,
            $calibrationId,
            $usageId,
            $standardRange,
            $standardUnit,
            $usagePurpose,
            $remarks,
        ): void {
            $locked = $this->lockSession($connection, $calibrationId);
            $this->ownedUsage($connection, $usageId, $locked['usages']);

            $connection->table('calibration_standard_usage')
                ->where('calibration_standard_usage_id', $usageId)
                ->where('calibration_id', $calibrationId)
                ->update([
                    'standard_range' => $standardRange,
                    'standard_unit' => $standardUnit,
                    'usage_purpose' => $usagePurpose,
                    'remarks' => $remarks,
                ]);
        });
    }

    /**
     * @throws RecordCalibrationStandardUsageException
     * @throws CalibrationSessionConnectionException
     * @throws QueryException
     */
    public function remove(int $calibrationId, int $usageId, Connection $connection): void
    {
        $connection = $this->open($connection, $calibrationId);

        if ($usageId < 1) {
            throw RecordCalibrationStandardUsageException::invalidUsageId();
        }

        $connection->transaction(function () use ($connection, $calibrationId, $usageId): void {
            $locked = $this->lockSession($connection, $calibrationId);
            $this->ownedUsage($connection, $usageId, $locked['usages']);

            $deleted = $connection->table('calibration_standard_usage')
                ->where('calibration_standard_usage_id', $usageId)
                ->where('calibration_id', $calibrationId)
                ->delete();

            if ($deleted !== 1) {
                throw RecordCalibrationStandardUsageException::missingUsage();
            }

            $order = 1;

            foreach ($locked['usages'] as $usage) {
                if ((int) $usage->calibration_standard_usage_id === $usageId) {
                    continue;
                }

                if ((int) $usage->usage_order !== $order) {
                    $connection->table('calibration_standard_usage')
                        ->where('calibration_standard_usage_id', $usage->calibration_standard_usage_id)
                        ->where('calibration_id', $calibrationId)
                        ->update(['usage_order' => $order]);
                }

                $order++;
            }
        });
    }

    private function open(Connection $connection, int $calibrationId): Connection
    {
        $connection = $this->sessionConnection->resolve($connection);

        if ($calibrationId < 1) {
            throw RecordCalibrationStandardUsageException::invalidCalibrationId();
        }

        return $connection;
    }

    /**
     * @return array{calibration: object, usages: Collection<int, object>}
     */
    private function lockSession(Connection $connection, int $calibrationId): array
    {
        $calibration = $connection->table('calibration')
            ->where('calibration_id', $calibrationId)
            ->lockForUpdate()
            ->first();

        if ($calibration === null) {
            throw RecordCalibrationStandardUsageException::missingCalibration();
        }

        if ((string) $calibration->calibration_method !== Calibration::METHOD_INTERNAL) {
            throw RecordCalibrationStandardUsageException::invalidCalibrationMethod();
        }

        if ((string) $calibration->session_status !== Calibration::STATUS_IN_PROGRESS) {
            throw RecordCalibrationStandardUsageException::invalidCalibrationStatus();
        }

        $snapshot = $connection->table('calibration_requirement_snapshot')
            ->where('calibration_id', $calibrationId)
            ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_STANDARDS)
            ->orderBy('calibration_requirement_snapshot_id')
            ->lockForUpdate()
            ->first();

        if ($snapshot === null) {
            throw RecordCalibrationStandardUsageException::missingStandardsRequirement();
        }

        $this->assertOperableMode($snapshot);

        $usages = $connection->table('calibration_standard_usage')
            ->where('calibration_id', $calibrationId)
            ->orderBy('usage_order')
            ->orderBy('calibration_standard_usage_id')
            ->lockForUpdate()
            ->get();

        return [
            'calibration' => $calibration,
            'usages' => $usages,
        ];
    }

    private function assertOperableMode(object $snapshot): void
    {
        $frozen = (string) $snapshot->frozen_mode;
        $resolved = $snapshot->resolved_mode !== null ? (string) $snapshot->resolved_mode : null;
        $mode = $this->effectiveMode($frozen, $resolved);

        if (in_array($mode, [InstrumentTypeRequirement::MODE_REQUIRED, InstrumentTypeRequirement::MODE_OPTIONAL], true)) {
            return;
        }

        if ($frozen === InstrumentTypeRequirement::MODE_NOT_CONFIGURED) {
            throw RecordCalibrationStandardUsageException::unresolvedStandardsMode();
        }

        throw RecordCalibrationStandardUsageException::invalidEffectiveMode();
    }

    /**
     * PostgreSQL calibration_session_effective_mode CASE.
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
     * @param  Collection<int, object>  $usages
     */
    private function assertDistinctStandard(Collection $usages, int $standardId): void
    {
        foreach ($usages as $usage) {
            if ($usage->standard_id !== null && (int) $usage->standard_id === $standardId) {
                throw RecordCalibrationStandardUsageException::duplicateStandardUsage();
            }
        }
    }

    private function lockAssignment(Connection $connection, object $calibration, int $standardId): object
    {
        $assignment = $connection->table('instrument_type_standard_assignment')
            ->where('instrument_type_id', $calibration->instrument_type_id)
            ->where('standard_id', $standardId)
            ->orderBy('instrument_type_standard_assignment_id')
            ->lockForUpdate()
            ->first();

        if ($assignment === null) {
            throw RecordCalibrationStandardUsageException::missingAssignment();
        }

        return $assignment;
    }

    private function lockMaster(Connection $connection, object $assignment, int $standardId): object
    {
        $master = $connection->table('standard_master')
            ->where('standard_id', $standardId)
            ->lockForUpdate()
            ->first();

        if ($master === null || (int) $master->standard_id !== (int) $assignment->standard_id) {
            throw RecordCalibrationStandardUsageException::missingMasterStandard();
        }

        return $master;
    }

    /**
     * @param  Collection<int, object>  $usages
     */
    private function nextUsageOrder(Collection $usages): int
    {
        if ($usages->isEmpty()) {
            return 1;
        }

        return ((int) $usages->max(fn (object $usage): int => (int) $usage->usage_order)) + 1;
    }

    /**
     * @param  Collection<int, object>  $usages
     */
    private function ownedUsage(Connection $connection, int $usageId, Collection $usages): object
    {
        foreach ($usages as $usage) {
            if ((int) $usage->calibration_standard_usage_id === $usageId) {
                return $usage;
            }
        }

        $owner = $connection->table('calibration_standard_usage')
            ->where('calibration_standard_usage_id', $usageId)
            ->value('calibration_id');

        if ($owner === null) {
            throw RecordCalibrationStandardUsageException::missingUsage();
        }

        throw RecordCalibrationStandardUsageException::foreignSessionUsage();
    }
}
