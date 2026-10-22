<?php

namespace App\TestColumns;

use App\Models\TestColumn;
use App\Models\TestPoint;
use App\Models\TestPointValue;
use App\Models\TestProfileVersion;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use stdClass;

class TestTableSnapshotBuilder
{
    public const SCHEMA_VERSION = 2;

    public const DURATION_FORMAT = 'hh:mm:ss';

    /**
     * Frozen Test Table configuration for one calibration scope (schema_version 2).
     * Historical schema_version 1 snapshots remain readable and are not rewritten.
     *
     * @return array{
     *     schema_version: 2,
     *     captured_at: string,
     *     test_profile_id: int,
     *     test_profile_version_id: int,
     *     version_number: int,
     *     profile_name: string,
     *     display_title: ?string,
     *     description: ?string,
     *     duration_format: 'hh:mm:ss',
     *     columns: list<array{
     *         column_key: string,
     *         column_label: string,
     *         column_order: int,
     *         data_type: string,
     *         value_source: string,
     *         unit: ?string,
     *         display_scale: ?int,
     *         formula_code: ?string,
     *         formula_inputs: list<string>,
     *         completion_rule: 'required'|'optional'|'not_configured'
     *     }>,
     *     rows: list<array{
     *         test_point_id: int,
     *         point_order: int,
     *         nominal_value: ?string,
     *         unit: ?string,
     *         tolerance_plus: ?string,
     *         tolerance_minus: ?string,
     *         description: ?string,
     *         template_values: array<string, array{numeric_value: ?string, text_value: ?string, unit: ?string}>|stdClass
     *     }>
     * }
     */
    public function build(TestProfileVersion $version, ?Connection $connection = null): array
    {
        $connectionName = $this->connectionName($connection, $version);

        $version = TestProfileVersion::on($connectionName)->whereKey($version->getKey())->first();

        if ($version === null) {
            throw new TestTableSnapshotException('The Test Table version does not exist.');
        }

        if ($version->isDraft()) {
            throw new TestTableSnapshotException('A calibration snapshot cannot be taken from a draft Test Table version.');
        }

        if ($version->status === TestProfileVersion::STATUS_RETIRED) {
            throw new TestTableSnapshotException('A calibration snapshot cannot be taken from a retired Test Table version.');
        }

        if (! $version->isActive()) {
            throw new TestTableSnapshotException('A calibration snapshot can only be taken from an active Test Table version.');
        }

        $versionId = (int) $version->test_profile_version_id;
        $profileId = (int) $version->test_profile_id;

        $version->load([
            'testColumns' => function (HasMany $query) use ($profileId, $versionId): void {
                $query
                    ->where('test_profile_id', $profileId)
                    ->where('test_profile_version_id', $versionId)
                    ->orderBy('column_order')
                    ->orderBy('test_column_id');
            },
            'testPoints' => function (HasMany $query) use ($profileId, $versionId): void {
                $query
                    ->where('test_profile_id', $profileId)
                    ->where('test_profile_version_id', $versionId)
                    ->orderBy('point_order')
                    ->orderBy('test_point_id');
            },
            'testPoints.testPointValues' => function (HasMany $query) use ($profileId, $versionId): void {
                $query
                    ->where('test_profile_id', $profileId)
                    ->where('test_profile_version_id', $versionId);
            },
        ]);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'test_profile_id' => $profileId,
            'test_profile_version_id' => $versionId,
            'version_number' => (int) $version->version_number,
            'profile_name' => $version->profile_name,
            'display_title' => $version->display_title,
            'description' => $version->description,
            'duration_format' => self::DURATION_FORMAT,
            'columns' => $version->testColumns
                ->map(fn (TestColumn $column): array => $this->column($column))
                ->values()
                ->all(),
            'rows' => $version->testPoints
                ->map(fn (TestPoint $point): array => $this->row($point, $version->testColumns))
                ->values()
                ->all(),
        ];
    }

    /**
     * Mirrors public.test_table_snapshot_wellformed for schema_version 1 and 2.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function isWellformed(array $snapshot): bool
    {
        $schemaVersion = (string) ($snapshot['schema_version'] ?? '');

        if (! in_array($schemaVersion, ['1', '2'], true)) {
            return false;
        }

        if (($snapshot['duration_format'] ?? null) !== self::DURATION_FORMAT) {
            return false;
        }

        if (! is_numeric($snapshot['test_profile_id'] ?? null)
            || ! is_numeric($snapshot['test_profile_version_id'] ?? null)
            || ! is_numeric($snapshot['version_number'] ?? null)
            || ! is_string($snapshot['profile_name'] ?? null)
            || trim($snapshot['profile_name']) === '') {
            return false;
        }

        if (! is_array($snapshot['columns'] ?? null) || ! is_array($snapshot['rows'] ?? null)) {
            return false;
        }

        $seenKeys = [];

        foreach ($snapshot['columns'] as $column) {
            if (! is_array($column)) {
                return false;
            }

            $columnKey = $column['column_key'] ?? null;

            if (! is_string($columnKey)
                || preg_match('/^[a-z][a-z0-9_]{0,62}$/', $columnKey) !== 1
                || in_array($columnKey, $seenKeys, true)
                || ! is_string($column['column_label'] ?? null)
                || trim($column['column_label']) === ''
                || ! is_int($column['column_order'] ?? null)
                || $column['column_order'] < 1
                || ! in_array($column['data_type'] ?? null, ['numeric', 'text', 'duration'], true)
                || ! in_array($column['value_source'] ?? null, ['template', 'engineer', 'calculated'], true)
                || ! is_array($column['formula_inputs'] ?? null)
                || array_is_list($column['formula_inputs']) === false) {
                return false;
            }

            if ($schemaVersion === '2'
                && ! in_array($column['completion_rule'] ?? null, ['required', 'optional', 'not_configured'], true)) {
                return false;
            }

            $seenKeys[] = $columnKey;
        }

        foreach ($snapshot['rows'] as $row) {
            if (! is_array($row)
                || ! is_int($row['point_order'] ?? null)
                || $row['point_order'] < 1
                || ! self::isJsonObject($row['template_values'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{
     *     column_key: string,
     *     column_label: string,
     *     column_order: int,
     *     data_type: string,
     *     value_source: string,
     *     unit: ?string,
     *     display_scale: ?int,
     *     formula_code: ?string,
     *     formula_inputs: list<string>,
     *     completion_rule: 'required'|'optional'|'not_configured'
     * }
     */
    private function column(TestColumn $column): array
    {
        return [
            'column_key' => $column->column_key,
            'column_label' => $column->column_label,
            'column_order' => (int) $column->column_order,
            'data_type' => $column->data_type,
            'value_source' => $column->value_source,
            'unit' => filled($column->unit) ? (string) $column->unit : null,
            'display_scale' => $column->display_scale === null ? null : (int) $column->display_scale,
            'formula_code' => filled($column->formula_code) ? (string) $column->formula_code : null,
            'formula_inputs' => $column->inputKeys(),
            'completion_rule' => $this->completionRule($column),
        ];
    }

    /**
     * @return 'required'|'optional'|'not_configured'
     */
    private function completionRule(TestColumn $column): string
    {
        $rule = trim((string) $column->completion_rule);

        return $rule === '' ? 'not_configured' : $rule;
    }

    /**
     * @param  Collection<int, TestColumn>  $columns
     * @return array{
     *     test_point_id: int,
     *     point_order: int,
     *     nominal_value: ?string,
     *     unit: ?string,
     *     tolerance_plus: ?string,
     *     tolerance_minus: ?string,
     *     description: ?string,
     *     template_values: array<string, array{numeric_value: ?string, text_value: ?string, unit: ?string}>|stdClass
     * }
     */
    private function row(TestPoint $point, Collection $columns): array
    {
        return [
            'test_point_id' => (int) $point->test_point_id,
            'point_order' => (int) $point->point_order,
            'nominal_value' => $this->quantityString($point->nominal_value),
            'unit' => filled($point->unit) ? (string) $point->unit : null,
            'tolerance_plus' => $this->quantityString($point->tolerance_plus),
            'tolerance_minus' => $this->quantityString($point->tolerance_minus),
            'description' => filled($point->description) ? (string) $point->description : null,
            'template_values' => $this->templateValues($point, $columns),
        ];
    }

    /**
     * @param  Collection<int, TestColumn>  $columns
     * @return array<string, array{numeric_value: ?string, text_value: ?string, unit: ?string}>|stdClass
     */
    private function templateValues(TestPoint $point, Collection $columns): array|stdClass
    {
        $values = [];

        foreach ($columns as $column) {
            if ($column->value_source !== 'template') {
                continue;
            }

            $cell = $point->testPointValues->first(
                fn (TestPointValue $value): bool => (int) $value->test_column_id === (int) $column->test_column_id
                    && (int) $value->test_profile_version_id === (int) $column->test_profile_version_id,
            );

            $values[$column->column_key] = $this->templateCell($column, $cell);
        }

        return $values === [] ? new stdClass : $values;
    }

    /**
     * @return array{numeric_value: ?string, text_value: ?string, unit: ?string}
     */
    private function templateCell(TestColumn $column, ?TestPointValue $cell): array
    {
        $unit = filled($cell?->unit) ? (string) $cell->unit : null;

        if ($column->data_type === 'text') {
            return [
                'numeric_value' => null,
                'text_value' => filled($cell?->text_value) ? (string) $cell->text_value : null,
                'unit' => $unit,
            ];
        }

        $numeric = $column->data_type === 'duration'
            ? $this->durationSecondsString($cell?->numeric_value)
            : $this->quantityString($cell?->numeric_value);

        return [
            'numeric_value' => $numeric,
            'text_value' => null,
            'unit' => $unit,
        ];
    }

    private function quantityString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (! is_numeric($text)) {
            return $text;
        }

        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
            $text = $text === '' || $text === '-' ? '0' : $text;
        }

        return $text;
    }

    private function connectionName(?Connection $connection, TestProfileVersion $version): string
    {
        $name = $connection?->getName();

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $name = $version->getConnectionName();

        return is_string($name) && $name !== '' ? $name : (string) config('database.default');
    }

    private function durationSecondsString(mixed $value): ?string
    {
        $quantity = $this->quantityString($value);

        if ($quantity === null) {
            return null;
        }

        if (! is_numeric($quantity) || str_contains($quantity, '.')) {
            throw new TestTableSnapshotException('Duration template values must be whole seconds.');
        }

        return $quantity;
    }

    private static function isJsonObject(mixed $value): bool
    {
        if ($value instanceof stdClass) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        return $value === [] || ! array_is_list($value);
    }
}
