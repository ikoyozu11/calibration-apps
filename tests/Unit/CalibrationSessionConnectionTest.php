<?php

use App\CalibrationSessions\CalibrationSessionConnection;
use App\CalibrationSessions\CalibrationSessionConnectionException;
use Illuminate\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

uses(TestCase::class);

function sessionConnectionGuard(DatabaseManager $db, array $connections, string $default = 'sqlite'): CalibrationSessionConnection
{
    return new CalibrationSessionConnection($db, new Repository([
        'database' => [
            'default' => $default,
            'connections' => $connections,
        ],
    ]));
}

function sessionGuardSqliteMemoryConfig(): array
{
    return [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'url' => '',
    ];
}

it('allows an explicit sqlite memory connection without querying', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('sqlite');
    $connection->shouldReceive('getName')->andReturn('session_sqlite');
    $connection->shouldNotReceive('scalar');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'session_sqlite' => sessionGuardSqliteMemoryConfig(),
    ]);

    expect($guard->resolve($connection))->toBe($connection);
});

it('allows a sqlite memory connection whose name is absent from config', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('sqlite');
    $connection->shouldReceive('getName')->andReturn('unnamed_memory');
    $connection->shouldReceive('getDatabaseName')->andReturn(':memory:');
    $connection->shouldNotReceive('scalar');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    expect(sessionConnectionGuard($db, [])->resolve($connection))->toBe($connection);
});

it('opens the default sqlite memory connection after inspecting config', function () {
    $opened = Mockery::mock(Connection::class);
    $opened->shouldReceive('getDriverName')->andReturn('sqlite');
    $opened->shouldReceive('getName')->andReturn('sqlite');
    $opened->shouldNotReceive('scalar');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldReceive('connection')->once()->withNoArgs()->andReturn($opened);

    $guard = sessionConnectionGuard($db, [
        'sqlite' => sessionGuardSqliteMemoryConfig(),
    ]);

    expect($guard->resolve(null))->toBe($opened);
});

it('refuses the default postgresql connection without opening it', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql' => [
            'driver' => 'pgsql',
            'database' => 'calibration_staging_db',
            'url' => '',
        ],
    ], 'pgsql');

    expect(fn () => $guard->resolve(null))
        ->toThrow(CalibrationSessionConnectionException::class, 'Refusing to use the default PostgreSQL connection for a calibration session workflow.');
});

it('refuses a production database name from config before any sql', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('getName')->andReturn('pgsql_prod');
    $connection->shouldNotReceive('getDatabaseName');
    $connection->shouldNotReceive('scalar');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql_prod' => [
            'driver' => 'pgsql',
            'database' => 'calibration_db',
            'url' => '',
        ],
    ]);

    expect(fn () => $guard->resolve($connection))
        ->toThrow(CalibrationSessionConnectionException::class, 'Refusing to use the production database for a calibration session workflow.');
});

it('refuses a production database name supplied only in a connection url before any sql', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('getName')->andReturn('pgsql_url');
    $connection->shouldNotReceive('scalar');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql_url' => [
            'driver' => 'pgsql',
            'database' => null,
            'url' => 'pgsql://review:review@127.0.0.1:5432/calibration_db',
        ],
    ]);

    expect(fn () => $guard->resolve($connection))
        ->toThrow(CalibrationSessionConnectionException::class, 'Refusing to use the production database for a calibration session workflow.');
});

it('refuses production on an unconfigured connection name without sql', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('getName')->andReturn('guarded_pgsql');
    $connection->shouldReceive('getDatabaseName')->andReturn('calibration_db');
    $connection->shouldNotReceive('scalar');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    expect(fn () => sessionConnectionGuard($db, [])->resolve($connection))
        ->toThrow(CalibrationSessionConnectionException::class, 'Refusing to use the production database for a calibration session workflow.');
});

it('does not open the default connection when its configured name is production', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql' => [
            'driver' => 'pgsql',
            'database' => 'calibration_db',
            'url' => '',
        ],
    ], 'pgsql');

    expect(fn () => $guard->resolve(null))
        ->toThrow(CalibrationSessionConnectionException::class, 'Refusing to use the production database for a calibration session workflow.');
});

it('refuses a missing default connection name as ambiguous', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    expect(fn () => sessionConnectionGuard($db, [], '')->resolve(null))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');
});

it('refuses a mysql connection as ambiguous without querying', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldNotReceive('scalar');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    expect(fn () => sessionConnectionGuard($db, [])->resolve($connection))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');
});

it('refuses a sqlite file connection as ambiguous', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('sqlite');
    $connection->shouldReceive('getName')->andReturn('sqlite_file');
    $connection->shouldReceive('getDatabaseName')->andReturn('/tmp/calibration.sqlite');
    $connection->shouldNotReceive('scalar');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'sqlite_file' => [
            'driver' => 'sqlite',
            'database' => '/tmp/calibration.sqlite',
        ],
    ]);

    expect(fn () => $guard->resolve($connection))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');
});

it('refuses a postgresql connection that is not staging before any sql', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('getName')->andReturn('pgsql_other');
    $connection->shouldNotReceive('scalar');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql_other' => [
            'driver' => 'pgsql',
            'database' => 'other_local_db',
            'url' => '',
        ],
    ]);

    expect(fn () => $guard->resolve($connection))
        ->toThrow(CalibrationSessionConnectionException::class, 'PostgreSQL calibration session workflow requires calibration_staging_db.');
});

it('allows an explicit staging connection after a matching current_database check', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('getName')->andReturn('pgsql_staging');
    $connection->shouldReceive('scalar')
        ->once()
        ->with('select current_database()')
        ->andReturn('calibration_staging_db');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql_staging' => [
            'driver' => 'pgsql',
            'database' => 'calibration_staging_db',
            'url' => '',
        ],
    ]);

    expect($guard->resolve($connection))->toBe($connection);
});

it('refuses a staging-configured connection whose live identity is production', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('getName')->andReturn('pgsql_staging');
    $connection->shouldReceive('scalar')
        ->once()
        ->with('select current_database()')
        ->andReturn('calibration_db');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql_staging' => [
            'driver' => 'pgsql',
            'database' => 'calibration_staging_db',
            'url' => '',
        ],
    ]);

    $exception = null;

    try {
        $guard->resolve($connection);
    } catch (CalibrationSessionConnectionException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(CalibrationSessionConnectionException::class)
        ->and($exception?->isProduction())->toBeTrue()
        ->and($exception?->getMessage())->toBe('Refusing to use the production database for a calibration session workflow.');
});

it('refuses a staging-configured connection whose live identity does not match', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('getName')->andReturn('pgsql_staging');
    $connection->shouldReceive('scalar')
        ->once()
        ->with('select current_database()')
        ->andReturn('unexpected_db');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql_staging' => [
            'driver' => 'pgsql',
            'database' => 'calibration_staging_db',
            'url' => '',
        ],
    ]);

    expect(fn () => $guard->resolve($connection))
        ->toThrow(CalibrationSessionConnectionException::class, 'PostgreSQL connection identity does not match calibration_staging_db.');
});

it('refuses the default postgresql connection even when pgsql_staging is configured', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql' => [
            'driver' => 'pgsql',
            'database' => 'calibration_db',
            'url' => '',
        ],
        'pgsql_staging' => [
            'driver' => 'pgsql',
            'database' => 'calibration_staging_db',
            'url' => null,
        ],
    ], 'pgsql');

    expect(fn () => $guard->resolve(null))
        ->toThrow(CalibrationSessionConnectionException::class, 'Refusing to use the production database for a calibration session workflow.');
});

it('refuses the default postgresql connection even when it names staging and pgsql_staging exists', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql' => [
            'driver' => 'pgsql',
            'database' => 'calibration_staging_db',
            'url' => '',
        ],
        'pgsql_staging' => [
            'driver' => 'pgsql',
            'database' => 'calibration_staging_db',
            'url' => null,
        ],
    ], 'pgsql');

    expect(fn () => $guard->resolve(null))
        ->toThrow(CalibrationSessionConnectionException::class, 'Refusing to use the default PostgreSQL connection for a calibration session workflow.');
});

it('refuses a pgsql_staging connection whose configured database is not staging before any sql', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('getName')->andReturn('pgsql_staging');
    $connection->shouldNotReceive('scalar');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql_staging' => [
            'driver' => 'pgsql',
            'database' => 'other_local_db',
            'url' => null,
        ],
    ]);

    expect(fn () => $guard->resolve($connection))
        ->toThrow(CalibrationSessionConnectionException::class, 'PostgreSQL calibration session workflow requires calibration_staging_db.');
});

it('treats an explicit pgsql_staging configuration as staging before opening a default connection', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('getName')->andReturn('pgsql_staging');
    $connection->shouldReceive('scalar')
        ->once()
        ->with('select current_database()')
        ->andReturn('calibration_staging_db');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql' => [
            'driver' => 'pgsql',
            'database' => 'calibration_db',
            'url' => '',
        ],
        'pgsql_staging' => [
            'driver' => 'pgsql',
            'database' => 'calibration_staging_db',
            'url' => null,
        ],
    ], 'pgsql');

    expect($guard->resolve($connection))->toBe($connection);
});

function sessionGuardStagingConfig(array $overrides = []): array
{
    return array_merge([
        'driver' => 'pgsql',
        'database' => 'calibration_staging_db',
        'url' => null,
    ], $overrides);
}

it('resolveNamed allows a named sqlite memory connection after config validation', function () {
    $opened = Mockery::mock(Connection::class);
    $opened->shouldReceive('getDriverName')->andReturn('sqlite');
    $opened->shouldReceive('getName')->andReturn('session_sqlite');
    $opened->shouldNotReceive('scalar');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldReceive('connection')->once()->with('session_sqlite')->andReturn($opened);

    $guard = sessionConnectionGuard($db, [
        'session_sqlite' => sessionGuardSqliteMemoryConfig(),
    ]);

    expect($guard->resolveNamed('session_sqlite'))->toBe($opened);
});

it('resolveNamed refuses an unknown connection name without opening anything', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    expect(fn () => sessionConnectionGuard($db, [
        'sqlite' => sessionGuardSqliteMemoryConfig(),
    ])->resolveNamed('missing_connection'))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');
});

it('resolveNamed refuses the pgsql connection name without opening it', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql' => [
            'driver' => 'pgsql',
            'database' => 'calibration_staging_db',
            'url' => null,
        ],
        'pgsql_staging' => sessionGuardStagingConfig(),
    ]);

    expect(fn () => $guard->resolveNamed('pgsql'))
        ->toThrow(CalibrationSessionConnectionException::class, 'Refusing to use the default PostgreSQL connection for a calibration session workflow.');
});

it('resolveNamed refuses a production database name before opening a connection', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql_staging' => sessionGuardStagingConfig([
            'database' => 'calibration_db',
        ]),
    ]);

    expect(fn () => $guard->resolveNamed('pgsql_staging'))
        ->toThrow(CalibrationSessionConnectionException::class, 'Refusing to use the production database for a calibration session workflow.');
});

it('resolveNamed refuses a wrong staging database before opening a connection', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql_staging' => sessionGuardStagingConfig([
            'database' => 'other_local_db',
        ]),
    ]);

    expect(fn () => $guard->resolveNamed('pgsql_staging'))
        ->toThrow(CalibrationSessionConnectionException::class, 'PostgreSQL calibration session workflow requires calibration_staging_db.');
});

it('resolveNamed refuses an unsafe url override before opening a connection', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql_staging' => sessionGuardStagingConfig([
            'url' => 'pgsql://review:review@127.0.0.1:5432/calibration_db',
        ]),
    ]);

    expect(fn () => $guard->resolveNamed('pgsql_staging'))
        ->toThrow(CalibrationSessionConnectionException::class, 'Refusing to use the production database for a calibration session workflow.');
});

it('resolveNamed refuses read and write overrides before opening a connection', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql_staging' => sessionGuardStagingConfig([
            'read' => [
                'host' => '127.0.0.1',
                'database' => 'calibration_staging_db',
            ],
            'write' => [
                'host' => '127.0.0.1',
                'database' => 'calibration_staging_db',
            ],
        ]),
    ]);

    expect(fn () => $guard->resolveNamed('pgsql_staging'))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');
});

it('resolveNamed refuses an unsupported driver before opening a connection', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'session_mysql' => [
            'driver' => 'mysql',
            'database' => 'calibration_staging_db',
            'url' => null,
        ],
    ]);

    expect(fn () => $guard->resolveNamed('session_mysql'))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');
});

it('resolveNamed refuses incorrect live identity using a mocked connection only', function () {
    $opened = Mockery::mock(Connection::class);
    $opened->shouldReceive('getDriverName')->andReturn('pgsql');
    $opened->shouldReceive('getName')->andReturn('pgsql_staging');
    $opened->shouldReceive('scalar')
        ->once()
        ->with('select current_database()')
        ->andReturn('unexpected_db');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldReceive('connection')->once()->with('pgsql_staging')->andReturn($opened);

    $guard = sessionConnectionGuard($db, [
        'pgsql_staging' => sessionGuardStagingConfig(),
    ]);

    expect(fn () => $guard->resolveNamed('pgsql_staging'))
        ->toThrow(CalibrationSessionConnectionException::class, 'PostgreSQL connection identity does not match calibration_staging_db.');
});

it('resolveNamed opens pgsql_staging only after valid staging metadata passes', function () {
    $opened = Mockery::mock(Connection::class);
    $opened->shouldReceive('getDriverName')->andReturn('pgsql');
    $opened->shouldReceive('getName')->andReturn('pgsql_staging');
    $opened->shouldReceive('scalar')
        ->once()
        ->with('select current_database()')
        ->andReturn('calibration_staging_db');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldReceive('connection')->once()->with('pgsql_staging')->andReturn($opened);

    $guard = sessionConnectionGuard($db, [
        'pgsql' => [
            'driver' => 'pgsql',
            'database' => 'calibration_db',
            'url' => '',
        ],
        'pgsql_staging' => sessionGuardStagingConfig(),
    ], 'pgsql');

    expect($guard->resolveNamed('pgsql_staging'))->toBe($opened);
});

it('resolveNamed never falls back to the default connection', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'sqlite' => sessionGuardSqliteMemoryConfig(),
        'pgsql_staging' => sessionGuardStagingConfig(),
    ]);

    expect(fn () => $guard->resolveNamed('not_configured_name'))
        ->toThrow(CalibrationSessionConnectionException::class);

    expect(fn () => $guard->resolveNamed(''))
        ->toThrow(CalibrationSessionConnectionException::class);
});

it('resolveNamed refuses a sqlite file database before opening a connection', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'sqlite_file' => [
            'driver' => 'sqlite',
            'database' => 'database/testing.sqlite',
            'url' => '',
        ],
    ]);

    expect(fn () => $guard->resolveNamed('sqlite_file'))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');
});

it('resolveNamed refuses a non-allowlisted postgresql name before opening a connection', function () {
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldNotReceive('connection');

    $guard = sessionConnectionGuard($db, [
        'pgsql_other' => [
            'driver' => 'pgsql',
            'database' => 'calibration_staging_db',
            'url' => null,
        ],
    ]);

    expect(fn () => $guard->resolveNamed('pgsql_other'))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');
});
