<?php

use App\Models\Calibration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestDatabaseGuard;
use Tests\TestRefusingPostgresConnector;
use Tests\TestSafeConnectionFactory;
use Tests\TestSafeDatabaseManager;
use Tests\UnsafeTestDatabaseException;

function safetyRegisterNamedSqlite(string $name): void
{
    config([
        'database.connections.'.$name => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge($name);
}

function safetyAssertPostgresUnreachable(): void
{
    expect(app('db'))->toBeInstanceOf(TestSafeDatabaseManager::class);
    expect(app('db.factory'))->toBeInstanceOf(TestSafeConnectionFactory::class);
    expect(app('db.connector.pgsql'))->toBeInstanceOf(TestRefusingPostgresConnector::class);
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
    expect(TestSafeConnectionFactory::$refusedMakes)->toBe([]);
}

it('keeps the default sqlite memory connection usable', function () {
    $connection = DB::connection();

    expect($connection->getName())->toBe('sqlite')
        ->and($connection->getDriverName())->toBe('sqlite')
        ->and($connection->getDatabaseName())->toBe(':memory:');
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('allows a named sqlite memory connection', function () {
    safetyRegisterNamedSqlite('session_sqlite');

    $connection = DB::connection('session_sqlite');

    expect($connection->getDriverName())->toBe('sqlite')
        ->and($connection->getDatabaseName())->toBe(':memory:');
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('refuses pgsql_staging before a postgresql connector is created', function () {
    safetyAssertPostgresUnreachable();

    expect(fn () => DB::connection('pgsql_staging'))
        ->toThrow(UnsafeTestDatabaseException::class, 'Refusing PostgreSQL connection [pgsql_staging] during tests.');

    expect(TestSafeConnectionFactory::$refusedMakes)->toBe([]);
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('refuses the pgsql connection before a postgresql connector is created', function () {
    expect(fn () => DB::connection('pgsql'))
        ->toThrow(UnsafeTestDatabaseException::class, 'Refusing PostgreSQL connection [pgsql] during tests.');

    expect(TestSafeConnectionFactory::$refusedMakes)->toBe([]);
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('refuses a url that would retarget sqlite memory to production', function () {
    config([
        'database.connections.sneaky_url' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'url' => 'pgsql://review:review@127.0.0.1:5432/calibration_db',
        ],
    ]);

    expect(fn () => DB::connection('sneaky_url'))
        ->toThrow(UnsafeTestDatabaseException::class);

    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
    expect(TestSafeConnectionFactory::$refusedMakes)->toBe([]);
});

it('refuses a postgresql read override on a sqlite connection', function () {
    config([
        'database.connections.split_rw' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'url' => null,
            'read' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'calibration_staging_db',
            ],
            'write' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
        ],
    ]);

    expect(fn () => DB::connection('split_rw'))
        ->toThrow(UnsafeTestDatabaseException::class, 'Refusing PostgreSQL driver [split_rw.read] during tests.');

    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('refuses a post-bootstrap default retarget to pgsql_staging', function () {
    expect(fn () => DB::setDefaultConnection('pgsql_staging'))
        ->toThrow(UnsafeTestDatabaseException::class, 'Refusing PostgreSQL connection [pgsql_staging] during tests.');
    expect(config('database.default'))->toBe('sqlite');

    $previous = config('database.default');
    config(['database.default' => 'pgsql_staging']);

    try {
        expect(fn () => DB::connection())
            ->toThrow(UnsafeTestDatabaseException::class, 'Refusing PostgreSQL connection [pgsql_staging] during tests.');
        expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
    } finally {
        config(['database.default' => $previous]);
    }

    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
});

it('refuses an explicit postgresql transaction setup', function () {
    expect(fn () => DB::connection('pgsql_staging')->beginTransaction())
        ->toThrow(UnsafeTestDatabaseException::class);

    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('refuses schema and eloquent entry points for pgsql_staging', function () {
    expect(fn () => Schema::connection('pgsql_staging')->hasTable('calibration'))
        ->toThrow(UnsafeTestDatabaseException::class);
    expect(fn () => Calibration::on('pgsql_staging')->whereKey(1)->first())
        ->toThrow(UnsafeTestDatabaseException::class);
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('refuses reconnect, usingConnection, and connectUsing for postgresql targets', function () {
    expect(fn () => DB::reconnect('pgsql_staging'))
        ->toThrow(UnsafeTestDatabaseException::class);
    expect(fn () => DB::usingConnection('pgsql_staging', fn () => true))
        ->toThrow(UnsafeTestDatabaseException::class);
    expect(fn () => DB::connectUsing('forced_pgsql', [
        'driver' => 'pgsql',
        'database' => 'calibration_staging_db',
        'host' => '127.0.0.1',
    ]))->toThrow(UnsafeTestDatabaseException::class);
    expect(fn () => DB::build([
        'driver' => 'pgsql',
        'database' => 'calibration_db',
        'host' => '127.0.0.1',
    ]))->toThrow(UnsafeTestDatabaseException::class);

    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
    expect(TestSafeConnectionFactory::$refusedMakes)->toBe([]);
});

it('still reconnects a named sqlite memory connection after purge', function () {
    safetyRegisterNamedSqlite('session_sqlite');
    DB::connection('session_sqlite');
    DB::purge('session_sqlite');

    $connection = DB::reconnect('session_sqlite');

    expect($connection->getDriverName())->toBe('sqlite')
        ->and($connection->getDatabaseName())->toBe(':memory:');
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('refuses pgsql_staging after purge so a cached connection cannot bypass the guard', function () {
    DB::purge('pgsql_staging');

    expect(fn () => DB::connection('pgsql_staging'))
        ->toThrow(UnsafeTestDatabaseException::class, 'Refusing PostgreSQL connection [pgsql_staging] during tests.');
    expect(fn () => DB::reconnect('pgsql_staging'))
        ->toThrow(UnsafeTestDatabaseException::class);
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
    expect(TestSafeConnectionFactory::$refusedMakes)->toBe([]);
});

it('allows sqlite refresh database through the command guard', function () {
    expect(Schema::hasTable('migrations'))->toBeTrue();
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');

    $this->artisan('migrate')->run();

    expect(array_column(TestDatabaseGuard::$inspectedCommands, 'command'))->toContain('migrate');
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('binds a refusing postgresql connector that never opens PDO', function () {
    expect(fn () => app('db.connector.pgsql')->connect(['driver' => 'pgsql']))
        ->toThrow(UnsafeTestDatabaseException::class, 'PostgreSQL PDO connector must not be used during automated tests.');

    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(1);
});

it('refuses unsafe migrate:fresh before the postgresql connector is reached', function () {
    safetyAssertPostgresUnreachable();

    expect(fn () => $this->artisan('migrate:fresh', ['--database' => 'pgsql_staging'])->run())
        ->toThrow(UnsafeTestDatabaseException::class, 'Refusing PostgreSQL connection [pgsql_staging] during tests.');

    expect(array_column(TestDatabaseGuard::$inspectedCommands, 'command'))->toContain('migrate:fresh');
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
    expect(TestSafeConnectionFactory::$refusedMakes)->toBe([]);
});

it('refuses unsafe db:wipe before the postgresql connector is reached', function () {
    safetyAssertPostgresUnreachable();

    expect(fn () => $this->artisan('db:wipe', ['--database' => 'pgsql_staging'])->run())
        ->toThrow(UnsafeTestDatabaseException::class);

    expect(array_column(TestDatabaseGuard::$inspectedCommands, 'command'))->toContain('db:wipe');
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('refuses other destructive artisan commands that name pgsql_staging', function (string $command) {
    safetyAssertPostgresUnreachable();

    expect(fn () => $this->artisan($command, ['--database' => 'pgsql_staging'])->run())
        ->toThrow(UnsafeTestDatabaseException::class);

    expect(array_column(TestDatabaseGuard::$inspectedCommands, 'command'))->toContain($command);
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'migrate:refresh',
    'migrate:reset',
    'migrate:rollback',
]);
