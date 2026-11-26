<?php

/**
 * Static inspection of the staging reconciliation scheduler launcher.
 * Does not execute the launcher or open PostgreSQL.
 */

use Tests\TestRefusingPostgresConnector;

function stagingReconciliationLauncherSource(): string
{
    $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'run-staging-reconciliation-scheduler.ps1';
    $source = file_get_contents($path);

    expect($source)->toBeString();

    return $source;
}

it('pins the scheduler launcher to staging and does not inherit database urls', function () {
    $source = stagingReconciliationLauncherSource();

    expect($source)->toContain("EnvironmentVariables['DB_CONNECTION'] = 'pgsql'")
        ->and($source)->toContain("EnvironmentVariables['DB_DATABASE'] = 'calibration_staging_db'")
        ->and($source)->toContain("EnvironmentVariables['DB_HOST'] = '127.0.0.1'")
        ->and($source)->toContain("EnvironmentVariables['DB_PORT'] = '5432'")
        ->and($source)->toContain("EnvironmentVariables['DB_URL'] = ''")
        ->and($source)->toContain("EnvironmentVariables['DATABASE_URL'] = ''")
        ->and($source)->toContain("EnvironmentVariables['CACHE_STORE'] = 'file'")
        ->and($source)->toContain("EnvironmentVariables['CACHE_FILE_PATH'] = \$cacheDir")
        ->and($source)->toContain("EnvironmentVariables['CACHE_FILE_LOCK_PATH'] = \$cacheDir")
        ->and($source)->toContain("EnvironmentVariables['STAGING_RUNTIME_ISOLATION'] = '1'")
        ->and($source)->toContain("EnvironmentVariables['CALIBRATION_NOTIFICATION_RECONCILIATION_STAGING'] = '1'")
        ->and($source)->not->toContain('calibration_db')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('requires uncached config and runs staging preflight before schedule ticks', function () {
    $source = stagingReconciliationLauncherSource();

    expect($source)->toContain('bootstrap\\cache\\config.php')
        ->and($source)->toContain("Invoke-StagingCommand -Arguments 'artisan staging:preflight'")
        ->and($source)->toContain("Invoke-StagingCommand -Arguments 'artisan schedule:run'")
        ->and($source)->not->toContain('artisan config:cache')
        ->and($source)->not->toContain('artisan config:clear');
});
