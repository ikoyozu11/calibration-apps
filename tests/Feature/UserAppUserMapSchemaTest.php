<?php

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestRefusingPostgresConnector;

const USER_APP_USER_MAP_CONNECTION = 'user_app_user_map_sqlite';

function userAppUserMapConnection(): Connection
{
    config([
        'database.connections.'.USER_APP_USER_MAP_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(USER_APP_USER_MAP_CONNECTION);

    $connection = DB::connection(USER_APP_USER_MAP_CONNECTION);

    $connection->unprepared('
        CREATE TABLE users (
            id INTEGER PRIMARY KEY
        )
    ');

    $connection->unprepared('
        CREATE TABLE app_user (
            app_user_id INTEGER PRIMARY KEY
        )
    ');

    $connection->unprepared("
        CREATE TABLE user_app_user_map (
            user_app_user_map_id INTEGER PRIMARY KEY,
            laravel_user_id INTEGER NOT NULL,
            app_user_id INTEGER NOT NULL,
            status VARCHAR(20) NOT NULL,
            created_at TEXT NOT NULL,
            inactivated_at TEXT NULL,
            CONSTRAINT uq_user_app_user_map_laravel_user UNIQUE (laravel_user_id),
            CONSTRAINT uq_user_app_user_map_app_user UNIQUE (app_user_id),
            CONSTRAINT fk_user_app_user_map_user
                FOREIGN KEY (laravel_user_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_user_app_user_map_app_user
                FOREIGN KEY (app_user_id) REFERENCES app_user (app_user_id) ON DELETE RESTRICT,
            CONSTRAINT chk_user_app_user_map_status
                CHECK (status IN ('active', 'inactive')),
            CONSTRAINT chk_user_app_user_map_inactivated_at
                CHECK (
                    (status = 'active' AND inactivated_at IS NULL)
                    OR (status = 'inactive' AND inactivated_at IS NOT NULL)
                )
        )
    ");

    return $connection;
}

/**
 * @param  array{laravel_user_id: int, app_user_id: int, status: string, inactivated_at: string|null}  $map
 */
function insertUserAppUserMap(Connection $connection, array $map, int $id = 1): void
{
    $connection->table('user_app_user_map')->insert([
        'user_app_user_map_id' => $id,
        'laravel_user_id' => $map['laravel_user_id'],
        'app_user_id' => $map['app_user_id'],
        'status' => $map['status'],
        'created_at' => '2026-10-21 07:00:00',
        'inactivated_at' => $map['inactivated_at'],
    ]);
}

it('stores one active mapping between distinct user ids on the supplied connection', function () {
    $connection = userAppUserMapConnection();
    $connection->table('users')->insert([
        ['id' => 5],
        ['id' => 6],
    ]);
    $connection->table('app_user')->insert([
        ['app_user_id' => 90],
        ['app_user_id' => 91],
    ]);
    $default = DB::connection();
    $default->enableQueryLog();

    insertUserAppUserMap($connection, [
        'laravel_user_id' => 5,
        'app_user_id' => 90,
        'status' => 'active',
        'inactivated_at' => null,
    ]);
    insertUserAppUserMap($connection, [
        'laravel_user_id' => 6,
        'app_user_id' => 91,
        'status' => 'inactive',
        'inactivated_at' => '2026-10-21 08:00:00',
    ], 2);

    $defaultSql = implode("\n", array_column($default->getQueryLog(), 'query'));

    expect($connection->table('user_app_user_map')->orderBy('user_app_user_map_id')->get(['laravel_user_id', 'app_user_id', 'status', 'inactivated_at'])->map(fn (object $row): array => [
        'laravel_user_id' => (int) $row->laravel_user_id,
        'app_user_id' => (int) $row->app_user_id,
        'status' => $row->status,
        'inactivated_at' => $row->inactivated_at,
    ])->all())->toBe([
        [
            'laravel_user_id' => 5,
            'app_user_id' => 90,
            'status' => 'active',
            'inactivated_at' => null,
        ],
        [
            'laravel_user_id' => 6,
            'app_user_id' => 91,
            'status' => 'inactive',
            'inactivated_at' => '2026-10-21 08:00:00',
        ],
    ]);
    expect(Schema::connection('sqlite')->hasTable('user_app_user_map'))->toBeFalse()
        ->and(Schema::connection('sqlite')->hasTable('app_user'))->toBeFalse()
        ->and($defaultSql)->not->toContain('user_app_user_map')
        ->and(config('database.default'))->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects a second mapping for the same Laravel user', function () {
    $connection = userAppUserMapConnection();
    $connection->table('users')->insert([
        ['id' => 5],
    ]);
    $connection->table('app_user')->insert([
        ['app_user_id' => 90],
        ['app_user_id' => 91],
    ]);
    insertUserAppUserMap($connection, [
        'laravel_user_id' => 5,
        'app_user_id' => 90,
        'status' => 'active',
        'inactivated_at' => null,
    ]);

    expect(fn () => insertUserAppUserMap($connection, [
        'laravel_user_id' => 5,
        'app_user_id' => 91,
        'status' => 'active',
        'inactivated_at' => null,
    ], 2))->toThrow(QueryException::class);

    expect($connection->table('user_app_user_map')->count())->toBe(1)
        ->and((int) $connection->table('user_app_user_map')->value('app_user_id'))->toBe(90);
});

it('rejects a second mapping for the same app user', function () {
    $connection = userAppUserMapConnection();
    $connection->table('users')->insert([
        ['id' => 5],
        ['id' => 6],
    ]);
    $connection->table('app_user')->insert([
        ['app_user_id' => 90],
    ]);
    insertUserAppUserMap($connection, [
        'laravel_user_id' => 5,
        'app_user_id' => 90,
        'status' => 'active',
        'inactivated_at' => null,
    ]);

    expect(fn () => insertUserAppUserMap($connection, [
        'laravel_user_id' => 6,
        'app_user_id' => 90,
        'status' => 'active',
        'inactivated_at' => null,
    ], 2))->toThrow(QueryException::class);

    expect($connection->table('user_app_user_map')->count())->toBe(1)
        ->and((int) $connection->table('user_app_user_map')->value('laravel_user_id'))->toBe(5);
});

it('rejects a mapping whose Laravel user does not exist', function () {
    $connection = userAppUserMapConnection();
    $connection->table('app_user')->insert([
        ['app_user_id' => 90],
    ]);

    expect(fn () => insertUserAppUserMap($connection, [
        'laravel_user_id' => 5,
        'app_user_id' => 90,
        'status' => 'active',
        'inactivated_at' => null,
    ]))->toThrow(QueryException::class);

    expect($connection->table('user_app_user_map')->count())->toBe(0);
});

it('rejects a mapping whose app user does not exist', function () {
    $connection = userAppUserMapConnection();
    $connection->table('users')->insert([
        ['id' => 5],
    ]);

    expect(fn () => insertUserAppUserMap($connection, [
        'laravel_user_id' => 5,
        'app_user_id' => 90,
        'status' => 'active',
        'inactivated_at' => null,
    ]))->toThrow(QueryException::class);

    expect($connection->table('user_app_user_map')->count())->toBe(0);
});

it('rejects a status and inactivation timestamp that disagree', function (string $status, ?string $inactivatedAt) {
    $connection = userAppUserMapConnection();
    $connection->table('users')->insert([
        ['id' => 5],
    ]);
    $connection->table('app_user')->insert([
        ['app_user_id' => 90],
    ]);

    expect(fn () => insertUserAppUserMap($connection, [
        'laravel_user_id' => 5,
        'app_user_id' => 90,
        'status' => $status,
        'inactivated_at' => $inactivatedAt,
    ]))->toThrow(QueryException::class);

    expect($connection->table('user_app_user_map')->count())->toBe(0);
})->with([
    'active with a timestamp' => ['active', '2026-10-21 08:00:00'],
    'inactive without a timestamp' => ['inactive', null],
    'unknown status' => ['retired', null],
]);

it('restricts deleting a mapped Laravel user', function () {
    $connection = userAppUserMapConnection();
    $connection->table('users')->insert([
        ['id' => 5],
    ]);
    $connection->table('app_user')->insert([
        ['app_user_id' => 90],
    ]);
    insertUserAppUserMap($connection, [
        'laravel_user_id' => 5,
        'app_user_id' => 90,
        'status' => 'active',
        'inactivated_at' => null,
    ]);

    expect(fn () => $connection->table('users')->where('id', 5)->delete())->toThrow(QueryException::class);

    expect($connection->table('users')->where('id', 5)->count())->toBe(1)
        ->and($connection->table('user_app_user_map')->count())->toBe(1)
        ->and($connection->table('app_user')->where('app_user_id', 90)->count())->toBe(1);
});

it('restricts deleting a mapped app user', function () {
    $connection = userAppUserMapConnection();
    $connection->table('users')->insert([
        ['id' => 5],
    ]);
    $connection->table('app_user')->insert([
        ['app_user_id' => 90],
    ]);
    insertUserAppUserMap($connection, [
        'laravel_user_id' => 5,
        'app_user_id' => 90,
        'status' => 'active',
        'inactivated_at' => null,
    ]);

    expect(fn () => $connection->table('app_user')->where('app_user_id', 90)->delete())->toThrow(QueryException::class);

    expect($connection->table('app_user')->where('app_user_id', 90)->count())->toBe(1)
        ->and($connection->table('user_app_user_map')->count())->toBe(1)
        ->and($connection->table('users')->where('id', 5)->count())->toBe(1);
});
