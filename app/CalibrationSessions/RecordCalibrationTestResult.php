<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use App\TestColumns\TestTableSnapshotBuilder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Records or clears one manual test-component cell on a pinned schema version 2 scope.
 *
 * Identity is the calibration, the calibration scope, the frozen test point, and
 * the frozen column key. Calculated columns are stored as engineer entries and
 * formulas are not evaluated. A null value deletes the cell. The parent result
 * row is deleted only when it has no remaining cells and no remarks, legacy
 * values, or readings. This service does not pin, resolve requirements, write
 * legacy result columns, or call CompleteCalibrationSession. SQLite compiles
 * lockForUpdate() without a real row lock or the PostgreSQL checks.
 */
class RecordCalibrationTestResult
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    /**
     * @throws RecordCalibrationTestResultException
     * @throws CalibrationSessionConnectionException
     * @throws QueryException
     */
    public function record(
        int $calibrationId,
        int $calibrationScopeId,
        int $testPointId,
        string $columnKey,
        ?string $value,
        Connection $connection,
    ): void {
        $connection = $this->sessionConnection->resolve($connection);

        if ($calibrationId < 1) {
            throw RecordCalibrationTestResultException::invalidCalibrationId();
        }

        if ($calibrationScopeId < 1) {
            throw RecordCalibrationTestResultException::invalidScopeId();
        }

        if ($testPointId < 1) {
            throw RecordCalibrationTestResultException::invalidTestPointId();
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $columnKey) !== 1) {
            throw RecordCalibrationTestResultException::invalidColumnKey();
        }

        $connection->transaction(function () use ($connection, $calibrationId, $calibrationScopeId, $testPointId, $columnKey, $value): void {
            $calibration = $this->lockCalibration($connection, $calibrationId);
            $this->assertOperableMode($this->lockRequirement($connection, $calibrationId));
            $scopes = $this->lockScopes($connection, $calibrationId);
            $scope = $this->selectedScope($scopes, $calibrationId, $calibrationScopeId, (int) $calibration->instrument_type_id);
            $snapshot = $this->frozenSnapshot($scope);
            $this->assertFrozenRow($snapshot, $testPointId);
            $column = $this->frozenColumn($snapshot, $columnKey);
            $this->assertWritableColumn($column);

            $results = $this->lockResults($connection, $scopes);
            $parent = $this->parentResult($results, $calibrationScopeId, $testPointId);
            $cells = $this->lockCells($connection, $results);
            $cell = $parent === null
                ? null
                : $this->existingCell($cells, (int) $parent->calibration_test_result_id, $columnKey);

            if ($value === null) {
                $this->clear($connection, $calibrationScopeId, $testPointId, $columnKey, $parent, $cell);

                return;
            }

            [$numericValue, $textValue] = $this->measurement((string) $column['data_type'], $value);
            $unit = $this->frozenUnit($column);
            $resultId = $parent === null
                ? $this->insertResult($connection, $calibrationScopeId, $testPointId)
                : (int) $parent->calibration_test_result_id;

            if ($cell === null) {
                $this->insertCell($connection, $resultId, $columnKey, (string) $column['data_type'], $numericValue, $textValue, $unit);

                return;
            }

            $connection->table('calibration_result_value')
                ->where('calibration_result_value_id', $cell->calibration_result_value_id)
                ->where('calibration_test_result_id', $resultId)
                ->where('column_key', $columnKey)
                ->update([
                    'numeric_value' => $numericValue,
                    'text_value' => $textValue,
                    'unit' => $unit,
                    'data_type' => $column['data_type'],
                    'value_source' => 'engineer',
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
            throw RecordCalibrationTestResultException::missingCalibration();
        }

        if ((string) $calibration->calibration_method !== Calibration::METHOD_INTERNAL) {
            throw RecordCalibrationTestResultException::unsupportedCalibrationMethod();
        }

        if ((string) $calibration->session_status !== Calibration::STATUS_IN_PROGRESS) {
            throw RecordCalibrationTestResultException::invalidSessionStatus();
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
            throw RecordCalibrationTestResultException::missingTestProfileRequirement();
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
            throw RecordCalibrationTestResultException::unresolvedTestProfileMode();
        }

        throw RecordCalibrationTestResultException::invalidEffectiveMode();
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

        if (count($matches) !== 1) {
            throw RecordCalibrationTestResultException::unknownScope();
        }

        $scope = $matches[0];

        if ((int) $scope->calibration_id !== $calibrationId) {
            throw RecordCalibrationTestResultException::unknownScope();
        }

        if ((int) $scope->instrument_type_id !== $instrumentTypeId) {
            throw RecordCalibrationTestResultException::instrumentTypeMismatch();
        }

        return $scope;
    }

    /**
     * @return array<string, mixed>
     */
    private function frozenSnapshot(object $scope): array
    {
        if ($scope->test_profile_version_id === null || ! $this->snapshotPresent($scope->test_table_snapshot)) {
            throw RecordCalibrationTestResultException::unpinnedScope();
        }

        $snapshot = $this->decodedSnapshot($scope->test_table_snapshot);

        if ($snapshot === null || ! array_key_exists('schema_version', $snapshot)) {
            throw RecordCalibrationTestResultException::malformedFrozenSnapshot();
        }

        $schemaVersion = $snapshot['schema_version'];

        if ($schemaVersion !== 2 && $schemaVersion !== '2') {
            throw RecordCalibrationTestResultException::unsupportedSchemaVersion();
        }

        if ($this->hasDuplicateColumnKeys($snapshot) || $this->hasDuplicatePointIds($snapshot)) {
            throw RecordCalibrationTestResultException::ambiguousFrozenIdentity();
        }

        if (! TestTableSnapshotBuilder::isWellformed($snapshot)) {
            throw RecordCalibrationTestResultException::malformedFrozenSnapshot();
        }

        if ((int) $snapshot['test_profile_id'] !== (int) $scope->test_profile_id
            || (int) $snapshot['test_profile_version_id'] !== (int) $scope->test_profile_version_id) {
            throw RecordCalibrationTestResultException::snapshotVersionMismatch();
        }

        return $snapshot;
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

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function hasDuplicateColumnKeys(array $snapshot): bool
    {
        $columns = $snapshot['columns'] ?? null;

        if (! is_array($columns)) {
            return false;
        }

        $seen = [];

        foreach ($columns as $column) {
            if (! is_array($column) || ! is_string($column['column_key'] ?? null)) {
                continue;
            }

            $columnKey = $column['column_key'];

            if (isset($seen[$columnKey])) {
                return true;
            }

            $seen[$columnKey] = true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function hasDuplicatePointIds(array $snapshot): bool
    {
        $rows = $snapshot['rows'] ?? null;

        if (! is_array($rows)) {
            return false;
        }

        $seen = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_numeric($row['test_point_id'] ?? null)) {
                continue;
            }

            $testPointId = (int) $row['test_point_id'];

            if (isset($seen[$testPointId])) {
                return true;
            }

            $seen[$testPointId] = true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function assertFrozenRow(array $snapshot, int $testPointId): void
    {
        $matches = 0;

        foreach ($snapshot['rows'] as $row) {
            if (is_array($row) && is_numeric($row['test_point_id'] ?? null) && (int) $row['test_point_id'] === $testPointId) {
                $matches++;
            }
        }

        if ($matches === 1) {
            return;
        }

        if ($matches > 1) {
            throw RecordCalibrationTestResultException::ambiguousFrozenIdentity();
        }

        throw RecordCalibrationTestResultException::unknownFrozenRow();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function frozenColumn(array $snapshot, string $columnKey): array
    {
        $matches = [];

        foreach ($snapshot['columns'] as $column) {
            if (is_array($column) && ($column['column_key'] ?? null) === $columnKey) {
                $matches[] = $column;
            }
        }

        if (count($matches) > 1) {
            throw RecordCalibrationTestResultException::ambiguousFrozenIdentity();
        }

        if (count($matches) === 0) {
            throw RecordCalibrationTestResultException::unknownFrozenColumn();
        }

        return $matches[0];
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function assertWritableColumn(array $column): void
    {
        $valueSource = $column['value_source'] ?? null;

        if ($valueSource === 'template') {
            throw RecordCalibrationTestResultException::templateColumnNotEditable();
        }

        if (! in_array($valueSource, ['engineer', 'calculated'], true)) {
            throw RecordCalibrationTestResultException::unsupportedValueSource();
        }

        $completionRule = $column['completion_rule'] ?? null;

        if ($completionRule === InstrumentTypeRequirement::MODE_NOT_CONFIGURED) {
            throw RecordCalibrationTestResultException::notConfiguredColumn();
        }

        if (! in_array($completionRule, [InstrumentTypeRequirement::MODE_REQUIRED, InstrumentTypeRequirement::MODE_OPTIONAL], true)) {
            throw RecordCalibrationTestResultException::unsupportedCompletionRule();
        }

        if (! in_array($column['data_type'] ?? null, ['numeric', 'text', 'duration'], true)) {
            throw RecordCalibrationTestResultException::unsupportedDataType();
        }
    }

    /**
     * @param  Collection<int, object>  $scopes
     * @return Collection<int, object>
     */
    private function lockResults(Connection $connection, Collection $scopes): Collection
    {
        return $connection->table('calibration_test_result')
            ->whereIn('calibration_scope_id', $scopes->map(fn (object $scope): int => (int) $scope->calibration_scope_id)->all())
            ->orderBy('calibration_test_result_id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, object>  $results
     */
    private function parentResult(Collection $results, int $calibrationScopeId, int $testPointId): ?object
    {
        $matches = [];

        foreach ($results as $result) {
            if ((int) $result->calibration_scope_id === $calibrationScopeId && (int) $result->test_point_id === $testPointId) {
                $matches[] = $result;
            }
        }

        if (count($matches) > 1) {
            throw RecordCalibrationTestResultException::duplicateParentResultRows();
        }

        return $matches[0] ?? null;
    }

    /**
     * @param  Collection<int, object>  $results
     * @return Collection<int, object>
     */
    private function lockCells(Connection $connection, Collection $results): Collection
    {
        return $connection->table('calibration_result_value')
            ->whereIn('calibration_test_result_id', $results->map(fn (object $result): int => (int) $result->calibration_test_result_id)->all())
            ->orderBy('calibration_test_result_id')
            ->orderBy('column_key')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, object>  $cells
     */
    private function existingCell(Collection $cells, int $resultId, string $columnKey): ?object
    {
        $matches = [];

        foreach ($cells as $cell) {
            if ((int) $cell->calibration_test_result_id === $resultId && (string) $cell->column_key === $columnKey) {
                $matches[] = $cell;
            }
        }

        if (count($matches) > 1) {
            throw RecordCalibrationTestResultException::duplicateResultCells();
        }

        return $matches[0] ?? null;
    }

    private function clear(
        Connection $connection,
        int $calibrationScopeId,
        int $testPointId,
        string $columnKey,
        ?object $parent,
        ?object $cell,
    ): void {
        if ($parent === null || $cell === null) {
            return;
        }

        $resultId = (int) $parent->calibration_test_result_id;

        $connection->table('calibration_result_value')
            ->where('calibration_result_value_id', $cell->calibration_result_value_id)
            ->where('calibration_test_result_id', $resultId)
            ->where('column_key', $columnKey)
            ->delete();

        $remaining = (int) $connection->table('calibration_result_value')
            ->where('calibration_test_result_id', $resultId)
            ->count();

        if ($remaining > 0) {
            return;
        }

        if ($this->parentRetainsHistory($connection, $parent)) {
            throw RecordCalibrationTestResultException::unsafeParentRowDeletion();
        }

        $connection->table('calibration_test_result')
            ->where('calibration_test_result_id', $resultId)
            ->where('calibration_scope_id', $calibrationScopeId)
            ->where('test_point_id', $testPointId)
            ->delete();
    }

    private function parentRetainsHistory(Connection $connection, object $parent): bool
    {
        $remarks = $parent->remarks;

        if ($remarks !== null && (! is_string($remarks) || trim($remarks) !== '')) {
            return true;
        }

        foreach (['standard_value', 'average_value', 'correction_value'] as $column) {
            if ($parent->{$column} !== null) {
                return true;
            }
        }

        return $connection->table('calibration_reading')
            ->where('calibration_test_result_id', (int) $parent->calibration_test_result_id)
            ->count() > 0;
    }

    /**
     * Locale-independent decimal, or a non-negative whole-second duration.
     * The accepted string is stored unchanged. Commas, exponents, a leading
     * plus, and surrounding whitespace are rejected. hh:mm:ss is not parsed.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function measurement(string $dataType, string $value): array
    {
        if ($dataType === 'numeric') {
            if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
                throw RecordCalibrationTestResultException::invalidNumericValue();
            }

            return [$value, null];
        }

        if ($dataType === 'duration') {
            if (preg_match('/^\d+$/', $value) !== 1) {
                throw RecordCalibrationTestResultException::invalidDurationValue();
            }

            return [$value, null];
        }

        if ($dataType === 'text') {
            if (trim($value) === '') {
                throw RecordCalibrationTestResultException::invalidTextValue();
            }

            return [null, $value];
        }

        throw RecordCalibrationTestResultException::unsupportedDataType();
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function frozenUnit(array $column): ?string
    {
        if (! array_key_exists('unit', $column) || $column['unit'] === null) {
            return null;
        }

        if (! is_string($column['unit'])) {
            throw RecordCalibrationTestResultException::invalidFrozenUnit();
        }

        $unit = $column['unit'];

        if (trim($unit) === '' || mb_strlen($unit) > 50) {
            throw RecordCalibrationTestResultException::invalidFrozenUnit();
        }

        return $unit;
    }

    private function insertResult(Connection $connection, int $calibrationScopeId, int $testPointId): int
    {
        $payload = [
            'calibration_scope_id' => $calibrationScopeId,
            'test_point_id' => $testPointId,
            'created_at' => now(),
        ];

        if ($connection->getDriverName() !== 'pgsql') {
            $payload['calibration_test_result_id'] = ((int) $connection->table('calibration_test_result')->max('calibration_test_result_id')) + 1;
            $connection->table('calibration_test_result')->insert($payload);

            return (int) $payload['calibration_test_result_id'];
        }

        return (int) $connection->table('calibration_test_result')->insertGetId($payload, 'calibration_test_result_id');
    }

    private function insertCell(
        Connection $connection,
        int $resultId,
        string $columnKey,
        string $dataType,
        ?string $numericValue,
        ?string $textValue,
        ?string $unit,
    ): void {
        $payload = [
            'calibration_test_result_id' => $resultId,
            'column_key' => $columnKey,
            'value_source' => 'engineer',
            'data_type' => $dataType,
            'numeric_value' => $numericValue,
            'text_value' => $textValue,
            'unit' => $unit,
            'created_at' => now(),
        ];

        if ($connection->getDriverName() !== 'pgsql') {
            $payload['calibration_result_value_id'] = ((int) $connection->table('calibration_result_value')->max('calibration_result_value_id')) + 1;
        }

        $connection->table('calibration_result_value')->insert($payload);
    }
}
