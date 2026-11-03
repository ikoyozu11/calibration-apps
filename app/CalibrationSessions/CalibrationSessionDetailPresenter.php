<?php

namespace App\CalibrationSessions;

use App\Dashboard\CalibrationSessionHistory;
use App\Models\Calibration;
use App\Models\CalibrationActivity;
use App\Models\CalibrationEnvironmentParameterSnapshot;
use App\Models\CalibrationEnvironmentValue;
use App\Models\CalibrationScope;
use App\Models\CalibrationStandardUsage;
use App\Models\InstrumentTypeRequirement;
use App\TestColumns\ColumnFormula;
use App\TestColumns\DurationValue;
use App\TestColumns\NumericDisplay;
use App\TestColumns\TestTableSnapshotBuilder;
use Illuminate\Support\Collection;

/**
 * Read-only presentation of a calibration session detail page.
 *
 * Snapshot schema version 2 is the session source of truth for Test Components.
 * This class does not pin, record, resolve, calculate, or write.
 */
final class CalibrationSessionDetailPresenter
{
    public static function sessionStatusLabel(string $status): string
    {
        return CalibrationSessionHistory::statusLabel($status);
    }

    public static function isInternal(Calibration $calibration): bool
    {
        return (string) $calibration->calibration_method === Calibration::METHOD_INTERNAL;
    }

    public static function isExternal(Calibration $calibration): bool
    {
        return (string) $calibration->calibration_method === Calibration::METHOD_EXTERNAL;
    }

    public static function recordedVendorName(Calibration $calibration): ?string
    {
        $value = $calibration->getAttribute('external_vendor_name');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * @return list<array{key: int, filename: string, uploaded_at: string, status: string, replaced: bool}>
     */
    public static function vendorDocuments(Calibration $calibration): array
    {
        if (! $calibration->relationLoaded('externalDocuments')) {
            return [];
        }

        $rows = [];

        foreach ($calibration->externalDocuments as $document) {
            $active = (bool) $document->getAttribute('is_active');

            $rows[] = [
                'key' => (int) $document->getAttribute('document_id'),
                'filename' => self::display($document->getAttribute('original_filename')),
                'uploaded_at' => self::formatTimestamp($document->getAttribute('uploaded_at')),
                'status' => $active ? 'Active' : 'Replaced',
                'replaced' => ! $active,
            ];
        }

        return $rows;
    }

    /**
     * @return array{name: string, type: string, brand: string, model: string, serial_number: string, asset_number: string}
     */
    public static function identity(Calibration $calibration): array
    {
        if (self::hasFrozenIdentity($calibration)) {
            return [
                'name' => self::display($calibration->getAttribute('snapshot_instrument_name')),
                'type' => self::display($calibration->getAttribute('snapshot_instrument_type_name')),
                'brand' => self::display($calibration->getAttribute('snapshot_brand')),
                'model' => self::display($calibration->getAttribute('snapshot_model')),
                'serial_number' => self::display($calibration->getAttribute('snapshot_serial_number')),
                'asset_number' => self::display($calibration->getAttribute('snapshot_asset_number')),
            ];
        }

        $instrument = $calibration->relationLoaded('instrument') ? $calibration->instrument : null;
        $type = $calibration->relationLoaded('instrumentType') ? $calibration->instrumentType : null;

        return [
            'name' => self::display($instrument?->instrument_name),
            'type' => self::display($type?->type_name),
            'brand' => self::display($type?->brand),
            'model' => self::display($type?->model),
            'serial_number' => self::display($instrument?->serial_number),
            'asset_number' => self::display($type?->asset_number),
        ];
    }

    /**
     * Overlay frozen identity onto one calibrated-type row.
     *
     * @return array{key: int, name: string, brand: string, type: string, model: string, serial_number: string, asset_number: string}
     */
    public static function typedIdentity(Calibration $calibration, int $key, ?object $type): array
    {
        if (self::hasFrozenIdentity($calibration)) {
            return [
                'key' => $key,
                ...self::identity($calibration),
            ];
        }

        $instrument = $calibration->relationLoaded('instrument') ? $calibration->instrument : null;

        return [
            'key' => $key,
            'name' => self::display($instrument?->instrument_name),
            'brand' => self::display($type?->brand ?? null),
            'type' => self::display($type?->type_name ?? null),
            'model' => self::display($type?->model ?? null),
            'serial_number' => self::display($instrument?->serial_number),
            'asset_number' => self::display($type?->asset_number ?? null),
        ];
    }

    private static function hasFrozenIdentity(Calibration $calibration): bool
    {
        foreach ([
            'snapshot_instrument_name',
            'snapshot_instrument_type_name',
            'snapshot_brand',
            'snapshot_model',
            'snapshot_serial_number',
            'snapshot_asset_number',
        ] as $attribute) {
            if (filled($calibration->getAttribute($attribute))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{category: string, frozen: string, resolved: string, effective: string}>
     */
    public static function requirementModes(Calibration $calibration): array
    {
        if (! $calibration->relationLoaded('requirementSnapshots')) {
            return [];
        }

        $snapshots = $calibration->requirementSnapshots->keyBy('requirement_category');
        $rows = [];

        foreach (InstrumentTypeRequirement::CATEGORIES as $category) {
            $snapshot = $snapshots->get($category);

            if ($snapshot === null) {
                continue;
            }

            $frozen = (string) $snapshot->frozen_mode;
            $resolved = $snapshot->resolved_mode !== null ? (string) $snapshot->resolved_mode : null;

            $rows[] = [
                'category' => $category,
                'frozen' => self::display($frozen),
                'resolved' => self::display($resolved),
                'effective' => self::display(CalibrationSessionCompleteness::effectiveMode($frozen, $resolved)),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{
     *     key: int,
     *     standard_id: int|null,
     *     usage_order: int,
     *     name: string,
     *     code: string,
     *     type: string,
     *     serial_number: string,
     *     purpose: string,
     *     range: string,
     *     unit: string,
     *     remarks: string
     * }>
     */
    public static function standards(Calibration $calibration): array
    {
        if (! $calibration->relationLoaded('standardUsages')) {
            return [];
        }

        return $calibration->standardUsages
            ->sortBy([
                ['usage_order', 'asc'],
                ['calibration_standard_usage_id', 'asc'],
            ])
            ->values()
            ->map(fn (CalibrationStandardUsage $usage): array => self::standardRow($usage))
            ->all();
    }

    /**
     * @return list<array{
     *     key: int,
     *     activity_id: int|null,
     *     activity_order: int,
     *     name: string,
     *     description: string,
     *     result: string
     * }>
     */
    public static function activities(Calibration $calibration): array
    {
        if (! $calibration->relationLoaded('activities')) {
            return [];
        }

        return $calibration->activities
            ->sortBy([
                ['activity_order', 'asc'],
                ['calibration_activity_id', 'asc'],
            ])
            ->values()
            ->map(fn (CalibrationActivity $activity): array => self::activityRow($activity))
            ->all();
    }

    /**
     * @return list<array{
     *     key: int,
     *     parameter_id: int,
     *     code: string,
     *     name: string,
     *     unit: string,
     *     data_type: string,
     *     requirement_mode: string,
     *     required: bool,
     *     value: string,
     *     input: string,
     *     has_value: bool,
     *     missing_required: bool
     * }>
     */
    public static function environmentParameters(Calibration $calibration): array
    {
        if (! $calibration->relationLoaded('environmentParameterSnapshots')) {
            return [];
        }

        $values = $calibration->relationLoaded('environmentValues')
            ? $calibration->environmentValues->keyBy(
                fn (CalibrationEnvironmentValue $value): int => (int) $value->parameter_id,
            )
            : new Collection;

        return $calibration->environmentParameterSnapshots
            ->sortBy([
                ['display_order', 'asc'],
                ['parameter_id', 'asc'],
            ])
            ->values()
            ->map(fn (CalibrationEnvironmentParameterSnapshot $snapshot): array => self::environmentParameterRow(
                $snapshot,
                $values->get((int) $snapshot->parameter_id),
            ))
            ->all();
    }

    /**
     * @return array{confirmed: bool, confirmed_at: string, reason: string}
     */
    public static function environmentConfirmation(Calibration $calibration): array
    {
        if (! $calibration->relationLoaded('requirementSnapshots')) {
            return [
                'confirmed' => false,
                'confirmed_at' => '—',
                'reason' => '—',
            ];
        }

        $snapshot = $calibration->requirementSnapshots->first(
            fn (object $row): bool => (string) $row->requirement_category === InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
        );

        if ($snapshot === null) {
            return [
                'confirmed' => false,
                'confirmed_at' => '—',
                'reason' => '—',
            ];
        }

        $confirmedAt = $snapshot->getAttribute('configuration_confirmed_at');

        return [
            'confirmed' => $confirmedAt !== null,
            'confirmed_at' => self::display($confirmedAt),
            'reason' => self::display($snapshot->getAttribute('configuration_confirmed_reason')),
        ];
    }

    /**
     * @param  Collection<int, object>  $resultValues
     * @return array{state: 'hidden'|'unpinned'|'tables', tables: list<array{key: int, title: string, state: 'snapshot'|'unsupported', columns: list<array{key: string, label: string}>, rows: list<array{key: string, point_order: string, cells: list<string>}>}>}
     */
    public static function testComponents(Calibration $calibration, Collection $resultValues): array
    {
        if (! self::isInternal($calibration)) {
            return [
                'state' => 'hidden',
                'tables' => [],
            ];
        }

        if (! $calibration->relationLoaded('calibrationScopes') || $calibration->calibrationScopes->isEmpty()) {
            return [
                'state' => 'unpinned',
                'tables' => [],
            ];
        }

        $cellsByPoint = self::indexResultValues($calibration, $resultValues);
        $tables = [];

        foreach ($calibration->calibrationScopes->sortBy([
            ['scope_order', 'asc'],
            ['calibration_scope_id', 'asc'],
        ]) as $scope) {
            if (! self::scopeHasSnapshotPayload($scope)) {
                continue;
            }

            $tables[] = self::scopeTable($scope, $cellsByPoint);
        }

        if ($tables === []) {
            return [
                'state' => 'hidden',
                'tables' => [],
            ];
        }

        return [
            'state' => 'tables',
            'tables' => $tables,
        ];
    }

    /**
     * Snapshot-driven entry metadata for in-progress internal sessions.
     *
     * @param  Collection<int, object>  $resultValues
     * @return array{state: 'hidden'|'unpinned'|'tables', tables: list<array{key: int, title: string, state: 'snapshot'|'unsupported', columns: list<array{key: string, label: string, unit: string, value_source: string}>, rows: list<array{key: string, test_point_id: int, point_order: string, cells: list<array{id: string, column_key: string, value_source: string, data_type: string, unit: string, editable: bool, display: string, input: string}>}>}>}
     */
    public static function entryGrid(Calibration $calibration, Collection $resultValues): array
    {
        $presentation = self::testComponents($calibration, $resultValues);

        if ($presentation['state'] !== 'tables') {
            return [
                'state' => $presentation['state'],
                'tables' => [],
            ];
        }

        $raw = self::indexRawResultValues($calibration, $resultValues);
        $tables = [];

        foreach ($calibration->calibrationScopes->sortBy([
            ['scope_order', 'asc'],
            ['calibration_scope_id', 'asc'],
        ]) as $scope) {
            if (! self::scopeHasSnapshotPayload($scope)) {
                continue;
            }

            $tables[] = self::entryScopeTable($scope, $raw);
        }

        return [
            'state' => $tables === [] ? 'hidden' : 'tables',
            'tables' => $tables,
        ];
    }

    public static function resultCellKey(int $scopeId, int $testPointId, string $columnKey): string
    {
        return 's'.$scopeId.'p'.$testPointId.'c'.$columnKey;
    }

    /**
     * @param  Collection<int, object>  $resultValues
     * @return array{scopes: list<array{key: int, state: 'unpinned'|'complete'|'missing_template'|'conflict'|'invalid_snapshot', label: string, recoverable: bool}>}
     */
    public static function templateMaterialization(Calibration $calibration, Collection $resultValues): array
    {
        if (! self::isInternal($calibration) || ! $calibration->relationLoaded('calibrationScopes')) {
            return ['scopes' => []];
        }

        $raw = self::indexGroupedResultValues($calibration, $resultValues);
        $scopes = [];

        foreach ($calibration->calibrationScopes->sortBy([
            ['scope_order', 'asc'],
            ['calibration_scope_id', 'asc'],
        ]) as $scope) {
            $scopes[] = self::classifyTemplateMaterialization($calibration, $scope, $raw);
        }

        return ['scopes' => $scopes];
    }

    public static function showsLegacyScopeDetails(Calibration $calibration, CalibrationScope $scope): bool
    {
        if (! self::isInternal($calibration)) {
            return true;
        }

        return ! self::scopeHasSnapshotPayload($scope);
    }

    public static function display(mixed $value): string
    {
        return filled($value) ? (string) $value : '—';
    }

    public static function formatTimestamp(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        return self::display($value);
    }

    /**
     * @param  list<mixed>  $values
     */
    public static function firstFilled(array $values): mixed
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     key: int,
     *     standard_id: int|null,
     *     usage_order: int,
     *     name: string,
     *     code: string,
     *     type: string,
     *     serial_number: string,
     *     purpose: string,
     *     range: string,
     *     unit: string,
     *     remarks: string
     * }
     */
    private static function standardRow(CalibrationStandardUsage $usage): array
    {
        $instrument = $usage->relationLoaded('instrument') ? $usage->instrument : null;
        $standardId = $usage->getAttribute('standard_id');

        return [
            'key' => (int) $usage->calibration_standard_usage_id,
            'standard_id' => $standardId !== null ? (int) $standardId : null,
            'usage_order' => (int) $usage->usage_order,
            'name' => self::display(self::firstFilled([
                $usage->getAttribute('snapshot_instrument_name'),
                $instrument?->instrument_name,
            ])),
            'code' => self::display($usage->getAttribute('snapshot_standard_code')),
            'type' => self::display($usage->getAttribute('snapshot_type_specification')),
            'serial_number' => self::display($usage->getAttribute('snapshot_serial_number')),
            'purpose' => self::display($usage->usage_purpose),
            'range' => self::display($usage->standard_range),
            'unit' => self::display($usage->standard_unit),
            'remarks' => self::display($usage->remarks),
        ];
    }

    /**
     * @return array{
     *     key: int,
     *     activity_id: int|null,
     *     activity_order: int,
     *     name: string,
     *     description: string,
     *     result: string
     * }
     */
    private static function activityRow(CalibrationActivity $activity): array
    {
        $activityId = $activity->getAttribute('activity_id');

        return [
            'key' => (int) $activity->calibration_activity_id,
            'activity_id' => $activityId !== null ? (int) $activityId : null,
            'activity_order' => (int) $activity->activity_order,
            'name' => self::display(self::firstFilled([
                $activity->getAttribute('activity_name'),
                $activity->activity_type,
            ])),
            'description' => self::display($activity->activity_description),
            'result' => self::display($activity->activity_result),
        ];
    }

    /**
     * @return array{
     *     key: int,
     *     parameter_id: int,
     *     code: string,
     *     name: string,
     *     unit: string,
     *     data_type: string,
     *     requirement_mode: string,
     *     required: bool,
     *     value: string,
     *     input: string,
     *     has_value: bool,
     *     missing_required: bool
     * }
     */
    private static function environmentParameterRow(
        CalibrationEnvironmentParameterSnapshot $snapshot,
        ?CalibrationEnvironmentValue $value,
    ): array {
        $raw = '';

        if ($value !== null) {
            $raw = (string) ($value->numeric_value ?? $value->text_value ?? '');
        }

        $mode = (string) $snapshot->requirement_mode;
        $required = $mode === InstrumentTypeRequirement::MODE_REQUIRED;
        $hasValue = CalibrationSessionCompleteness::hasValue($raw === '' ? null : $raw);

        return [
            'key' => (int) $snapshot->parameter_id,
            'parameter_id' => (int) $snapshot->parameter_id,
            'code' => (string) $snapshot->parameter_code,
            'name' => self::display($snapshot->parameter_name),
            'unit' => self::display($snapshot->unit),
            'data_type' => self::display($snapshot->data_type),
            'requirement_mode' => self::display($mode),
            'required' => $required,
            'value' => self::display($raw === '' ? null : $raw),
            'input' => $raw,
            'has_value' => $hasValue,
            'missing_required' => $required && ! $hasValue,
        ];
    }

    private static function scopeHasSnapshotPayload(CalibrationScope $scope): bool
    {
        $snapshot = $scope->getAttribute('test_table_snapshot');

        if ($snapshot === null) {
            return false;
        }

        if (is_string($snapshot)) {
            return trim($snapshot) !== '';
        }

        return is_array($snapshot) && $snapshot !== [];
    }

    /**
     * @param  array<int, array<string, string>>  $cellsByPoint
     * @return array{key: int, title: string, state: 'snapshot'|'unsupported', columns: list<array{key: string, label: string}>, rows: list<array{key: string, point_order: string, cells: list<string>}>}
     */
    private static function scopeTable(CalibrationScope $scope, array $cellsByPoint): array
    {
        $decoded = self::decodedSnapshot($scope->getAttribute('test_table_snapshot'));
        $base = [
            'key' => (int) $scope->calibration_scope_id,
            'title' => self::display(self::firstFilled([
                is_array($decoded) ? ($decoded['display_title'] ?? null) : null,
                is_array($decoded) ? ($decoded['profile_name'] ?? null) : null,
                $scope->relationLoaded('testProfile') ? $scope->testProfile?->profile_name : null,
            ])),
            'remarks' => self::display(is_array($decoded) ? ($decoded['remarks'] ?? null) : null),
            'columns' => [],
            'rows' => [],
        ];

        if ($decoded === null || ! self::isSchemaVersion2($decoded) || ! TestTableSnapshotBuilder::isWellformed($decoded)) {
            return [...$base, 'state' => 'unsupported'];
        }

        if ($scope->test_profile_version_id !== null
            && (int) $decoded['test_profile_version_id'] !== (int) $scope->test_profile_version_id) {
            return [...$base, 'state' => 'unsupported'];
        }

        $columns = self::orderedColumns($decoded['columns']);
        $rows = self::orderedRows($decoded['rows']);

        return [
            ...$base,
            'state' => 'snapshot',
            'columns' => array_map(fn (array $column): array => [
                'key' => (string) $column['column_key'],
                'label' => (string) $column['column_label'],
            ], $columns),
            'rows' => array_map(fn (array $row): array => self::presentRow($row, $columns, $cellsByPoint), $rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $columns
     * @param  array<int, array<string, string>>  $cellsByPoint
     * @return array{key: string, point_order: string, cells: list<string>}
     */
    private static function presentRow(array $row, array $columns, array $cellsByPoint): array
    {
        $testPointId = is_numeric($row['test_point_id'] ?? null) ? (int) $row['test_point_id'] : 0;
        $templateValues = self::templateValues($row['template_values'] ?? null);
        $cells = [];

        foreach ($columns as $column) {
            $columnKey = (string) $column['column_key'];
            $source = $column['value_source'] ?? null;

            if ($source === 'template') {
                $cells[] = self::formatStoredValue($column, $templateValues[$columnKey] ?? null);

                continue;
            }

            $cells[] = $cellsByPoint[$testPointId][$columnKey] ?? '—';
        }

        return [
            'key' => $testPointId > 0 ? (string) $testPointId : 'order-'.(string) ($row['point_order'] ?? '0'),
            'point_order' => self::display($row['point_order'] ?? null),
            'cells' => $cells,
        ];
    }

    /**
     * @param  array<string, mixed>  $column
     * @param  array<string, mixed>|null  $entry
     */
    private static function formatStoredValue(array $column, ?array $entry): string
    {
        if ($entry === null) {
            return '—';
        }

        $dataType = $column['data_type'] ?? null;

        if ($dataType === 'text') {
            return self::display($entry['text_value'] ?? null);
        }

        $numeric = $entry['numeric_value'] ?? null;

        if ($numeric === null || $numeric === '') {
            return '—';
        }

        $stored = (string) $numeric;

        if ($dataType === 'duration' && preg_match('/^-?\d+$/', $stored) === 1) {
            return DurationValue::format((int) $stored, alwaysSigned: false);
        }

        $scale = array_key_exists('display_scale', $column) && $column['display_scale'] !== null
            ? (int) $column['display_scale']
            : null;

        return NumericDisplay::format($stored, $scale);
    }

    /**
     * @param  Collection<int, object>  $resultValues
     * @return array<int, array<string, string>>
     */
    private static function indexResultValues(Calibration $calibration, Collection $resultValues): array
    {
        $resultsById = [];

        foreach ($calibration->calibrationScopes as $scope) {
            if (! $scope->relationLoaded('calibrationTestResults')) {
                continue;
            }

            foreach ($scope->calibrationTestResults as $result) {
                $resultsById[(int) $result->calibration_test_result_id] = $result;
            }
        }

        $indexed = [];

        foreach ($resultValues as $value) {
            $resultId = (int) $value->calibration_test_result_id;
            $result = $resultsById[$resultId] ?? null;

            if ($result === null) {
                continue;
            }

            $columnKey = (string) $value->column_key;
            $testPointId = (int) $result->test_point_id;
            $column = [
                'data_type' => $value->data_type,
                'display_scale' => null,
            ];

            $indexed[$testPointId][$columnKey] = self::formatStoredValue($column, [
                'numeric_value' => $value->numeric_value,
                'text_value' => $value->text_value,
            ]);
        }

        return $indexed;
    }

    /**
     * @param  Collection<int, object>  $resultValues
     * @return array<int, array<int, array<string, list<object>>>>
     */
    private static function indexGroupedResultValues(Calibration $calibration, Collection $resultValues): array
    {
        $resultsById = [];

        foreach ($calibration->calibrationScopes as $scope) {
            if (! $scope->relationLoaded('calibrationTestResults')) {
                continue;
            }

            foreach ($scope->calibrationTestResults as $result) {
                $resultsById[(int) $result->calibration_test_result_id] = $result;
            }
        }

        $indexed = [];

        foreach ($resultValues as $value) {
            $result = $resultsById[(int) $value->calibration_test_result_id] ?? null;

            if ($result === null) {
                continue;
            }

            $scopeId = (int) $result->calibration_scope_id;
            $testPointId = (int) $result->test_point_id;
            $columnKey = (string) $value->column_key;
            $indexed[$scopeId][$testPointId][$columnKey][] = $value;
        }

        return $indexed;
    }

    /**
     * @param  Collection<int, object>  $resultValues
     * @return array<int, array<int, array<string, object>>>
     */
    private static function indexRawResultValues(Calibration $calibration, Collection $resultValues): array
    {
        $resultsById = [];

        foreach ($calibration->calibrationScopes as $scope) {
            if (! $scope->relationLoaded('calibrationTestResults')) {
                continue;
            }

            foreach ($scope->calibrationTestResults as $result) {
                $resultsById[(int) $result->calibration_test_result_id] = $result;
            }
        }

        $indexed = [];

        foreach ($resultValues as $value) {
            $result = $resultsById[(int) $value->calibration_test_result_id] ?? null;

            if ($result === null) {
                continue;
            }

            $scopeId = (int) $result->calibration_scope_id;
            $testPointId = (int) $result->test_point_id;
            $columnKey = (string) $value->column_key;
            $indexed[$scopeId][$testPointId][$columnKey] = $value;
        }

        return $indexed;
    }

    /**
     * @param  array<int, array<int, array<string, object>>>  $raw
     * @return array{key: int, title: string, state: 'snapshot'|'unsupported', columns: list<array{key: string, label: string, unit: string, value_source: string}>, rows: list<array{key: string, test_point_id: int, point_order: string, cells: list<array{id: string, column_key: string, value_source: string, data_type: string, unit: string, editable: bool, display: string, input: string}>}>}
     */
    private static function entryScopeTable(CalibrationScope $scope, array $raw): array
    {
        $decoded = self::decodedSnapshot($scope->getAttribute('test_table_snapshot'));
        $base = [
            'key' => (int) $scope->calibration_scope_id,
            'title' => self::display(self::firstFilled([
                is_array($decoded) ? ($decoded['display_title'] ?? null) : null,
                is_array($decoded) ? ($decoded['profile_name'] ?? null) : null,
                $scope->relationLoaded('testProfile') ? $scope->testProfile?->profile_name : null,
            ])),
            'remarks' => self::display(is_array($decoded) ? ($decoded['remarks'] ?? null) : null),
            'columns' => [],
            'rows' => [],
        ];

        if ($decoded === null || ! self::isSchemaVersion2($decoded) || ! TestTableSnapshotBuilder::isWellformed($decoded)) {
            return [...$base, 'state' => 'unsupported'];
        }

        if ($scope->test_profile_version_id !== null
            && (int) $decoded['test_profile_version_id'] !== (int) $scope->test_profile_version_id) {
            return [...$base, 'state' => 'unsupported'];
        }

        $columns = self::orderedColumns($decoded['columns']);
        $rows = self::orderedRows($decoded['rows']);
        $scopeId = (int) $scope->calibration_scope_id;

        return [
            ...$base,
            'state' => 'snapshot',
            'columns' => array_map(fn (array $column): array => [
                'key' => (string) $column['column_key'],
                'label' => (string) $column['column_label'],
                'unit' => self::display($column['unit'] ?? null),
                'value_source' => (string) ($column['value_source'] ?? ''),
            ], $columns),
            'rows' => array_map(
                fn (array $row): array => self::entryRow($scopeId, $row, $columns, $raw[$scopeId] ?? []),
                $rows,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $columns
     * @param  array<int, array<string, object>>  $rawByPoint
     * @return array{key: string, test_point_id: int, point_order: string, cells: list<array{id: string, column_key: string, value_source: string, data_type: string, unit: string, editable: bool, display: string, input: string}>}
     */
    private static function entryRow(int $scopeId, array $row, array $columns, array $rawByPoint): array
    {
        $testPointId = is_numeric($row['test_point_id'] ?? null) ? (int) $row['test_point_id'] : 0;
        $templateValues = self::templateValues($row['template_values'] ?? null);
        $cells = [];

        foreach ($columns as $column) {
            $columnKey = (string) $column['column_key'];
            $source = (string) ($column['value_source'] ?? '');
            $dataType = (string) ($column['data_type'] ?? '');
            $stored = $rawByPoint[$testPointId][$columnKey] ?? null;
            $editable = $testPointId > 0
                && ($source === 'engineer'
                    || ($source === 'calculated'
                        && ($column['formula_code'] ?? null) !== ColumnFormula::StandardMinusReading))
                && in_array($column['completion_rule'] ?? null, [
                    InstrumentTypeRequirement::MODE_REQUIRED,
                    InstrumentTypeRequirement::MODE_OPTIONAL,
                    InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
                ], true);

            if ($source === 'template') {
                $display = self::formatStoredValue($column, $templateValues[$columnKey] ?? null);
                $input = '';
            } else {
                $entry = $stored === null ? null : [
                    'numeric_value' => $stored->numeric_value ?? null,
                    'text_value' => $stored->text_value ?? null,
                ];
                $display = self::formatStoredValue($column, $entry);
                $input = self::inputValue($column, $entry);
            }

            $cells[] = [
                'id' => self::resultCellKey($scopeId, $testPointId, $columnKey),
                'column_key' => $columnKey,
                'value_source' => $source,
                'data_type' => $dataType,
                'unit' => self::display($column['unit'] ?? null),
                'editable' => $editable,
                'display' => $display,
                'input' => $input,
            ];
        }

        return [
            'key' => $testPointId > 0 ? (string) $testPointId : 'order-'.(string) ($row['point_order'] ?? '0'),
            'test_point_id' => $testPointId,
            'point_order' => self::display($row['point_order'] ?? null),
            'cells' => $cells,
        ];
    }

    /**
     * @param  array<string, mixed>  $column
     * @param  array<string, mixed>|null  $entry
     */
    private static function inputValue(array $column, ?array $entry): string
    {
        if ($entry === null) {
            return '';
        }

        $dataType = $column['data_type'] ?? null;

        if ($dataType === 'text') {
            return is_string($entry['text_value'] ?? null) ? (string) $entry['text_value'] : '';
        }

        $numeric = $entry['numeric_value'] ?? null;

        if ($numeric === null || $numeric === '') {
            return '';
        }

        $stored = (string) $numeric;

        if ($dataType === 'duration' && preg_match('/^-?\d+$/', $stored) === 1) {
            return DurationValue::format((int) $stored, alwaysSigned: false);
        }

        return $stored;
    }

    /**
     * @param  list<mixed>  $columns
     * @return list<array<string, mixed>>
     */
    private static function orderedColumns(array $columns): array
    {
        $normalized = [];

        foreach ($columns as $column) {
            if (is_array($column) && is_string($column['column_key'] ?? null)) {
                $normalized[] = $column;
            }
        }

        usort($normalized, function (array $left, array $right): int {
            $order = ((int) ($left['column_order'] ?? 0)) <=> ((int) ($right['column_order'] ?? 0));

            if ($order !== 0) {
                return $order;
            }

            return strcmp((string) $left['column_key'], (string) $right['column_key']);
        });

        return $normalized;
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private static function orderedRows(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized[] = $row;
            }
        }

        usort($normalized, function (array $left, array $right): int {
            $order = ((int) ($left['point_order'] ?? 0)) <=> ((int) ($right['point_order'] ?? 0));

            if ($order !== 0) {
                return $order;
            }

            return ((int) ($left['test_point_id'] ?? 0)) <=> ((int) ($right['test_point_id'] ?? 0));
        });

        return $normalized;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function templateValues(mixed $values): array
    {
        if (is_object($values)) {
            $values = (array) $values;
        }

        if (! is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $key => $entry) {
            if (! is_string($key)) {
                continue;
            }

            if (is_object($entry)) {
                $entry = (array) $entry;
            }

            $normalized[$key] = is_array($entry) ? $entry : null;
        }

        return array_filter($normalized, fn (mixed $entry): bool => is_array($entry));
    }

    /**
     * @param  array<int, array<int, array<string, list<object>>>>  $raw
     * @return array{key: int, state: 'unpinned'|'complete'|'missing_template'|'conflict'|'invalid_snapshot', label: string, recoverable: bool}
     */
    private static function classifyTemplateMaterialization(
        Calibration $calibration,
        CalibrationScope $scope,
        array $raw,
    ): array {
        $key = (int) $scope->calibration_scope_id;

        if (! self::scopeHasSnapshotPayload($scope)) {
            return self::materializationRow($key, 'unpinned');
        }

        $plans = self::expectedTemplatePlans($calibration, $scope);

        if ($plans === null) {
            return self::materializationRow($key, 'invalid_snapshot');
        }

        $missing = false;
        $conflict = false;

        foreach ($plans as $plan) {
            $cells = $raw[$plan['calibration_scope_id']][$plan['test_point_id']][$plan['column_key']] ?? [];

            if ($cells === []) {
                $missing = true;

                continue;
            }

            if (count($cells) !== 1 || ! self::sameExpectedTemplateCell($cells[0], $plan)) {
                $conflict = true;
            }
        }

        if ($conflict) {
            return self::materializationRow($key, 'conflict');
        }

        if ($missing) {
            return self::materializationRow($key, 'missing_template');
        }

        return self::materializationRow($key, 'complete');
    }

    /**
     * @return array{key: int, state: 'unpinned'|'complete'|'missing_template'|'conflict'|'invalid_snapshot', label: string, recoverable: bool}
     */
    private static function materializationRow(int $key, string $state): array
    {
        $labels = [
            'unpinned' => 'This Test Table is not pinned.',
            'complete' => 'Template values are complete.',
            'missing_template' => 'Template values are missing.',
            'conflict' => 'Template values conflict and require manual review.',
            'invalid_snapshot' => 'The frozen Test Table snapshot is invalid.',
        ];

        return [
            'key' => $key,
            'state' => $state,
            'label' => $labels[$state],
            'recoverable' => $state === 'missing_template',
        ];
    }

    /**
     * @return list<array{calibration_scope_id: int, test_point_id: int, column_key: string, data_type: string, numeric_value: ?string, text_value: ?string, unit: ?string}>|null
     */
    private static function expectedTemplatePlans(Calibration $calibration, CalibrationScope $scope): ?array
    {
        $decoded = self::decodedSnapshot($scope->getAttribute('test_table_snapshot'));

        if ($decoded === null || ! self::isSchemaVersion2($decoded) || ! TestTableSnapshotBuilder::isWellformed($decoded)) {
            return null;
        }

        if ($scope->test_profile_version_id !== null
            && (int) $decoded['test_profile_version_id'] !== (int) $scope->test_profile_version_id) {
            return null;
        }

        if ((int) $decoded['test_profile_id'] !== (int) $scope->test_profile_id) {
            return null;
        }

        $typeId = (int) ($calibration->instrument_type_id ?? 0);

        if ($typeId > 0 && (int) $scope->instrument_type_id !== $typeId) {
            return null;
        }

        $columnKeys = [];
        $pointIds = [];
        $plans = [];

        foreach ($decoded['columns'] as $column) {
            if (! is_array($column) || ! is_string($column['column_key'] ?? null)) {
                return null;
            }

            $columnKey = $column['column_key'];

            if (isset($columnKeys[$columnKey])) {
                return null;
            }

            $columnKeys[$columnKey] = true;
        }

        foreach ($decoded['rows'] as $row) {
            if (! is_array($row) || ! is_int($row['test_point_id'] ?? null) || $row['test_point_id'] < 1) {
                return null;
            }

            $testPointId = $row['test_point_id'];

            if (isset($pointIds[$testPointId])) {
                return null;
            }

            $pointIds[$testPointId] = true;

            foreach ($decoded['columns'] as $column) {
                if (! is_array($column) || ($column['value_source'] ?? null) !== 'template') {
                    continue;
                }

                $plan = self::expectedTemplatePlan((int) $scope->calibration_scope_id, $row, $column);

                if ($plan === false) {
                    return null;
                }

                if ($plan !== null) {
                    $plans[] = $plan;
                }
            }
        }

        return $plans;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $column
     * @return array{calibration_scope_id: int, test_point_id: int, column_key: string, data_type: string, numeric_value: ?string, text_value: ?string, unit: ?string}|false|null
     */
    private static function expectedTemplatePlan(int $scopeId, array $row, array $column): array|false|null
    {
        $rule = $column['completion_rule'] ?? null;
        $dataType = $column['data_type'] ?? null;
        $columnKey = $column['column_key'] ?? null;

        if ($rule === InstrumentTypeRequirement::MODE_NOT_CONFIGURED) {
            $rule = InstrumentTypeRequirement::MODE_OPTIONAL;
        }

        if (! is_string($columnKey)
            || ! in_array($dataType, ['numeric', 'text', 'duration'], true)
            || ! in_array($rule, [InstrumentTypeRequirement::MODE_REQUIRED, InstrumentTypeRequirement::MODE_OPTIONAL], true)) {
            return false;
        }

        $values = $row['template_values'] ?? null;

        if (! is_array($values)) {
            return false;
        }

        if (! array_key_exists($columnKey, $values) || $values[$columnKey] === null) {
            return $rule === InstrumentTypeRequirement::MODE_REQUIRED ? false : null;
        }

        $entry = $values[$columnKey];

        if (! is_array($entry) || array_is_list($entry)) {
            return false;
        }

        $numeric = $entry['numeric_value'] ?? null;
        $text = $entry['text_value'] ?? null;

        if ($numeric !== null && $text !== null) {
            return false;
        }

        if ($dataType === 'text') {
            if ($numeric !== null || ! is_string($text) || trim($text) === '') {
                return $rule === InstrumentTypeRequirement::MODE_REQUIRED ? false : null;
            }

            $unit = self::expectedTemplateUnit($entry);

            return $unit === false ? false : [
                'calibration_scope_id' => $scopeId,
                'test_point_id' => (int) $row['test_point_id'],
                'column_key' => $columnKey,
                'data_type' => $dataType,
                'numeric_value' => null,
                'text_value' => $text,
                'unit' => $unit,
            ];
        }

        if ($text !== null || ! is_string($numeric)) {
            return $rule === InstrumentTypeRequirement::MODE_REQUIRED ? false : null;
        }

        if ($dataType === 'duration' && preg_match('/^\d+$/', $numeric) !== 1) {
            return false;
        }

        if ($dataType === 'numeric' && preg_match('/^-?\d+(?:\.\d+)?$/', $numeric) !== 1) {
            return false;
        }

        $unit = self::expectedTemplateUnit($entry);

        return $unit === false ? false : [
            'calibration_scope_id' => $scopeId,
            'test_point_id' => (int) $row['test_point_id'],
            'column_key' => $columnKey,
            'data_type' => $dataType,
            'numeric_value' => $numeric,
            'text_value' => null,
            'unit' => $unit,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function expectedTemplateUnit(array $entry): string|false|null
    {
        if (! array_key_exists('unit', $entry) || $entry['unit'] === null) {
            return null;
        }

        if (! is_string($entry['unit'])) {
            return false;
        }

        $unit = $entry['unit'];

        if (trim($unit) === '' || mb_strlen($unit) > 50) {
            return false;
        }

        return $unit;
    }

    /**
     * @param  array{data_type: string, numeric_value: ?string, text_value: ?string, unit: ?string}  $plan
     */
    private static function sameExpectedTemplateCell(object $cell, array $plan): bool
    {
        if ((string) $cell->value_source !== 'template' || (string) $cell->data_type !== $plan['data_type']) {
            return false;
        }

        $storedUnit = $cell->unit ?? null;
        $frozenUnit = $plan['unit'];

        if ($frozenUnit === null) {
            if ($storedUnit !== null) {
                return false;
            }
        } elseif (! is_string($storedUnit) || $storedUnit !== $frozenUnit) {
            return false;
        }

        if ($plan['data_type'] === 'text') {
            return $cell->numeric_value === null
                && is_string($cell->text_value)
                && $cell->text_value === $plan['text_value'];
        }

        return $cell->text_value === null
            && self::sameExpectedDecimal($cell->numeric_value, $plan['numeric_value']);
    }

    private static function sameExpectedDecimal(mixed $stored, ?string $frozen): bool
    {
        if ($frozen === null || ! is_string($stored) || preg_match('/^-?\d+(?:\.\d+)?$/', $stored) !== 1) {
            return false;
        }

        return self::canonicalDecimal($stored) === self::canonicalDecimal($frozen);
    }

    private static function canonicalDecimal(string $value): string
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

    /**
     * @return array<string, mixed>|null
     */
    private static function decodedSnapshot(mixed $snapshot): ?array
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
    private static function isSchemaVersion2(array $snapshot): bool
    {
        $version = $snapshot['schema_version'] ?? null;

        return $version === 2 || $version === '2';
    }
}
