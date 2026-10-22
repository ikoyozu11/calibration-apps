<?php

use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\PinCalibrationTestTables;
use App\CalibrationSessions\PinCalibrationTestTablesException;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use App\TestColumns\TestTableSnapshotException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestRefusingPostgresConnector;

const PIN_TEST_TABLES_CONNECTION = 'pin_test_tables_sqlite';

function pinTestTablesConnection(): Connection
{
    config([
        'database.connections.'.PIN_TEST_TABLES_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(PIN_TEST_TABLES_CONNECTION);

    $connection = DB::connection(PIN_TEST_TABLES_CONNECTION);

    $connection->unprepared('
        CREATE TABLE calibration (
            calibration_id INTEGER PRIMARY KEY,
            session_status VARCHAR(20) NOT NULL,
            calibration_method VARCHAR(20) NOT NULL,
            instrument_type_id INTEGER NULL,
            calibration_date TEXT NULL,
            calibration_due TEXT NULL
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
            UNIQUE (calibration_id, requirement_category)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_scope (
            calibration_scope_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            instrument_type_id INTEGER NOT NULL,
            test_profile_id INTEGER NULL,
            test_profile_version_id INTEGER NULL,
            test_table_snapshot TEXT NULL,
            scope_order INTEGER NOT NULL,
            remarks TEXT NULL,
            created_at TEXT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE test_profile (
            test_profile_id INTEGER PRIMARY KEY,
            instrument_type_id INTEGER NOT NULL,
            profile_name VARCHAR(150) NOT NULL,
            display_title VARCHAR(150) NULL,
            description TEXT NULL,
            archived_at TEXT NULL,
            created_at TEXT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE test_profile_version (
            test_profile_version_id INTEGER PRIMARY KEY,
            test_profile_id INTEGER NOT NULL,
            version_number INTEGER NOT NULL,
            status VARCHAR(20) NOT NULL,
            profile_name VARCHAR(150) NOT NULL,
            display_title VARCHAR(150) NULL,
            description TEXT NULL,
            published_at TEXT NULL,
            created_at TEXT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE test_column (
            test_column_id INTEGER PRIMARY KEY,
            test_profile_id INTEGER NOT NULL,
            test_profile_version_id INTEGER NOT NULL,
            column_key VARCHAR(63) NOT NULL,
            column_label VARCHAR(150) NOT NULL,
            column_order INTEGER NOT NULL,
            data_type VARCHAR(20) NOT NULL,
            value_source VARCHAR(20) NOT NULL,
            unit VARCHAR(20) NULL,
            display_scale INTEGER NULL,
            formula_code VARCHAR(40) NULL,
            formula_inputs TEXT NULL,
            completion_rule VARCHAR(20) NOT NULL,
            created_at TEXT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE test_point (
            test_point_id INTEGER PRIMARY KEY,
            test_profile_id INTEGER NOT NULL,
            test_profile_version_id INTEGER NOT NULL,
            point_order INTEGER NOT NULL,
            nominal_value TEXT NULL,
            unit VARCHAR(20) NULL,
            tolerance_plus TEXT NULL,
            tolerance_minus TEXT NULL,
            description TEXT NULL,
            created_at TEXT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE test_point_value (
            test_point_value_id INTEGER PRIMARY KEY,
            test_profile_id INTEGER NOT NULL,
            test_profile_version_id INTEGER NOT NULL,
            test_point_id INTEGER NOT NULL,
            test_column_id INTEGER NOT NULL,
            numeric_value TEXT NULL,
            text_value TEXT NULL,
            unit VARCHAR(20) NULL,
            created_at TEXT NULL
        )
    ');

    return $connection;
}

function pinTestTables(): PinCalibrationTestTables
{
    return app(PinCalibrationTestTables::class);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedPinSession(Connection $connection, array $overrides = []): void
{
    $connection->table('calibration')->insert(array_merge([
        'calibration_id' => 40,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'instrument_type_id' => 12,
        'calibration_date' => '2026-10-22',
        'calibration_due' => '2027-10-22',
    ], $overrides));
}

function seedPinRequirement(
    Connection $connection,
    string $frozenMode = 'required',
    ?string $resolvedMode = null,
): void {
    $connection->table('calibration_requirement_snapshot')->insert([
        'calibration_requirement_snapshot_id' => 1,
        'calibration_id' => 40,
        'requirement_category' => InstrumentTypeRequirement::CATEGORY_TEST_PROFILE,
        'frozen_mode' => $frozenMode,
        'resolved_mode' => $resolvedMode,
        'resolved_at' => $resolvedMode === null ? null : '2026-10-22 08:00:00',
        'resolved_reason' => $resolvedMode === null ? null : 'chosen at the bench',
        'resolved_by_user_id' => $resolvedMode === null ? null : 90,
    ]);
}

function seedActivePinTable(
    Connection $connection,
    int $profileId,
    int $versionId,
    string $status = 'active',
    ?string $archivedAt = null,
    int $typeId = 12,
): void {
    $connection->table('test_profile')->insert([
        'test_profile_id' => $profileId,
        'instrument_type_id' => $typeId,
        'profile_name' => 'Profile '.$profileId,
        'display_title' => 'Table '.$profileId,
        'description' => 'Frozen description '.$profileId,
        'archived_at' => $archivedAt,
        'created_at' => '2026-10-22 07:00:00',
    ]);
    $connection->table('test_profile_version')->insert([
        'test_profile_version_id' => $versionId,
        'test_profile_id' => $profileId,
        'version_number' => 1,
        'status' => $status,
        'profile_name' => 'Profile '.$profileId,
        'display_title' => 'Table '.$profileId,
        'description' => 'Frozen description '.$profileId,
        'published_at' => '2026-10-22 07:30:00',
        'created_at' => '2026-10-22 07:00:00',
    ]);
    $connection->table('test_column')->insert([
        [
            'test_column_id' => ($versionId * 10) + 1,
            'test_profile_id' => $profileId,
            'test_profile_version_id' => $versionId,
            'column_key' => 'standard_value',
            'column_label' => 'Standard',
            'column_order' => 1,
            'data_type' => 'numeric',
            'value_source' => 'template',
            'unit' => 'kPa',
            'display_scale' => 1,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'completion_rule' => 'optional',
            'created_at' => '2026-10-22 07:00:00',
        ],
        [
            'test_column_id' => ($versionId * 10) + 2,
            'test_profile_id' => $profileId,
            'test_profile_version_id' => $versionId,
            'column_key' => 'reading',
            'column_label' => 'Reading',
            'column_order' => 2,
            'data_type' => 'numeric',
            'value_source' => 'engineer',
            'unit' => 'kPa',
            'display_scale' => 1,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'completion_rule' => 'required',
            'created_at' => '2026-10-22 07:00:00',
        ],
        [
            'test_column_id' => ($versionId * 10) + 3,
            'test_profile_id' => $profileId,
            'test_profile_version_id' => $versionId,
            'column_key' => 'average',
            'column_label' => 'Average',
            'column_order' => 3,
            'data_type' => 'numeric',
            'value_source' => 'calculated',
            'unit' => 'kPa',
            'display_scale' => 1,
            'formula_code' => 'average',
            'formula_inputs' => '["reading"]',
            'completion_rule' => 'optional',
            'created_at' => '2026-10-22 07:00:00',
        ],
        [
            'test_column_id' => ($versionId * 10) + 4,
            'test_profile_id' => $profileId,
            'test_profile_version_id' => $versionId,
            'column_key' => 'deviation',
            'column_label' => 'Deviation',
            'column_order' => 4,
            'data_type' => 'numeric',
            'value_source' => 'calculated',
            'unit' => 'kPa',
            'display_scale' => 1,
            'formula_code' => 'deviation',
            'formula_inputs' => '["standard_value","reading"]',
            'completion_rule' => 'optional',
            'created_at' => '2026-10-22 07:00:00',
        ],
    ]);
    $connection->table('test_point')->insert([
        [
            'test_point_id' => ($versionId * 10) + 1,
            'test_profile_id' => $profileId,
            'test_profile_version_id' => $versionId,
            'point_order' => 1,
            'nominal_value' => '10',
            'unit' => 'kPa',
            'tolerance_plus' => '0.2',
            'tolerance_minus' => '0.2',
            'description' => 'Low',
            'created_at' => '2026-10-22 07:00:00',
        ],
        [
            'test_point_id' => ($versionId * 10) + 2,
            'test_profile_id' => $profileId,
            'test_profile_version_id' => $versionId,
            'point_order' => 2,
            'nominal_value' => '20',
            'unit' => 'kPa',
            'tolerance_plus' => '0.2',
            'tolerance_minus' => '0.2',
            'description' => 'High',
            'created_at' => '2026-10-22 07:00:00',
        ],
    ]);
    $connection->table('test_point_value')->insert([
        'test_point_value_id' => ($versionId * 10) + 1,
        'test_profile_id' => $profileId,
        'test_profile_version_id' => $versionId,
        'test_point_id' => ($versionId * 10) + 1,
        'test_column_id' => ($versionId * 10) + 1,
        'numeric_value' => '10',
        'text_value' => null,
        'unit' => 'kPa',
        'created_at' => '2026-10-22 07:00:00',
    ]);
}

function pinTables(Connection $connection): void
{
    pinTestTables()->pin(40, $connection);
}

function pinScopes(Connection $connection): Collection
{
    return $connection->table('calibration_scope')->orderBy('scope_order')->orderBy('calibration_scope_id')->get();
}

/**
 * @return array<string, mixed>
 */
function pinSnapshot(object $scope): array
{
    $decoded = json_decode((string) $scope->test_table_snapshot, true);

    expect($decoded)->toBeArray();

    return $decoded;
}

function pinSql(Connection $connection): string
{
    return strtolower(implode("\n", array_column($connection->getQueryLog(), 'query')));
}

function pinConfigureTemplate(Connection $connection, int $versionId, string $dataType, string $completionRule): void
{
    $connection->table('test_column')
        ->where('test_column_id', ($versionId * 10) + 1)
        ->update([
            'data_type' => $dataType,
            'completion_rule' => $completionRule,
        ]);
}

function pinReplaceTemplateValues(
    Connection $connection,
    int $profileId,
    int $versionId,
    ?string $numericValue,
    ?string $textValue,
    ?string $unit,
): void {
    $connection->table('test_point_value')
        ->where('test_profile_version_id', $versionId)
        ->delete();

    foreach ([1, 2] as $offset) {
        $connection->table('test_point_value')->insert([
            'test_point_value_id' => ($versionId * 10) + $offset,
            'test_profile_id' => $profileId,
            'test_profile_version_id' => $versionId,
            'test_point_id' => ($versionId * 10) + $offset,
            'test_column_id' => ($versionId * 10) + 1,
            'numeric_value' => $numericValue,
            'text_value' => $textValue,
            'unit' => $unit,
            'created_at' => '2026-10-22 07:00:00',
        ]);
    }
}

it('pins every active test table for an in-progress internal session', function (string $frozenMode) {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection, $frozenMode);
    seedActivePinTable($connection, 20, 200);
    seedActivePinTable($connection, 10, 100);
    $connection->table('test_profile_version')->insert([
        'test_profile_version_id' => 101,
        'test_profile_id' => 10,
        'version_number' => 2,
        'status' => 'draft',
        'profile_name' => 'Draft',
        'display_title' => null,
        'description' => null,
        'published_at' => null,
        'created_at' => '2026-10-22 07:10:00',
    ]);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    pinTables($connection);

    $scopes = pinScopes($connection);
    $first = pinSnapshot($scopes[0]);
    $sql = pinSql($connection);
    $calibrationPosition = strpos($sql, 'from "calibration"');
    $requirementPosition = strpos($sql, 'from "calibration_requirement_snapshot"');
    $profilePosition = strpos($sql, 'from "test_profile"');
    $versionPosition = strpos($sql, 'from "test_profile_version"');
    $scopePosition = strpos($sql, 'from "calibration_scope"');
    $insertPosition = strpos($sql, 'insert into "calibration_scope"');

    expect($scopes)->toHaveCount(2)
        ->and($scopes[0]->test_profile_id)->toBe(10)
        ->and($scopes[0]->test_profile_version_id)->toBe(100)
        ->and($scopes[0]->scope_order)->toBe(1)
        ->and($scopes[1]->test_profile_id)->toBe(20)
        ->and($scopes[1]->test_profile_version_id)->toBe(200)
        ->and($scopes[1]->scope_order)->toBe(2)
        ->and($first['schema_version'])->toBe(2)
        ->and(array_column($first['columns'], 'column_key'))->toBe([
            'standard_value',
            'reading',
            'average',
            'deviation',
        ])
        ->and(array_column($first['columns'], 'column_order'))->toBe([1, 2, 3, 4])
        ->and($first['columns'][2]['formula_code'])->toBe('average')
        ->and($first['columns'][2]['formula_inputs'])->toBe(['reading'])
        ->and($first['columns'][3]['formula_code'])->toBe('deviation')
        ->and($first['columns'][3]['formula_inputs'])->toBe(['standard_value', 'reading'])
        ->and(array_column($first['rows'], 'point_order'))->toBe([1, 2])
        ->and($first['rows'][0]['template_values']['standard_value']['numeric_value'])->toBe('10')
        ->and($first['rows'][0]['nominal_value'])->toBe('10')
        ->and($first)->not->toHaveKey('average_value')
        ->and($connection->table('calibration')->value('session_status'))->toBe('in_progress')
        ->and($connection->table('calibration')->value('calibration_date'))->toBe('2026-10-22')
        ->and($connection->table('calibration')->value('calibration_due'))->toBe('2027-10-22')
        ->and($connection->table('calibration_requirement_snapshot')->value('frozen_mode'))->toBe($frozenMode)
        ->and($connection->table('calibration_requirement_snapshot')->value('resolved_mode'))->toBeNull()
        ->and($connection->table('test_profile_version')->where('test_profile_version_id', 101)->value('status'))->toBe('draft')
        ->and($connection->table('test_profile_version')->where('test_profile_version_id', 100)->value('status'))->toBe('active')
        ->and($calibrationPosition)->toBeInt()
        ->and($requirementPosition)->toBeInt()
        ->and($profilePosition)->toBeInt()
        ->and($versionPosition)->toBeInt()
        ->and($scopePosition)->toBeInt()
        ->and($insertPosition)->toBeInt()
        ->and($calibrationPosition)->toBeLessThan($requirementPosition)
        ->and($requirementPosition)->toBeLessThan($profilePosition)
        ->and($profilePosition)->toBeLessThan($versionPosition)
        ->and($versionPosition)->toBeLessThan($scopePosition)
        ->and($scopePosition)->toBeLessThan($insertPosition)
        ->and($sql)->not->toContain('update "calibration"')
        ->and($sql)->not->toContain('update "test_profile"')
        ->and($sql)->not->toContain('update "test_profile_version"')
        ->and($sql)->not->toContain('calibration_test_result')
        ->and($sql)->not->toContain('calibration_result_value')
        ->and($sql)->not->toContain('instrument_type_requirement')
        ->and($sql)->not->toContain('certificate')
        ->and(pinSql($default))->not->toContain('calibration_scope')
        ->and(pinSql($default))->not->toContain('test_profile_version')
        ->and(Schema::connection('sqlite')->hasTable('calibration_scope'))->toBeFalse()
        ->and(config('database.default'))->toBe('sqlite')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'required' => 'required',
    'optional' => 'optional',
]);

it('pins a test profile that was resolved from not configured', function (string $resolvedMode) {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection, 'not_configured', $resolvedMode);
    seedActivePinTable($connection, 10, 100);

    pinTables($connection);

    $scope = pinScopes($connection)->sole();

    expect($scope->test_profile_version_id)->toBe(100)
        ->and($connection->table('calibration_requirement_snapshot')->value('resolved_mode'))->toBe($resolvedMode)
        ->and($connection->table('calibration_requirement_snapshot')->value('resolved_reason'))->toBe('chosen at the bench')
        ->and($connection->table('calibration_requirement_snapshot')->value('resolved_by_user_id'))->toBe(90);
})->with([
    'required' => 'required',
    'optional' => 'optional',
]);

it('rejects an unresolved not configured test profile', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection, 'not_configured');
    seedActivePinTable($connection, 10, 100);

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'An unresolved not-configured test profile cannot be pinned.');

    expect(pinScopes($connection))->toBeEmpty();
});

it('rejects an effective mode that is not required or optional', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection, 'not_configured', 'mandatory');
    seedActivePinTable($connection, 10, 100);

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'The test profile requirement mode cannot be pinned.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and($connection->table('calibration_requirement_snapshot')->value('resolved_mode'))->toBe('mandatory');
});

it('rejects a missing test profile requirement snapshot', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedActivePinTable($connection, 10, 100);

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'The test profile requirement snapshot does not exist.');

    expect(pinScopes($connection))->toBeEmpty();
});

it('rejects an external session', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection, ['calibration_method' => Calibration::METHOD_EXTERNAL]);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'Test tables can be pinned only for an internal calibration session.');

    expect(pinScopes($connection))->toBeEmpty();
});

it('rejects a session that is not in progress', function (string $sessionStatus) {
    $connection = pinTestTablesConnection();
    seedPinSession($connection, ['session_status' => $sessionStatus]);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'Only an in-progress calibration session can pin test tables.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and($connection->table('calibration')->value('session_status'))->toBe($sessionStatus);
})->with([
    'completed' => Calibration::STATUS_COMPLETED,
    'cancelled' => Calibration::STATUS_CANCELLED,
]);

it('rejects a missing calibration', function () {
    $connection = pinTestTablesConnection();

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'The calibration does not exist.');
});

it('rejects a calibration id below 1 before reading the session', function (int $calibrationId) {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    $connection->enableQueryLog();

    expect(fn () => pinTestTables()->pin($calibrationId, $connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'The calibration id must be at least 1.');

    expect(pinSql($connection))->not->toContain('from "calibration"')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('skips archived draft and retired profiles and pins only eligible active tables', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100, 'active', '2026-10-22 09:00:00');
    seedActivePinTable($connection, 20, 200, 'draft');
    seedActivePinTable($connection, 30, 300, 'retired');
    seedActivePinTable($connection, 40, 400, 'active');

    pinTables($connection);

    $scopes = pinScopes($connection);

    expect($scopes)->toHaveCount(1)
        ->and($scopes[0]->test_profile_id)->toBe(40)
        ->and($scopes[0]->test_profile_version_id)->toBe(400)
        ->and($connection->table('test_profile')->where('test_profile_id', 10)->value('archived_at'))->not->toBeNull()
        ->and($connection->table('test_profile_version')->where('test_profile_version_id', 200)->value('status'))->toBe('draft')
        ->and($connection->table('test_profile_version')->where('test_profile_version_id', 300)->value('status'))->toBe('retired');
});

it('rejects explicit pinning when no eligible active test table exists', function (string $status, ?string $archivedAt) {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100, $status, $archivedAt);

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'No active Test Table is available for this instrument type.');

    expect(pinScopes($connection))->toBeEmpty();
})->with([
    'archived' => ['active', '2026-10-22 09:00:00'],
    'draft only' => ['draft', null],
    'retired only' => ['retired', null],
]);

it('leaves an existing frozen pin unchanged on a later call and after a newer active version', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinTables($connection);
    $first = pinScopes($connection)->sole();
    $snapshot = (string) $first->test_table_snapshot;
    $connection->table('test_profile_version')->where('test_profile_version_id', 100)->update(['status' => 'retired']);
    $connection->table('test_profile_version')->insert([
        'test_profile_version_id' => 101,
        'test_profile_id' => 10,
        'version_number' => 2,
        'status' => 'active',
        'profile_name' => 'Profile 10 revised',
        'display_title' => 'Revised',
        'description' => null,
        'published_at' => '2026-10-22 10:00:00',
        'created_at' => '2026-10-22 10:00:00',
    ]);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    pinTables($connection);

    $second = pinScopes($connection)->sole();

    expect($second->calibration_scope_id)->toBe($first->calibration_scope_id)
        ->and($second->test_profile_version_id)->toBe(100)
        ->and((string) $second->test_table_snapshot)->toBe($snapshot)
        ->and(pinSnapshot($second)['test_profile_version_id'])->toBe(100)
        ->and(pinSql($connection))->not->toContain('insert into "calibration_scope"')
        ->and($connection->table('test_profile_version')->where('test_profile_version_id', 101)->value('status'))->toBe('active');
});

it('rejects a partially pinned scope without repairing it', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    $connection->table('calibration_scope')->insert([
        'calibration_scope_id' => 1,
        'calibration_id' => 40,
        'instrument_type_id' => 12,
        'test_profile_id' => 10,
        'test_profile_version_id' => null,
        'test_table_snapshot' => null,
        'scope_order' => 1,
        'remarks' => null,
        'created_at' => '2026-10-22 08:00:00',
    ]);

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'This calibration has partially pinned test tables and cannot be repaired.');

    $scope = pinScopes($connection)->sole();

    expect($scope->test_profile_version_id)->toBeNull()
        ->and($scope->test_table_snapshot)->toBeNull();
});

it('rejects a pinned snapshot that does not match its version', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    $connection->table('calibration_scope')->insert([
        'calibration_scope_id' => 1,
        'calibration_id' => 40,
        'instrument_type_id' => 12,
        'test_profile_id' => 10,
        'test_profile_version_id' => 100,
        'test_table_snapshot' => json_encode(pinFrozenSnapshot(10, 999)),
        'scope_order' => 1,
        'remarks' => null,
        'created_at' => '2026-10-22 08:00:00',
    ]);

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'A pinned test table snapshot does not match its frozen version.');

    expect(pinScopes($connection))->toHaveCount(1)
        ->and(pinScopes($connection)->first()->test_profile_version_id)->toBe(100);
});

it('rejects duplicate pinned scopes for one test table without overwriting them', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    $snapshot = json_encode(pinFrozenSnapshot(10, 100));
    $connection->table('calibration_scope')->insert([
        [
            'calibration_scope_id' => 1,
            'calibration_id' => 40,
            'instrument_type_id' => 12,
            'test_profile_id' => 10,
            'test_profile_version_id' => 100,
            'test_table_snapshot' => $snapshot,
            'scope_order' => 1,
            'remarks' => null,
            'created_at' => '2026-10-22 08:00:00',
        ],
        [
            'calibration_scope_id' => 2,
            'calibration_id' => 40,
            'instrument_type_id' => 12,
            'test_profile_id' => 10,
            'test_profile_version_id' => 100,
            'test_table_snapshot' => $snapshot,
            'scope_order' => 2,
            'remarks' => null,
            'created_at' => '2026-10-22 08:00:00',
        ],
    ]);

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'Existing pinned test tables conflict with this calibration.');

    expect(pinScopes($connection))->toHaveCount(2)
        ->and(pinScopes($connection)->pluck('test_profile_version_id')->all())->toBe([100, 100]);
});

it('rejects a test table with more than one active version', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    $connection->table('test_profile_version')->insert([
        'test_profile_version_id' => 101,
        'test_profile_id' => 10,
        'version_number' => 2,
        'status' => 'active',
        'profile_name' => 'Profile 10 other',
        'display_title' => null,
        'description' => null,
        'published_at' => '2026-10-22 09:00:00',
        'created_at' => '2026-10-22 09:00:00',
    ]);

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'A Test Table has more than one active version.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and($connection->table('test_profile_version')->orderBy('test_profile_version_id')->pluck('status')->all())->toBe([
            'active',
            'active',
        ]);
});

it('rolls back every new scope when snapshot generation fails', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    seedActivePinTable($connection, 20, 200);
    $connection->table('test_column')->insert([
        'test_column_id' => 2005,
        'test_profile_id' => 20,
        'test_profile_version_id' => 200,
        'column_key' => 'duration',
        'column_label' => 'Duration',
        'column_order' => 5,
        'data_type' => 'duration',
        'value_source' => 'template',
        'unit' => 's',
        'display_scale' => null,
        'formula_code' => null,
        'formula_inputs' => '[]',
        'completion_rule' => 'optional',
        'created_at' => '2026-10-22 07:00:00',
    ]);
    $connection->table('test_point_value')->insert([
        'test_point_value_id' => 2005,
        'test_profile_id' => 20,
        'test_profile_version_id' => 200,
        'test_point_id' => 2001,
        'test_column_id' => 2005,
        'numeric_value' => '1.5',
        'text_value' => null,
        'unit' => 's',
        'created_at' => '2026-10-22 07:00:00',
    ]);

    expect(fn () => pinTables($connection))
        ->toThrow(TestTableSnapshotException::class);

    expect(pinScopes($connection))->toBeEmpty();
});

it('rolls back every new scope when a later insert fails', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    seedActivePinTable($connection, 20, 200);
    $connection->unprepared('
        CREATE TRIGGER abort_second_scope_insert
        BEFORE INSERT ON calibration_scope
        WHEN (SELECT COUNT(*) FROM calibration_scope) >= 1
        BEGIN
            SELECT RAISE(ABORT, \'simulated insert failure\');
        END
    ');

    expect(fn () => pinTables($connection))
        ->toThrow(QueryException::class);

    expect(pinScopes($connection))->toBeEmpty()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('propagates an unsafe connection exception before querying the session', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('table');
    $unsafe->shouldNotReceive('transaction');

    expect(fn () => pinTestTables()->pin(40, $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('pins through the supplied connection when its name is empty or unregistered', function (string $connectionName) {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    $config = new ReflectionProperty($connection, 'config');
    $settings = $config->getValue($connection);
    $settings['name'] = $connectionName;
    $config->setValue($connection, $settings);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    pinTables($connection);

    expect($connection->getName())->toBe($connectionName)
        ->and(pinScopes($connection)->sole()->test_profile_version_id)->toBe(100)
        ->and(pinSql($connection))->toContain('insert into "calibration_scope"')
        ->and(pinSql($default))->not->toContain('calibration_scope')
        ->and(pinSql($default))->not->toContain('test_profile_version')
        ->and(Schema::connection('sqlite')->hasTable('test_profile'))->toBeFalse()
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty name' => '',
    'unregistered name' => 'unowned-pin-test-tables',
]);

it('pins a required numeric template value exactly as the builder froze it', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinConfigureTemplate($connection, 100, 'numeric', 'required');
    pinReplaceTemplateValues($connection, 10, 100, '100.5', null, 'kPa');

    pinTables($connection);

    $snapshot = pinSnapshot(pinScopes($connection)->sole());

    expect($snapshot['columns'][0]['completion_rule'])->toBe('required')
        ->and($snapshot['columns'][0]['data_type'])->toBe('numeric')
        ->and($snapshot['rows'][0]['template_values']['standard_value']['numeric_value'])->toBe('100.5')
        ->and($snapshot['rows'][1]['template_values']['standard_value']['numeric_value'])->toBe('100.5')
        ->and($snapshot['rows'][0]['template_values']['standard_value']['unit'])->toBe('kPa')
        ->and($connection->table('calibration')->value('session_status'))->toBe('in_progress')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('pins a required text template and preserves the frozen text', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinConfigureTemplate($connection, 100, 'text', 'required');
    pinReplaceTemplateValues($connection, 10, 100, null, 'PASS', null);
    $connection->table('test_point_value')->where('test_point_id', 1002)->update(['text_value' => '  PASS  ']);

    pinTables($connection);

    $values = pinSnapshot(pinScopes($connection)->sole())['rows'];

    expect($values[0]['template_values']['standard_value']['text_value'])->toBe('PASS')
        ->and($values[1]['template_values']['standard_value']['text_value'])->toBe('  PASS  ')
        ->and($values[0]['template_values']['standard_value']['numeric_value'])->toBeNull()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('pins a required duration template of whole seconds', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinConfigureTemplate($connection, 100, 'duration', 'required');
    pinReplaceTemplateValues($connection, 10, 100, '30', null, 's');
    $connection->table('test_point_value')->where('test_point_id', 1002)->update(['numeric_value' => '120']);

    pinTables($connection);

    $values = pinSnapshot(pinScopes($connection)->sole())['rows'];

    expect($values[0]['template_values']['standard_value']['numeric_value'])->toBe('30')
        ->and($values[1]['template_values']['standard_value']['numeric_value'])->toBe('120')
        ->and($values[0]['template_values']['standard_value']['unit'])->toBe('s')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('pins a required numeric template of zero', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinConfigureTemplate($connection, 100, 'numeric', 'required');
    pinReplaceTemplateValues($connection, 10, 100, '0', null, 'kPa');

    pinTables($connection);

    expect(pinSnapshot(pinScopes($connection)->sole())['rows'][0]['template_values']['standard_value']['numeric_value'])->toBe('0')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('pins a required text template of 0', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinConfigureTemplate($connection, 100, 'text', 'required');
    pinReplaceTemplateValues($connection, 10, 100, null, '0', null);

    pinTables($connection);

    expect(pinSnapshot(pinScopes($connection)->sole())['rows'][0]['template_values']['standard_value']['text_value'])->toBe('0')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('pins a required duration template of zero seconds', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinConfigureTemplate($connection, 100, 'duration', 'required');
    pinReplaceTemplateValues($connection, 10, 100, '0', null, 's');

    pinTables($connection);

    expect(pinSnapshot(pinScopes($connection)->sole())['rows'][0]['template_values']['standard_value']['numeric_value'])->toBe('0')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects a required template column when the template cell is absent', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinConfigureTemplate($connection, 100, 'numeric', 'required');
    $connection->table('test_point_value')->where('test_profile_version_id', 100)->delete();

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'A required template value is missing.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and($connection->table('test_point_value')->count())->toBe(0)
        ->and($connection->table('test_column')->where('test_column_id', 1001)->value('completion_rule'))->toBe('required')
        ->and($connection->table('calibration')->value('session_status'))->toBe('in_progress')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects a required template column when the frozen numeric value is null', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinConfigureTemplate($connection, 100, 'numeric', 'required');
    pinReplaceTemplateValues($connection, 10, 100, null, null, 'kPa');

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'A required template value is missing.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and($connection->table('test_point_value')->where('test_point_id', 1001)->value('numeric_value'))->toBeNull()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects a required numeric template when the master value is blank', function (string $value) {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinConfigureTemplate($connection, 100, 'numeric', 'required');
    pinReplaceTemplateValues($connection, 10, 100, $value, null, 'kPa');

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'A required template value is missing.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and($connection->table('test_point_value')->where('test_point_id', 1001)->value('numeric_value'))->toBe($value)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty' => '',
    'whitespace' => '   ',
]);

it('rejects a required text template when the master text is blank', function (string $text) {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinConfigureTemplate($connection, 100, 'text', 'required');
    pinReplaceTemplateValues($connection, 10, 100, null, $text, null);

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'A required template value is missing.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and($connection->table('test_point_value')->where('test_point_id', 1001)->value('text_value'))->toBe($text)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty' => '',
    'whitespace' => '   ',
]);

it('rejects a required numeric template token preserved by the snapshot builder', function (string $token) {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinConfigureTemplate($connection, 100, 'numeric', 'required');
    pinReplaceTemplateValues($connection, 10, 100, $token, null, 'kPa');

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'The frozen template value is invalid.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and($connection->table('test_point_value')->where('test_point_id', 1001)->value('numeric_value'))->toBe($token)
        ->and($connection->table('calibration')->value('session_status'))->toBe('in_progress')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'letters' => 'abc',
    'leading plus' => '+1',
    'exponent' => '1e2',
]);

it('pins a required negative numeric template value', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinConfigureTemplate($connection, 100, 'numeric', 'required');
    pinReplaceTemplateValues($connection, 10, 100, '-2.5', null, 'kPa');

    pinTables($connection);

    expect(pinSnapshot(pinScopes($connection)->sole())['rows'][0]['template_values']['standard_value']['numeric_value'])->toBe('-2.5')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects a negative duration template value', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinConfigureTemplate($connection, 100, 'duration', 'required');
    pinReplaceTemplateValues($connection, 10, 100, '-1', null, 's');

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'The frozen template value is invalid.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and($connection->table('test_point_value')->where('test_point_id', 1001)->value('numeric_value'))->toBe('-1')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects an invalid duration token with the snapshot builder exception', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinConfigureTemplate($connection, 100, 'duration', 'required');
    pinReplaceTemplateValues($connection, 10, 100, 'abc', null, 's');

    expect(fn () => pinTables($connection))
        ->toThrow(TestTableSnapshotException::class, 'Duration template values must be whole seconds.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('pins a required template value when the unit is null', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinConfigureTemplate($connection, 100, 'numeric', 'required');
    pinReplaceTemplateValues($connection, 10, 100, '10', null, null);

    pinTables($connection);

    expect(pinSnapshot(pinScopes($connection)->sole())['rows'][0]['template_values']['standard_value']['unit'])->toBeNull()
        ->and($connection->table('test_point_value')->where('test_point_id', 1001)->value('unit'))->toBeNull()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('pins a required template value when the builder normalizes a blank unit to null', function (string $unit) {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinConfigureTemplate($connection, 100, 'numeric', 'required');
    pinReplaceTemplateValues($connection, 10, 100, '10', null, $unit);

    pinTables($connection);

    expect(pinSnapshot(pinScopes($connection)->sole())['rows'][0]['template_values']['standard_value']['unit'])->toBeNull()
        ->and($connection->table('test_point_value')->where('test_point_id', 1001)->value('unit'))->toBe($unit)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty' => '',
    'whitespace' => '   ',
]);

it('rejects a required template value when the frozen unit is longer than 50 characters', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    $unit = str_repeat('m', 51);
    pinConfigureTemplate($connection, 100, 'numeric', 'required');
    pinReplaceTemplateValues($connection, 10, 100, '10', null, $unit);

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'The frozen template value is invalid.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and($connection->table('test_point_value')->where('test_point_id', 1001)->value('unit'))->toBe($unit)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects a not configured template column before inserting a scope', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    $connection->table('test_column')->where('test_column_id', 1001)->update(['completion_rule' => 'not_configured']);

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'A not-configured test component cannot be pinned.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and($connection->table('test_column')->where('test_column_id', 1001)->value('completion_rule'))->toBe('not_configured')
        ->and($connection->table('test_point_value')->where('test_point_id', 1001)->value('numeric_value'))->toBe('10')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects a not configured engineer column before inserting a scope', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    $connection->table('test_column')->where('test_column_id', 1002)->update(['completion_rule' => 'not_configured']);

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'A not-configured test component cannot be pinned.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and($connection->table('test_column')->where('test_column_id', 1002)->value('completion_rule'))->toBe('not_configured')
        ->and($connection->table('test_column')->where('test_column_id', 1002)->value('value_source'))->toBe('engineer')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects a not configured calculated column before inserting a scope', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    $connection->table('test_column')->where('test_column_id', 1003)->update(['completion_rule' => 'not_configured']);

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'A not-configured test component cannot be pinned.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and($connection->table('test_column')->where('test_column_id', 1003)->value('completion_rule'))->toBe('not_configured')
        ->and($connection->table('test_column')->where('test_column_id', 1003)->value('value_source'))->toBe('calculated')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('pins an optional template column that has no value', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    $connection->table('test_point_value')->where('test_profile_version_id', 100)->delete();

    pinTables($connection);

    $snapshot = pinSnapshot(pinScopes($connection)->sole());

    expect($snapshot['columns'][0]['completion_rule'])->toBe('optional')
        ->and($snapshot['rows'][0]['template_values']['standard_value']['numeric_value'])->toBeNull()
        ->and($snapshot['rows'][1]['template_values']['standard_value']['numeric_value'])->toBeNull()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects an optional template column when the frozen value is invalid', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinReplaceTemplateValues($connection, 10, 100, 'abc', null, 'kPa');

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'The frozen template value is invalid.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and($connection->table('test_column')->where('test_column_id', 1001)->value('completion_rule'))->toBe('optional')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('pins required engineer and optional calculated columns that have no prefilled value', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);

    pinTables($connection);

    $snapshot = pinSnapshot(pinScopes($connection)->sole());

    expect($snapshot['columns'][1]['value_source'])->toBe('engineer')
        ->and($snapshot['columns'][1]['completion_rule'])->toBe('required')
        ->and($snapshot['columns'][2]['value_source'])->toBe('calculated')
        ->and($snapshot['columns'][2]['completion_rule'])->toBe('optional')
        ->and(array_keys($snapshot['rows'][0]['template_values']))->toBe(['standard_value'])
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('pins an optional engineer column and a required calculated column that have no prefilled value', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    $connection->table('test_column')->where('test_column_id', 1002)->update(['completion_rule' => 'optional']);
    $connection->table('test_column')->where('test_column_id', 1003)->update(['completion_rule' => 'required']);

    pinTables($connection);

    $snapshot = pinSnapshot(pinScopes($connection)->sole());

    expect($snapshot['columns'][1]['completion_rule'])->toBe('optional')
        ->and($snapshot['columns'][1]['value_source'])->toBe('engineer')
        ->and($snapshot['columns'][2]['completion_rule'])->toBe('required')
        ->and($snapshot['columns'][2]['value_source'])->toBe('calculated')
        ->and(array_keys($snapshot['rows'][0]['template_values']))->toBe(['standard_value'])
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('inserts no scopes when a later active table cannot be completed', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    seedActivePinTable($connection, 20, 200);
    $connection->table('test_column')->where('test_column_id', 2001)->update(['completion_rule' => 'required']);
    $connection->table('test_point_value')->where('test_profile_version_id', 200)->delete();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect(fn () => pinTables($connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'A required template value is missing.');

    expect(pinScopes($connection))->toBeEmpty()
        ->and(pinSql($connection))->not->toContain('insert into "calibration_scope"')
        ->and($connection->table('calibration')->value('session_status'))->toBe('in_progress')
        ->and($connection->table('calibration')->value('calibration_date'))->toBe('2026-10-22')
        ->and($connection->table('test_column')->where('test_column_id', 2001)->value('completion_rule'))->toBe('required')
        ->and($connection->table('test_profile_version')->whereIn('test_profile_version_id', [100, 200])->pluck('status')->unique()->all())->toBe(['active'])
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('does not rebuild a pinned snapshot after the master column becomes not configured', function () {
    $connection = pinTestTablesConnection();
    seedPinSession($connection);
    seedPinRequirement($connection);
    seedActivePinTable($connection, 10, 100);
    pinTables($connection);
    $first = pinScopes($connection)->sole();
    $snapshot = (string) $first->test_table_snapshot;
    $connection->table('test_column')->where('test_column_id', 1001)->update(['completion_rule' => 'not_configured']);
    $connection->table('test_point_value')->where('test_column_id', 1001)->update(['numeric_value' => null]);

    pinTables($connection);

    $second = pinScopes($connection)->sole();

    expect($second->calibration_scope_id)->toBe($first->calibration_scope_id)
        ->and($second->test_profile_version_id)->toBe(100)
        ->and((string) $second->test_table_snapshot)->toBe($snapshot)
        ->and($second->remarks)->toBeNull()
        ->and($connection->table('test_column')->where('test_column_id', 1001)->value('completion_rule'))->toBe('not_configured')
        ->and(pinSnapshot($second)['columns'][0]['completion_rule'])->toBe('optional')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

/**
 * @return array<string, mixed>
 */
function pinFrozenSnapshot(int $profileId, int $versionId): array
{
    return [
        'schema_version' => 2,
        'duration_format' => 'hh:mm:ss',
        'test_profile_id' => $profileId,
        'test_profile_version_id' => $versionId,
        'version_number' => 1,
        'profile_name' => 'Profile '.$profileId,
        'columns' => [[
            'column_key' => 'reading',
            'column_label' => 'Reading',
            'column_order' => 1,
            'data_type' => 'numeric',
            'value_source' => 'engineer',
            'formula_inputs' => [],
            'completion_rule' => 'required',
        ]],
        'rows' => [[
            'point_order' => 1,
            'template_values' => [],
        ]],
    ];
}
