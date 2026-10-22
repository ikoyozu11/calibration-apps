<?php

use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\CancelCalibrationSession;
use App\CalibrationSessions\CancelCalibrationSessionException;
use App\CalibrationSessions\ResolveDomainActorException;
use App\Models\Calibration;
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
 * @param  array{laravel_user_id?: int, app_user_id?: int, status?: string, inactivated_at?: string|null, is_active?: int, map_id?: int}  $actor
 */
function seedCancellationActor(Connection $connection, array $actor = []): void
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
 * @param  array<string, mixed>  $overrides
 */
function seedCancellableSession(Connection $connection, array $overrides = []): void
{
    seedCancellationActor($connection);
    $connection->table('calibration')->insert(array_merge([
        'calibration_id' => 40,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'calibration_number' => 'CAL.VT0007/10/26',
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'instrument_type_id' => 12,
        'calibration_date' => '2026-10-22',
        'calibration_due' => '2027-10-22',
        'completed_at' => null,
        'certificate_number' => 'CERT-KEEP',
        'cancelled_at' => null,
        'cancelled_reason' => null,
        'cancelled_by_user_id' => null,
        'snapshot_instrument_name' => 'Gauge A',
        'snapshot_instrument_type_name' => 'Pressure',
    ], $overrides));
}

function cancellationRow(Connection $connection): ?object
{
    return $connection->table('calibration')->where('calibration_id', 40)->first();
}

function cancellationSql(Connection $connection): string
{
    return strtolower(implode("\n", array_column($connection->getQueryLog(), 'query')));
}

function cancelCalibration(Connection $connection, string $reason = 'instrument withdrawn', int $laravelUserId = 5): void
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

it('cancels an incomplete internal session and stores the domain actor', function () {
    $connection = sessionCancellationConnection();
    seedCancellableSession($connection);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();
    $reason = str_repeat('withdrawn ', 40);

    cancelCalibration($connection, '  '.$reason.'  ');

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
        ->and($row->cancelled_by_user_id)->toBe(90)
        ->and($row->calibration_number)->toBe('CAL.VT0007/10/26')
        ->and($row->calibration_method)->toBe('INTERNAL')
        ->and($row->instrument_type_id)->toBe(12)
        ->and($row->calibration_date)->toBe('2026-10-22')
        ->and($row->calibration_due)->toBe('2027-10-22')
        ->and($row->completed_at)->toBeNull()
        ->and($row->certificate_number)->toBe('CERT-KEEP')
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
        ->and($sql)->not->toContain('insert')
        ->and($sql)->not->toContain('delete')
        ->and($sql)->not->toContain('certificate')
        ->and($sql)->not->toContain('notification_log')
        ->and($sql)->not->toContain('from "instrument"')
        ->and($sql)->not->toContain('from "instrument_type"')
        ->and(cancellationSql($default))->not->toContain('calibration')
        ->and(Schema::connection('sqlite')->hasTable('calibration'))->toBeFalse()
        ->and(config('database.default'))->toBe('sqlite')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('cancels an external session without a vendor document', function () {
    $connection = sessionCancellationConnection();
    seedCancellableSession($connection, [
        'calibration_method' => Calibration::METHOD_EXTERNAL,
    ]);

    cancelCalibration($connection, 'vendor work stopped');

    $row = cancellationRow($connection);

    expect($row->session_status)->toBe('cancelled')
        ->and($row->calibration_method)->toBe('EXTERNAL')
        ->and($row->cancelled_reason)->toBe('vendor work stopped')
        ->and($row->cancelled_by_user_id)->toBe(90)
        ->and($row->completed_at)->toBeNull();
});

it('leaves related records unchanged when an incomplete session is cancelled', function () {
    $connection = sessionCancellationConnection();
    seedCancellableSession($connection);
    seedCancellationChildren($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    cancelCalibration($connection, 'requirements were never finished');

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

it('rejects a blank cancellation reason before reading the session', function (string $reason) {
    $connection = sessionCancellationConnection();
    seedCancellableSession($connection);
    $connection->enableQueryLog();

    expect(fn () => cancelCalibration($connection, $reason))
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
    seedCancellableSession($connection);
    $connection->enableQueryLog();

    expect(fn () => sessionCanceller()->cancel($calibrationId, 'needed', 5, $connection))
        ->toThrow(CancelCalibrationSessionException::class, 'The calibration id must be at least 1.');

    expect(cancellationSql($connection))->not->toContain('from "calibration"')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects a missing calibration', function () {
    $connection = sessionCancellationConnection();
    seedCancellationActor($connection);

    expect(fn () => cancelCalibration($connection))
        ->toThrow(CancelCalibrationSessionException::class, 'The calibration does not exist.');
});

it('rejects a completed session', function () {
    $connection = sessionCancellationConnection();
    seedCancellableSession($connection, [
        'session_status' => Calibration::STATUS_COMPLETED,
        'completed_at' => '2026-10-22 09:00:00',
    ]);

    expect(fn () => cancelCalibration($connection))
        ->toThrow(CancelCalibrationSessionException::class, 'Only an in-progress calibration session can be cancelled.');

    $row = cancellationRow($connection);

    expect($row->session_status)->toBe('completed')
        ->and($row->completed_at)->toBe('2026-10-22 09:00:00')
        ->and($row->cancelled_at)->toBeNull()
        ->and($row->cancelled_reason)->toBeNull()
        ->and($row->cancelled_by_user_id)->toBeNull();
});

it('rejects an unsupported session status', function () {
    $connection = sessionCancellationConnection();
    seedCancellableSession($connection, [
        'session_status' => 'archived',
    ]);

    expect(fn () => cancelCalibration($connection))
        ->toThrow(CancelCalibrationSessionException::class, 'Only an in-progress calibration session can be cancelled.');

    expect(cancellationRow($connection)->session_status)->toBe('archived')
        ->and(cancellationRow($connection)->cancelled_at)->toBeNull();
});

it('rejects a second cancellation and preserves the first audit', function (string $reason, int $laravelUserId) {
    $connection = sessionCancellationConnection();
    seedCancellableSession($connection);
    cancelCalibration($connection, 'instrument withdrawn');
    $first = cancellationRow($connection);
    seedCancellationActor($connection, [
        'laravel_user_id' => 6,
        'app_user_id' => 91,
        'map_id' => 2,
    ]);

    expect(fn () => cancelCalibration($connection, $reason, $laravelUserId))
        ->toThrow(CancelCalibrationSessionException::class, 'This calibration session has already been cancelled.');

    $second = cancellationRow($connection);

    expect($second->session_status)->toBe('cancelled')
        ->and($second->cancelled_at)->toBe($first->cancelled_at)
        ->and($second->cancelled_reason)->toBe('instrument withdrawn')
        ->and($second->cancelled_by_user_id)->toBe(90)
        ->and($second->calibration_number)->toBe('CAL.VT0007/10/26');
})->with([
    'identical input' => ['instrument withdrawn', 5],
    'different reason' => ['a later note', 5],
    'different actor' => ['instrument withdrawn', 6],
]);

it('rejects a partial cancellation audit without repairing it', function () {
    $connection = sessionCancellationConnection();
    seedCancellableSession($connection, [
        'cancelled_at' => '2026-10-22 08:15:00',
    ]);

    expect(fn () => cancelCalibration($connection, 'repair the missing reason'))
        ->toThrow(CancelCalibrationSessionException::class, 'Cancellation audit data is partially recorded and cannot be repaired.');

    $row = cancellationRow($connection);

    expect($row->session_status)->toBe('in_progress')
        ->and($row->cancelled_at)->toBe('2026-10-22 08:15:00')
        ->and($row->cancelled_reason)->toBeNull()
        ->and($row->cancelled_by_user_id)->toBeNull();
});

it('rolls back when the actor mapping is inactive', function () {
    $connection = sessionCancellationConnection();
    seedCancellableSession($connection);
    $connection->table('user_app_user_map')->update([
        'status' => 'inactive',
        'inactivated_at' => '2026-10-21 08:00:00',
    ]);

    expect(fn () => cancelCalibration($connection))
        ->toThrow(ResolveDomainActorException::class, 'The domain actor mapping is inactive.');

    $row = cancellationRow($connection);

    expect($row->session_status)->toBe('in_progress')
        ->and($row->cancelled_at)->toBeNull()
        ->and($row->cancelled_reason)->toBeNull()
        ->and($row->cancelled_by_user_id)->toBeNull();
});

it('rolls back when the mapped app user is inactive', function () {
    $connection = sessionCancellationConnection();
    seedCancellableSession($connection);
    $connection->table('app_user')->update(['is_active' => 0]);

    expect(fn () => cancelCalibration($connection))
        ->toThrow(ResolveDomainActorException::class, 'The mapped app user is inactive.');

    $row = cancellationRow($connection);

    expect($row->session_status)->toBe('in_progress')
        ->and($row->cancelled_at)->toBeNull()
        ->and($row->cancelled_reason)->toBeNull()
        ->and($row->cancelled_by_user_id)->toBeNull();
});

it('propagates a missing calibration table as a query exception', function () {
    $connection = sessionCancellationConnection(false);
    seedCancellationActor($connection);

    expect(fn () => cancelCalibration($connection))
        ->toThrow(QueryException::class);

    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('propagates an unsafe connection exception before querying the session', function () {
    $connection = sessionCancellationConnection();
    seedCancellableSession($connection);
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('table');
    $unsafe->shouldNotReceive('transaction');

    expect(fn () => sessionCanceller()->cancel(40, 'needed', 5, $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');

    expect(cancellationRow($connection)->session_status)->toBe('in_progress')
        ->and(cancellationRow($connection)->cancelled_at)->toBeNull()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('cancels through the supplied connection when its name is empty or unregistered', function (string $connectionName) {
    $connection = sessionCancellationConnection();
    seedCancellableSession($connection);
    $config = new ReflectionProperty($connection, 'config');
    $settings = $config->getValue($connection);
    $settings['name'] = $connectionName;
    $config->setValue($connection, $settings);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    cancelCalibration($connection);

    expect($connection->getName())->toBe($connectionName)
        ->and(cancellationRow($connection)->cancelled_by_user_id)->toBe(90)
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
