<?php

/*
 * SQLite does not execute database/sql/laravel_users_staging.sql.
 * These tests do not prove the staging database-name guard, rerun refusal,
 * bigint, timestamp(0) without time zone, sequence ownership, or transactional
 * rollback of CREATE TABLE. The script-text test only checks that those
 * clauses are present.
 */

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestRefusingPostgresConnector;

const LARAVEL_USERS_CONNECTION = 'laravel_users_sqlite';

function laravelUsersConnection(): Connection
{
    config([
        'database.connections.'.LARAVEL_USERS_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(LARAVEL_USERS_CONNECTION);

    $connection = DB::connection(LARAVEL_USERS_CONNECTION);

    $connection->unprepared('
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            email_verified_at TEXT NULL,
            password VARCHAR(255) NOT NULL,
            remember_token VARCHAR(100) NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL,
            CONSTRAINT users_email_unique UNIQUE (email)
        )
    ');

    return $connection;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertLaravelUser(Connection $connection, array $overrides = []): void
{
    $connection->table('users')->insert(array_merge([
        'name' => 'Analyst',
        'email' => 'analyst@example.test',
        'email_verified_at' => null,
        'password' => Hash::make('secret-pass'),
        'remember_token' => null,
        'created_at' => null,
        'updated_at' => null,
    ], $overrides));
}

it('creates the expected users columns on the supplied connection', function () {
    $connection = laravelUsersConnection();

    expect(Schema::connection(LARAVEL_USERS_CONNECTION)->getColumnListing('users'))->toBe([
        'id',
        'name',
        'email',
        'email_verified_at',
        'password',
        'remember_token',
        'created_at',
        'updated_at',
    ])
        ->and($connection->table('users')->count())->toBe(0);
});

it('rejects a null required users field', function (string $field) {
    $connection = laravelUsersConnection();
    $row = [
        'name' => 'Analyst',
        'email' => 'analyst@example.test',
        'password' => Hash::make('secret-pass'),
    ];
    $row[$field] = null;

    expect(fn () => $connection->table('users')->insert($row))->toThrow(QueryException::class);

    expect($connection->table('users')->count())->toBe(0);
})->with([
    'name',
    'email',
    'password',
]);

it('stores null verification, remember token, and timestamps', function () {
    $connection = laravelUsersConnection();

    insertLaravelUser($connection);

    $row = $connection->table('users')->first();

    expect($row)->not->toBeNull()
        ->and($row->email_verified_at)->toBeNull()
        ->and($row->remember_token)->toBeNull()
        ->and($row->created_at)->toBeNull()
        ->and($row->updated_at)->toBeNull();
});

it('rejects a duplicate email and keeps the first user', function () {
    $connection = laravelUsersConnection();
    insertLaravelUser($connection, ['email' => 'analyst@example.test']);

    expect(fn () => insertLaravelUser($connection, [
        'name' => 'Other Analyst',
        'email' => 'analyst@example.test',
    ]))->toThrow(QueryException::class);

    expect($connection->table('users')->count())->toBe(1)
        ->and($connection->table('users')->value('name'))->toBe('Analyst');
});

it('stores distinct users with unique primary keys', function () {
    $connection = laravelUsersConnection();

    insertLaravelUser($connection, ['email' => 'one@example.test']);
    insertLaravelUser($connection, ['email' => 'two@example.test', 'name' => 'Reviewer']);

    $ids = $connection->table('users')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

    expect($connection->table('users')->count())->toBe(2)
        ->and($ids)->toBe([1, 2])
        ->and(array_unique($ids))->toHaveCount(2);

    expect(fn () => insertLaravelUser($connection, [
        'id' => 1,
        'email' => 'third@example.test',
    ]))->toThrow(QueryException::class);

    expect($connection->table('users')->count())->toBe(2);
});

it('generates the next users id when none is supplied', function () {
    $connection = laravelUsersConnection();

    insertLaravelUser($connection, ['email' => 'one@example.test']);
    insertLaravelUser($connection, ['email' => 'two@example.test']);

    expect((int) $connection->table('users')->where('email', 'one@example.test')->value('id'))->toBe(1)
        ->and((int) $connection->table('users')->where('email', 'two@example.test')->value('id'))->toBe(2);
});

it('stores a Laravel hashed password', function () {
    $connection = laravelUsersConnection();
    $hashed = Hash::make('secret-pass');

    insertLaravelUser($connection, ['password' => $hashed]);

    $stored = $connection->table('users')->value('password');

    expect($stored)->toBe($hashed)
        ->and($stored)->not->toBe('secret-pass')
        ->and(Hash::check('secret-pass', (string) $stored))->toBeTrue()
        ->and(Hash::check('wrong-pass', (string) $stored))->toBeFalse();
});

it('leaves the default connection and unrelated tables untouched', function () {
    $connection = laravelUsersConnection();
    $default = DB::connection();
    $defaultUsersBefore = Schema::connection('sqlite')->hasTable('users')
        ? $default->table('users')->count()
        : 0;
    $default->enableQueryLog();

    insertLaravelUser($connection, ['email' => 'named-connection@example.test']);

    $defaultSql = implode("\n", array_column($default->getQueryLog(), 'query'));
    $named = Schema::connection(LARAVEL_USERS_CONNECTION);

    expect($named->hasTable('password_reset_tokens'))->toBeFalse()
        ->and($named->hasTable('sessions'))->toBeFalse()
        ->and($named->hasTable('cache'))->toBeFalse()
        ->and($named->hasTable('cache_locks'))->toBeFalse()
        ->and($named->hasTable('jobs'))->toBeFalse()
        ->and($named->hasTable('job_batches'))->toBeFalse()
        ->and($named->hasTable('failed_jobs'))->toBeFalse()
        ->and($named->hasTable('migrations'))->toBeFalse()
        ->and($named->hasTable('app_user'))->toBeFalse()
        ->and($named->hasTable('user_app_user_map'))->toBeFalse()
        ->and($default->table('users')->count())->toBe($defaultUsersBefore)
        ->and($defaultSql)->not->toContain('named-connection@example.test')
        ->and(config('database.default'))->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('declares the staging guard, rerun refusal, and one transaction in the users script', function () {
    $sql = file_get_contents(base_path('database/sql/laravel_users_staging.sql'));

    expect($sql)->toContain('BEGIN;')
        ->and($sql)->toContain('COMMIT;')
        ->and($sql)->toContain("current_database() IS DISTINCT FROM 'calibration_staging_db'")
        ->and($sql)->toContain("to_regclass('public.users')")
        ->and($sql)->toContain("to_regclass('public.users_id_seq')")
        ->and($sql)->toContain('CREATE SEQUENCE public.users_id_seq')
        ->and($sql)->toContain('CREATE TABLE public.users')
        ->and($sql)->toContain('CONSTRAINT users_pkey PRIMARY KEY (id)')
        ->and($sql)->toContain('CONSTRAINT users_email_unique UNIQUE (email)')
        ->and($sql)->toContain('ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id')
        ->and($sql)->not->toContain('INSERT INTO')
        ->and($sql)->not->toContain('CREATE TABLE public.password_reset_tokens')
        ->and($sql)->not->toContain('CREATE TABLE public.sessions')
        ->and($sql)->not->toContain('CREATE TABLE public.app_user')
        ->and($sql)->not->toContain('CREATE TABLE public.user_app_user_map')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});
