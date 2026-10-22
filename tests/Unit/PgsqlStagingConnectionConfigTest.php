<?php

use App\CalibrationSessions\CalibrationSessionConnection;
use App\Database\DestructiveCommandGuard;
use Illuminate\Support\ConfigurationUrlParser;
use Tests\TestCase;

uses(TestCase::class);

function pgsqlStagingConfigFileSource(): string
{
    $source = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'database.php');

    expect($source)->toBeString();

    return $source;
}

function pgsqlStagingConfigBlock(): string
{
    $source = pgsqlStagingConfigFileSource();

    expect($source)->toMatch("/'pgsql_staging'\s*=>\s*\[/");

    preg_match("/'pgsql_staging'\s*=>\s*\[(.*?)\\n        \],/s", $source, $matches);

    expect($matches[1] ?? null)->toBeString();

    return $matches[1];
}

it('declares pgsql_staging with a literal staging database in the config file', function () {
    $block = pgsqlStagingConfigBlock();

    expect($block)->toContain("'driver' => 'pgsql'")
        ->and($block)->toContain("'database' => 'calibration_staging_db'")
        ->and($block)->not->toContain("env('DB_DATABASE'")
        ->and($block)->not->toContain("env('DB_URL'")
        ->and($block)->not->toContain("env('DATABASE_URL'");
});

it('declares a null url and no read or write overrides on pgsql_staging', function () {
    $block = pgsqlStagingConfigBlock();

    expect($block)->toContain("'url' => null")
        ->and($block)->not->toContain("'read'")
        ->and($block)->not->toContain("'write'");
});

it('does not change the default or pgsql connection declarations', function () {
    $source = pgsqlStagingConfigFileSource();

    expect($source)->toContain("'default' => env('DB_CONNECTION', 'sqlite')")
        ->and($source)->toMatch("/'pgsql'\s*=>\s*\[[\s\S]*?'database'\s*=>\s*env\('DB_DATABASE', 'laravel'\)/");
});

it('exposes pgsql_staging as literal staging even when phpunit forces sqlite memory', function () {
    $staging = config('database.connections.pgsql_staging');
    $default = config('database.connections.'.config('database.default'));

    expect(config('database.default'))->toBe('sqlite')
        ->and(is_array($staging))->toBeTrue()
        ->and($staging['driver'])->toBe('pgsql')
        ->and($staging['database'])->toBe(CalibrationSessionConnection::STAGING_DATABASE)
        ->and($staging['url'])->toBeNull()
        ->and(array_key_exists('read', $staging))->toBeFalse()
        ->and(array_key_exists('write', $staging))->toBeFalse()
        ->and(DestructiveCommandGuard::databaseNameFromConnection($staging))->toBe('calibration_staging_db')
        ->and(DestructiveCommandGuard::connectionIsSqliteMemory($default))->toBeTrue();
});

it('keeps the existing pgsql connection on the phpunit sqlite memory database name', function () {
    $pgsql = config('database.connections.pgsql');

    expect($pgsql['driver'])->toBe('pgsql')
        ->and($pgsql['database'])->toBe(':memory:')
        ->and($pgsql['url'])->toBe('')
        ->and($pgsql['database'])->not->toBe('calibration_staging_db');
});

it('does not let a configuration url override the literal staging database name', function () {
    $staging = config('database.connections.pgsql_staging');
    $parsed = (new ConfigurationUrlParser)->parseConfiguration($staging);

    expect($staging['url'])->toBeNull()
        ->and($parsed['database'])->toBe('calibration_staging_db')
        ->and(array_key_exists('url', $parsed))->toBeFalse();
});
