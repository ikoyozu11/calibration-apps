<?php

namespace App\Exports;

use App\Models\Calibration;
use App\Models\Instrument;

class InstrumentTypeCsvExport
{
    /**
     * @return list<string>
     */
    public static function header(): array
    {
        return [
            'Instrument Name',
            'Type Name',
            'Serial Number',
            'Asset Number',
            'Location Code',
            'Location Name',
            'Detail Location Code',
            'Detail Location Name',
            'Calibration Date',
            'Calibration Due',
            'Status',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public static function rows(): array
    {
        $instruments = Instrument::query()
            ->select([
                'instrument_id',
                'instrument_name',
                'detail_location_id',
            ])
            ->with([
                'instrumentTypes:instrument_type_id,instrument_id,type_name,serial_number,asset_number',
                'detailLocation:detail_location_id,location_id,detail_location_code,detail_location_name',
                'detailLocation.location:location_id,location_code,location_name',
                'calibrations:calibration_id,instrument_id,calibration_date,calibration_due',
            ])
            ->orderBy('instrument_id')
            ->get();

        $rows = [];

        foreach ($instruments as $instrument) {
            $calibration = $instrument->calibrations
                ->sortByDesc(fn (Calibration $calibration): array => [
                    $calibration->calibration_date?->getTimestamp() ?? PHP_INT_MIN,
                    $calibration->calibration_id,
                ])
                ->first();
            $detailLocation = $instrument->detailLocation;
            $location = $detailLocation?->location;

            foreach ($instrument->instrumentTypes->sortBy('instrument_type_id') as $type) {
                $rows[] = [
                    CsvDownload::cell($instrument->instrument_name),
                    CsvDownload::cell($type->type_name),
                    CsvDownload::cell($type->serial_number),
                    CsvDownload::cell($type->asset_number),
                    CsvDownload::cell($location?->location_code),
                    CsvDownload::cell($location?->location_name),
                    CsvDownload::cell($detailLocation?->detail_location_code),
                    CsvDownload::cell($detailLocation?->detail_location_name),
                    CsvDownload::date($calibration?->calibration_date),
                    CsvDownload::date($calibration?->calibration_due),
                    CsvDownload::status($calibration?->calibration_due),
                ];
            }
        }

        return $rows;
    }
}
