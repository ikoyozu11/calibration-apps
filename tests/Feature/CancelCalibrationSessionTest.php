<?php

use App\Authorization\ApplicationRoleName;
use App\Authorization\AssignApplicationRole;
use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\CancelCalibrationSession;
use App\CalibrationSessions\CancelCalibrationSessionException;
use App\CalibrationSessions\ResolveDomainActorException;
use App\Models\Calibration;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestRefusingPostgresConnector;

const SESSION_CANCELLATION_CONNECTION = 'session_cancellation_sqlite';

function sessionCancellationConnection(bool $withCalibration = true): Connection
{
    config([
        'database.connections.'.SESSION_CANCELLATION_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(SESSION_CANCELLATION_CONNECTION);

    $connection = DB::connection(SESSION_CANCELLATION_CONNECTION);

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

    if ($withCalibration) {
        $connection->unprepared('
            CREATE TABLE calibration (
                calibration_id INTEGER PRIMARY KEY,
                session_status VARCHAR(20) NOT NULL,
                calibration_number VARCHAR(50) NULL,
                calibration_method VARCHAR(20) NULL,
                instrument_type_id INTEGER NULL,
                calibration_date TEXT NULL,
                calibration_due TEXT NULL,
                completed_at TEXT NULL,
                certificate_number TEXT NULL,
                cancelled_at TEXT NULL,
                cancelled_reason TEXT NULL,
                cancelled_by_user_id INTEGER NULL,
                started_by_app_user_id INTEGER NULL,
                snapshot_instrument_name TEXT NULL,
                snapshot_instrument_type_name TEXT NULL
            )
        ');
    }

    return $connection;
}

function sessionCanceller(): CancelCalibrationSession
{
    return app(CancelCalibrationSession::class);
}

/**
 * Seeds a Laravel auth identity (default connection) plus domain actor mapping
 * on the session Connection. Returns the trusted laravel users.id for Cancel.
 *
 * @param  array{app_user_id?: int, status?: string, inactivated_at?: string|null, is_active?: int, map_id?: int}  $actor
 * @return array{laravel_user_id: int, app_user_id: int}
 */
function seedCancellationActor(
    Connection $connection,
    array $actor = [],
    ?ApplicationRoleName $role = ApplicationRoleName::Engineer,
): array {
    $user = User::factory()->create();
    $laravelUserId = (int) $user->id;
    $appUserId = $actor['app_user_id'] ?? 90;

    if ($role !== null) {
        app(AssignApplicationRole::class)->assign($laravelUserId, $role);
    }

    $connection->table('users')->insert(['id' => $laravelUserId]);

    if (! $connection->table('app_user')->where('app_user_id', $appUserId)->exists()) {
        $connection->table('app_user')->insert([
            'app_user_id' => $appUserId,
            'is_active' => $actor['is_active'] ?? 1,
        ]);
    } elseif (array_key_exists('is_active', $actor)) {
        $connection->table('app_user')->where('app_user_id', $appUserId)->update([
            'is_active' => $actor['is_active'],
        ]);
    }

    $connection->table('user_app_user_map')->insert([
        'user_app_user_map_id' => $actor['map_id'] ?? $laravelUserId,
        'laravel_user_id' => $laravelUserId,
        'app_user_id' => $appUserId,
        'status' => $actor['status'] ?? 'active',
        'created_at' => '2026-10-21 07:00:00',
        'inactivated_at' => $actor['inactivated_at'] ?? null,
    ]);

    return [
        'laravel_user_id' => $laravelUserId,
        'app_user_id' => $appUserId,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{laravel_user_id: int, app_user_id: int}
 */
function seedCancellableSession(
    Connection $connection,
    array $overrides = [],
    ?ApplicationRoleName $role = ApplicationRoleName::Engineer,
): array {
    $ownerAppUserId = array_key_exists('started_by_app_user_id', $overrides)
        ? $overrides['started_by_app_user_id']
        : ($overrides['owner_app_user_id'] ?? 90);

    unset($overrides['owner_app_user_id']);

    $identity = seedCancellationActor(
        $connection,
        [
            'app_user_id' => is_int($ownerAppUserId) ? $ownerAppUserId : 90,
            'is_active' => $overrides['actor_is_active'] ?? 1,
            'status' => $overrides['actor_status'] ?? 'active',
            'inactivated_at' => $overrides['actor_inactivated_at'] ?? null,
            'map_id' => $overrides['actor_map_id'] ?? null,
        ],
        $role,
    );

    unset(
        $overrides['actor_is_active'],
        $overrides['actor_status'],
        $overrides['actor_inactivated_at'],
        $overrides['actor_map_id'],
    );

    if (! array_key_exists('started_by_app_user_id', $overrides)) {
        $overrides['started_by_app_user_id'] = $identity['app_user_id'];
    }

    $connection->table('calibration')->insert(array_merge([
        'calibration_id' => 40,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'calibration_number' => 'CAL.VT0007/10/26',
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'instrument_type_id' => 12,
        'calibration_date' => '2026-10-22',
        'calibration_due' => '2027-10-22',
        'completed_at' => null,
        'certificate_number' => null,
        'cancelled_at' => null,
        'cancelled_reason' => null,
        'cancelled_by_user_id' => null,
        'snapshot_instrument_name' => 'Gauge A',
        'snapshot_instrument_type_name' => 'Pressure',
    ], $overrides));

    return $identity;
}

function cancellationRow(Connection $connection): ?object
{
    return $connection->table('calibration')->where('calibration_id', 40)->first();
}

function cancellationSql(Connection $connection): string
{
    return strtolower(implode("\n", array_column($connection->getQueryLog(), 'query')));
}

function cancelCalibration(Connection $connection, string $reason, int $laravelUserId): void
{
    sessionCanceller()->cancel(40, $reason, $laravelUserId, $connection);
}

function seedCancellationChildren(Connection $connection): void
{
    $connection->unprepared('
        CREATE TABLE calibration_requirement_snapshot (
            calibration_requirement_snapshot_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            requirement_category VARCHAR(20) NOT NULL,
            frozen_mode VARCHAR(20) NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_environment_parameter_snapshot (
            calibration_id INTEGER NOT NULL,
            parameter_id INTEGER NOT NULL,
            inclusion_confirmed INTEGER NOT NULL,
            PRIMARY KEY (calibration_id, parameter_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_environment_value (
            calibration_environment_value_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            numeric_value TEXT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_standard_usage (
            calibration_standard_usage_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            marker TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_activity (
            calibration_activity_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            marker TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_scope (
            calibration_scope_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            marker TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_test_result (
            calibration_test_result_id INTEGER PRIMARY KEY,
            calibration_scope_id INTEGER NOT NULL,
            marker TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_result_value (
            calibration_result_value_id INTEGER PRIMARY KEY,
            calibration_test_result_id INTEGER NOT NULL,
            marker TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_reading (
            calibration_reading_id INTEGER PRIMARY KEY,
            calibration_test_result_id INTEGER NOT NULL,
            marker TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_test_profile_revision (
            calibration_test_profile_revision_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            marker TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_external_document (
            calibration_external_document_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            marker TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE certificate (
            certificate_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            marker TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE certificate_revision (
            certificate_revision_id INTEGER PRIMARY KEY,
            certificate_id INTEGER NOT NULL,
            marker TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE notification_log (
            notification_log_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            marker TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE instrument (
            instrument_id INTEGER PRIMARY KEY,
            instrument_name TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE instrument_type (
            instrument_type_id INTEGER PRIMARY KEY,
            type_name TEXT NOT NULL
        )
    ');

    $connection->table('calibration_requirement_snapshot')->insert([
        'calibration_requirement_snapshot_id' => 1,
        'calibration_id' => 40,
        'requirement_category' => 'environment',
        'frozen_mode' => 'not_configured',
    ]);
    $connection->table('calibration_environment_parameter_snapshot')->insert([
        'calibration_id' => 40,
        'parameter_id' => 7,
        'inclusion_confirmed' => 0,
    ]);
    $connection->table('calibration_environment_value')->insert([
        'calibration_environment_value_id' => 1,
        'calibration_id' => 40,
        'numeric_value' => '23.4',
    ]);
    $connection->table('calibration_standard_usage')->insert([
        'calibration_standard_usage_id' => 1,
        'calibration_id' => 40,
        'marker' => 'standard-kept',
    ]);
    $connection->table('calibration_activity')->insert([
        'calibration_activity_id' => 1,
        'calibration_id' => 40,
        'marker' => 'activity-kept',
    ]);
    $connection->table('calibration_scope')->insert([
        'calibration_scope_id' => 1,
        'calibration_id' => 40,
        'marker' => 'scope-kept',
    ]);
    $connection->table('calibration_test_result')->insert([
        'calibration_test_result_id' => 1,
        'calibration_scope_id' => 1,
        'marker' => 'result-kept',
    ]);
    $connection->table('calibration_result_value')->insert([
        'calibration_result_value_id' => 1,
        'calibration_test_result_id' => 1,
        'marker' => 'value-kept',
    ]);
    $connection->table('calibration_reading')->insert([
        'calibration_reading_id' => 1,
        'calibration_test_result_id' => 1,
        'marker' => 'reading-kept',
    ]);
    $connection->table('calibration_test_profile_revision')->insert([
        'calibration_test_profile_revision_id' => 1,
        'calibration_id' => 40,
        'marker' => 'revision-kept',
    ]);
    $connection->table('calibration_external_document')->insert([
        'calibration_external_document_id' => 1,
        'calibration_id' => 40,
        'marker' => 'pdf-kept',
    ]);
    $connection->table('certificate')->insert([
        'certificate_id' => 1,
        'calibration_id' => 40,
        'marker' => 'certificate-kept',
    ]);
    $connection->table('certificate_revision')->insert([
        'certificate_revision_id' => 1,
        'certificate_id' => 1,
        'marker' => 'revision-kept',
    ]);
    $connection->table('notification_log')->insert([
        'notification_log_id' => 1,
        'calibration_id' => 40,
        'marker' => 'notice-kept',
    ]);
    $connection->table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Gauge A',
    ]);
    $connection->table('instrument_type')->insert([
        'instrument_type_id' => 12,
        'type_name' => 'Pressure',
    ]);
}

/*
 * Caller-ID authenticity is a separate trusted-boundary requirement:
 * CancelCalibrationSession trusts $laravelUserId as already authenticated
 * server-side identity. These tests seed User::factory() IDs and pass them
 * directly — they do not prove an HTTP layer cannot spoof request payloads.
 * Future HTTP/Livewire must use (int) auth()->id() after auth middleware.
 */

it('cancels an incomplete internal session and stores the domain actor', function () {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();
    $reason = str_repeat('withdrawn ', 40);

    cancelCalibration($connection, '  '.$reason.'  ', $identity['laravel_user_id']);

    $row = cancellationRow($connection);
    $sql = cancellationSql($connection);
    $calibrationPosition = strpos($sql, 'from "calibration"');
    $mapPosition = strpos($sql, 'from "user_app_user_map"');
    $appUserPosition = strpos($sql, 'from "app_user"');
    $updatePosition = strpos($sql, 'update "calibration"');
    $updates = array_values(array_filter(
        $connection->getQueryLog(),
        fn (array $entry): bool => str_starts_with(strtolower($entry['query']), 'update '),
    ));

    expect($row)->not->toBeNull()
        ->and($row->session_status)->toBe('cancelled')
        ->and($row->cancelled_at)->not->toBeNull()
        ->and($row->cancelled_reason)->toBe(trim($reason))
        ->and($row->cancelled_by_user_id)->toBe($identity['app_user_id'])
        ->and($row->started_by_app_user_id)->toBe($identity['app_user_id'])
        ->and($row->calibration_number)->toBe('CAL.VT0007/10/26')
        ->and($row->calibration_method)->toBe('INTERNAL')
        ->and($row->instrument_type_id)->toBe(12)
        ->and($row->calibration_date)->toBe('2026-10-22')
        ->and($row->calibration_due)->toBe('2027-10-22')
        ->and($row->completed_at)->toBeNull()
        ->and($row->certificate_number)->toBeNull()
        ->and($row->snapshot_instrument_name)->toBe('Gauge A')
        ->and($row->snapshot_instrument_type_name)->toBe('Pressure')
        ->and($calibrationPosition)->toBeInt()
        ->and($mapPosition)->toBeInt()
        ->and($appUserPosition)->toBeInt()
        ->and($updatePosition)->toBeInt()
        ->and($calibrationPosition)->toBeLessThan($mapPosition)
        ->and($mapPosition)->toBeLessThan($appUserPosition)
        ->and($appUserPosition)->toBeLessThan($updatePosition)
        ->and($updates)->toHaveCount(1)
        ->and(strtolower($updates[0]['query']))->toContain('update "calibration"')
        ->and(strtolower($updates[0]['query']))->not->toContain('calibration_number')
        ->and(strtolower($updates[0]['query']))->not->toContain('certificate_number')
        ->and(strtolower($updates[0]['query']))->not->toContain('completed_at')
        ->and(strtolower($updates[0]['query']))->not->toContain('started_by_app_user_id')
        ->and($sql)->not->toContain('insert')
        ->and($sql)->not->toContain('delete')
        ->and($sql)->not->toContain('certificate')
        ->and($sql)->not->toContain('notification_log')
        ->and($sql)->not->toContain('from "instrument"')
        ->and($sql)->not->toContain('from "instrument_type"')
        ->and(cancellationSql($default))->not->toContain('from "calibration"')
        ->and(cancellationSql($default))->not->toContain('user_app_user_map')
        ->and(Schema::connection('sqlite')->hasTable('calibration'))->toBeFalse()
        ->and(config('database.default'))->toBe('sqlite')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('cancels an external session without a vendor document', function () {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection, [
        'calibration_method' => Calibration::METHOD_EXTERNAL,
    ]);

    cancelCalibration($connection, 'vendor work stopped', $identity['laravel_user_id']);

    $row = cancellationRow($connection);

    expect($row->session_status)->toBe('cancelled')
        ->and($row->calibration_method)->toBe('EXTERNAL')
        ->and($row->cancelled_reason)->toBe('vendor work stopped')
        ->and($row->cancelled_by_user_id)->toBe($identity['app_user_id'])
        ->and($row->started_by_app_user_id)->toBe($identity['app_user_id'])
        ->and($row->completed_at)->toBeNull();
});

it('leaves related records unchanged when an incomplete session is cancelled', function () {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection);
    seedCancellationChildren($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    cancelCalibration($connection, 'requirements were never finished', $identity['laravel_user_id']);

    $sql = cancellationSql($connection);

    expect(cancellationRow($connection)->session_status)->toBe('cancelled')
        ->and($connection->table('calibration_requirement_snapshot')->value('frozen_mode'))->toBe('not_configured')
        ->and($connection->table('calibration_environment_parameter_snapshot')->value('inclusion_confirmed'))->toBe(0)
        ->and($connection->table('calibration_environment_value')->value('numeric_value'))->toBe('23.4')
        ->and($connection->table('calibration_standard_usage')->value('marker'))->toBe('standard-kept')
        ->and($connection->table('calibration_activity')->value('marker'))->toBe('activity-kept')
        ->and($connection->table('calibration_scope')->value('marker'))->toBe('scope-kept')
        ->and($connection->table('calibration_test_result')->value('marker'))->toBe('result-kept')
        ->and($connection->table('calibration_result_value')->value('marker'))->toBe('value-kept')
        ->and($connection->table('calibration_reading')->value('marker'))->toBe('reading-kept')
        ->and($connection->table('calibration_test_profile_revision')->value('marker'))->toBe('revision-kept')
        ->and($connection->table('calibration_external_document')->value('marker'))->toBe('pdf-kept')
        ->and($connection->table('certificate')->value('marker'))->toBe('certificate-kept')
        ->and($connection->table('certificate_revision')->value('marker'))->toBe('revision-kept')
        ->and($connection->table('notification_log')->value('marker'))->toBe('notice-kept')
        ->and($connection->table('instrument')->value('instrument_name'))->toBe('Gauge A')
        ->and($connection->table('instrument_type')->value('type_name'))->toBe('Pressure')
        ->and($connection->table('calibration')->count())->toBe(1)
        ->and($sql)->not->toContain('calibration_requirement_snapshot')
        ->and($sql)->not->toContain('calibration_environment')
        ->and($sql)->not->toContain('calibration_standard_usage')
        ->and($sql)->not->toContain('calibration_activity')
        ->and($sql)->not->toContain('calibration_scope')
        ->and($sql)->not->toContain('calibration_test_result')
        ->and($sql)->not->toContain('calibration_result_value')
        ->and($sql)->not->toContain('calibration_reading')
        ->and($sql)->not->toContain('calibration_test_profile_revision')
        ->and($sql)->not->toContain('calibration_external_document')
        ->and($sql)->not->toContain('from "certificate"')
        ->and($sql)->not->toContain('notification_log')
        ->and($sql)->not->toContain('from "instrument"')
        ->and($sql)->not->toContain('from "instrument_type"');
});

it('allows an Administrator to cancel their own in-progress session', function () {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection, [], ApplicationRoleName::Administrator);

    cancelCalibration($connection, 'admin own cancel', $identity['laravel_user_id']);

    $row = cancellationRow($connection);

    expect($row->session_status)->toBe('cancelled')
        ->and($row->cancelled_by_user_id)->toBe($identity['app_user_id'])
        ->and($row->started_by_app_user_id)->toBe($identity['app_user_id'])
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('allows an Administrator to cancel another actor owned session', function () {
    $connection = sessionCancellationConnection();
    $owner = seedCancellationActor($connection, ['app_user_id' => 90], ApplicationRoleName::Engineer);
    $admin = seedCancellationActor($connection, ['app_user_id' => 91], ApplicationRoleName::Administrator);
    $connection->table('calibration')->insert([
        'calibration_id' => 40,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'calibration_number' => 'CAL.VT0007/10/26',
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'instrument_type_id' => 12,
        'calibration_date' => '2026-10-22',
        'calibration_due' => '2027-10-22',
        'completed_at' => null,
        'certificate_number' => null,
        'cancelled_at' => null,
        'cancelled_reason' => null,
        'cancelled_by_user_id' => null,
        'started_by_app_user_id' => $owner['app_user_id'],
        'snapshot_instrument_name' => 'Gauge A',
        'snapshot_instrument_type_name' => 'Pressure',
    ]);

    cancelCalibration($connection, 'admin cancels other', $admin['laravel_user_id']);

    $row = cancellationRow($connection);

    expect($row->session_status)->toBe('cancelled')
        ->and($row->cancelled_by_user_id)->toBe($admin['app_user_id'])
        ->and($row->started_by_app_user_id)->toBe($owner['app_user_id']);
});

it('allows an Administrator to cancel a legacy NULL-owner session', function () {
    $connection = sessionCancellationConnection();
    $admin = seedCancellableSession($connection, [
        'started_by_app_user_id' => null,
    ], ApplicationRoleName::Administrator);

    cancelCalibration($connection, 'admin legacy cancel', $admin['laravel_user_id']);

    $row = cancellationRow($connection);

    expect($row->session_status)->toBe('cancelled')
        ->and($row->cancelled_by_user_id)->toBe($admin['app_user_id'])
        ->and($row->started_by_app_user_id)->toBeNull();
});

it('allows an Engineer to cancel their own in-progress session', function () {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection, [], ApplicationRoleName::Engineer);

    cancelCalibration($connection, 'engineer own cancel', $identity['laravel_user_id']);

    $row = cancellationRow($connection);

    expect($row->session_status)->toBe('cancelled')
        ->and($row->cancelled_by_user_id)->toBe($identity['app_user_id'])
        ->and($row->started_by_app_user_id)->toBe($identity['app_user_id']);
});

it('denies an Engineer cancelling another Engineer owned session', function () {
    $connection = sessionCancellationConnection();
    $owner = seedCancellationActor($connection, ['app_user_id' => 90], ApplicationRoleName::Engineer);
    $other = seedCancellationActor($connection, ['app_user_id' => 91], ApplicationRoleName::Engineer);
    $connection->table('calibration')->insert([
        'calibration_id' => 40,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'calibration_number' => 'CAL.VT0007/10/26',
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'instrument_type_id' => 12,
        'calibration_date' => '2026-10-22',
        'calibration_due' => '2027-10-22',
        'completed_at' => null,
        'certificate_number' => null,
        'cancelled_at' => null,
        'cancelled_reason' => null,
        'cancelled_by_user_id' => null,
        'started_by_app_user_id' => $owner['app_user_id'],
        'snapshot_instrument_name' => 'Gauge A',
        'snapshot_instrument_type_name' => 'Pressure',
    ]);

    expect(fn () => cancelCalibration($connection, 'not my session', $other['laravel_user_id']))
        ->toThrow(CancelCalibrationSessionException::class, 'You are not allowed to cancel this calibration session.');

    $row = cancellationRow($connection);

    expect($row->session_status)->toBe('in_progress')
        ->and($row->cancelled_at)->toBeNull()
        ->and($row->cancelled_reason)->toBeNull()
        ->and($row->cancelled_by_user_id)->toBeNull()
        ->and($row->started_by_app_user_id)->toBe($owner['app_user_id']);
});

it('denies an Engineer cancelling an Administrator-owned session', function () {
    $connection = sessionCancellationConnection();
    $admin = seedCancellationActor($connection, ['app_user_id' => 90], ApplicationRoleName::Administrator);
    $engineer = seedCancellationActor($connection, ['app_user_id' => 91], ApplicationRoleName::Engineer);
    $connection->table('calibration')->insert([
        'calibration_id' => 40,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'calibration_number' => 'CAL.VT0007/10/26',
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'instrument_type_id' => 12,
        'calibration_date' => '2026-10-22',
        'calibration_due' => '2027-10-22',
        'completed_at' => null,
        'certificate_number' => null,
        'cancelled_at' => null,
        'cancelled_reason' => null,
        'cancelled_by_user_id' => null,
        'started_by_app_user_id' => $admin['app_user_id'],
        'snapshot_instrument_name' => 'Gauge A',
        'snapshot_instrument_type_name' => 'Pressure',
    ]);

    expect(fn () => cancelCalibration($connection, 'not admin session', $engineer['laravel_user_id']))
        ->toThrow(CancelCalibrationSessionException::class, 'You are not allowed to cancel this calibration session.');

    expect(cancellationRow($connection)->session_status)->toBe('in_progress')
        ->and(cancellationRow($connection)->cancelled_at)->toBeNull()
        ->and(cancellationRow($connection)->started_by_app_user_id)->toBe($admin['app_user_id']);
});

it('denies an Engineer cancelling a legacy NULL-owner session', function () {
    $connection = sessionCancellationConnection();
    $engineer = seedCancellableSession($connection, [
        'started_by_app_user_id' => null,
    ], ApplicationRoleName::Engineer);

    expect(fn () => cancelCalibration($connection, 'legacy no owner', $engineer['laravel_user_id']))
        ->toThrow(CancelCalibrationSessionException::class, 'You are not allowed to cancel this calibration session.');

    expect(cancellationRow($connection)->session_status)->toBe('in_progress')
        ->and(cancellationRow($connection)->cancelled_at)->toBeNull()
        ->and(cancellationRow($connection)->started_by_app_user_id)->toBeNull();
});

it('denies a Viewer from cancelling any session', function () {
    $connection = sessionCancellationConnection();
    $viewer = seedCancellableSession($connection, [], ApplicationRoleName::Viewer);

    expect(fn () => cancelCalibration($connection, 'viewer attempt', $viewer['laravel_user_id']))
        ->toThrow(CancelCalibrationSessionException::class, 'You are not allowed to cancel this calibration session.');

    expect(cancellationRow($connection)->session_status)->toBe('in_progress')
        ->and(cancellationRow($connection)->cancelled_at)->toBeNull()
        ->and(cancellationRow($connection)->cancelled_by_user_id)->toBeNull()
        ->and(cancellationRow($connection)->started_by_app_user_id)->toBe($viewer['app_user_id']);
});

it('denies a roleless user from cancelling', function () {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection, [], null);

    expect(fn () => cancelCalibration($connection, 'roleless attempt', $identity['laravel_user_id']))
        ->toThrow(CancelCalibrationSessionException::class, 'You are not allowed to cancel this calibration session.');

    expect(cancellationRow($connection)->session_status)->toBe('in_progress')
        ->and(cancellationRow($connection)->cancelled_at)->toBeNull();
});

it('denies an invalid application role from cancelling', function () {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection, [], null);
    $invalid = Role::query()->create(['name' => 'Contractor']);
    UserRole::query()->create([
        'laravel_user_id' => $identity['laravel_user_id'],
        'role_id' => $invalid->id,
    ]);

    expect(fn () => cancelCalibration($connection, 'invalid role attempt', $identity['laravel_user_id']))
        ->toThrow(CancelCalibrationSessionException::class, 'You are not allowed to cancel this calibration session.');

    expect(cancellationRow($connection)->session_status)->toBe('in_progress')
        ->and(cancellationRow($connection)->cancelled_at)->toBeNull();
});

it('rejects a blank cancellation reason before reading the session', function (string $reason) {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection);
    $connection->enableQueryLog();

    expect(fn () => cancelCalibration($connection, $reason, $identity['laravel_user_id']))
        ->toThrow(CancelCalibrationSessionException::class, 'The cancellation reason is required.');

    expect(cancellationSql($connection))->not->toContain('from "calibration"')
        ->and(cancellationRow($connection)->session_status)->toBe('in_progress')
        ->and(cancellationRow($connection)->cancelled_at)->toBeNull();
})->with([
    'empty' => '',
    'whitespace' => '   ',
]);

it('rejects a calibration id below 1 before reading the session', function (int $calibrationId) {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection);
    $connection->enableQueryLog();

    expect(fn () => sessionCanceller()->cancel($calibrationId, 'needed', $identity['laravel_user_id'], $connection))
        ->toThrow(CancelCalibrationSessionException::class, 'The calibration id must be at least 1.');

    expect(cancellationSql($connection))->not->toContain('from "calibration"')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects a missing calibration', function () {
    $connection = sessionCancellationConnection();
    $identity = seedCancellationActor($connection);

    expect(fn () => cancelCalibration($connection, 'missing session', $identity['laravel_user_id']))
        ->toThrow(CancelCalibrationSessionException::class, 'The calibration does not exist.');
});

it('rejects a completed session', function () {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection, [
        'session_status' => Calibration::STATUS_COMPLETED,
        'completed_at' => '2026-10-22 09:00:00',
    ]);

    expect(fn () => cancelCalibration($connection, 'too late', $identity['laravel_user_id']))
        ->toThrow(CancelCalibrationSessionException::class, 'Only an in-progress calibration session can be cancelled.');

    $row = cancellationRow($connection);

    expect($row->session_status)->toBe('completed')
        ->and($row->completed_at)->toBe('2026-10-22 09:00:00')
        ->and($row->cancelled_at)->toBeNull()
        ->and($row->cancelled_reason)->toBeNull()
        ->and($row->cancelled_by_user_id)->toBeNull()
        ->and($row->started_by_app_user_id)->toBe($identity['app_user_id']);
});

it('rejects an in-progress session with an issued certificate', function () {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection, [
        'certificate_number' => 'SR.VT001/11/26',
    ]);

    expect(fn () => cancelCalibration($connection, 'certificate already issued', $identity['laravel_user_id']))
        ->toThrow(CancelCalibrationSessionException::class, 'A calibration session with an issued certificate cannot be cancelled.');

    expect(cancellationRow($connection)->session_status)->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and(cancellationRow($connection)->cancelled_at)->toBeNull()
        ->and(cancellationRow($connection)->cancelled_reason)->toBeNull();
});

it('rejects an unsupported session status', function () {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection, [
        'session_status' => 'archived',
    ]);

    expect(fn () => cancelCalibration($connection, 'archived', $identity['laravel_user_id']))
        ->toThrow(CancelCalibrationSessionException::class, 'Only an in-progress calibration session can be cancelled.');

    expect(cancellationRow($connection)->session_status)->toBe('archived')
        ->and(cancellationRow($connection)->cancelled_at)->toBeNull();
});

it('rejects a second cancellation and preserves the first audit', function (string $variant) {
    $connection = sessionCancellationConnection();
    $first = seedCancellableSession($connection);
    cancelCalibration($connection, 'instrument withdrawn', $first['laravel_user_id']);
    $before = cancellationRow($connection);
    $second = seedCancellationActor($connection, ['app_user_id' => 91], ApplicationRoleName::Administrator);

    $callerId = $variant === 'different actor' ? $second['laravel_user_id'] : $first['laravel_user_id'];
    $reason = $variant === 'different reason' ? 'a later note' : 'instrument withdrawn';

    expect(fn () => cancelCalibration($connection, $reason, $callerId))
        ->toThrow(CancelCalibrationSessionException::class, 'This calibration session has already been cancelled.');

    $after = cancellationRow($connection);

    expect($after->session_status)->toBe('cancelled')
        ->and($after->cancelled_at)->toBe($before->cancelled_at)
        ->and($after->cancelled_reason)->toBe('instrument withdrawn')
        ->and($after->cancelled_by_user_id)->toBe($first['app_user_id'])
        ->and($after->started_by_app_user_id)->toBe($first['app_user_id'])
        ->and($after->calibration_number)->toBe('CAL.VT0007/10/26');
})->with([
    'identical input',
    'different reason',
    'different actor',
]);

it('rejects a partial cancellation audit without repairing it', function () {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection, [
        'cancelled_at' => '2026-10-22 08:15:00',
    ]);

    expect(fn () => cancelCalibration($connection, 'repair the missing reason', $identity['laravel_user_id']))
        ->toThrow(CancelCalibrationSessionException::class, 'Cancellation audit data is partially recorded and cannot be repaired.');

    $row = cancellationRow($connection);

    expect($row->session_status)->toBe('in_progress')
        ->and($row->cancelled_at)->toBe('2026-10-22 08:15:00')
        ->and($row->cancelled_reason)->toBeNull()
        ->and($row->cancelled_by_user_id)->toBeNull()
        ->and($row->started_by_app_user_id)->toBe($identity['app_user_id']);
});

it('rolls back when the actor mapping is inactive', function () {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection);
    $connection->table('user_app_user_map')->update([
        'status' => 'inactive',
        'inactivated_at' => '2026-10-21 08:00:00',
    ]);

    expect(fn () => cancelCalibration($connection, 'inactive map', $identity['laravel_user_id']))
        ->toThrow(ResolveDomainActorException::class, 'The domain actor mapping is inactive.');

    $row = cancellationRow($connection);

    expect($row->session_status)->toBe('in_progress')
        ->and($row->cancelled_at)->toBeNull()
        ->and($row->cancelled_reason)->toBeNull()
        ->and($row->cancelled_by_user_id)->toBeNull();
});

it('rolls back when the mapped app user is inactive', function () {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection);
    $connection->table('app_user')->update(['is_active' => 0]);

    expect(fn () => cancelCalibration($connection, 'inactive app user', $identity['laravel_user_id']))
        ->toThrow(ResolveDomainActorException::class, 'The mapped app user is inactive.');

    $row = cancellationRow($connection);

    expect($row->session_status)->toBe('in_progress')
        ->and($row->cancelled_at)->toBeNull()
        ->and($row->cancelled_reason)->toBeNull()
        ->and($row->cancelled_by_user_id)->toBeNull();
});

it('rolls back when the domain actor mapping is missing', function () {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection);
    $connection->table('user_app_user_map')->delete();

    expect(fn () => cancelCalibration($connection, 'missing map', $identity['laravel_user_id']))
        ->toThrow(ResolveDomainActorException::class);

    $row = cancellationRow($connection);

    expect($row->session_status)->toBe('in_progress')
        ->and($row->cancelled_at)->toBeNull()
        ->and($row->cancelled_reason)->toBeNull()
        ->and($row->cancelled_by_user_id)->toBeNull();
});

it('propagates a missing calibration table as a query exception', function () {
    $connection = sessionCancellationConnection(false);
    $identity = seedCancellationActor($connection);

    expect(fn () => cancelCalibration($connection, 'no table', $identity['laravel_user_id']))
        ->toThrow(QueryException::class);

    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('propagates an unsafe connection exception before querying the session', function () {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection);
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('table');
    $unsafe->shouldNotReceive('transaction');

    expect(fn () => sessionCanceller()->cancel(40, 'needed', $identity['laravel_user_id'], $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');

    expect(cancellationRow($connection)->session_status)->toBe('in_progress')
        ->and(cancellationRow($connection)->cancelled_at)->toBeNull()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('cancels through the supplied connection when its name is empty or unregistered', function (string $connectionName) {
    $connection = sessionCancellationConnection();
    $identity = seedCancellableSession($connection);
    $config = new ReflectionProperty($connection, 'config');
    $settings = $config->getValue($connection);
    $settings['name'] = $connectionName;
    $config->setValue($connection, $settings);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    cancelCalibration($connection, 'unnamed connection', $identity['laravel_user_id']);

    expect($connection->getName())->toBe($connectionName)
        ->and(cancellationRow($connection)->cancelled_by_user_id)->toBe($identity['app_user_id'])
        ->and(cancellationRow($connection)->session_status)->toBe('cancelled')
        ->and(cancellationSql($connection))->toContain('update "calibration"')
        ->and(cancellationSql($default))->not->toContain('from "calibration"')
        ->and(cancellationSql($default))->not->toContain('user_app_user_map')
        ->and(Schema::connection('sqlite')->hasTable('user_app_user_map'))->toBeFalse()
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty name' => '',
    'unregistered name' => 'unowned-session-cancellation',
]);
