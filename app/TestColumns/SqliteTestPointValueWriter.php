<?php

namespace App\TestColumns;

/**
 * Persists template cells on the SQLite test connection.
 * PostgreSQL writes go through PostgresTestPointValueWriter and the database functions.
 */
class SqliteTestPointValueWriter implements TestPointValueWriter
{
    use WritesThroughHttpConnection;

    public function __construct(private TestTableVersioning $versioning) {}

    public function upsert(
        int $profileId,
        int $pointId,
        int $columnId,
        ?string $numericValue,
        ?string $textValue,
        ?string $unit = null,
    ): void {
        $existingId = $this->writeConnection()->table('test_point_value')
            ->where('test_profile_id', $profileId)
            ->where('test_point_id', $pointId)
            ->where('test_column_id', $columnId)
            ->value('test_point_value_id');

        $payload = [
            'test_profile_id' => $profileId,
            'test_point_id' => $pointId,
            'test_column_id' => $columnId,
            'numeric_value' => $numericValue,
            'text_value' => $textValue,
            'unit' => $unit,
        ];

        $versionId = $this->versioning->draftId($profileId);

        if ($versionId !== null) {
            $payload['test_profile_version_id'] = $versionId;
        }

        if ($existingId !== null) {
            $this->writeConnection()->table('test_point_value')
                ->where('test_point_value_id', $existingId)
                ->update($payload);

            return;
        }

        $this->writeConnection()->table('test_point_value')->insert([
            ...$payload,
            'test_point_value_id' => ((int) $this->writeConnection()->table('test_point_value')->max('test_point_value_id')) + 1,
            'created_at' => now(),
        ]);
    }

    public function delete(int $profileId, int $pointId, int $columnId): void
    {
        $this->writeConnection()->table('test_point_value')
            ->where('test_profile_id', $profileId)
            ->where('test_point_id', $pointId)
            ->where('test_column_id', $columnId)
            ->delete();
    }
}
