<?php

namespace App\TestColumns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PostgresTestPointValueWriter implements TestPointValueWriter
{
    public function upsert(
        int $profileId,
        int $pointId,
        int $columnId,
        ?string $numericValue,
        ?string $textValue,
        ?string $unit = null,
    ): void {
        $this->call(
            'select public.test_point_value_upsert(?::bigint, ?::bigint, ?::bigint, ?::numeric, ?::text, ?::varchar) as upserted',
            [$profileId, $pointId, $columnId, $numericValue, $textValue, $unit],
        );
    }

    public function delete(int $profileId, int $pointId, int $columnId): void
    {
        $this->call(
            'select public.test_point_value_delete(?::bigint, ?::bigint, ?::bigint) as deleted',
            [$profileId, $pointId, $columnId],
        );
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function call(string $sql, array $bindings): void
    {
        try {
            DB::selectOne($sql, $bindings);
        } catch (QueryException $exception) {
            throw new TestPointValueWriteException(
                TestPointValueConstraintMessage::fromDatabaseMessage($exception->getMessage()),
            );
        }
    }
}
