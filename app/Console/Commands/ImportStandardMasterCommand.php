<?php

namespace App\Console\Commands;

use App\StagingPreflight;
use App\Standards\StandardMasterException;
use App\Standards\StandardMasterImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('standards:import-master {path?}')]
#[Description('Import approved Standard(s) Used master rows without overwriting existing codes.')]
class ImportStandardMasterCommand extends Command
{
    public function handle(StandardMasterImporter $importer, StagingPreflight $preflight): int
    {
        if (DB::getDriverName() === 'pgsql' && $preflight->currentDatabase() !== StagingPreflight::REQUIRED_DATABASE) {
            $this->error('Refusing import: current database is '.$preflight->currentDatabase().', expected '.StagingPreflight::REQUIRED_DATABASE.'.');

            return self::FAILURE;
        }

        $path = $this->argument('path');
        $workbook = is_string($path) && $path !== ''
            ? $path
            : database_path('imports/Standard_Used_Master_Final_70.xlsx');

        try {
            $result = $importer->import($workbook);
        } catch (StandardMasterException $exception) {
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
