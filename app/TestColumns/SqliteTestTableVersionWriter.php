<?php

namespace App\TestColumns;

use App\Models\TestProfileVersion;
use Illuminate\Support\Facades\DB;
use stdClass;

class SqliteTestTableVersionWriter implements TestTableVersionWriter
{
    public function __construct(private TestTableVersionGuard $guard) {}

    public function fork(int $sourceVersionId): int
    {
        return DB::transaction(function () use ($sourceVersionId): int {
            $source = $this->guard->lockForkSource($sourceVersionId);
            $newVersionId = $this->nextId('test_profile_version', 'test_profile_version_id');
            $nextNumber = ((int) DB::table('test_profile_version')
                ->where('test_profile_id', $source->test_profile_id)
                ->max('version_number')) + 1;

            DB::table('test_profile_version')->insert([
                'test_profile_version_id' => $newVersionId,
                'test_profile_id' => $source->test_profile_id,
                'version_number' => $nextNumber,
                'status' => TestProfileVersion::STATUS_DRAFT,
                'profile_name' => $source->profile_name,
                'display_title' => $source->display_title,
                'description' => $source->description,
                'published_at' => null,
                'created_at' => now(),
            ]);

            $columnMap = $this->copyColumns($sourceVersionId, (int) $source->test_profile_id, $newVersionId);
            $pointMap = $this->copyPoints($sourceVersionId, (int) $source->test_profile_id, $newVersionId);
            $this->copyValues($sourceVersionId, (int) $source->test_profile_id, $newVersionId, $columnMap, $pointMap);

            return $newVersionId;
        });
    }

    public function publish(int $profileId): int
    {
        return DB::transaction(function () use ($profileId): int {
            $locked = $this->guard->lockPublishableDraft($profileId);
            $draft = $locked['draft'];
            $active = $locked['active'];

            if ($active !== null) {
                DB::table('test_profile_version')
                    ->where('test_profile_version_id', $active->test_profile_version_id)
                    ->update(['status' => TestProfileVersion::STATUS_RETIRED]);
            }

            DB::table('test_profile_version')
                ->where('test_profile_version_id', $draft->test_profile_version_id)
                ->update([
                    'status' => TestProfileVersion::STATUS_ACTIVE,
                    'published_at' => now(),
                ]);

            DB::table('test_profile')
                ->where('test_profile_id', $profileId)
                ->update([
                    'profile_name' => $draft->profile_name,
                    'display_title' => $draft->display_title,
                    'description' => $draft->description,
                ]);

            return (int) $draft->test_profile_version_id;
        });
    }

    /**
     * @return array<int, int>
     */
    private function copyColumns(int $sourceVersionId, int $profileId, int $newVersionId): array
    {
        $columns = DB::table('test_column')
            ->where('test_profile_version_id', $sourceVersionId)
            ->orderByRaw("case when value_source = 'calculated' then 1 else 0 end")
            ->orderBy('column_order')
            ->orderBy('test_column_id')
            ->get();

        $map = [];

        foreach ($columns as $column) {
            $newId = $this->nextId('test_column', 'test_column_id');
            $map[(int) $column->test_column_id] = $newId;

            DB::table('test_column')->insert([
                'test_column_id' => $newId,
                'test_profile_id' => $profileId,
                'test_profile_version_id' => $newVersionId,
                'column_key' => $column->column_key,
                'column_label' => $column->column_label,
                'column_order' => $column->column_order,
                'data_type' => $column->data_type,
                'value_source' => $column->value_source,
                'unit' => $column->unit,
                'display_scale' => $column->display_scale,
                'formula_code' => $column->formula_code,
                'formula_inputs' => $column->formula_inputs,
                'created_at' => now(),
            ]);
        }

        return $map;
    }

    /**
     * @return array<int, int>
     */
    private function copyPoints(int $sourceVersionId, int $profileId, int $newVersionId): array
    {
        $points = DB::table('test_point')
            ->where('test_profile_version_id', $sourceVersionId)
            ->orderBy('point_order')
            ->orderBy('test_point_id')
            ->get();

        $map = [];

        foreach ($points as $point) {
            $newId = $this->nextId('test_point', 'test_point_id');
            $map[(int) $point->test_point_id] = $newId;

            DB::table('test_point')->insert([
                'test_point_id' => $newId,
                'test_profile_id' => $profileId,
                'test_profile_version_id' => $newVersionId,
                'point_order' => $point->point_order,
                'nominal_value' => $point->nominal_value,
                'unit' => $point->unit,
                'tolerance_plus' => $point->tolerance_plus,
                'tolerance_minus' => $point->tolerance_minus,
                'description' => $point->description,
                'created_at' => now(),
            ]);
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $columnMap
     * @param  array<int, int>  $pointMap
     */
    private function copyValues(
        int $sourceVersionId,
        int $profileId,
        int $newVersionId,
        array $columnMap,
        array $pointMap,
    ): void {
        $cells = DB::table('test_point_value')
            ->where('test_profile_version_id', $sourceVersionId)
            ->orderBy('test_point_value_id')
            ->get();

        foreach ($cells as $cell) {
            $this->assertMappedCell($cell, $columnMap, $pointMap);

            DB::table('test_point_value')->insert([
                'test_point_value_id' => $this->nextId('test_point_value', 'test_point_value_id'),
                'test_profile_id' => $profileId,
                'test_profile_version_id' => $newVersionId,
                'test_point_id' => $pointMap[(int) $cell->test_point_id],
                'test_column_id' => $columnMap[(int) $cell->test_column_id],
                'numeric_value' => $cell->numeric_value,
                'text_value' => $cell->text_value,
                'unit' => $cell->unit,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<int, int>  $columnMap
     * @param  array<int, int>  $pointMap
     */
    private function assertMappedCell(stdClass $cell, array $columnMap, array $pointMap): void
    {
        if (! isset($pointMap[(int) $cell->test_point_id], $columnMap[(int) $cell->test_column_id])) {
            throw new TestTableVersionWriteException('The test table version could not be updated. Refresh the page and try again.');
        }
    }

    private function nextId(string $table, string $column): int
    {
        return ((int) DB::table($table)->max($column)) + 1;
    }
}
