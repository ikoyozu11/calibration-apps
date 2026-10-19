<?php

namespace App\Console\Commands;

use App\StagingPreflight;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('staging:preflight')]
#[Description('Read-only check that Laravel is connected to calibration_staging_db with the session schema.')]
class StagingPreflightCommand extends Command
{
    /**
     * Run a development server against staging without changing .env:
     *
     *   $env:DB_CONNECTION = 'pgsql'
     *   $env:DB_DATABASE = 'calibration_staging_db'
     *   $env:DB_URL = ''
     *   $env:STAGING_PREFLIGHT_HTTP = '1'
     *   php artisan staging:preflight
     *   php -S 127.0.0.1:8000 -t public
     *
     * Or: scripts/serve-staging.ps1
     */
    public function handle(StagingPreflight $preflight): int
    {
        $report = $preflight->inspect();

        $this->line('database: '.$report['database']);
        $this->line('required: '.$report['required_database']);
        $this->line('driver: '.$report['driver']);

        foreach ($report['schema'] as $name => $present) {
            $this->line(($present ? 'ok    ' : 'missing').'  '.$name);
        }

        if ($report['ok']) {
            $this->info('Staging preflight passed.');

            return self::SUCCESS;
        }

        if (! $report['database_ok']) {
            $this->error('Active database is not calibration_staging_db. Override DB_DATABASE and DB_URL for this process; do not change .env.');
        }

        if (! $report['schema_ok']) {
            $this->error('Required staging schema is incomplete.');
        }

        return self::FAILURE;
    }
}
