<?php

use App\CalibrationSessions\CalibrationSessionCompleteness;
use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\ConfirmEnvironmentConfiguration;
use App\CalibrationSessions\ConfirmEnvironmentConfigurationException;
use App\CalibrationSessions\ResolveDomainActorException;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestRefusingPostgresConnector;

const ENVIRONMENT_CONFIRMATION_CONNECTION = 'environment_confirmation_sqlite';

function environmentConfirmationConnection(bool $withSnapshot = true): Connection
{
    config([
        'database.connections.'.ENVIRONMENT_CONFIRMATION_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(ENVIRONMENT_CONFIRMATION_CONNECTION);

    $connection = DB::connection(ENVIRONMENT_CONFIRMATION_CONNECTION);

    $connection->unprepared('
        CREATE TABLE users (
            id INTEGER PRIMARY KEY
        )
    ');
    $connection->unprepared('
        CREATE TABLE app_user (
            app_user_id INTEGER PRIMARY KEY,
            is_active INTEGER NOT NULL
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
    $connection->unprepared('
        CREATE TABLE calibration (
            calibration_id INTEGER PRIMARY KEY,
            session_status VARCHAR(20) NOT NULL
        )
    ');

    if ($withSnapshot) {
        $connection->unprepared('
            CREATE TABLE calibration_requirement_snapshot (
                calibration_requirement_snapshot_id INTEGER PRIMARY KEY,
                calibration_id INTEGER NOT NULL,
                requirement_category VARCHAR(20) NOT NULL,
                frozen_mode VARCHAR(20) NOT NULL,
                resolved_mode VARCHAR(20) NULL,
                resolved_at TEXT NULL,
                resolved_reason TEXT NULL,
                resolved_by_user_id INTEGER NULL,
                configuration_confirmed_at TEXT NULL,
                configuration_confirmed_reason TEXT NULL,
                configuration_confirmed_by_user_id INTEGER NULL,
                UNIQUE (calibration_id, requirement_category)
            )
        ');
        $connection->unprepared('
            CREATE TABLE calibration_environment_parameter_snapshot (
                calibration_id INTEGER NOT NULL,
                parameter_id INTEGER NOT NULL,
                parameter_code VARCHAR(50) NOT NULL,
                requirement_mode VARCHAR(20) NOT NULL,
                display_order INTEGER NOT NULL,
                inclusion_confirmed INTEGER NOT NULL,
                PRIMARY KEY (calibration_id, parameter_id)
            )
        ');
        $connection->unprepared('
            CREATE TABLE calibration_environment_value (
                calibration_environment_value_id INTEGER PRIMARY KEY,
                calibration_id INTEGER NOT NULL,
                parameter_id INTEGER NOT NULL,
                numeric_value TEXT NULL,
                text_value TEXT NULL
            )
        ');
    }

    return $connection;
}

function environmentConfirmer(): ConfirmEnvironmentConfiguration
{
    return app(ConfirmEnvironmentConfiguration::class);
}

/**
 * @param  array{laravel_user_id?: int, app_user_id?: int, status?: string, inactivated_at?: string|null, is_active?: int, map_id?: int}  $actor
 */
function seedConfirmationActor(Connection $connection, array $actor = []): void
{
    $connection->table('users')->insert(['id' => $actor['laravel_user_id'] ?? 5]);
    $connection->table('app_user')->insert([
        'app_user_id' => $actor['app_user_id'] ?? 90,
        'is_active' => $actor['is_active'] ?? 1,
    ]);
    $connection->table('user_app_user_map')->insert([
        'user_app_user_map_id' => $actor['map_id'] ?? 1,
        'laravel_user_id' => $actor['laravel_user_id'] ?? 5,
        'app_user_id' => $actor['app_user_id'] ?? 90,
        'status' => $actor['status'] ?? 'active',
        'created_at' => '2026-10-21 07:00:00',
        'inactivated_at' => $actor['inactivated_at'] ?? null,
    ]);
}

/**
 * @param  array{id?: int, category?: string, frozen_mode?: string, resolved_mode?: string|null, resolved_at?: string|null, resolved_reason?: string|null, resolved_by_user_id?: int|null, configuration_confirmed_at?: string|null, configuration_confirmed_reason?: string|null, configuration_confirmed_by_user_id?: int|null}  $snapshot
 */
function seedEnvironmentSnapshot(Connection $connection, array $snapshot = []): void
{
    $connection->table('calibration_requirement_snapshot')->insert([
        'calibration_requirement_snapshot_id' => $snapshot['id'] ?? 1,
        'calibration_id' => 40,
        'requirement_category' => $snapshot['category'] ?? InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
        'frozen_mode' => $snapshot['frozen_mode'] ?? InstrumentTypeRequirement::MODE_REQUIRED,
        'resolved_mode' => $snapshot['resolved_mode'] ?? null,
        'resolved_at' => $snapshot['resolved_at'] ?? null,
        'resolved_reason' => $snapshot['resolved_reason'] ?? null,
        'resolved_by_user_id' => $snapshot['resolved_by_user_id'] ?? null,
        'configuration_confirmed_at' => $snapshot['configuration_confirmed_at'] ?? null,
        'configuration_confirmed_reason' => $snapshot['configuration_confirmed_reason'] ?? null,
        'configuration_confirmed_by_user_id' => $snapshot['configuration_confirmed_by_user_id'] ?? null,
    ]);
}

function seedConfirmableSession(
    Connection $connection,
    string $frozenMode = InstrumentTypeRequirement::MODE_REQUIRED,
    string $sessionStatus = Calibration::STATUS_IN_PROGRESS,
): void {
    seedConfirmationActor($connection);
    $connection->table('calibration')->insert([
        'calibration_id' => 40,
        'session_status' => $sessionStatus,
    ]);
    seedEnvironmentSnapshot($connection, ['frozen_mode' => $frozenMode]);
    seedEnvironmentSnapshot($connection, [
        'id' => 2,
        'category' => InstrumentTypeRequirement::CATEGORY_STANDARDS,
        'frozen_mode' => InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
    ]);
}

function environmentSnapshot(Connection $connection, string $category = InstrumentTypeRequirement::CATEGORY_ENVIRONMENT): ?object
{
    return $connection->table('calibration_requirement_snapshot')
        ->where('calibration_id', 40)
        ->where('requirement_category', $category)
        ->first();
}

function confirmationSql(Connection $connection): string
{
    return strtolower(implode("\n", array_column($connection->getQueryLog(), 'query')));
}

function confirmEnvironment(Connection $connection, string $reason = 'bench is stable', int $laravelUserId = 5): void
{
    environmentConfirmer()->confirm(40, $reason, $laravelUserId, $connection);
}

it('confirms a frozen required environment once and stores the domain actor', function () {
    $connection = environmentConfirmationConnection();
    seedConfirmableSession($connection);
    $connection->table('calibration_environment_parameter_snapshot')->insert([
        'calibration_id' => 40,
        'parameter_id' => 7,
        'parameter_code' => 'temperature',
        'requirement_mode' => 'required',
        'display_order' => 1,
        'inclusion_confirmed' => 1,
    ]);
    $connection->table('calibration_environment_value')->insert([
        'calibration_environment_value_id' => 1,
        'calibration_id' => 40,
        'parameter_id' => 7,
        'numeric_value' => null,
        'text_value' => null,
    ]);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();
    $reason = str_repeat('measured ', 40);

    confirmEnvironment($connection, '  '.$reason.'  ');

    $environment = environmentSnapshot($connection);
    $standards = environmentSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS);
    $sql = confirmationSql($connection);
    $calibrationPosition = strpos($sql, 'from "calibration"');
    $snapshotPosition = strpos($sql, 'from "calibration_requirement_snapshot"');
    $mapPosition = strpos($sql, 'from "user_app_user_map"');
    $appUserPosition = strpos($sql, 'from "app_user"');

    expect($environment)->not->toBeNull()
        ->and($environment->frozen_mode)->toBe('required')
        ->and($environment->resolved_mode)->toBeNull()
        ->and($environment->resolved_at)->toBeNull()
        ->and($environment->resolved_reason)->toBeNull()
        ->and($environment->resolved_by_user_id)->toBeNull()
        ->and($environment->configuration_confirmed_at)->not->toBeNull()
        ->and($environment->configuration_confirmed_reason)->toBe(trim($reason))
        ->and($environment->configuration_confirmed_by_user_id)->toBe(90)
        ->and($standards->configuration_confirmed_at)->toBeNull()
        ->and($standards->frozen_mode)->toBe('not_configured')
        ->and($connection->table('calibration')->value('session_status'))->toBe('in_progress')
        ->and($connection->table('calibration_environment_parameter_snapshot')->value('inclusion_confirmed'))->toBe(1)
        ->and($connection->table('calibration_environment_value')->value('numeric_value'))->toBeNull()
        ->and(CalibrationSessionCompleteness::environmentReadyForInternalComplete(
            'required',
            true,
            [['requirement_mode' => 'required', 'value' => null]],
        ))->toBeFalse()
        ->and($calibrationPosition)->toBeInt()
        ->and($snapshotPosition)->toBeInt()
        ->and($mapPosition)->toBeInt()
        ->and($appUserPosition)->toBeInt()
        ->and($calibrationPosition)->toBeLessThan($snapshotPosition)
        ->and($snapshotPosition)->toBeLessThan($mapPosition)
        ->and($mapPosition)->toBeLessThan($appUserPosition)
        ->and($sql)->not->toContain('insert')
        ->and($sql)->not->toContain('instrument_type_requirement')
        ->and($sql)->not->toContain('update "calibration_environment_parameter_snapshot"')
        ->and($sql)->not->toContain('update "calibration_environment_value"')
        ->and($sql)->not->toContain('update "calibration"')
        ->and($sql)->toContain('update "calibration_requirement_snapshot"')
        ->and(confirmationSql($default))->not->toContain('calibration_requirement_snapshot')
        ->and(Schema::connection('sqlite')->hasTable('calibration_requirement_snapshot'))->toBeFalse()
        ->and(config('database.default'))->toBe('sqlite')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('confirms a frozen optional environment without a required parameter', function () {
    $connection = environmentConfirmationConnection();
    seedConfirmableSession($connection, InstrumentTypeRequirement::MODE_OPTIONAL);

    confirmEnvironment($connection, 'optional environment is acceptable');

    $environment = environmentSnapshot($connection);

    expect($environment->frozen_mode)->toBe('optional')
        ->and($environment->configuration_confirmed_reason)->toBe('optional environment is acceptable')
        ->and($environment->configuration_confirmed_by_user_id)->toBe(90)
        ->and($connection->table('calibration_environment_parameter_snapshot')->count())->toBe(0);
});

it('confirms an environment that was resolved from not configured', function (string $resolvedMode) {
    $connection = environmentConfirmationConnection();
    seedConfirmableSession($connection, InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    $connection->table('calibration_requirement_snapshot')
        ->where('calibration_requirement_snapshot_id', 1)
        ->update([
            'resolved_mode' => $resolvedMode,
            'resolved_at' => '2026-10-21 08:00:00',
            'resolved_reason' => 'chosen at the bench',
            'resolved_by_user_id' => 90,
        ]);

    confirmEnvironment($connection, 'configuration matches the resolution');

    $environment = environmentSnapshot($connection);

    expect($environment->frozen_mode)->toBe('not_configured')
        ->and($environment->resolved_mode)->toBe($resolvedMode)
        ->and($environment->resolved_at)->toBe('2026-10-21 08:00:00')
        ->and($environment->resolved_reason)->toBe('chosen at the bench')
        ->and($environment->resolved_by_user_id)->toBe(90)
        ->and($environment->configuration_confirmed_by_user_id)->toBe(90)
        ->and($environment->configuration_confirmed_reason)->toBe('configuration matches the resolution');
})->with([
    'required' => 'required',
    'optional' => 'optional',
]);

it('rejects an unresolved not configured environment', function () {
    $connection = environmentConfirmationConnection();
    seedConfirmableSession($connection, InstrumentTypeRequirement::MODE_NOT_CONFIGURED);

    expect(fn () => confirmEnvironment($connection))
        ->toThrow(ConfirmEnvironmentConfigurationException::class, 'An unresolved not-configured environment cannot be confirmed.');

    expect(environmentSnapshot($connection)->configuration_confirmed_at)->toBeNull();
});

it('rejects an environment whose effective mode is not required or optional', function () {
    $connection = environmentConfirmationConnection();
    seedConfirmableSession($connection, InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    $connection->table('calibration_requirement_snapshot')
        ->where('calibration_requirement_snapshot_id', 1)
        ->update([
            'resolved_mode' => 'mandatory',
            'resolved_at' => '2026-10-21 08:00:00',
            'resolved_reason' => 'not a supported mode',
            'resolved_by_user_id' => 90,
        ]);

    expect(fn () => confirmEnvironment($connection))
        ->toThrow(ConfirmEnvironmentConfigurationException::class, 'The environment requirement mode cannot be confirmed.');

    expect(environmentSnapshot($connection)->configuration_confirmed_at)->toBeNull()
        ->and(environmentSnapshot($connection)->resolved_mode)->toBe('mandatory');
});

it('rejects a missing calibration', function () {
    $connection = environmentConfirmationConnection();
    seedConfirmationActor($connection);

    expect(fn () => confirmEnvironment($connection))
        ->toThrow(ConfirmEnvironmentConfigurationException::class, 'The calibration does not exist.');
});

it('rejects a calibration id below 1 before reading the session', function (int $calibrationId) {
    $connection = environmentConfirmationConnection();
    seedConfirmableSession($connection);
    $connection->enableQueryLog();

    expect(fn () => environmentConfirmer()->confirm($calibrationId, 'needed', 5, $connection))
        ->toThrow(ConfirmEnvironmentConfigurationException::class, 'The calibration id must be at least 1.');

    expect(confirmationSql($connection))->not->toContain('from "calibration"')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects a session that is not in progress', function (string $sessionStatus) {
    $connection = environmentConfirmationConnection();
    seedConfirmableSession($connection, InstrumentTypeRequirement::MODE_REQUIRED, $sessionStatus);

    expect(fn () => confirmEnvironment($connection))
        ->toThrow(ConfirmEnvironmentConfigurationException::class, 'Only an in-progress calibration session can confirm environment configuration.');

    expect(environmentSnapshot($connection)->configuration_confirmed_at)->toBeNull()
        ->and($connection->table('calibration')->value('session_status'))->toBe($sessionStatus);
})->with([
    'completed' => Calibration::STATUS_COMPLETED,
    'cancelled' => Calibration::STATUS_CANCELLED,
]);

it('rejects a missing environment snapshot', function () {
    $connection = environmentConfirmationConnection();
    seedConfirmationActor($connection);
    $connection->table('calibration')->insert([
        'calibration_id' => 40,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
    ]);

    expect(fn () => confirmEnvironment($connection))
        ->toThrow(ConfirmEnvironmentConfigurationException::class, 'The environment requirement snapshot does not exist.');
});

it('rejects a blank confirmation reason before reading the session', function (string $reason) {
    $connection = environmentConfirmationConnection();
    seedConfirmableSession($connection);
    $connection->enableQueryLog();

    expect(fn () => confirmEnvironment($connection, $reason))
        ->toThrow(ConfirmEnvironmentConfigurationException::class, 'The confirmation reason is required.');

    expect(confirmationSql($connection))->not->toContain('from "calibration"')
        ->and(environmentSnapshot($connection)->configuration_confirmed_at)->toBeNull();
})->with([
    'empty' => '',
    'whitespace' => '   ',
]);

it('rejects a second confirmation and preserves the first audit', function (string $reason, int $laravelUserId) {
    $connection = environmentConfirmationConnection();
    seedConfirmableSession($connection);
    confirmEnvironment($connection, 'bench is stable');
    $first = environmentSnapshot($connection);
    seedConfirmationActor($connection, [
        'laravel_user_id' => 6,
        'app_user_id' => 91,
        'map_id' => 2,
    ]);

    expect(fn () => confirmEnvironment($connection, $reason, $laravelUserId))
        ->toThrow(ConfirmEnvironmentConfigurationException::class, 'Environment configuration has already been confirmed.');

    $second = environmentSnapshot($connection);

    expect($second->configuration_confirmed_at)->toBe($first->configuration_confirmed_at)
        ->and($second->configuration_confirmed_reason)->toBe('bench is stable')
        ->and($second->configuration_confirmed_by_user_id)->toBe(90);
})->with([
    'identical input' => ['bench is stable', 5],
    'different reason' => ['a later note', 5],
    'different actor' => ['bench is stable', 6],
]);

it('rejects a partial confirmation without repairing it', function () {
    $connection = environmentConfirmationConnection();
    seedConfirmableSession($connection);
    $connection->table('calibration_requirement_snapshot')
        ->where('calibration_requirement_snapshot_id', 1)
        ->update(['configuration_confirmed_at' => '2026-10-21 09:30:00']);

    expect(fn () => confirmEnvironment($connection, 'repair the missing reason'))
        ->toThrow(ConfirmEnvironmentConfigurationException::class, 'Environment configuration confirmation is partially recorded and cannot be repaired.');

    $environment = environmentSnapshot($connection);

    expect($environment->configuration_confirmed_at)->toBe('2026-10-21 09:30:00')
        ->and($environment->configuration_confirmed_reason)->toBeNull()
        ->and($environment->configuration_confirmed_by_user_id)->toBeNull();
});

it('rolls back when the actor mapping is inactive', function () {
    $connection = environmentConfirmationConnection();
    seedConfirmableSession($connection);
    $connection->table('user_app_user_map')->update([
        'status' => 'inactive',
        'inactivated_at' => '2026-10-21 08:00:00',
    ]);

    expect(fn () => confirmEnvironment($connection))
        ->toThrow(ResolveDomainActorException::class, 'The domain actor mapping is inactive.');

    expect(environmentSnapshot($connection)->configuration_confirmed_at)->toBeNull()
        ->and(environmentSnapshot($connection)->configuration_confirmed_reason)->toBeNull()
        ->and(environmentSnapshot($connection)->configuration_confirmed_by_user_id)->toBeNull();
});

it('rolls back when the mapped app user is inactive', function () {
    $connection = environmentConfirmationConnection();
    seedConfirmableSession($connection);
    $connection->table('app_user')->update(['is_active' => 0]);

    expect(fn () => confirmEnvironment($connection))
        ->toThrow(ResolveDomainActorException::class, 'The mapped app user is inactive.');

    expect(environmentSnapshot($connection)->configuration_confirmed_at)->toBeNull();
});

it('propagates a missing snapshot table as a query exception', function () {
    $connection = environmentConfirmationConnection(false);
    seedConfirmationActor($connection);
    $connection->table('calibration')->insert([
        'calibration_id' => 40,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
    ]);

    expect(fn () => confirmEnvironment($connection))
        ->toThrow(QueryException::class);

    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('propagates an unsafe connection exception before querying the session', function () {
    $connection = environmentConfirmationConnection();
    seedConfirmableSession($connection);
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('table');
    $unsafe->shouldNotReceive('transaction');

    expect(fn () => environmentConfirmer()->confirm(40, 'needed', 5, $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');

    expect(environmentSnapshot($connection)->configuration_confirmed_at)->toBeNull()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('confirms through the supplied connection when its name is empty or unregistered', function (string $connectionName) {
    $connection = environmentConfirmationConnection();
    seedConfirmableSession($connection);
    $config = new ReflectionProperty($connection, 'config');
    $settings = $config->getValue($connection);
    $settings['name'] = $connectionName;
    $config->setValue($connection, $settings);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    confirmEnvironment($connection);

    expect($connection->getName())->toBe($connectionName)
        ->and(environmentSnapshot($connection)->configuration_confirmed_by_user_id)->toBe(90)
        ->and(confirmationSql($connection))->toContain('update "calibration_requirement_snapshot"')
        ->and(confirmationSql($default))->not->toContain('calibration_requirement_snapshot')
        ->and(confirmationSql($default))->not->toContain('user_app_user_map')
        ->and(Schema::connection('sqlite')->hasTable('user_app_user_map'))->toBeFalse()
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty name' => '',
    'unregistered name' => 'unowned-environment-confirmation',
]);
