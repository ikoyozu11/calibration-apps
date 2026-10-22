<?php

use Illuminate\Config\Repository;
use Symfony\Component\Console\Input\ArrayInput;
use Tests\TestDatabaseGuard;
use Tests\UnsafeTestDatabaseException;

it('accepts sqlite memory with an empty url in the process environment', function () {
    expect(TestDatabaseGuard::environmentViolations('sqlite', ':memory:', ''))->toBe([])
        ->and(TestDatabaseGuard::environmentViolations('sqlite', ':memory:', false))->toBe([])
        ->and(TestDatabaseGuard::environmentViolations('sqlite', ':memory:', null))->toBe([]);
});

it('rejects a postgresql process environment without reading a database', function () {
    $violations = TestDatabaseGuard::environmentViolations(
        'pgsql',
        'must_not_connect_db',
        'pgsql://invalid:invalid@127.0.0.1:1/must_not_connect_db',
    );

    expect($violations)->toContain('DB_CONNECTION must be sqlite, got pgsql')
        ->and($violations)->toContain('DB_DATABASE must be :memory:, got must_not_connect_db')
        ->and($violations)->toContain('DB_URL must be empty')
        ->and(implode("\n", $violations))->not->toContain('invalid:invalid');
});

it('accepts sqlite memory with an empty url in laravel config', function () {
    expect(TestDatabaseGuard::configViolations(sqliteMemoryConfig()))->toBe([]);
});

it('rejects a postgresql laravel config without opening a connection', function () {
    $config = new Repository([
        'database' => [
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => [
                    'driver' => 'pgsql',
                    'url' => 'pgsql://invalid:invalid@127.0.0.1:1/must_not_connect_db',
                    'database' => 'must_not_connect_db',
                ],
            ],
        ],
    ]);

    expect(fn () => TestDatabaseGuard::ensure($config))
        ->toThrow(function (UnsafeTestDatabaseException $exception): void {
            expect($exception->getMessage())->toContain('database.default must be sqlite, got pgsql')
                ->and($exception->getMessage())->toContain('default connection driver must be sqlite, got pgsql')
                ->and($exception->getMessage())->toContain('SQLite database must be :memory:, got must_not_connect_db')
                ->and($exception->getMessage())->toContain('database connection URL must be empty')
                ->and($exception->getMessage())->toContain('before RefreshDatabase or migrations can run')
                ->and($exception->getMessage())->not->toContain('invalid:invalid');
        });
});

it('rejects a sqlite file database and a connection url', function () {
    $config = sqliteMemoryConfig();
    $config->set('database.connections.sqlite.database', 'database/testing.sqlite');
    $config->set('database.connections.sqlite.url', 'sqlite://unused');

    $violations = TestDatabaseGuard::configViolations($config);

    expect($violations)->toHaveCount(2)
        ->and($violations[0])->toContain('SQLite database must be :memory:')
        ->and($violations[1])->toBe('database connection URL must be empty');
});

it('aborts before a database-mutating setup step when config is unsafe', function () {
    $log = [];

    $setup = function () use (&$log): void {
        $log[] = 'createApplication';
        TestDatabaseGuard::ensure(new Repository([
            'database' => [
                'default' => 'pgsql',
                'connections' => [
                    'pgsql' => [
                        'driver' => 'pgsql',
                        'database' => 'must_not_connect_db',
                        'url' => '',
                    ],
                ],
            ],
        ]));
        $log[] = 'refreshDatabase';
    };

    expect($setup)->toThrow(UnsafeTestDatabaseException::class)
        ->and($log)->toBe(['createApplication']);
});

function sqliteMemoryConfig(): Repository
{
    return new Repository([
        'database' => [
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => [
                    'driver' => 'sqlite',
                    'url' => '',
                    'database' => ':memory:',
                ],
            ],
        ],
    ]);
}

it('refuses pgsql_staging metadata before any connector is involved', function () {
    $violations = TestDatabaseGuard::connectionSafetyViolations([
        'driver' => 'pgsql',
        'database' => 'calibration_staging_db',
        'url' => null,
    ], 'pgsql_staging');

    expect($violations)->toContain('Refusing PostgreSQL connection [pgsql_staging] during tests.')
        ->and($violations)->toContain('Refusing PostgreSQL driver [pgsql_staging] during tests.')
        ->and($violations)->toContain('Refusing protected database [calibration_staging_db] during tests.');
});

it('refuses a postgresql url that would override a sqlite memory database', function () {
    $violations = TestDatabaseGuard::connectionSafetyViolations([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'url' => 'pgsql://review:review@127.0.0.1:5432/calibration_db',
    ], 'sneaky');

    expect($violations)->toContain('Refusing protected database [calibration_db] supplied by a connection URL.')
        ->and($violations)->toContain('Refusing PostgreSQL driver [sneaky] during tests.')
        ->and(implode("\n", $violations))->not->toContain('review:review');
});

it('refuses a postgresql read override on an otherwise sqlite connection', function () {
    $violations = TestDatabaseGuard::connectionSafetyViolations([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'url' => null,
        'read' => [
            'driver' => 'pgsql',
            'database' => 'calibration_staging_db',
            'host' => '127.0.0.1',
        ],
    ], 'split');

    expect($violations)->toContain('Refusing PostgreSQL driver [split.read] during tests.')
        ->and($violations)->toContain('Refusing protected database [calibration_staging_db] during tests.');
});

it('allows named sqlite memory connection metadata', function () {
    expect(TestDatabaseGuard::connectionSafetyViolations([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'url' => null,
    ], 'session_sqlite'))->toBe([]);
});

it('refuses sqlite file database metadata', function () {
    $violations = TestDatabaseGuard::connectionSafetyViolations([
        'driver' => 'sqlite',
        'database' => 'database/testing.sqlite',
        'url' => '',
    ], 'sqlite_file');

    expect($violations)->toContain('SQLite tests must use :memory:, got database/testing.sqlite');
});

it('refuses an artisan wipe targeting pgsql_staging without reading a database', function () {
    $input = new ArrayInput([
        '--database' => 'pgsql_staging',
    ]);

    expect(fn () => TestDatabaseGuard::assertCommandMayRun(
        sqliteMemoryConfig(),
        'migrate:fresh',
        $input,
    ))->toThrow(UnsafeTestDatabaseException::class, 'Refusing PostgreSQL connection [pgsql_staging] during tests.');
});

it('allows migrate:fresh against the sqlite memory default', function () {
    TestDatabaseGuard::assertCommandMayRun(
        sqliteMemoryConfig(),
        'migrate:fresh',
        new ArrayInput([]),
    );

    expect(true)->toBeTrue();
});
