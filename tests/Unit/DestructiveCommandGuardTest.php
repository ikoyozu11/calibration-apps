<?php

use App\Database\DestructiveCommandGuard;
use Illuminate\Config\Repository;

it('allows sqlite memory even when the application is marked production', function () {
    expect(DestructiveCommandGuard::shouldProhibit('sqlite', ':memory:', true))->toBeFalse();
});

it('prohibits the production database name regardless of app environment', function () {
    expect(DestructiveCommandGuard::shouldProhibit('pgsql', 'calibration_db', false))->toBeTrue();
});

it('prohibits the staging database name regardless of app environment', function () {
    expect(DestructiveCommandGuard::shouldProhibit('pgsql', 'calibration_staging_db', false))->toBeTrue();
});

it('prohibits an undetermined postgresql database name', function () {
    expect(DestructiveCommandGuard::shouldProhibit('pgsql', '', false))->toBeTrue()
        ->and(DestructiveCommandGuard::shouldProhibit('pgsql', null, false))->toBeTrue()
        ->and(DestructiveCommandGuard::shouldProhibit(null, null, false))->toBeTrue();
});

it('does not prohibit an unrelated local database name when not production', function () {
    expect(DestructiveCommandGuard::shouldProhibit('pgsql', 'other_local_db', false))->toBeFalse();
});

it('prohibits when a non-default connection names a protected database', function () {
    $config = new Repository([
        'database' => [
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                    'url' => '',
                ],
                'pgsql' => [
                    'driver' => 'pgsql',
                    'database' => 'calibration_staging_db',
                    'url' => '',
                ],
            ],
        ],
    ]);

    expect(DestructiveCommandGuard::shouldProhibitConfiguredConnections($config, false))->toBeTrue();
});

it('prohibits a protected database name supplied only in a connection url', function () {
    $config = new Repository([
        'database' => [
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                ],
                'pgsql' => [
                    'driver' => 'pgsql',
                    'database' => null,
                    'url' => 'pgsql://review:review@127.0.0.1:5432/calibration_db',
                ],
            ],
        ],
    ]);

    expect(DestructiveCommandGuard::shouldProhibitConfiguredConnections($config, false))->toBeTrue()
        ->and(DestructiveCommandGuard::databaseNameFromUrl(
            'pgsql://review:review@127.0.0.1:5432/calibration_db',
        ))->toBe('calibration_db');
});

it('allows configured connections when only sqlite memory is present', function () {
    $config = new Repository([
        'database' => [
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                    'url' => '',
                ],
            ],
        ],
    ]);

    expect(DestructiveCommandGuard::shouldProhibitConfiguredConnections($config, false))->toBeFalse();
});

it('prohibits when configured connections cannot be read', function () {
    expect(DestructiveCommandGuard::shouldProhibitConfiguredConnections(new Repository([
        'database' => ['connections' => []],
    ]), false))->toBeTrue();
});

it('still prohibits a named staging connection when not testing', function () {
    expect(DestructiveCommandGuard::shouldProhibitConfiguredConnections(pgsqlStagingBesideSqliteMemoryConfig(), false))->toBeTrue();
});

it('allows sqlite memory refresh during testing when a named staging connection is configured', function () {
    expect(DestructiveCommandGuard::shouldProhibitConfiguredConnections(
        pgsqlStagingBesideSqliteMemoryConfig(),
        false,
        true,
    ))->toBeFalse();
});

it('still prohibits during testing when the default connection is not sqlite memory', function () {
    $config = new Repository([
        'database' => [
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => [
                    'driver' => 'pgsql',
                    'database' => 'other_local_db',
                    'url' => '',
                ],
                'pgsql_staging' => [
                    'driver' => 'pgsql',
                    'database' => 'calibration_staging_db',
                    'url' => null,
                ],
            ],
        ],
    ]);

    expect(DestructiveCommandGuard::shouldProhibitConfiguredConnections($config, false, true))->toBeTrue();
});

function pgsqlStagingBesideSqliteMemoryConfig(): Repository
{
    return new Repository([
        'database' => [
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                    'url' => '',
                ],
                'pgsql_staging' => [
                    'driver' => 'pgsql',
                    'database' => 'calibration_staging_db',
                    'url' => null,
                ],
            ],
        ],
    ]);
}
