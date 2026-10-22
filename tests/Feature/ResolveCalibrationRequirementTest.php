<?php

use App\CalibrationSessions\CalibrationSessionCompleteness;
use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\ResolveCalibrationRequirement;
use App\CalibrationSessions\ResolveCalibrationRequirementException;
use App\CalibrationSessions\ResolveDomainActorException;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestRefusingPostgresConnector;

const REQUIREMENT_RESOLUTION_CONNECTION = 'requirement_resolution_sqlite';

function requirementResolutionConnection(): Connection
{
    config([
        'database.connections.'.REQUIREMENT_RESOLUTION_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(REQUIREMENT_RESOLUTION_CONNECTION);

    $connection = DB::connection(REQUIREMENT_RESOLUTION_CONNECTION);

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

    return $connection;
}

function requirementResolver(): ResolveCalibrationRequirement
{
    return app(ResolveCalibrationRequirement::class);
}

/**
 * @param  array{status?: string, inactivated_at?: string|null, is_active?: int}  $actor
 */
function seedResolutionActor(Connection $connection, array $actor = []): void
{
    $connection->table('users')->insert(['id' => 5]);
    $connection->table('app_user')->insert([
        'app_user_id' => 90,
        'is_active' => $actor['is_active'] ?? 1,
    ]);
    $connection->table('user_app_user_map')->insert([
        'user_app_user_map_id' => 1,
        'laravel_user_id' => 5,
        'app_user_id' => 90,
        'status' => $actor['status'] ?? 'active',
        'created_at' => '2026-10-21 07:00:00',
        'inactivated_at' => $actor['inactivated_at'] ?? null,
    ]);
}

/**
 * @param  array{id?: int, category?: string, frozen_mode?: string, resolved_mode?: string|null, resolved_at?: string|null, resolved_reason?: string|null, resolved_by_user_id?: int|null}  $snapshot
 */
function seedRequirementSnapshot(Connection $connection, array $snapshot = []): void
{
    $connection->table('calibration_requirement_snapshot')->insert([
        'calibration_requirement_snapshot_id' => $snapshot['id'] ?? 1,
        'calibration_id' => 40,
        'requirement_category' => $snapshot['category'] ?? InstrumentTypeRequirement::CATEGORY_STANDARDS,
        'frozen_mode' => $snapshot['frozen_mode'] ?? InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
        'resolved_mode' => $snapshot['resolved_mode'] ?? null,
        'resolved_at' => $snapshot['resolved_at'] ?? null,
        'resolved_reason' => $snapshot['resolved_reason'] ?? null,
        'resolved_by_user_id' => $snapshot['resolved_by_user_id'] ?? null,
        'configuration_confirmed_at' => '2026-10-21 09:00:00',
        'configuration_confirmed_reason' => 'kept',
        'configuration_confirmed_by_user_id' => 90,
    ]);
}

function seedResolvableSession(Connection $connection, string $sessionStatus = Calibration::STATUS_IN_PROGRESS): void
{
    seedResolutionActor($connection);
    $connection->table('calibration')->insert([
        'calibration_id' => 40,
        'session_status' => $sessionStatus,
    ]);
    seedRequirementSnapshot($connection);
    seedRequirementSnapshot($connection, [
        'id' => 2,
        'category' => InstrumentTypeRequirement::CATEGORY_ACTIVITY,
    ]);
}

function requirementSnapshot(Connection $connection, string $category): ?object
{
    return $connection->table('calibration_requirement_snapshot')
        ->where('calibration_id', 40)
        ->where('requirement_category', $category)
        ->first();
}

function resolutionSql(Connection $connection): string
{
    return strtolower(implode("\n", array_column($connection->getQueryLog(), 'query')));
}

function resolveStandards(Connection $connection, string $mode = 'required', string $reason = 'needed on site'): void
{
    requirementResolver()->resolve(
        40,
        InstrumentTypeRequirement::CATEGORY_STANDARDS,
        $mode,
        $reason,
        5,
        $connection,
    );
}

it('resolves a not configured requirement to required and keeps the frozen mode', function () {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    resolveStandards($connection, 'required', '  needed on site  ');

    $standards = requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS);
    $activity = requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_ACTIVITY);
    $sql = resolutionSql($connection);
    $calibrationPosition = strpos($sql, 'from "calibration"');
    $snapshotPosition = strpos($sql, 'from "calibration_requirement_snapshot"');
    $mapPosition = strpos($sql, 'from "user_app_user_map"');
    $appUserPosition = strpos($sql, 'from "app_user"');

    expect($standards)->not->toBeNull()
        ->and($standards->frozen_mode)->toBe('not_configured')
        ->and($standards->resolved_mode)->toBe('required')
        ->and($standards->resolved_reason)->toBe('needed on site')
        ->and($standards->resolved_at)->not->toBeNull()
        ->and($standards->resolved_by_user_id)->toBe(90)
        ->and($standards->requirement_category)->toBe('standards')
        ->and((int) $standards->calibration_id)->toBe(40)
        ->and($standards->configuration_confirmed_at)->toBe('2026-10-21 09:00:00')
        ->and($standards->configuration_confirmed_reason)->toBe('kept')
        ->and($standards->configuration_confirmed_by_user_id)->toBe(90)
        ->and($activity->resolved_mode)->toBeNull()
        ->and($activity->frozen_mode)->toBe('not_configured')
        ->and($connection->table('calibration')->value('session_status'))->toBe('in_progress')
        ->and(CalibrationSessionCompleteness::effectiveMode($standards->frozen_mode, $standards->resolved_mode))->toBe('required')
        ->and(CalibrationSessionCompleteness::effectiveMode('required', null))->toBe('required')
        ->and(CalibrationSessionCompleteness::effectiveMode('not_configured', null))->toBe('not_configured')
        ->and($calibrationPosition)->toBeInt()
        ->and($snapshotPosition)->toBeInt()
        ->and($mapPosition)->toBeInt()
        ->and($appUserPosition)->toBeInt()
        ->and($calibrationPosition)->toBeLessThan($snapshotPosition)
        ->and($snapshotPosition)->toBeLessThan($mapPosition)
        ->and($mapPosition)->toBeLessThan($appUserPosition)
        ->and($sql)->not->toContain('insert')
        ->and($sql)->not->toContain('instrument_type_requirement')
        ->and($sql)->not->toContain('update "user_app_user_map"')
        ->and($sql)->not->toContain('update "app_user"')
        ->and($sql)->not->toContain('update "calibration"')
        ->and(resolutionSql($default))->not->toContain('calibration_requirement_snapshot')
        ->and(Schema::connection('sqlite')->hasTable('calibration_requirement_snapshot'))->toBeFalse()
        ->and(config('database.default'))->toBe('sqlite')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('resolves a not configured requirement to optional', function () {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);

    resolveStandards($connection, 'optional', 'operator may skip this');

    $standards = requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS);

    expect($standards->frozen_mode)->toBe('not_configured')
        ->and($standards->resolved_mode)->toBe('optional')
        ->and($standards->resolved_by_user_id)->toBe(90)
        ->and(CalibrationSessionCompleteness::effectiveMode($standards->frozen_mode, $standards->resolved_mode))->toBe('optional');
});

it('stores a long resolution reason without truncating it', function () {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);
    $reason = str_repeat('measured ', 40);

    resolveStandards($connection, 'required', '  '.$reason.'  ');

    expect(requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS)->resolved_reason)->toBe(trim($reason));
});

it('rejects a blank resolution reason before reading the session', function (string $reason) {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);
    $connection->enableQueryLog();

    expect(fn () => resolveStandards($connection, 'required', $reason))
        ->toThrow(ResolveCalibrationRequirementException::class, 'The resolution reason is required.');

    expect(resolutionSql($connection))->not->toContain('from "calibration"')
        ->and(requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS)->resolved_mode)->toBeNull();
})->with([
    'empty' => '',
    'whitespace' => '   ',
]);

it('rejects an unsupported requirement category', function () {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);
    $connection->enableQueryLog();

    expect(fn () => requirementResolver()->resolve(40, 'certificate', 'required', 'needed', 5, $connection))
        ->toThrow(ResolveCalibrationRequirementException::class, 'The requirement category is not supported.');

    expect(resolutionSql($connection))->not->toContain('from "calibration"');
});

it('rejects a resolved mode other than required or optional', function (string $mode) {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);

    expect(fn () => resolveStandards($connection, $mode))
        ->toThrow(ResolveCalibrationRequirementException::class, 'The resolved requirement mode must be required or optional.');

    expect(requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS)->resolved_mode)->toBeNull();
})->with([
    'not configured' => 'not_configured',
    'unknown' => 'mandatory',
]);

it('rejects a calibration id below 1 before reading the session', function (int $calibrationId) {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);
    $connection->enableQueryLog();

    expect(fn () => requirementResolver()->resolve($calibrationId, 'standards', 'required', 'needed', 5, $connection))
        ->toThrow(ResolveCalibrationRequirementException::class, 'The calibration id must be at least 1.');

    expect(resolutionSql($connection))->not->toContain('from "calibration"')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects a missing calibration', function () {
    $connection = requirementResolutionConnection();
    seedResolutionActor($connection);

    expect(fn () => resolveStandards($connection))
        ->toThrow(ResolveCalibrationRequirementException::class, 'The calibration does not exist.');
});

it('rejects a session that is not in progress', function (string $sessionStatus) {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection, $sessionStatus);

    expect(fn () => resolveStandards($connection))
        ->toThrow(ResolveCalibrationRequirementException::class, 'Only an in-progress calibration session can resolve a requirement.');

    expect(requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS)->resolved_mode)->toBeNull()
        ->and($connection->table('calibration')->value('session_status'))->toBe($sessionStatus);
})->with([
    'completed' => Calibration::STATUS_COMPLETED,
    'cancelled' => Calibration::STATUS_CANCELLED,
]);

it('rejects a missing requirement snapshot', function () {
    $connection = requirementResolutionConnection();
    seedResolutionActor($connection);
    $connection->table('calibration')->insert([
        'calibration_id' => 40,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
    ]);

    expect(fn () => resolveStandards($connection))
        ->toThrow(ResolveCalibrationRequirementException::class, 'The requirement snapshot does not exist.');
});

it('rejects a requirement that was already frozen', function (string $frozenMode) {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);
    $connection->table('calibration_requirement_snapshot')
        ->where('calibration_requirement_snapshot_id', 1)
        ->update(['frozen_mode' => $frozenMode]);

    expect(fn () => resolveStandards($connection))
        ->toThrow(ResolveCalibrationRequirementException::class, 'Only a not-configured requirement can be resolved.');

    expect(requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS)->resolved_mode)->toBeNull()
        ->and(requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS)->frozen_mode)->toBe($frozenMode);
})->with([
    'required' => 'required',
    'optional' => 'optional',
]);

it('rejects a second resolution even when the values are unchanged', function () {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);
    resolveStandards($connection, 'required', 'needed on site');
    $first = requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS);

    expect(fn () => resolveStandards($connection, 'required', 'needed on site'))
        ->toThrow(ResolveCalibrationRequirementException::class, 'This requirement has already been resolved.');

    $second = requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS);

    expect($second->resolved_mode)->toBe('required')
        ->and($second->resolved_reason)->toBe('needed on site')
        ->and($second->resolved_at)->toBe($first->resolved_at)
        ->and($second->resolved_by_user_id)->toBe(90);
});

it('rejects a second resolution that asks for a different mode', function () {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);
    resolveStandards($connection, 'required', 'needed on site');

    expect(fn () => resolveStandards($connection, 'optional', 'changed later'))
        ->toThrow(ResolveCalibrationRequirementException::class, 'This requirement has already been resolved.');

    expect(requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS)->resolved_mode)->toBe('required')
        ->and(requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS)->resolved_reason)->toBe('needed on site');
});

it('rolls back when the actor mapping is inactive', function () {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);
    $connection->table('user_app_user_map')->update([
        'status' => 'inactive',
        'inactivated_at' => '2026-10-21 08:00:00',
    ]);

    expect(fn () => resolveStandards($connection))
        ->toThrow(ResolveDomainActorException::class, 'The domain actor mapping is inactive.');

    expect(requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS)->resolved_mode)->toBeNull()
        ->and(requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS)->resolved_by_user_id)->toBeNull()
        ->and(requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS)->resolved_at)->toBeNull();
});

it('rolls back when the mapped app user is inactive', function () {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);
    $connection->table('app_user')->update(['is_active' => 0]);

    expect(fn () => resolveStandards($connection))
        ->toThrow(ResolveDomainActorException::class, 'The mapped app user is inactive.');

    expect(requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS)->resolved_mode)->toBeNull();
});

it('resolves required environment when a frozen required parameter exists', function () {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);
    seedRequirementSnapshot($connection, [
        'id' => 3,
        'category' => InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
    ]);
    $connection->table('calibration_environment_parameter_snapshot')->insert([
        'calibration_id' => 40,
        'parameter_id' => 7,
        'parameter_code' => 'temperature',
        'requirement_mode' => 'required',
        'display_order' => 1,
        'inclusion_confirmed' => 1,
    ]);
    $connection->enableQueryLog();

    requirementResolver()->resolve(40, InstrumentTypeRequirement::CATEGORY_ENVIRONMENT, 'required', 'temperature is mandatory', 5, $connection);

    $environment = requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_ENVIRONMENT);
    $sql = resolutionSql($connection);

    expect($environment->frozen_mode)->toBe('not_configured')
        ->and($environment->resolved_mode)->toBe('required')
        ->and($environment->resolved_by_user_id)->toBe(90)
        ->and($environment->configuration_confirmed_reason)->toBe('kept')
        ->and($sql)->toContain('calibration_environment_parameter_snapshot')
        ->and($sql)->not->toContain('update "calibration_environment_parameter_snapshot"')
        ->and($sql)->not->toContain('instrument_type_environment_parameter')
        ->and(requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS)->resolved_mode)->toBeNull();
});

it('rejects required environment when no frozen required parameter exists', function () {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);
    seedRequirementSnapshot($connection, [
        'id' => 3,
        'category' => InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
    ]);
    $connection->table('calibration_environment_parameter_snapshot')->insert([
        'calibration_id' => 40,
        'parameter_id' => 8,
        'parameter_code' => 'relative_humidity',
        'requirement_mode' => 'optional',
        'display_order' => 1,
        'inclusion_confirmed' => 1,
    ]);

    expect(fn () => requirementResolver()->resolve(40, InstrumentTypeRequirement::CATEGORY_ENVIRONMENT, 'required', 'temperature is mandatory', 5, $connection))
        ->toThrow(ResolveCalibrationRequirementException::class, 'Required environment needs at least one required environment parameter snapshot.');

    expect(requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)->resolved_mode)->toBeNull()
        ->and($connection->table('calibration_environment_parameter_snapshot')->count())->toBe(1)
        ->and($connection->table('user_app_user_map')->where('status', 'active')->count())->toBe(1);
});

it('resolves optional environment without a required parameter snapshot', function () {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);
    seedRequirementSnapshot($connection, [
        'id' => 3,
        'category' => InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
    ]);
    $connection->enableQueryLog();

    requirementResolver()->resolve(40, InstrumentTypeRequirement::CATEGORY_ENVIRONMENT, 'optional', 'environment may be skipped', 5, $connection);

    $environment = requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_ENVIRONMENT);

    expect($environment->resolved_mode)->toBe('optional')
        ->and($environment->frozen_mode)->toBe('not_configured')
        ->and($environment->resolved_by_user_id)->toBe(90)
        ->and($environment->configuration_confirmed_at)->toBe('2026-10-21 09:00:00')
        ->and(resolutionSql($connection))->not->toContain('calibration_environment_parameter_snapshot')
        ->and($connection->table('calibration_environment_parameter_snapshot')->count())->toBe(0);
});

it('propagates an unsafe connection exception before querying the session', function () {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('table');
    $unsafe->shouldNotReceive('transaction');

    expect(fn () => requirementResolver()->resolve(40, 'standards', 'required', 'needed', 5, $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');

    expect(requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS)->resolved_mode)->toBeNull()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('resolves through the supplied connection when its name is empty or unregistered', function (string $connectionName) {
    $connection = requirementResolutionConnection();
    seedResolvableSession($connection);
    $config = new ReflectionProperty($connection, 'config');
    $settings = $config->getValue($connection);
    $settings['name'] = $connectionName;
    $config->setValue($connection, $settings);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    resolveStandards($connection);

    expect($connection->getName())->toBe($connectionName)
        ->and(requirementSnapshot($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS)->resolved_by_user_id)->toBe(90)
        ->and(resolutionSql($connection))->toContain('from "calibration_requirement_snapshot"')
        ->and(resolutionSql($default))->not->toContain('calibration_requirement_snapshot')
        ->and(resolutionSql($default))->not->toContain('user_app_user_map')
        ->and(Schema::connection('sqlite')->hasTable('user_app_user_map'))->toBeFalse()
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty name' => '',
    'unregistered name' => 'unowned-requirement-resolution',
]);
