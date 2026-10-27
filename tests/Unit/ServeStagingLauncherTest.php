<?php

/**
 * Static inspection of scripts/serve-staging.ps1 isolation contract.
 * Does not execute the launcher or open PostgreSQL.
 */

use Tests\TestRefusingPostgresConnector;

function serveStagingLauncherSource(): string
{
    $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'serve-staging.ps1';
    $source = file_get_contents($path);

    expect($source)->toBeString();

    return $source;
}

it('forces staging database connection overrides in the launcher', function () {
    $source = serveStagingLauncherSource();

    expect($source)->toContain("EnvironmentVariables['DB_CONNECTION'] = 'pgsql'")
        ->and($source)->toContain("EnvironmentVariables['DB_DATABASE'] = 'calibration_staging_db'")
        ->and($source)->toContain("EnvironmentVariables['DB_HOST'] = '127.0.0.1'")
        ->and($source)->toContain("EnvironmentVariables['DB_PORT'] = '5432'")
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('clears DB_URL and DATABASE_URL in the launcher child environment', function () {
    $source = serveStagingLauncherSource();

    expect($source)->toContain("EnvironmentVariables['DB_URL'] = ''")
        ->and($source)->toContain("EnvironmentVariables['DATABASE_URL'] = ''");
});

it('forces file sessions, file cache, and sync queue in the launcher', function () {
    $source = serveStagingLauncherSource();

    expect($source)->toContain("EnvironmentVariables['SESSION_DRIVER'] = 'file'")
        ->and($source)->toContain("EnvironmentVariables['CACHE_STORE'] = 'file'")
        ->and($source)->toContain("EnvironmentVariables['QUEUE_CONNECTION'] = 'sync'")
        ->and($source)->toContain("EnvironmentVariables['STAGING_PREFLIGHT_HTTP'] = '1'")
        ->and($source)->toContain("EnvironmentVariables['STAGING_RUNTIME_ISOLATION'] = '1'");
});

it('refuses to launch when config cache exists', function () {
    $source = serveStagingLauncherSource();

    expect($source)->toContain('bootstrap\\cache\\config.php')
        ->and($source)->toContain('Test-Path -LiteralPath $configCache')
        ->and($source)->toContain('Refusing to launch: bootstrap/cache/config.php exists')
        ->and($source)->not->toContain('artisan config:cache')
        ->and($source)->not->toContain('artisan config:clear');
});

it('keeps staging:preflight before the web server child process', function () {
    $source = serveStagingLauncherSource();

    expect($source)->toContain("Invoke-StagingPhp -Arguments 'artisan staging:preflight'")
        ->and($source)->toContain('Invoke-StagingPhp -Arguments "-S 127.0.0.1:$Port -t public"')
        ->and(strpos($source, 'artisan staging:preflight'))->toBeLessThan(strpos($source, '-S 127.0.0.1:$Port'));
});

it('does not embed credentials in the launcher', function () {
    $source = serveStagingLauncherSource();

    expect($source)->not->toMatch('/DB_PASSWORD\s*=/')
        ->and($source)->not->toMatch('/password\s*=\s*[\'"][^\'"]+[\'"]/i')
        ->and($source)->not->toContain('EnvironmentVariables[\'DB_USERNAME\']');
});

it('applies the same child environment helper to preflight and server', function () {
    $source = serveStagingLauncherSource();

    expect($source)->toContain('function Set-StagingChildEnvironment')
        ->and($source)->toContain('Set-StagingChildEnvironment -Info $info')
        ->and(substr_count($source, 'Set-StagingChildEnvironment'))->toBeGreaterThanOrEqual(2);
});
