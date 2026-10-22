<?php

use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\ResolveDomainActor;
use App\CalibrationSessions\ResolveDomainActorException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use Tests\TestRefusingPostgresConnector;

const DOMAIN_ACTOR_CONNECTION = 'domain_actor_sqlite';

function domainActorConnection(string $fixture = 'standard'): Connection
{
    config([
        'database.connections.'.DOMAIN_ACTOR_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(DOMAIN_ACTOR_CONNECTION);

    $connection = DB::connection(DOMAIN_ACTOR_CONNECTION);
    $constraints = [
        'CONSTRAINT uq_user_app_user_map_app_user UNIQUE (app_user_id)',
        'CONSTRAINT fk_user_app_user_map_user FOREIGN KEY (laravel_user_id) REFERENCES users (id) ON DELETE RESTRICT',
        'CONSTRAINT fk_user_app_user_map_app_user FOREIGN KEY (app_user_id) REFERENCES app_user (app_user_id) ON DELETE RESTRICT',
        "CONSTRAINT chk_user_app_user_map_status CHECK (status IN ('active', 'inactive'))",
    ];

    if ($fixture !== 'duplicate-laravel-user') {
        array_unshift($constraints, 'CONSTRAINT uq_user_app_user_map_laravel_user UNIQUE (laravel_user_id)');
    }

    if ($fixture !== 'unchecked-inactivation') {
        $constraints[] = "CONSTRAINT chk_user_app_user_map_inactivated_at CHECK ((status = 'active' AND inactivated_at IS NULL) OR (status = 'inactive' AND inactivated_at IS NOT NULL))";
    }

    $constraintSql = implode(",\n", $constraints);

    $connection->unprepared('
        CREATE TABLE users (
            id INTEGER PRIMARY KEY
        )
    ');
    $isActiveColumn = $fixture === 'text-active' ? 'is_active TEXT NOT NULL' : 'is_active INTEGER NOT NULL';

    $connection->unprepared("
        CREATE TABLE app_user (
            app_user_id INTEGER PRIMARY KEY,
            {$isActiveColumn}
        )
    ");
    $connection->unprepared("
        CREATE TABLE user_app_user_map (
            user_app_user_map_id INTEGER PRIMARY KEY,
            laravel_user_id INTEGER NOT NULL,
            app_user_id INTEGER NOT NULL,
            status VARCHAR(20) NOT NULL,
            created_at TEXT NOT NULL,
            inactivated_at TEXT NULL,
            {$constraintSql}
        )
    ");

    return $connection;
}

function domainActorResolver(): ResolveDomainActor
{
    return app(ResolveDomainActor::class);
}

/**
 * @param  array{laravel_user_id?: int, app_user_id?: int, status?: string, inactivated_at?: string|null, is_active?: mixed, map_id?: int}  $row
 */
function seedDomainActor(Connection $connection, array $row = []): void
{
    $laravelUserId = $row['laravel_user_id'] ?? 5;
    $appUserId = $row['app_user_id'] ?? 90;

    $connection->table('users')->insert(['id' => $laravelUserId]);
    $connection->table('app_user')->insert([
        'app_user_id' => $appUserId,
        'is_active' => $row['is_active'] ?? 1,
    ]);
    $connection->table('user_app_user_map')->insert([
        'user_app_user_map_id' => $row['map_id'] ?? 1,
        'laravel_user_id' => $laravelUserId,
        'app_user_id' => $appUserId,
        'status' => $row['status'] ?? 'active',
        'created_at' => '2026-10-21 07:00:00',
        'inactivated_at' => $row['inactivated_at'] ?? null,
    ]);
}

function domainActorSql(Connection $connection): string
{
    return implode("\n", array_column($connection->getQueryLog(), 'query'));
}

it('returns the mapped app user id for an active mapping', function () {
    $connection = domainActorConnection();
    seedDomainActor($connection);
    $default = DB::connection();

    $appUserId = $connection->transaction(function () use ($connection, $default): int {
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $default->flushQueryLog();
        $default->enableQueryLog();

        return domainActorResolver()->resolve(5, $connection);
    });

    $sql = strtolower(domainActorSql($connection));
    $mapPosition = strpos($sql, 'from "user_app_user_map"');
    $appUserPosition = strpos($sql, 'from "app_user"');

    expect($appUserId)->toBe(90)
        ->and($mapPosition)->toBeInt()
        ->and($appUserPosition)->toBeInt()
        ->and($mapPosition)->toBeLessThan($appUserPosition)
        ->and($sql)->not->toContain('insert')
        ->and($sql)->not->toContain('update')
        ->and($sql)->not->toContain('delete')
        ->and($connection->table('user_app_user_map')->count())->toBe(1)
        ->and($connection->table('users')->count())->toBe(1)
        ->and($connection->table('app_user')->count())->toBe(1)
        ->and(domainActorSql($default))->not->toContain('user_app_user_map')
        ->and(Schema::connection('sqlite')->hasTable('user_app_user_map'))->toBeFalse()
        ->and(Schema::connection('sqlite')->hasTable('app_user'))->toBeFalse()
        ->and(config('database.default'))->toBe('sqlite')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects a laravel user with no mapping', function () {
    $connection = domainActorConnection();
    $connection->table('users')->insert(['id' => 5]);
    $connection->table('app_user')->insert([
        'app_user_id' => 90,
        'is_active' => 1,
    ]);
    $connection->enableQueryLog();

    expect(fn () => $connection->transaction(
        fn () => domainActorResolver()->resolve(5, $connection),
    ))->toThrow(ResolveDomainActorException::class, 'The Laravel user has no domain actor mapping.');

    expect(domainActorSql($connection))->not->toContain('from "app_user"')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects an inactive mapping', function () {
    $connection = domainActorConnection();
    seedDomainActor($connection, [
        'status' => 'inactive',
        'inactivated_at' => '2026-10-21 08:00:00',
    ]);
    $connection->enableQueryLog();

    expect(fn () => $connection->transaction(
        fn () => domainActorResolver()->resolve(5, $connection),
    ))->toThrow(ResolveDomainActorException::class, 'The domain actor mapping is inactive.');

    expect(domainActorSql($connection))->not->toContain('from "app_user"')
        ->and($connection->table('user_app_user_map')->count())->toBe(1)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects an active mapping that already has an inactivation timestamp', function () {
    $connection = domainActorConnection('unchecked-inactivation');
    seedDomainActor($connection, [
        'inactivated_at' => '2026-10-21 08:00:00',
    ]);
    $connection->enableQueryLog();

    expect(fn () => $connection->transaction(
        fn () => domainActorResolver()->resolve(5, $connection),
    ))->toThrow(ResolveDomainActorException::class, 'The active domain actor mapping has an inactivation timestamp.');

    expect(domainActorSql($connection))->not->toContain('from "app_user"')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('returns the mapped app user id when the active flag is stored as the string 1', function () {
    $connection = domainActorConnection('text-active');
    seedDomainActor($connection, ['is_active' => '1']);

    expect($connection->table('app_user')->value('is_active'))->toBe('1');

    $appUserId = $connection->transaction(
        fn (): int => domainActorResolver()->resolve(5, $connection),
    );

    expect($appUserId)->toBe(90)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects an inactive app user', function () {
    $connection = domainActorConnection();
    seedDomainActor($connection, ['is_active' => 0]);

    expect(fn () => $connection->transaction(
        fn () => domainActorResolver()->resolve(5, $connection),
    ))->toThrow(ResolveDomainActorException::class, 'The mapped app user is inactive.');

    expect($connection->table('app_user')->where('app_user_id', 90)->value('is_active'))->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects a mapping whose app user row is missing', function () {
    $connection = domainActorConnection();
    seedDomainActor($connection);
    $connection->statement('PRAGMA foreign_keys = OFF');
    $connection->table('app_user')->delete();
    $connection->statement('PRAGMA foreign_keys = ON');
    $connection->enableQueryLog();

    expect(fn () => $connection->transaction(
        fn () => domainActorResolver()->resolve(5, $connection),
    ))->toThrow(ResolveDomainActorException::class, 'The mapped app user does not exist.');

    expect($connection->table('app_user')->count())->toBe(0)
        ->and($connection->table('user_app_user_map')->count())->toBe(1)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects two mappings for one laravel user', function () {
    $connection = domainActorConnection('duplicate-laravel-user');
    seedDomainActor($connection);
    $connection->table('users')->insert(['id' => 6]);
    $connection->table('app_user')->insert([
        'app_user_id' => 91,
        'is_active' => 1,
    ]);
    $connection->table('user_app_user_map')->insert([
        'user_app_user_map_id' => 2,
        'laravel_user_id' => 5,
        'app_user_id' => 91,
        'status' => 'active',
        'created_at' => '2026-10-21 07:00:00',
        'inactivated_at' => null,
    ]);
    $connection->enableQueryLog();

    expect(fn () => $connection->transaction(
        fn () => domainActorResolver()->resolve(5, $connection),
    ))->toThrow(ResolveDomainActorException::class, 'The Laravel user has more than one domain actor mapping.');

    expect(domainActorSql($connection))->not->toContain('from "app_user"')
        ->and($connection->table('user_app_user_map')->count())->toBe(2)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects a laravel user id below 1 before reading the mapping', function (int $laravelUserId) {
    $connection = domainActorConnection();
    seedDomainActor($connection);
    $connection->enableQueryLog();

    expect(fn () => $connection->transaction(
        fn () => domainActorResolver()->resolve($laravelUserId, $connection),
    ))->toThrow(ResolveDomainActorException::class, 'The Laravel user id must be at least 1.');

    expect(domainActorSql($connection))->not->toContain('user_app_user_map')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects resolution outside an active transaction', function () {
    $connection = domainActorConnection();
    seedDomainActor($connection);
    $connection->enableQueryLog();

    expect(fn () => domainActorResolver()->resolve(5, $connection))
        ->toThrow(ResolveDomainActorException::class, 'Domain actor resolution requires an active transaction.');

    expect(domainActorSql($connection))->not->toContain('user_app_user_map')
        ->and($connection->table('user_app_user_map')->count())->toBe(1)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('propagates an unsafe connection exception before querying the mapping', function () {
    $connection = domainActorConnection();
    seedDomainActor($connection);
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('transactionLevel');
    $unsafe->shouldNotReceive('table');

    expect(fn () => domainActorResolver()->resolve(5, $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');

    expect($connection->table('user_app_user_map')->count())->toBe(1)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('propagates a production connection exception unchanged', function () {
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('pgsql');
    $unsafe->shouldReceive('getName')->andReturn('');
    $unsafe->shouldReceive('getDatabaseName')->andReturn('calibration_db');
    $unsafe->shouldNotReceive('scalar');
    $unsafe->shouldNotReceive('table');
    $unsafe->shouldNotReceive('transactionLevel');

    expect(fn () => domainActorResolver()->resolve(5, $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class, 'Refusing to use the production database for a calibration session workflow.');

    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('propagates a missing mapping table as a query exception', function () {
    config([
        'database.connections.'.DOMAIN_ACTOR_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);
    DB::purge(DOMAIN_ACTOR_CONNECTION);
    $connection = DB::connection(DOMAIN_ACTOR_CONNECTION);

    expect(fn () => $connection->transaction(
        fn () => domainActorResolver()->resolve(5, $connection),
    ))->toThrow(QueryException::class);

    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('resolves through the supplied connection when its name is empty or unregistered', function (string $connectionName) {
    $connection = domainActorConnection();
    seedDomainActor($connection);
    $config = new ReflectionProperty($connection, 'config');
    $settings = $config->getValue($connection);
    $settings['name'] = $connectionName;
    $config->setValue($connection, $settings);
    $default = DB::connection();

    $appUserId = $connection->transaction(function () use ($connection, $default): int {
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $default->flushQueryLog();
        $default->enableQueryLog();

        return domainActorResolver()->resolve(5, $connection);
    });

    $sql = strtolower(domainActorSql($connection));

    expect($connection->getName())->toBe($connectionName)
        ->and($appUserId)->toBe(90)
        ->and($sql)->toContain('from "user_app_user_map"')
        ->and($sql)->toContain('from "app_user"')
        ->and($sql)->not->toContain('insert')
        ->and($sql)->not->toContain('update')
        ->and($sql)->not->toContain('delete')
        ->and(domainActorSql($default))->not->toContain('user_app_user_map')
        ->and(domainActorSql($default))->not->toContain('app_user')
        ->and(Schema::connection('sqlite')->hasTable('user_app_user_map'))->toBeFalse()
        ->and(config('database.default'))->toBe('sqlite')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty name' => '',
    'unregistered name' => 'unowned-actor-lock',
]);
