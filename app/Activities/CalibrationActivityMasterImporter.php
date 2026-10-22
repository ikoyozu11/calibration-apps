<?php

namespace App\Activities;

use App\Models\CalibrationActivityMaster;
use Illuminate\Support\Facades\DB;

class CalibrationActivityMasterImporter
{
    public function __construct(private CalibrationActivityMasterWorkbook $workbook) {}

    /**
     * @return array{imported: int, skipped: int, total: int}
     */
    public function import(string $path): array
    {
        $rows = $this->workbook->rows($path);

        if ($rows === []) {
            throw new CalibrationActivityMasterException('The activity master workbook did not contain any activities.');
        }

        $codes = array_map(fn (array $row): string => $row['activity_code'], $rows);

        if (in_array('', $codes, true)) {
            throw new CalibrationActivityMasterException('Every activity master row must include an Activity Code.');
        }

        if (count($codes) !== count(array_unique($codes))) {
            throw new CalibrationActivityMasterException('Activity Codes in the workbook must be unique.');
        }

        return DB::transaction(function () use ($rows): array {
            $existing = CalibrationActivityMaster::query()
                ->whereIn('activity_code', array_map(fn (array $row): string => $row['activity_code'], $rows))
                ->pluck('activity_code')
                ->all();
            $imported = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                if (in_array($row['activity_code'], $existing, true)) {
                    $skipped++;

                    continue;
                }

                if (trim($row['activity_name']) === '') {
                    throw new CalibrationActivityMasterException('Activity Name is required for '.$row['activity_code'].'.');
                }

                $activity = new CalibrationActivityMaster;
                $activity->activity_code = $row['activity_code'];
                $activity->activity_name = $row['activity_name'];
                $activity->is_active = true;
                $activity->save();
                $imported++;
            }

            return [
                'imported' => $imported,
                'skipped' => $skipped,
                'total' => count($rows),
            ];
        });
    }
}
