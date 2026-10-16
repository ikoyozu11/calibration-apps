<?php

namespace App\TestColumns;

interface TestColumnWriter
{
    public function insert(ColumnDefinition $column): int;

    public function update(int $columnId, ColumnDefinition $column): void;

    public function delete(int $columnId): void;

    /**
     * @param  list<int>  $columnIds
     */
    public function reorder(int $profileId, array $columnIds): void;
}
