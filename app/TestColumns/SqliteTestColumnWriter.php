<?php

namespace App\TestColumns;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Persists column changes on the SQLite test connection.
 * PostgreSQL writes go through PostgresTestColumnWriter and the database functions.
 */
class SqliteTestColumnWriter implements TestColumnWriter
{
    public function __construct(private TestTableVersioning $versioning) {}

    public function insert(ColumnDefinition $column): int
    {
        $id = ((int) DB::table('test_column')->max('test_column_id')) + 1;
        $payload = [
            'test_column_id' => $id,
            'test_profile_id' => $column->profileId,
            'column_key' => $column->columnKey,
            'column_label' => $column->columnLabel,
            'column_order' => $column->columnOrder,
            'data_type' => $column->dataType,
            'value_source' => $column->valueSource,
            'unit' => $column->unit,
            'display_scale' => $column->displayScale,
            'formula_code' => $column->formulaCode,
            'formula_inputs' => json_encode($column->formulaInputs),
            'created_at' => now(),
        ];

        $versionId = $this->versioning->draftId($column->profileId);

        if ($versionId !== null) {
            $payload['test_profile_version_id'] = $versionId;
        }

        DB::table('test_column')->insert($payload);

        return $id;
    }

    public function update(int $columnId, ColumnDefinition $column): void
    {
        $updated = DB::table('test_column')
            ->where('test_column_id', $columnId)
            ->update([
                'column_label' => $column->columnLabel,
                'column_order' => $column->columnOrder,
                'data_type' => $column->dataType,
                'value_source' => $column->valueSource,
                'unit' => $column->unit,
                'display_scale' => $column->displayScale,
                'formula_code' => $column->formulaCode,
                'formula_inputs' => json_encode($column->formulaInputs),
            ]);

        if ($updated === 0) {
            throw new TestColumnWriteException('This column could not be found.');
        }
    }

    public function delete(int $columnId): void
    {
        $deleted = DB::table('test_column')->where('test_column_id', $columnId)->delete();

        if ($deleted === 0) {
            throw new TestColumnWriteException('This column could not be found.');
        }
    }

    public function reorder(int $profileId, array $columnIds): void
    {
        $existingQuery = DB::table('test_column')->where('test_profile_id', $profileId);
        $this->scopeToWritableVersion($existingQuery, $profileId);

        $existing = $existingQuery
            ->pluck('test_column_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $incoming = array_map(static fn (int $id): int => $id, $columnIds);
        $sortedExisting = $existing;
        $sortedIncoming = $incoming;
        sort($sortedExisting);
        sort($sortedIncoming);

        if ($incoming === [] || count($incoming) !== count(array_unique($incoming)) || $sortedExisting !== $sortedIncoming) {
            throw new TestColumnWriteException('The new order must include every column of this test table once.');
        }

        $case = [];
        $bindings = [];

        foreach ($incoming as $index => $id) {
            $case[] = 'when test_column_id = ? then ?';
            $bindings[] = $id;
            $bindings[] = $index + 1;
        }

        $sql = 'update test_column set column_order = case '.implode(' ', $case).' end where test_profile_id = ?';
        $bindings[] = $profileId;

        $versionId = $this->versioning->draftId($profileId);

        if ($versionId !== null) {
            $sql .= ' and test_profile_version_id = ?';
            $bindings[] = $versionId;
        } elseif ($this->versioning->enabled()) {
            $sql .= ' and test_profile_version_id is null';
        }

        DB::update($sql, $bindings);
    }

    private function scopeToWritableVersion(Builder $query, int $profileId): void
    {
        $versionId = $this->versioning->draftId($profileId);

        if ($versionId !== null) {
            $query->where('test_profile_version_id', $versionId);

            return;
        }

        if ($this->versioning->enabled()) {
            $query->whereNull('test_profile_version_id');
        }
    }
}
