<?php

use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\CompleteCalibrationSession;
use App\CalibrationSessions\CompleteCalibrationSessionException;
use App\Models\Calibration;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestRefusingPostgresConnector;

const SESSION_COMPLETE_CONNECTION = 'complete_calibration_sqlite';

function sessionCompleteConnection(): Connection
{
    config([
        'database.connections.'.SESSION_COMPLETE_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
    ]);

    DB::purge(SESSION_COMPLETE_CONNECTION);

    $connection = DB::connection(SESSION_COMPLETE_CONNECTION);
    $connection->unprepared('
        CREATE TABLE calibration (
            calibration_id INTEGER PRIMARY KEY,
            instrument_id INTEGER NULL,
            instrument_type_id INTEGER NULL,
            calibration_provider_id INTEGER NULL,
            calibration_number VARCHAR(50) NULL,
            calibration_date TEXT NULL,
            calibration_due TEXT NULL,
            calibration_method VARCHAR(20) NULL,
            calibration_result TEXT NULL,
            session_status VARCHAR(20) NOT NULL,
            certificate_number TEXT NULL,
            remarks TEXT NULL,
            completed_at TEXT NULL,
            cancelled_at TEXT NULL,
            cancelled_reason TEXT NULL,
            cancelled_by_user_id INTEGER NULL,
            snapshot_instrument_name TEXT NULL,
            snapshot_instrument_type_name TEXT NULL,
            snapshot_location_id INTEGER NULL,
            snapshot_detail_location_id INTEGER NULL,
            snapshot_location_code TEXT NULL,
            snapshot_location_name TEXT NULL,
            snapshot_detail_location_code TEXT NULL,
            snapshot_detail_location_name TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_provider (
            calibration_provider_id INTEGER PRIMARY KEY,
            provider_code VARCHAR(50) NOT NULL,
            provider_name VARCHAR(100) NOT NULL,
            provider_type VARCHAR(50) NULL
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
            configuration_confirmed_by_user_id INTEGER NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_standard_usage (
            calibration_standard_usage_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_activity (
            calibration_activity_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_environment_parameter_snapshot (
            calibration_id INTEGER NOT NULL,
            parameter_id INTEGER NOT NULL,
            parameter_code VARCHAR(50) NOT NULL,
            parameter_name VARCHAR(150) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            data_type VARCHAR(20) NOT NULL,
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
            numeric_value NUMERIC NULL,
            text_value TEXT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_scope (
            calibration_scope_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            test_profile_id INTEGER NULL,
            test_profile_version_id INTEGER NULL,
            test_table_snapshot TEXT NULL,
            scope_order INTEGER NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_test_result (
            calibration_test_result_id INTEGER PRIMARY KEY,
            calibration_scope_id INTEGER NOT NULL,
            test_point_id INTEGER NOT NULL,
            average_value TEXT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_result_value (
            calibration_result_value_id INTEGER PRIMARY KEY,
            calibration_test_result_id INTEGER NOT NULL,
            column_key VARCHAR(63) NOT NULL,
            numeric_value NUMERIC NULL,
            text_value TEXT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_external_document (
            document_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            storage_uuid VARCHAR(36) NOT NULL,
            storage_path TEXT NOT NULL,
            original_filename TEXT NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            byte_size INTEGER NOT NULL,
            sha256 VARCHAR(64) NOT NULL,
            is_active INTEGER NOT NULL,
            uploaded_at TEXT NOT NULL,
            superseded_at TEXT NULL,
            uploaded_by_user_id INTEGER NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE certificate (
            certificate_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            certificate_number VARCHAR(50) NOT NULL
        )
    ');

    return $connection;
}

function sessionCompleteService(): CompleteCalibrationSession
{
    return app(CompleteCalibrationSession::class);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedSessionComplete(Connection $connection, array $overrides = []): void
{
    $connection->table('calibration')->insert(array_merge([
        'calibration_id' => 40,
        'instrument_id' => 3,
        'instrument_type_id' => 12,
        'calibration_provider_id' => null,
        'calibration_number' => 'CAL.VT0040/10/26',
        'calibration_date' => null,
        'calibration_due' => null,
        'calibration_method' => Calibration::METHOD_EXTERNAL,
        'calibration_result' => null,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'certificate_number' => 'CERT-1',
        'remarks' => 'leave me',
        'completed_at' => null,
        'cancelled_at' => null,
        'cancelled_reason' => null,
        'cancelled_by_user_id' => null,
        'snapshot_instrument_name' => 'Gauge',
        'snapshot_instrument_type_name' => 'Pressure',
        'snapshot_location_id' => 4,
        'snapshot_detail_location_id' => 8,
        'snapshot_location_code' => 'LAB',
        'snapshot_location_name' => 'Laboratory',
        'snapshot_detail_location_code' => 'B1',
        'snapshot_detail_location_name' => 'Bench 1',
        'created_at' => '2026-10-22 08:00:00',
        'updated_at' => '2026-10-22 08:00:00',
    ], $overrides));
}

function seedSessionCompleteProvider(Connection $connection, int $providerId = 77): void
{
    $connection->table('calibration_provider')->insert([
        'calibration_provider_id' => $providerId,
        'provider_code' => 'INTERNAL_TEAM',
        'provider_name' => 'Internal Calibration Team',
        'provider_type' => 'internal',
    ]);
}

/**
 * @param  array<string, string|array{frozen: string, resolved: string|null}>  $modes
 */
function seedSessionCompleteRequirements(Connection $connection, array $modes = [], bool $confirmEnvironment = true): void
{
    $defaults = [
        'standards' => 'optional',
        'activity' => 'optional',
        'environment' => 'optional',
        'test_profile' => 'optional',
    ];
    $snapshotId = 1;

    foreach (array_merge($defaults, $modes) as $category => $mode) {
        $frozen = is_array($mode) ? $mode['frozen'] : $mode;
        $resolved = is_array($mode) ? $mode['resolved'] : null;
        $connection->table('calibration_requirement_snapshot')->insert([
            'calibration_requirement_snapshot_id' => $snapshotId,
            'calibration_id' => 40,
            'requirement_category' => $category,
            'frozen_mode' => $frozen,
            'resolved_mode' => $resolved,
            'resolved_at' => $resolved === null ? null : '2026-10-22 09:00:00',
            'resolved_reason' => $resolved === null ? null : 'chosen at the bench',
            'resolved_by_user_id' => $resolved === null ? null : 90,
            'configuration_confirmed_at' => $category === 'environment' && $confirmEnvironment ? '2026-10-22 09:30:00' : null,
            'configuration_confirmed_reason' => $category === 'environment' && $confirmEnvironment ? 'bench confirmed' : null,
            'configuration_confirmed_by_user_id' => $category === 'environment' && $confirmEnvironment ? 90 : null,
        ]);
        $snapshotId++;
    }
}

function seedSessionCompleteDocument(Connection $connection, int $documentId = 1, int $active = 1): void
{
    $connection->table('calibration_external_document')->insert([
        'document_id' => $documentId,
        'calibration_id' => 40,
        'storage_uuid' => sprintf('11111111-1111-1111-1111-%012d', $documentId),
        'storage_path' => 'missing/not-on-disk.pdf',
        'original_filename' => 'vendor.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => 10,
        'sha256' => str_repeat('ab', 32),
        'is_active' => $active,
        'uploaded_at' => '2026-10-22 08:00:00',
        'superseded_at' => $active === 1 ? null : '2026-10-22 08:30:00',
        'uploaded_by_user_id' => null,
    ]);
}

function seedSessionCompleteParameter(
    Connection $connection,
    string $dataType,
    string $requirementMode = 'required',
    int $inclusionConfirmed = 1,
    int $parameterId = 5,
): void {
    $connection->table('calibration_environment_parameter_snapshot')->insert([
        'calibration_id' => 40,
        'parameter_id' => $parameterId,
        'parameter_code' => $dataType === 'text' ? 'note' : 'temperature',
        'parameter_name' => $dataType === 'text' ? 'Note' : 'Temperature',
        'unit' => $dataType === 'text' ? '-' : 'C',
        'data_type' => $dataType,
        'requirement_mode' => $requirementMode,
        'display_order' => 1,
        'inclusion_confirmed' => $inclusionConfirmed,
    ]);
}

function seedSessionCompleteEnvironmentValue(
    Connection $connection,
    mixed $numeric = null,
    ?string $text = null,
    int $parameterId = 5,
): void {
    $connection->table('calibration_environment_value')->insert([
        'calibration_environment_value_id' => 1,
        'calibration_id' => 40,
        'parameter_id' => $parameterId,
        'numeric_value' => $numeric,
        'text_value' => $text,
    ]);
}

/**
 * @param  array<string, mixed>  $snapshot
 */
function seedSessionCompleteScope(Connection $connection, array $snapshot, ?int $versionId = 100): void
{
    $connection->table('calibration_scope')->insert([
        'calibration_scope_id' => 7,
        'calibration_id' => 40,
        'test_profile_id' => 10,
        'test_profile_version_id' => $versionId,
        'test_table_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        'scope_order' => 1,
    ]);
}

/**
 * @return array<string, mixed>
 */
function sessionCompleteSnapshot(int|string $schemaVersion, string $rule, string $columnKey = 'reading'): array
{
    return [
        'schema_version' => $schemaVersion,
        'columns' => [[
            'column_key' => $columnKey,
            'completion_rule' => $rule,
            'formula_code' => 'average',
            'formula_inputs' => ['reading'],
        ]],
        'rows' => [[
            'test_point_id' => 501,
            'point_order' => 1,
        ]],
    ];
}

function seedSessionCompleteResult(Connection $connection, mixed $numeric = null, ?string $text = null, bool $withValue = true): void
{
    $connection->table('calibration_test_result')->insert([
        'calibration_test_result_id' => 9,
        'calibration_scope_id' => 7,
        'test_point_id' => 501,
        'average_value' => null,
    ]);

    if (! $withValue) {
        return;
    }

    $connection->table('calibration_result_value')->insert([
        'calibration_result_value_id' => 4,
        'calibration_test_result_id' => 9,
        'column_key' => 'reading',
        'numeric_value' => $numeric,
        'text_value' => $text,
    ]);
}

function seedSessionCompleteInternal(Connection $connection, array $modes = [], bool $confirmEnvironment = true): void
{
    seedSessionCompleteProvider($connection);
    seedSessionComplete($connection, [
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'calibration_provider_id' => 77,
    ]);
    seedSessionCompleteRequirements($connection, $modes, $confirmEnvironment);
}

function sessionCompleteSql(Connection $connection): string
{
    return implode("\n", array_column($connection->getQueryLog(), 'query'));
}

/**
 * @param  array<string, mixed>  $before
 * @param  array<string, mixed>  $after
 */
function sessionCompleteExpectOnlyCompletionColumns(array $before, array $after, string $date, string $due): void
{
    expect($after['session_status'])->toBe(Calibration::STATUS_COMPLETED)
        ->and($after['calibration_date'])->toBe($date)
        ->and($after['calibration_due'])->toBe($due)
        ->and($after['completed_at'])->not->toBeNull();

    unset(
        $before['session_status'],
        $before['calibration_date'],
        $before['calibration_due'],
        $before['completed_at'],
        $after['session_status'],
        $after['calibration_date'],
        $after['calibration_due'],
        $after['completed_at'],
    );

    expect($after)->toBe($before);
}

it('completes an external session from one active pdf without internal requirements', function (string $due) {
    $connection = sessionCompleteConnection();
    seedSessionComplete($connection);
    seedSessionCompleteDocument($connection);
    $before = (array) $connection->table('calibration')->where('calibration_id', 40)->first();
    $pdf = storage_path('app/private/calibration-documents/missing/not-on-disk.pdf');
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    sessionCompleteService()->complete(40, '2026-10-22', $due, $connection);

    $after = (array) $connection->table('calibration')->where('calibration_id', 40)->first();
    $sql = sessionCompleteSql($connection);
    $calibrationPosition = strpos($sql, 'from "calibration"');
    $documentPosition = strpos($sql, 'from "calibration_external_document"');
    $updatePosition = strpos($sql, 'update "calibration"');

    sessionCompleteExpectOnlyCompletionColumns($before, $after, '2026-10-22', $due);
    expect(is_file($pdf))->toBeFalse()
        ->and($connection->table('certificate')->count())->toBe(0)
        ->and($connection->table('calibration_provider')->count())->toBe(0)
        ->and($sql)->not->toContain('calibration_requirement_snapshot')
        ->and($sql)->not->toContain('calibration_standard_usage')
        ->and($sql)->not->toContain('calibration_activity')
        ->and($sql)->not->toContain('calibration_scope')
        ->and($sql)->not->toContain('calibration_environment')
        ->and($sql)->not->toContain('certificate')
        ->and($sql)->not->toContain('insert into')
        ->and($sql)->not->toContain('update "calibration_')
        ->and($calibrationPosition)->toBeInt()
        ->and($documentPosition)->toBeInt()
        ->and($updatePosition)->toBeInt()
        ->and($calibrationPosition)->toBeLessThan($documentPosition)
        ->and($documentPosition)->toBeLessThan($updatePosition)
        ->and(sessionCompleteSql($default))->not->toContain('calibration')
        ->and(Schema::connection('sqlite')->hasTable('calibration_external_document'))->toBeFalse()
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'due after the calibration date' => '2026-10-23',
    'due on the calibration date' => '2026-10-22',
]);

it('rejects an external session unless exactly one pdf is active', function (int $activeDocuments) {
    $connection = sessionCompleteConnection();
    seedSessionComplete($connection);
    foreach (range(1, $activeDocuments) as $documentId) {
        seedSessionCompleteDocument($connection, $documentId);
    }
    $before = (array) $connection->table('calibration')->where('calibration_id', 40)->first();

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2026-10-23', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, 'External complete requires exactly one active vendor PDF.');

    expect((array) $connection->table('calibration')->where('calibration_id', 40)->first())->toBe($before)
        ->and($connection->table('certificate')->count())->toBe(0);
})->with([
    'none' => 0,
    'two' => 2,
]);

it('completes an internal session when every category is optional and nothing is pinned', function () {
    $connection = sessionCompleteConnection();
    seedSessionCompleteInternal($connection);
    $before = (array) $connection->table('calibration')->where('calibration_id', 40)->first();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    sessionCompleteService()->complete(40, '2026-10-22', '2027-10-22', $connection);

    $sql = sessionCompleteSql($connection);
    $calibrationPosition = strpos($sql, 'from "calibration"');
    $requirementPosition = strpos($sql, 'from "calibration_requirement_snapshot"');
    $providerPosition = strpos($sql, 'from "calibration_provider"');
    $parameterPosition = strpos($sql, 'from "calibration_environment_parameter_snapshot"');
    $valuePosition = strpos($sql, 'from "calibration_environment_value"');
    $scopePosition = strpos($sql, 'from "calibration_scope"');
    $updatePosition = strpos($sql, 'update "calibration"');
    $after = (array) $connection->table('calibration')->where('calibration_id', 40)->first();

    sessionCompleteExpectOnlyCompletionColumns($before, $after, '2026-10-22', '2027-10-22');
    expect($connection->table('calibration_standard_usage')->count())->toBe(0)
        ->and($connection->table('calibration_activity')->count())->toBe(0)
        ->and($connection->table('calibration_scope')->count())->toBe(0)
        ->and($connection->table('certificate')->count())->toBe(0)
        ->and($sql)->not->toContain('calibration_standard_usage')
        ->and($sql)->not->toContain('calibration_activity')
        ->and($sql)->not->toContain('calibration_test_result')
        ->and($sql)->not->toContain('calibration_result_value')
        ->and($sql)->not->toContain('certificate')
        ->and($sql)->not->toContain('insert into')
        ->and($sql)->not->toContain('update "calibration_')
        ->and($calibrationPosition)->toBeLessThan($requirementPosition)
        ->and($requirementPosition)->toBeLessThan($providerPosition)
        ->and($providerPosition)->toBeLessThan($parameterPosition)
        ->and($parameterPosition)->toBeLessThan($valuePosition)
        ->and($valuePosition)->toBeLessThan($scopePosition)
        ->and($scopePosition)->toBeLessThan($updatePosition)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('completes an internal session whose not configured requirements were resolved to optional', function () {
    $connection = sessionCompleteConnection();
    seedSessionCompleteInternal($connection, [
        'standards' => ['frozen' => 'not_configured', 'resolved' => 'optional'],
        'activity' => ['frozen' => 'not_configured', 'resolved' => 'optional'],
        'environment' => ['frozen' => 'not_configured', 'resolved' => 'optional'],
        'test_profile' => ['frozen' => 'not_configured', 'resolved' => 'optional'],
    ]);

    sessionCompleteService()->complete(40, '2026-10-22', '2027-10-22', $connection);

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_COMPLETED)
        ->and($connection->table('calibration_requirement_snapshot')->where('requirement_category', 'standards')->value('resolved_mode'))->toBe('optional');
});

it('rejects an internal session whose provider is not the internal team', function (?int $providerId, bool $seedTeam) {
    $connection = sessionCompleteConnection();
    if ($seedTeam) {
        seedSessionCompleteProvider($connection);
    }
    seedSessionComplete($connection, [
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'calibration_provider_id' => $providerId,
    ]);
    seedSessionCompleteRequirements($connection);

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2027-10-22', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, 'Internal complete requires Internal Calibration Team.');

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($connection->table('calibration_provider')->count())->toBe($seedTeam ? 1 : 0);
})->with([
    'wrong id' => [3, true],
    'missing id' => [null, true],
    'missing team' => [77, false],
]);

it('rejects an internal session that is missing a requirement category', function () {
    $connection = sessionCompleteConnection();
    seedSessionCompleteProvider($connection);
    seedSessionComplete($connection, [
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'calibration_provider_id' => 77,
    ]);
    seedSessionCompleteRequirements($connection);
    $connection->table('calibration_requirement_snapshot')->where('requirement_category', 'test_profile')->delete();

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2027-10-22', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, 'All four requirement categories must be frozen at start.');

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('rejects an unresolved or unsupported requirement mode', function (array $standards) {
    $connection = sessionCompleteConnection();
    seedSessionCompleteInternal($connection, ['standards' => $standards]);

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2027-10-22', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, 'The standards requirement is not configured.');

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($connection->table('calibration_requirement_snapshot')->where('requirement_category', 'standards')->value('frozen_mode'))->toBe('not_configured');
})->with([
    'unresolved' => [['frozen' => 'not_configured', 'resolved' => null]],
    'unsupported resolved mode' => [['frozen' => 'not_configured', 'resolved' => 'mandatory']],
]);

it('rejects required standards or activity when no rows exist', function (string $category, string $message) {
    $connection = sessionCompleteConnection();
    seedSessionCompleteInternal($connection, [$category => 'required']);

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2027-10-22', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, $message);

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($connection->table('calibration_standard_usage')->count())->toBe(0)
        ->and($connection->table('calibration_activity')->count())->toBe(0);
})->with([
    'standards' => ['standards', 'Required standards need at least one standard.'],
    'activity' => ['activity', 'Required calibration activity needs at least one activity.'],
]);

it('rejects an internal session whose environment configuration is not confirmed', function () {
    $connection = sessionCompleteConnection();
    seedSessionCompleteInternal($connection, [], false);

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2027-10-22', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, 'Environment requires explicit configuration confirmation before complete.');

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($connection->table('calibration_requirement_snapshot')->where('requirement_category', 'environment')->value('configuration_confirmed_at'))->toBeNull();
});

it('rejects an environment parameter whose inclusion is not confirmed', function () {
    $connection = sessionCompleteConnection();
    seedSessionCompleteInternal($connection);
    seedSessionCompleteParameter($connection, 'numeric', 'optional', 0);
    seedSessionCompleteEnvironmentValue($connection, 21);

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2027-10-22', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, 'Unconfirmed environment parameter inclusions block complete.');

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($connection->table('calibration_environment_parameter_snapshot')->value('inclusion_confirmed'))->toBe(0);
});

it('rejects required environment when no confirmed required parameter exists', function () {
    $connection = sessionCompleteConnection();
    seedSessionCompleteInternal($connection, ['environment' => 'required']);
    seedSessionCompleteParameter($connection, 'numeric', 'optional');

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2027-10-22', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, 'Required environment needs at least one confirmed required parameter.');

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('rejects a required environment parameter without its typed value', function (string $dataType, mixed $numeric, ?string $text) {
    $connection = sessionCompleteConnection();
    seedSessionCompleteInternal($connection, ['environment' => 'required']);
    seedSessionCompleteParameter($connection, $dataType);
    if ($numeric !== null || $text !== null) {
        seedSessionCompleteEnvironmentValue($connection, $numeric, $text);
    }

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2027-10-22', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, 'Required environment parameters are missing values.');

    expect($connection->table('calibration')->where('calibration_id', 40)->value('calibration_date'))->toBeNull();
})->with([
    'numeric without a value' => ['numeric', null, null],
    'text without a value' => ['text', null, null],
    'blank text' => ['text', null, '   '],
]);

it('completes an internal session when the required environment value is present', function (string $dataType, mixed $numeric, ?string $text) {
    $connection = sessionCompleteConnection();
    seedSessionCompleteInternal($connection, ['environment' => 'required']);
    seedSessionCompleteParameter($connection, $dataType);
    seedSessionCompleteEnvironmentValue($connection, $numeric, $text);

    sessionCompleteService()->complete(40, '2026-10-22', '2027-10-22', $connection);

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_COMPLETED)
        ->and($connection->table('calibration_environment_value')->count())->toBe(1)
        ->and($connection->table('calibration_environment_parameter_snapshot')->value('requirement_mode'))->toBe('required');
})->with([
    'numeric' => ['numeric', 21.5, null],
    'text' => ['text', null, 'bench'],
]);

it('rejects a required test profile that has no fully pinned scope', function () {
    $connection = sessionCompleteConnection();
    seedSessionCompleteInternal($connection, ['test_profile' => 'required']);

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2027-10-22', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, 'Required test profile must be pinned.');

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($connection->table('calibration_test_result')->count())->toBe(0);
});

it('rejects a pinned required component that is missing its result or value', function (bool $withResult) {
    $connection = sessionCompleteConnection();
    seedSessionCompleteInternal($connection, ['test_profile' => 'required']);
    seedSessionCompleteScope($connection, sessionCompleteSnapshot(2, 'required'));
    if ($withResult) {
        seedSessionCompleteResult($connection, null, null, false);
    }

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2027-10-22', $connection))
        ->toThrow(
            CompleteCalibrationSessionException::class,
            $withResult
                ? 'Required test component reading is missing an engineer-entered value.'
                : 'Required test component reading is missing a result row.',
        );

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($connection->table('calibration_test_result')->value('average_value'))->toBeNull();
})->with([
    'no result row' => false,
    'no result value' => true,
]);

it('completes a pinned required component when its entered value is present', function (mixed $numeric, ?string $text) {
    $connection = sessionCompleteConnection();
    seedSessionCompleteInternal($connection, ['test_profile' => 'required']);
    seedSessionCompleteScope($connection, sessionCompleteSnapshot(2, 'required'));
    seedSessionCompleteResult($connection, $numeric, $text);
    $connection->enableQueryLog();

    sessionCompleteService()->complete(40, '2026-10-22', '2027-10-22', $connection);

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_COMPLETED)
        ->and($connection->table('calibration_test_result')->value('average_value'))->toBeNull()
        ->and($connection->table('calibration_result_value')->count())->toBe(1)
        ->and(sessionCompleteSql($connection))->not->toContain('update "calibration_result_value"')
        ->and(sessionCompleteSql($connection))->not->toContain('insert into');
})->with([
    'numeric' => [12, null],
    'text' => [null, 'pass'],
]);

it('rejects a pinned snapshot whose schema or completion rule cannot be completed', function (int|string $schemaVersion, string $rule, string $message) {
    $connection = sessionCompleteConnection();
    seedSessionCompleteInternal($connection);
    seedSessionCompleteScope($connection, sessionCompleteSnapshot($schemaVersion, $rule));

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2027-10-22', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, $message);

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
})->with([
    'schema version 1' => [1, 'required', 'A used test profile without schema version 2 completion rules cannot be completed.'],
    'unconfigured rule' => [2, 'not_configured', 'An unconfigured test component blocks complete.'],
]);

it('rejects a session whose frozen identity or location is missing', function (array $overrides) {
    $connection = sessionCompleteConnection();
    seedSessionComplete($connection, $overrides);
    seedSessionCompleteDocument($connection);

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2026-10-23', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, 'Complete requires a frozen instrument identity and location snapshot.');

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
})->with([
    'blank instrument name' => [['snapshot_instrument_name' => '   ']],
    'null instrument type name' => [['snapshot_instrument_type_name' => null]],
    'null location' => [['snapshot_location_id' => null]],
    'blank location code' => [['snapshot_location_code' => '']],
    'null detail location name' => [['snapshot_detail_location_name' => null]],
]);

it('rejects dates that are blank, impossible, or out of order before reading the session', function (string $date, string $due, string $message) {
    $connection = sessionCompleteConnection();
    seedSessionComplete($connection);
    seedSessionCompleteDocument($connection);
    $connection->enableQueryLog();

    expect(fn () => sessionCompleteService()->complete(40, $date, $due, $connection))
        ->toThrow(CompleteCalibrationSessionException::class, $message);

    expect(sessionCompleteSql($connection))->not->toContain('from "calibration"')
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
})->with([
    'blank date' => ['', '2026-10-23', 'The calibration date and calibration due must be valid dates in YYYY-MM-DD format.'],
    'blank due' => ['2026-10-22', '   ', 'The calibration date and calibration due must be valid dates in YYYY-MM-DD format.'],
    'impossible day' => ['2026-02-31', '2026-03-01', 'The calibration date and calibration due must be valid dates in YYYY-MM-DD format.'],
    'impossible month' => ['2026-13-01', '2026-13-02', 'The calibration date and calibration due must be valid dates in YYYY-MM-DD format.'],
    'due before the calibration date' => ['2026-10-23', '2026-10-22', 'Calibration due must be on or after the calibration date.'],
]);

it('rejects a session that is no longer in progress', function (string $status) {
    $connection = sessionCompleteConnection();
    seedSessionComplete($connection, [
        'session_status' => $status,
        'calibration_date' => '2020-01-01',
        'calibration_due' => '2021-01-01',
        'completed_at' => $status === Calibration::STATUS_COMPLETED ? '2020-01-01 00:00:00' : null,
        'cancelled_at' => $status === Calibration::STATUS_CANCELLED ? '2020-01-01 00:00:00' : null,
        'cancelled_reason' => $status === Calibration::STATUS_CANCELLED ? 'stopped' : null,
        'cancelled_by_user_id' => $status === Calibration::STATUS_CANCELLED ? 90 : null,
    ]);
    seedSessionCompleteDocument($connection);

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2026-10-23', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, 'Only an in-progress calibration session can be completed.');

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe($status)
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('calibration_date'))->toBe('2020-01-01')
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('cancelled_reason'))->toBe($status === Calibration::STATUS_CANCELLED ? 'stopped' : null);
})->with([
    'completed' => Calibration::STATUS_COMPLETED,
    'cancelled' => Calibration::STATUS_CANCELLED,
    'unsupported' => 'archived',
]);

it('rejects a second completion without changing the first result', function () {
    $connection = sessionCompleteConnection();
    seedSessionComplete($connection);
    seedSessionCompleteDocument($connection);
    sessionCompleteService()->complete(40, '2026-10-22', '2027-10-22', $connection);
    $completedAt = $connection->table('calibration')->where('calibration_id', 40)->value('completed_at');

    expect(fn () => sessionCompleteService()->complete(40, '2026-11-01', '2026-12-01', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, 'Only an in-progress calibration session can be completed.');

    expect($connection->table('calibration')->where('calibration_id', 40)->value('completed_at'))->toBe($completedAt)
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('calibration_date'))->toBe('2026-10-22')
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('calibration_due'))->toBe('2027-10-22');
});

it('rejects a missing calibration', function () {
    $connection = sessionCompleteConnection();

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2026-10-23', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, 'The calibration does not exist.');
});

it('rejects a calibration id below 1 before reading the session', function (int $calibrationId) {
    $connection = sessionCompleteConnection();
    seedSessionComplete($connection);
    $connection->enableQueryLog();

    expect(fn () => sessionCompleteService()->complete($calibrationId, '2026-10-22', '2026-10-23', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, 'The calibration id must be at least 1.');

    expect(sessionCompleteSql($connection))->not->toContain('from "calibration"')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects an unsupported calibration method', function () {
    $connection = sessionCompleteConnection();
    seedSessionComplete($connection, ['calibration_method' => 'FIELD']);

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2026-10-23', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, 'Only an internal or external calibration session can be completed.');

    expect($connection->table('calibration')->where('calibration_id', 40)->value('calibration_method'))->toBe('FIELD')
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('propagates an unsafe connection exception before querying the session', function () {
    $connection = sessionCompleteConnection();
    seedSessionComplete($connection);
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('table');
    $unsafe->shouldNotReceive('transaction');

    expect(fn () => sessionCompleteService()->complete(40, '2026-10-22', '2026-10-23', $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('completes through the supplied connection when its name is empty or unregistered', function (string $connectionName) {
    $connection = sessionCompleteConnection();
    seedSessionComplete($connection);
    seedSessionCompleteDocument($connection);
    $config = new ReflectionProperty($connection, 'config');
    $settings = $config->getValue($connection);
    $settings['name'] = $connectionName;
    $config->setValue($connection, $settings);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    sessionCompleteService()->complete(40, '2026-10-22', '2026-10-23', $connection);

    expect($connection->getName())->toBe($connectionName)
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_COMPLETED)
        ->and(sessionCompleteSql($connection))->toContain('update "calibration"')
        ->and(sessionCompleteSql($default))->not->toContain('calibration')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty name' => '',
    'unregistered name' => 'unowned-complete-calibration',
]);
