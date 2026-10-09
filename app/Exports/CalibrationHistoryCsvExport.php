<?php

namespace App\Exports;

use App\Models\Calibration;

class CalibrationHistoryCsvExport
{
    /**
     * @return list<string>
     */
    public static function header(): array
    {
        return [
            'Calibration Number',
            'Instrument Name',
            'Calibration Date',
            'Calibration Due',
            'Status',
            'Calibration Method',
            'Calibration Result',
            'Certificate Number',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public static function rows(): array
    {
        $calibrations = Calibration::query()
            ->select([
                'calibration_id',
                'instrument_id',
                'calibration_number',
                'calibration_date',
                'calibration_due',
                'calibration_method',
                'calibration_result',
                'certificate_number',
            ])
            ->with([
                'instrument:instrument_id,instrument_name',
            ])
            ->orderByDesc('calibration_date')
            ->orderByDesc('calibration_id')
            ->get();

        $rows = [];

        foreach ($calibrations as $calibration) {
            $rows[] = [
                CsvDownload::cell($calibration->calibration_number),
                CsvDownload::cell($calibration->instrument->instrument_name),
                CsvDownload::date($calibration->calibration_date),
                CsvDownload::date($calibration->calibration_due),
                CsvDownload::status($calibration->calibration_due),
                CsvDownload::cell($calibration->calibration_method),
                CsvDownload::cell($calibration->calibration_result),
                CsvDownload::cell($calibration->certificate_number),
            ];
        }

        return $rows;
    }
}
