<?php

namespace App\Standards;

use App\Models\StandardMaster;
use Illuminate\Support\Facades\DB;

class StandardMasterImporter
{
    public function __construct(private StandardMasterWorkbook $workbook) {}

    /**
     * @return array{imported: int, skipped: int, total: int}
     */
    public function import(string $path): array
    {
        $rows = $this->workbook->rows($path);

        if ($rows === []) {
            throw new StandardMasterException('The standard master workbook did not contain any standards.');
        }

        $codes = array_map(fn (array $row): string => $row['standard_code'], $rows);

        if (in_array('', $codes, true)) {
            throw new StandardMasterException('Every standard master row must include a Standard ID.');
        }

        if (count($codes) !== count(array_unique($codes))) {
            throw new StandardMasterException('Standard IDs in the workbook must be unique.');
        }

        return DB::transaction(function () use ($rows): array {
            $existing = StandardMaster::query()
                ->whereIn('standard_code', array_map(fn (array $row): string => $row['standard_code'], $rows))
                ->pluck('standard_code')
                ->all();
            $imported = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                if (in_array($row['standard_code'], $existing, true)) {
                    $skipped++;

                    continue;
                }

                if (trim($row['instrument_name']) === '') {
                    throw new StandardMasterException('Instrument Name is required for '.$row['standard_code'].'.');
                }

                $standard = new StandardMaster;
                $standard->standard_code = $row['standard_code'];
                $standard->instrument_name = $row['instrument_name'];
                $standard->type_specification = $row['type_specification'];
                $standard->serial_number = $row['serial_number'];
                $standard->save();
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
