<?php

namespace App\TestColumns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PostgresTestColumnWriter implements TestColumnWriter
{
    public function insert(ColumnDefinition $column): int
    {
        $row = $this->call(
            'select public.test_column_insert(?::bigint, ?::varchar, ?::varchar, ?::integer, ?::text, ?::text, ?::varchar, ?::smallint, ?::text, ?::text[]) as test_column_id',
            [
                $column->profileId,
                $column->columnKey,
                $column->columnLabel,
                $column->columnOrder,
                $column->dataType,
                $column->valueSource,
                $column->unit,
                $column->displayScale,
                $column->formulaCode,
                $this->textArray($column->formulaInputs),
            ],
        );

        return (int) $row->test_column_id;
    }

    public function update(int $columnId, ColumnDefinition $column): void
    {
        $this->call(
            'select public.test_column_update(?::bigint, ?::varchar, ?::integer, ?::text, ?::text, ?::varchar, ?::smallint, ?::text, ?::text[]) as updated',
            [
                $columnId,
                $column->columnLabel,
                $column->columnOrder,
                $column->dataType,
                $column->valueSource,
                $column->unit,
                $column->displayScale,
                $column->formulaCode,
                $this->textArray($column->formulaInputs),
            ],
        );
    }

    public function delete(int $columnId): void
    {
        $this->call(
            'select public.test_column_delete(?::bigint) as deleted',
            [$columnId],
        );
    }

    public function reorder(int $profileId, array $columnIds): void
    {
        $this->call(
            'select public.test_column_reorder(?::bigint, ?::bigint[]) as reordered',
            [$profileId, $this->bigintArray($columnIds)],
        );
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function call(string $sql, array $bindings): object
    {
        try {
            $row = DB::selectOne($sql, $bindings);
        } catch (QueryException $exception) {
            throw new TestColumnWriteException(
                TestColumnConstraintMessage::fromDatabaseMessage($exception->getMessage()),
            );
        }

        return $row ?? (object) ['test_column_id' => 0];
    }

    /**
     * @param  list<string>  $values
     */
    private function textArray(array $values): string
    {
        if ($values === []) {
            return '{}';
        }

        return '{'.implode(',', $values).'}';
    }

    /**
     * @param  list<int>  $values
     */
    private function bigintArray(array $values): string
    {
        if ($values === []) {
            return '{}';
        }

        return '{'.implode(',', array_map(static fn (int $id): int => $id, $values)).'}';
    }
}
