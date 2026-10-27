<?php

namespace App\Console\Commands;

use App\Authorization\BootstrapFirstAdministrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Console-only first-Administrator bootstrap for calibration_staging_db.
 *
 * Does not create users, reset passwords, replace roles, open recovery mode,
 * or write domain actor mappings. Not reachable over HTTP.
 */
#[Signature('staging:bootstrap-administrator
    {--email= : Exact email of an existing Laravel user}
    {--confirm-database= : Must be exactly calibration_staging_db}
    {--dry-run : Validate without writing user_roles}')]
#[Description('Assign the first Administrator role to an existing Laravel user on calibration_staging_db.')]
class BootstrapAdministratorCommand extends Command
{
    public function handle(BootstrapFirstAdministrator $bootstrap): int
    {
        $email = (string) $this->option('email');
        $confirmDatabase = (string) $this->option('confirm-database');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $report = $bootstrap->run($email, $confirmDatabase, $dryRun);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('database: '.$report['database']);
        $this->line('connection: '.$report['connection']);
        $this->line('email: '.$report['email']);
        $this->line('user_id: '.($report['user_id'] ?? 'n/a'));
        $this->line('role_catalog_ready: '.($report['role_catalog_ready'] ? 'yes' : 'no'));
        $this->line('administrator_exists: '.($report['administrator_exists'] ? 'yes' : 'no'));
        $this->line('allowed: '.($report['allowed'] ? 'yes' : 'no'));
        $this->line('assigned: '.($report['assigned'] ? 'yes' : 'no'));
        $this->line('dry_run: '.($report['dry_run'] ? 'yes' : 'no'));
        $this->line($report['message']);

        if ($report['domain_mapping_warning'] !== null) {
            $this->warn($report['domain_mapping_warning']);
        }

        if ($report['dry_run'] && ! $report['allowed']) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
