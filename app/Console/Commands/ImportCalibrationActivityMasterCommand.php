<?php

namespace App\Console\Commands;

use App\Activities\CalibrationActivityMasterException;
use App\Activities\CalibrationActivityMasterImporter;
use App\StagingPreflight;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('activities:import-master {path?}')]
#[Description('Import approved Calibration Activity master rows without overwriting existing codes.')]
class ImportCalibrationActivityMasterCommand extends Command
{
    public function handle(CalibrationActivityMasterImporter $importer, StagingPreflight $preflight): int
    {
        if (DB::getDriverName() === 'pgsql' && $preflight->currentDatabase() !== StagingPreflight::REQUIRED_DATABASE) {
            $this->error('Refusing import: current database is '.$preflight->currentDatabase().', expected '.StagingPreflight::REQUIRED_DATABASE.'.');

            return self::FAILURE;
        }

        $path = $this->argument('path');
        $workbook = is_string($path) && $path !== ''
            ? $path
            : database_path('imports/Calibration_Activity_Master_Extracted.xlsx');

        try {
            $result = $importer->import($workbook);
        } catch (CalibrationActivityMasterException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('database: '.$preflight->currentDatabase());
        $this->line('imported: '.$result['imported']);
        $this->line('skipped: '.$result['skipped']);
        $this->line('workbook_rows: '.$result['total']);

        return self::SUCCESS;
    }
}
