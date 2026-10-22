<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use App\TestColumns\TestTableSnapshotBuilder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Copies frozen template constants into canonical result cells.
 *
 * The source is each pinned schema version 2 snapshot. Template columns are
 * stored with value_source template. Engineer and calculated columns are left
 * untouched, and formulas are not evaluated. An identical template cell is a
 * no-op. Any other cell on that column key rejects the call. SQLite compiles
 * lockForUpdate() without a real row lock or the PostgreSQL checks.
 */
class MaterializeCalibrationTestTemplateValues
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    /**
     * @throws MaterializeCalibrationTestTemplateValuesException
     * @throws CalibrationSessionConnectionException
     * @throws QueryException
     */
    public function materialize(int $calibrationId, Connection $connection): void
    {
        $connection = $this->sessionConnection->resolve($connection);

        if ($calibrationId < 1) {
            throw MaterializeCalibrationTestTemplateValuesException::invalidCalibrationId();
        }

        $connection->transaction(function () use ($connection, $calibrationId): void {
            $calibration = $this->lockCalibration($connection, $calibrationId);
            $mode = $this->assertOperableMode($this->lockRequirement($connection, $calibrationId));
            $scopes = $this->lockScopes($connection, $calibrationId);
            $plans = $this->plans($calibration, $scopes, $mode);

            if ($plans === null) {
                return;
            }

            $results = $this->lockResults($connection, $scopes);
            $cells = $this->lockCells($connection, $results);
            $createdParents = [];

            foreach ($plans as $plan) {
                $this->apply($connection, $results, $cells, $createdParents, $plan);
            }
        });
    }

    private function lockCalibration(Connection $connection, int $calibrationId): object
    {
        $calibration = $connection->table('calibration')
            ->where('calibration_id', $calibrationId)
            ->lockForUpdate()
            ->first();

        if ($calibration === null) {
            throw MaterializeCalibrationTestTemplateValuesException::missingCalibration();
        }

        if ((string) $calibration->calibration_method !== Calibration::METHOD_INTERNAL) {
            throw MaterializeCalibrationTestTemplateValuesException::unsupportedCalibrationMethod();
        }

        if ((string) $calibration->session_status !== Calibration::STATUS_IN_PROGRESS) {
            throw MaterializeCalibrationTestTemplateValuesException::invalidSessionStatus();
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
            throw MaterializeCalibrationTestTemplateValuesException::missingTestProfileRequirement();
        }

        return $snapshot;
    }

    private function assertOperableMode(object $snapshot): string
    {
        $frozen = (string) $snapshot->frozen_mode;
        $resolved = $snapshot->resolved_mode !== null ? (string) $snapshot->resolved_mode : null;
        $mode = $this->effectiveMode($frozen, $resolved);

        if (in_array($mode, [InstrumentTypeRequirement::MODE_REQUIRED, InstrumentTypeRequirement::MODE_OPTIONAL], true)) {
            return $mode;
        }

        if ($frozen === InstrumentTypeRequirement::MODE_NOT_CONFIGURED) {
            throw MaterializeCalibrationTestTemplateValuesException::unresolvedTestProfileMode();
        }

        throw MaterializeCalibrationTestTemplateValuesException::invalidEffectiveMode();
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
     * @return list<array{
     *     calibration_scope_id: int,
     *     test_point_id: int,
     *     column_key: string,
     *     data_type: string,
     *     numeric_value: ?string,
     *     text_value: ?string,
     *     unit: ?string
     * }>|null
     */
    private function plans(object $calibration, Collection $scopes, string $mode): ?array
    {
        if ($scopes->isEmpty()) {
            if ($mode === InstrumentTypeRequirement::MODE_REQUIRED) {
                throw MaterializeCalibrationTestTemplateValuesException::missingRequiredPinnedScope();
            }

            return null;
        }

        $plans = [];

        foreach ($scopes as $scope) {
            $snapshot = $this->frozenSnapshot($scope, (int) $calibration->instrument_type_id);

            foreach ($snapshot['rows'] as $row) {
                if (! is_array($row)) {
                    throw MaterializeCalibrationTestTemplateValuesException::malformedFrozenSnapshot();
                }

                foreach ($snapshot['columns'] as $column) {
                    if (! is_array($column) || ($column['value_source'] ?? null) !== 'template') {
                        continue;
                    }

                    $plan = $this->templatePlan((int) $scope->calibration_scope_id, $row, $column);

                    if ($plan !== null) {
                        $plans[] = $plan;
                    }
                }
            }
        }

        return $plans;
    }

    /**
     * @return array<string, mixed>
     */
    private function frozenSnapshot(object $scope, int $instrumentTypeId): array
    {
        if ($scope->test_profile_version_id === null || ! $this->snapshotPresent($scope->test_table_snapshot)) {
            throw MaterializeCalibrationTestTemplateValuesException::partiallyPinnedScope();
        }

        if ((int) $scope->instrument_type_id !== $instrumentTypeId) {
            throw MaterializeCalibrationTestTemplateValuesException::instrumentTypeMismatch();
        }

        $snapshot = $this->decodedSnapshot($scope->test_table_snapshot);

        if ($snapshot === null || ! array_key_exists('schema_version', $snapshot)) {
            throw MaterializeCalibrationTestTemplateValuesException::malformedFrozenSnapshot();
        }

        $schemaVersion = $snapshot['schema_version'];

        if ($schemaVersion !== 2 && $schemaVersion !== '2') {
            throw MaterializeCalibrationTestTemplateValuesException::unsupportedSchemaVersion();
        }

        if ($this->hasDuplicateColumnKeys($snapshot)) {
            throw MaterializeCalibrationTestTemplateValuesException::duplicateFrozenColumn();
        }

        if ($this->hasDuplicatePointIds($snapshot)) {
            throw MaterializeCalibrationTestTemplateValuesException::duplicateFrozenRow();
        }

        if (! TestTableSnapshotBuilder::isWellformed($snapshot)) {
            throw MaterializeCalibrationTestTemplateValuesException::malformedFrozenSnapshot();
        }

        if ((int) $snapshot['test_profile_id'] !== (int) $scope->test_profile_id
            || (int) $snapshot['test_profile_version_id'] !== (int) $scope->test_profile_version_id) {
            throw MaterializeCalibrationTestTemplateValuesException::snapshotVersionMismatch();
        }

        $this->assertRowIdentities($snapshot);

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
    private function assertRowIdentities(array $snapshot): void
    {
        foreach ($snapshot['rows'] as $row) {
            if (! is_array($row) || ! is_int($row['test_point_id'] ?? null) || $row['test_point_id'] < 1) {
                throw MaterializeCalibrationTestTemplateValuesException::malformedFrozenSnapshot();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $column
     * @return array{
     *     calibration_scope_id: int,
     *     test_point_id: int,
     *     column_key: string,
     *     data_type: string,
     *     numeric_value: ?string,
     *     text_value: ?string,
     *     unit: ?string
     * }|null
     */
    private function templatePlan(int $calibrationScopeId, array $row, array $column): ?array
    {
        $rule = $column['completion_rule'] ?? null;

        if ($rule === InstrumentTypeRequirement::MODE_NOT_CONFIGURED) {
            throw MaterializeCalibrationTestTemplateValuesException::notConfiguredTemplateColumn();
        }

        $dataType = $column['data_type'] ?? null;
        $columnKey = $column['column_key'] ?? null;

        if (! is_string($columnKey)
            || ! in_array($dataType, ['numeric', 'text', 'duration'], true)
            || ! in_array($rule, [InstrumentTypeRequirement::MODE_REQUIRED, InstrumentTypeRequirement::MODE_OPTIONAL], true)) {
            throw MaterializeCalibrationTestTemplateValuesException::invalidTemplateEntry();
        }

        $required = $rule === InstrumentTypeRequirement::MODE_REQUIRED;
        $entry = $this->templateEntry($row, $columnKey, $required);

        if ($entry === null) {
            return null;
        }

        [$numericValue, $textValue] = $this->templateValue($dataType, $entry, $required);

        if ($numericValue === null && $textValue === null) {
            return null;
        }

        return [
            'calibration_scope_id' => $calibrationScopeId,
            'test_point_id' => (int) $row['test_point_id'],
            'column_key' => $columnKey,
            'data_type' => $dataType,
            'numeric_value' => $numericValue,
            'text_value' => $textValue,
            'unit' => $this->templateUnit($entry),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function templateEntry(array $row, string $columnKey, bool $required): ?array
    {
        $values = $row['template_values'] ?? null;

        if (! is_array($values)) {
            throw MaterializeCalibrationTestTemplateValuesException::invalidTemplateEntry();
        }

        if (! array_key_exists($columnKey, $values) || $values[$columnKey] === null) {
            if ($required) {
                throw MaterializeCalibrationTestTemplateValuesException::missingRequiredTemplateValue();
            }

            return null;
        }

        $entry = $values[$columnKey];

        if (! is_array($entry) || array_is_list($entry)) {
            throw MaterializeCalibrationTestTemplateValuesException::invalidTemplateEntry();
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{0: ?string, 1: ?string}
     */
    private function templateValue(string $dataType, array $entry, bool $required): array
    {
        $numeric = $entry['numeric_value'] ?? null;
        $text = $entry['text_value'] ?? null;

        if ($numeric !== null && $text !== null) {
            throw MaterializeCalibrationTestTemplateValuesException::invalidTemplateEntry();
        }

        if ($dataType === 'text') {
            if ($numeric !== null) {
                throw MaterializeCalibrationTestTemplateValuesException::invalidTemplateEntry();
            }

            if ($text === null || (is_string($text) && trim($text) === '')) {
                if ($required && $text !== null && ! is_string($text)) {
                    throw MaterializeCalibrationTestTemplateValuesException::invalidTextTemplateValue();
                }

                if ($required && is_string($text)) {
                    throw MaterializeCalibrationTestTemplateValuesException::invalidTextTemplateValue();
                }

                if ($required) {
                    throw MaterializeCalibrationTestTemplateValuesException::missingRequiredTemplateValue();
                }

                return [null, null];
            }

            if (! is_string($text)) {
                throw MaterializeCalibrationTestTemplateValuesException::invalidTextTemplateValue();
            }

            return [null, $text];
        }

        if ($text !== null) {
            throw MaterializeCalibrationTestTemplateValuesException::invalidTemplateEntry();
        }

        if ($numeric === null) {
            if ($required) {
                throw MaterializeCalibrationTestTemplateValuesException::missingRequiredTemplateValue();
            }

            return [null, null];
        }

        if (! is_string($numeric)) {
            throw $dataType === 'duration'
                ? MaterializeCalibrationTestTemplateValuesException::invalidDurationTemplateValue()
                : MaterializeCalibrationTestTemplateValuesException::invalidNumericTemplateValue();
        }

        if ($dataType === 'duration') {
            if (preg_match('/^\d+$/', $numeric) !== 1) {
                throw MaterializeCalibrationTestTemplateValuesException::invalidDurationTemplateValue();
            }

            return [$numeric, null];
        }

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $numeric) !== 1) {
            throw MaterializeCalibrationTestTemplateValuesException::invalidNumericTemplateValue();
        }

        return [$numeric, null];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function templateUnit(array $entry): ?string
    {
        if (! array_key_exists('unit', $entry) || $entry['unit'] === null) {
            return null;
        }

        if (! is_string($entry['unit'])) {
            throw MaterializeCalibrationTestTemplateValuesException::invalidTemplateUnit();
        }

        $unit = $entry['unit'];

        if (trim($unit) === '' || mb_strlen($unit) > 50) {
            throw MaterializeCalibrationTestTemplateValuesException::invalidTemplateUnit();
        }

        return $unit;
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
     * @param  Collection<int, object>  $results
     * @param  Collection<int, object>  $cells
     * @param  array<string, int>  $createdParents
     * @param  array{
     *     calibration_scope_id: int,
     *     test_point_id: int,
     *     column_key: string,
     *     data_type: string,
     *     numeric_value: ?string,
     *     text_value: ?string,
     *     unit: ?string
     * }  $plan
     */
    private function apply(Connection $connection, Collection $results, Collection $cells, array &$createdParents, array $plan): void
    {
        $parentId = $this->parentId($connection, $results, $createdParents, $plan['calibration_scope_id'], $plan['test_point_id']);
        $existing = $this->existingCells($cells, $parentId, $plan['column_key']);

        if (count($existing) > 1) {
            throw MaterializeCalibrationTestTemplateValuesException::duplicateResultCells();
        }

        if (count($existing) === 1) {
            if ($this->sameTemplateCell($existing[0], $plan)) {
                return;
            }

            throw MaterializeCalibrationTestTemplateValuesException::conflictingResultCell();
        }

        $this->insertCell($connection, $parentId, $plan);
    }

    /**
     * @param  Collection<int, object>  $results
     * @param  array<string, int>  $createdParents
     */
    private function parentId(Connection $connection, Collection $results, array &$createdParents, int $calibrationScopeId, int $testPointId): int
    {
        $key = $calibrationScopeId.':'.$testPointId;
        $matches = [];

        foreach ($results as $result) {
            if ((int) $result->calibration_scope_id === $calibrationScopeId && (int) $result->test_point_id === $testPointId) {
                $matches[] = $result;
            }
        }

        if (count($matches) > 1) {
            throw MaterializeCalibrationTestTemplateValuesException::duplicateParentResultRows();
        }

        if (count($matches) === 1) {
            return (int) $matches[0]->calibration_test_result_id;
        }

        if (isset($createdParents[$key])) {
            return $createdParents[$key];
        }

        $createdParents[$key] = $this->insertResult($connection, $calibrationScopeId, $testPointId);

        return $createdParents[$key];
    }

    /**
     * @param  Collection<int, object>  $cells
     * @return list<object>
     */
    private function existingCells(Collection $cells, int $resultId, string $columnKey): array
    {
        $matches = [];

        foreach ($cells as $cell) {
            if ((int) $cell->calibration_test_result_id === $resultId && (string) $cell->column_key === $columnKey) {
                $matches[] = $cell;
            }
        }

        return $matches;
    }

    /**
     * Comparison normalizes decimal text only to decide equality. The stored
     * string and the frozen string are not rewritten.
     *
     * @param  array{
     *     data_type: string,
     *     numeric_value: ?string,
     *     text_value: ?string,
     *     unit: ?string
     * }  $plan
     */
    private function sameTemplateCell(object $cell, array $plan): bool
    {
        if ((string) $cell->value_source !== 'template' || (string) $cell->data_type !== $plan['data_type']) {
            return false;
        }

        if (! $this->sameUnit($cell->unit, $plan['unit'])) {
            return false;
        }

        if ($plan['data_type'] === 'text') {
            return $cell->numeric_value === null
                && is_string($cell->text_value)
                && $cell->text_value === $plan['text_value'];
        }

        return $cell->text_value === null
            && $this->sameDecimal($cell->numeric_value, $plan['numeric_value']);
    }

    private function sameUnit(mixed $stored, ?string $frozen): bool
    {
        if ($frozen === null) {
            return $stored === null;
        }

        return is_string($stored) && $stored === $frozen;
    }

    private function sameDecimal(mixed $stored, ?string $frozen): bool
    {
        if ($frozen === null) {
            return false;
        }

        $storedText = $this->decimalText($stored);

        if ($storedText === null) {
            return false;
        }

        return $this->decimalCanonical($storedText) === $this->decimalCanonical($frozen);
    }

    private function decimalText(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value) || preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * Sign, integer, and fraction with leading and trailing zeros removed.
     * Negative zero matches zero. This string is never stored.
     */
    private function decimalCanonical(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $digits = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($fraction, '0');
        $canonical = $fraction === '' ? $whole : $whole.'.'.$fraction;

        if ($negative && $canonical !== '0') {
            return '-'.$canonical;
        }

        return $canonical;
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

    /**
     * @param  array{
     *     column_key: string,
     *     data_type: string,
     *     numeric_value: ?string,
     *     text_value: ?string,
     *     unit: ?string
     * }  $plan
     */
    private function insertCell(Connection $connection, int $resultId, array $plan): void
    {
        $payload = [
            'calibration_test_result_id' => $resultId,
            'column_key' => $plan['column_key'],
            'value_source' => 'template',
            'data_type' => $plan['data_type'],
            'numeric_value' => $plan['numeric_value'],
            'text_value' => $plan['text_value'],
            'unit' => $plan['unit'],
            'created_at' => now(),
        ];

        if ($connection->getDriverName() !== 'pgsql') {
            $payload['calibration_result_value_id'] = ((int) $connection->table('calibration_result_value')->max('calibration_result_value_id')) + 1;
        }

        $connection->table('calibration_result_value')->insert($payload);
    }
}
