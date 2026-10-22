<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use App\TestColumns\TestTableSnapshotBuilder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Records or clears the note stored on one pinned calibration scope.
 *
 * The note is calibration_scope.remarks for one Test Result table. This
 * service does not create result rows, write other remarks columns, or call
 * CompleteCalibrationSession. SQLite compiles lockForUpdate() without a real
 * row lock or the PostgreSQL child trigger.
 */
class RecordCalibrationScopeRemarks
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    /**
     * @throws RecordCalibrationScopeRemarksException
     * @throws CalibrationSessionConnectionException
     * @throws QueryException
     */
    public function record(
        int $calibrationId,
        int $calibrationScopeId,
        ?string $remarks,
        Connection $connection,
    ): void {
        $connection = $this->sessionConnection->resolve($connection);

        if ($calibrationId < 1) {
            throw RecordCalibrationScopeRemarksException::invalidCalibrationId();
        }

        if ($calibrationScopeId < 1) {
            throw RecordCalibrationScopeRemarksException::invalidScopeId();
        }

        if ($remarks !== null && trim($remarks) === '') {
            throw RecordCalibrationScopeRemarksException::invalidRemarks();
        }

        $connection->transaction(function () use ($connection, $calibrationId, $calibrationScopeId, $remarks): void {
            $calibration = $this->lockCalibration($connection, $calibrationId);
            $this->assertOperableMode($this->lockRequirement($connection, $calibrationId));
            $scope = $this->selectedScope(
                $this->lockScopes($connection, $calibrationId),
                $calibrationId,
                $calibrationScopeId,
                (int) $calibration->instrument_type_id,
            );
            $this->assertPinnedSnapshot($scope);

            $connection->table('calibration_scope')
                ->where('calibration_scope_id', (int) $scope->calibration_scope_id)
                ->where('calibration_id', $calibrationId)
                ->update([
                    'remarks' => $remarks,
                ]);
        });
    }

    private function lockCalibration(Connection $connection, int $calibrationId): object
    {
        $calibration = $connection->table('calibration')
            ->where('calibration_id', $calibrationId)
            ->lockForUpdate()
            ->first();

        if ($calibration === null) {
            throw RecordCalibrationScopeRemarksException::missingCalibration();
        }

        if ((string) $calibration->calibration_method !== Calibration::METHOD_INTERNAL) {
            throw RecordCalibrationScopeRemarksException::unsupportedCalibrationMethod();
        }

        if ((string) $calibration->session_status !== Calibration::STATUS_IN_PROGRESS) {
            throw RecordCalibrationScopeRemarksException::invalidSessionStatus();
        }

        return $calibration;
    }

    private function lockRequirement(Connection $connection, int $calibrationId): object
    {
        $snapshot = $connection->table('calibration_requirement_snapshot')
            ->where('calibration_id', $calibrationId)
            ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_TEST_PROFILE)
            ->orderBy('calibration_requirement_snapshot_id')
            ->lockForUpdate()
            ->first();

        if ($snapshot === null) {
            throw RecordCalibrationScopeRemarksException::missingTestProfileRequirement();
        }

        return $snapshot;
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
            throw RecordCalibrationScopeRemarksException::unresolvedTestProfileMode();
        }

        throw RecordCalibrationScopeRemarksException::invalidEffectiveMode();
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
     * @return Collection<int, object>
     */
    private function lockScopes(Connection $connection, int $calibrationId): Collection
    {
        return $connection->table('calibration_scope')
            ->where('calibration_id', $calibrationId)
            ->orderBy('scope_order')
            ->orderBy('calibration_scope_id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, object>  $scopes
     */
    private function selectedScope(Collection $scopes, int $calibrationId, int $calibrationScopeId, int $instrumentTypeId): object
    {
        $matches = [];

        foreach ($scopes as $scope) {
            if ((int) $scope->calibration_scope_id === $calibrationScopeId) {
                $matches[] = $scope;
            }
        }

        if (count($matches) !== 1 || (int) $matches[0]->calibration_id !== $calibrationId) {
            throw RecordCalibrationScopeRemarksException::unknownScope();
        }

        $scope = $matches[0];

        if ((int) $scope->instrument_type_id !== $instrumentTypeId) {
            throw RecordCalibrationScopeRemarksException::instrumentTypeMismatch();
        }

        return $scope;
    }

    private function assertPinnedSnapshot(object $scope): void
    {
        if ($scope->test_profile_version_id === null || ! $this->snapshotPresent($scope->test_table_snapshot)) {
            throw RecordCalibrationScopeRemarksException::unpinnedScope();
        }

        $snapshot = $this->decodedSnapshot($scope->test_table_snapshot);

        if ($snapshot === null || ! array_key_exists('schema_version', $snapshot)) {
            throw RecordCalibrationScopeRemarksException::malformedFrozenSnapshot();
        }

        $schemaVersion = $snapshot['schema_version'];

        if ($schemaVersion !== 2 && $schemaVersion !== '2') {
            throw RecordCalibrationScopeRemarksException::unsupportedSchemaVersion();
        }

        if (! TestTableSnapshotBuilder::isWellformed($snapshot)) {
            throw RecordCalibrationScopeRemarksException::malformedFrozenSnapshot();
        }

        if ((int) $snapshot['test_profile_id'] !== (int) $scope->test_profile_id
            || (int) $snapshot['test_profile_version_id'] !== (int) $scope->test_profile_version_id) {
            throw RecordCalibrationScopeRemarksException::snapshotVersionMismatch();
        }
    }

    private function snapshotPresent(mixed $snapshot): bool
    {
        if ($snapshot === null) {
            return false;
        }

        if (is_string($snapshot)) {
            return trim($snapshot) !== '';
        }

        return is_array($snapshot) && $snapshot !== [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodedSnapshot(mixed $snapshot): ?array
    {
        if (is_array($snapshot)) {
            return $snapshot === [] ? null : $snapshot;
        }

        if (! is_string($snapshot) || trim($snapshot) === '') {
            return null;
        }

        $decoded = json_decode($snapshot, true);

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }
}
