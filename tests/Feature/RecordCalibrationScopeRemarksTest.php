<?php

use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\RecordCalibrationScopeRemarks;
use App\CalibrationSessions\RecordCalibrationScopeRemarksException;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestRefusingPostgresConnector;

const RECORD_SCOPE_REMARKS_CONNECTION = 'record_calibration_scope_remarks_sqlite';

afterEach(function () {
    Carbon::setTestNow();
});

function recordCalibrationScopeRemarksConnection(): Connection
{
    Carbon::setTestNow('2026-10-22 08:15:00');

    config([
        'database.connections.'.RECORD_SCOPE_REMARKS_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(RECORD_SCOPE_REMARKS_CONNECTION);

    $connection = DB::connection(RECORD_SCOPE_REMARKS_CONNECTION);
    $connection->unprepared('
        CREATE TABLE calibration (
            calibration_id INTEGER PRIMARY KEY,
            instrument_type_id INTEGER NULL,
            calibration_number VARCHAR(50) NULL,
            calibration_method VARCHAR(20) NULL,
            session_status VARCHAR(20) NOT NULL,
            calibration_date TEXT NULL,
            calibration_due TEXT NULL,
            certificate_number TEXT NULL,
            snapshot_instrument_name TEXT NULL,
            remarks TEXT NULL,
            resume TEXT NULL
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
            UNIQUE (calibration_id, requirement_category),
            FOREIGN KEY (calibration_id) REFERENCES calibration (calibration_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_scope (
            calibration_scope_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            instrument_type_id INTEGER NULL,
            test_profile_id INTEGER NULL,
            test_profile_version_id INTEGER NULL,
            test_table_snapshot TEXT NULL,
            scope_order INTEGER NOT NULL,
            remarks TEXT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (calibration_id) REFERENCES calibration (calibration_id)
        )
    ');

    return $connection;
}

function recordCalibrationScopeRemarksService(): RecordCalibrationScopeRemarks
{
    return app(RecordCalibrationScopeRemarks::class);
}

/**
 * @param  array<string, mixed>  $calibration
 * @param  array<string, mixed>|null  $requirement
 * @param  array<string, mixed>|null  $scope
 */
function recordCalibrationScopeRemarksSeed(Connection $connection, array $calibration = [], ?array $requirement = [], ?array $scope = []): ?string
{
    $connection->table('calibration')->insert(array_merge([
        'calibration_id' => 40,
        'instrument_type_id' => 12,
        'calibration_number' => 'CAL-40',
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'calibration_date' => null,
        'calibration_due' => null,
        'certificate_number' => 'CERT-40',
        'snapshot_instrument_name' => 'Gauge',
        'remarks' => 'session note',
        'resume' => 'session resume',
    ], $calibration));

    if ($requirement !== null) {
        $connection->table('calibration_requirement_snapshot')->insert(array_merge([
            'calibration_requirement_snapshot_id' => 1,
            'calibration_id' => 40,
            'requirement_category' => InstrumentTypeRequirement::CATEGORY_TEST_PROFILE,
            'frozen_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
            'resolved_mode' => null,
            'resolved_at' => null,
            'resolved_reason' => null,
            'resolved_by_user_id' => null,
            'configuration_confirmed_at' => null,
            'configuration_confirmed_reason' => null,
            'configuration_confirmed_by_user_id' => null,
        ], $requirement));
    }

    if ($scope === null) {
        return null;
    }

    $snapshot = array_key_exists('test_table_snapshot', $scope)
        ? $scope['test_table_snapshot']
        : recordCalibrationScopeRemarksSnapshot();
    unset($scope['test_table_snapshot']);
    $json = is_array($snapshot)
        ? json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        : $snapshot;

    $connection->table('calibration_scope')->insert(array_merge([
        'calibration_scope_id' => 8,
        'calibration_id' => 40,
        'instrument_type_id' => 12,
        'test_profile_id' => 5,
        'test_profile_version_id' => 7,
        'test_table_snapshot' => $json,
        'scope_order' => 1,
        'remarks' => null,
        'created_at' => '2024-01-01 00:00:00',
    ], $scope));

    return is_string($json) ? $json : null;
}

/**
 * @return array<string, mixed>
 */
function recordCalibrationScopeRemarksSnapshot(): array
{
    return [
        'schema_version' => 2,
        'captured_at' => '2026-10-22T01:00:00Z',
        'test_profile_id' => 5,
        'test_profile_version_id' => 7,
        'version_number' => 3,
        'profile_name' => 'Mass',
        'display_title' => null,
        'description' => null,
        'duration_format' => 'hh:mm:ss',
        'columns' => [
            [
                'column_key' => 'nominal',
                'column_label' => 'Nominal',
                'column_order' => 1,
                'data_type' => 'numeric',
                'value_source' => 'template',
                'unit' => 'g',
                'display_scale' => null,
                'formula_code' => null,
                'formula_inputs' => [],
                'completion_rule' => 'required',
            ],
        ],
        'rows' => [
            [
                'test_point_id' => 11,
                'point_order' => 1,
                'nominal_value' => '100',
                'unit' => 'g',
                'tolerance_plus' => '0.1',
                'tolerance_minus' => '0.1',
                'description' => null,
                'template_values' => [
                    'nominal' => [
                        'numeric_value' => '100',
                        'text_value' => null,
                        'unit' => 'g',
                    ],
                ],
            ],
        ],
    ];
}

function recordCalibrationScopeRemarksSql(Connection $connection): string
{
    return implode("\n", array_column($connection->getQueryLog(), 'query'));
}

/**
 * @return array<string, mixed>
 */
function recordCalibrationScopeRemarksSession(Connection $connection, int $calibrationId = 40): array
{
    return (array) $connection->table('calibration')->where('calibration_id', $calibrationId)->first();
}

/**
 * @return array<string, mixed>
 */
function recordCalibrationScopeRemarksScope(Connection $connection, int $scopeId = 8): array
{
    return (array) $connection->table('calibration_scope')->where('calibration_scope_id', $scopeId)->first();
}

function recordCalibrationScopeRemarksHistory(Connection $connection): void
{
    $connection->unprepared('
        CREATE TABLE calibration_test_result (
            calibration_test_result_id INTEGER PRIMARY KEY,
            calibration_scope_id INTEGER NOT NULL,
            test_point_id INTEGER NOT NULL,
            result_status TEXT NULL,
            remarks TEXT NULL,
            created_at TEXT NOT NULL,
            standard_value TEXT NULL,
            average_value TEXT NULL,
            correction_value TEXT NULL,
            FOREIGN KEY (calibration_scope_id) REFERENCES calibration_scope (calibration_scope_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_result_value (
            calibration_result_value_id INTEGER PRIMARY KEY,
            calibration_test_result_id INTEGER NOT NULL,
            column_key VARCHAR(63) NOT NULL,
            value_source TEXT NOT NULL,
            data_type TEXT NOT NULL,
            numeric_value TEXT NULL,
            text_value TEXT NULL,
            unit VARCHAR(50) NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (calibration_test_result_id) REFERENCES calibration_test_result (calibration_test_result_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_reading (
            calibration_reading_id INTEGER PRIMARY KEY,
            calibration_test_result_id INTEGER NOT NULL,
            reading_order INTEGER NOT NULL,
            reference_value TEXT NULL,
            instrument_value TEXT NULL,
            error_value TEXT NULL,
            uncertainty_value TEXT NULL,
            unit TEXT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (calibration_test_result_id) REFERENCES calibration_test_result (calibration_test_result_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_standard_usage (
            calibration_standard_usage_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            remarks TEXT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_environment (
            calibration_environment_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            remarks TEXT NULL
        )
    ');
    $connection->table('calibration_test_result')->insert([
        'calibration_test_result_id' => 4,
        'calibration_scope_id' => 8,
        'test_point_id' => 11,
        'result_status' => 'recorded',
        'remarks' => 'row note',
        'created_at' => '2020-01-01 00:00:00',
        'standard_value' => '10',
        'average_value' => '9',
        'correction_value' => '1',
    ]);
    $connection->table('calibration_result_value')->insert([
        [
            'calibration_result_value_id' => 1,
            'calibration_test_result_id' => 4,
            'column_key' => 'nominal',
            'value_source' => 'template',
            'data_type' => 'numeric',
            'numeric_value' => '100',
            'text_value' => null,
            'unit' => 'g',
            'created_at' => '2020-01-01 00:00:00',
        ],
        [
            'calibration_result_value_id' => 2,
            'calibration_test_result_id' => 4,
            'column_key' => 'reading',
            'value_source' => 'engineer',
            'data_type' => 'numeric',
            'numeric_value' => '99.8',
            'text_value' => null,
            'unit' => 'g',
            'created_at' => '2020-01-01 00:00:00',
        ],
    ]);
    $connection->table('calibration_reading')->insert([
        'calibration_reading_id' => 1,
        'calibration_test_result_id' => 4,
        'reading_order' => 1,
        'reference_value' => '10',
        'instrument_value' => '10',
        'error_value' => null,
        'uncertainty_value' => null,
        'unit' => 'g',
        'created_at' => '2020-01-01 00:00:00',
    ]);
    $connection->table('calibration_standard_usage')->insert([
        'calibration_standard_usage_id' => 1,
        'calibration_id' => 40,
        'remarks' => 'standard note',
    ]);
    $connection->table('calibration_environment')->insert([
        'calibration_environment_id' => 1,
        'calibration_id' => 40,
        'remarks' => 'environment note',
    ]);
}

it('records scope remarks and leaves the pinned scope identity unchanged', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    $snapshot = recordCalibrationScopeRemarksSeed($connection);
    $session = recordCalibrationScopeRemarksSession($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    recordCalibrationScopeRemarksService()->record(40, 8, '  bench note  ', $connection);

    $sql = recordCalibrationScopeRemarksSql($connection);
    $calibration = strpos($sql, 'from "calibration"');
    $requirement = strpos($sql, 'from "calibration_requirement_snapshot"');
    $scope = strpos($sql, 'from "calibration_scope"');
    $update = strpos($sql, 'update "calibration_scope"');

    expect(recordCalibrationScopeRemarksScope($connection))->toMatchArray([
        'calibration_scope_id' => 8,
        'calibration_id' => 40,
        'instrument_type_id' => 12,
        'test_profile_id' => 5,
        'test_profile_version_id' => 7,
        'test_table_snapshot' => $snapshot,
        'scope_order' => 1,
        'remarks' => '  bench note  ',
        'created_at' => '2024-01-01 00:00:00',
    ])
        ->and(recordCalibrationScopeRemarksSession($connection))->toBe($session)
        ->and($sql)->not->toContain('calibration_test_result')
        ->and($sql)->not->toContain('calibration_result_value')
        ->and($sql)->not->toContain('calibration_reading')
        ->and($sql)->not->toContain('from "test_')
        ->and($sql)->not->toContain('"created_at"')
        ->and($sql)->toContain('set "remarks"')
        ->and($calibration)->toBeInt()->toBeLessThan($requirement)
        ->and($requirement)->toBeLessThan($scope)
        ->and($scope)->toBeLessThan($update)
        ->and($sql)->toContain('order by "scope_order" asc, "calibration_scope_id" asc')
        ->and($connection->getName())->toBe(RECORD_SCOPE_REMARKS_CONNECTION)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('replaces existing scope remarks', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection, scope: ['remarks' => 'first']);

    recordCalibrationScopeRemarksService()->record(40, 8, 'second', $connection);

    expect(recordCalibrationScopeRemarksScope($connection)['remarks'])->toBe('second')
        ->and(recordCalibrationScopeRemarksScope($connection)['created_at'])->toBe('2024-01-01 00:00:00');
});

it('clears scope remarks when the value is null', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection, scope: ['remarks' => 'first']);

    recordCalibrationScopeRemarksService()->record(40, 8, null, $connection);

    expect(recordCalibrationScopeRemarksScope($connection)['remarks'])->toBeNull()
        ->and(recordCalibrationScopeRemarksScope($connection)['created_at'])->toBe('2024-01-01 00:00:00');
});

it('leaves null scope remarks null when cleared again', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection);

    recordCalibrationScopeRemarksService()->record(40, 8, null, $connection);

    expect(recordCalibrationScopeRemarksScope($connection)['remarks'])->toBeNull();
});

it('rejects whitespace-only scope remarks before reading the session', function (string $remarks) {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect(fn () => recordCalibrationScopeRemarksService()->record(40, 8, $remarks, $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'The scope remarks must not be blank.');

    expect(recordCalibrationScopeRemarksSql($connection))->not->toContain('from "calibration"')
        ->and(recordCalibrationScopeRemarksScope($connection)['remarks'])->toBeNull();
})->with([
    'empty' => '',
    'spaces' => '   ',
    'tabs' => "\t\t",
    'newline' => "\n",
    'mixed' => " \t\n ",
]);

it('preserves supplied scope remarks text', function (string $remarks) {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection);

    recordCalibrationScopeRemarksService()->record(40, 8, $remarks, $connection);

    expect(recordCalibrationScopeRemarksScope($connection)['remarks'])->toBe($remarks);
})->with([
    'leading spaces' => '  note',
    'trailing spaces' => 'note  ',
    'line break' => "line one\nline two",
    'unicode' => 'Catatan — µg',
]);

it('allows repeated scope remark updates', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection);
    $service = recordCalibrationScopeRemarksService();

    $service->record(40, 8, 'one', $connection);
    $service->record(40, 8, 'two', $connection);

    expect(recordCalibrationScopeRemarksScope($connection)['remarks'])->toBe('two')
        ->and(recordCalibrationScopeRemarksScope($connection)['created_at'])->toBe('2024-01-01 00:00:00');
});

it('records scope remarks before any result row exists', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection);

    recordCalibrationScopeRemarksService()->record(40, 8, 'before cells', $connection);

    expect(recordCalibrationScopeRemarksScope($connection)['remarks'])->toBe('before cells');
});

it('preserves result rows, cells, readings, and other remarks', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection);
    recordCalibrationScopeRemarksHistory($connection);
    $results = $connection->table('calibration_test_result')->get()->map(fn (object $row): array => (array) $row)->all();
    $cells = $connection->table('calibration_result_value')->orderBy('calibration_result_value_id')->get()->map(fn (object $row): array => (array) $row)->all();
    $readings = $connection->table('calibration_reading')->get()->map(fn (object $row): array => (array) $row)->all();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    recordCalibrationScopeRemarksService()->record(40, 8, 'table note', $connection);
    $sql = recordCalibrationScopeRemarksSql($connection);

    expect(recordCalibrationScopeRemarksScope($connection)['remarks'])->toBe('table note')
        ->and($connection->table('calibration_test_result')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($results)
        ->and($connection->table('calibration_result_value')->orderBy('calibration_result_value_id')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($cells)
        ->and($connection->table('calibration_reading')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($readings)
        ->and($connection->table('calibration')->value('remarks'))->toBe('session note')
        ->and($connection->table('calibration')->value('resume'))->toBe('session resume')
        ->and($connection->table('calibration_standard_usage')->value('remarks'))->toBe('standard note')
        ->and($connection->table('calibration_environment')->value('remarks'))->toBe('environment note')
        ->and($sql)->not->toContain('calibration_test_result')
        ->and($sql)->not->toContain('calibration_result_value')
        ->and($sql)->not->toContain('calibration_reading')
        ->and($sql)->not->toContain('calibration_standard_usage')
        ->and($sql)->not->toContain('calibration_environment');
});

it('updates one scope without changing another', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    $snapshot = recordCalibrationScopeRemarksSeed($connection);
    $connection->table('calibration_scope')->insert([
        'calibration_scope_id' => 9,
        'calibration_id' => 40,
        'instrument_type_id' => 12,
        'test_profile_id' => 5,
        'test_profile_version_id' => 7,
        'test_table_snapshot' => $snapshot,
        'scope_order' => 2,
        'remarks' => 'other table',
        'created_at' => '2024-01-01 00:00:00',
    ]);

    recordCalibrationScopeRemarksService()->record(40, 8, 'this table', $connection);

    expect(recordCalibrationScopeRemarksScope($connection, 8)['remarks'])->toBe('this table')
        ->and(recordCalibrationScopeRemarksScope($connection, 9)['remarks'])->toBe('other table');
});

it('records scope remarks for an operable test profile mode', function (?string $frozen, ?string $resolved) {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection, requirement: [
        'frozen_mode' => $frozen,
        'resolved_mode' => $resolved,
    ]);

    recordCalibrationScopeRemarksService()->record(40, 8, 'allowed', $connection);

    expect(recordCalibrationScopeRemarksScope($connection)['remarks'])->toBe('allowed')
        ->and($connection->table('calibration_requirement_snapshot')->value('frozen_mode'))->toBe($frozen)
        ->and($connection->table('calibration_requirement_snapshot')->value('resolved_mode'))->toBe($resolved);
})->with([
    'required' => [InstrumentTypeRequirement::MODE_REQUIRED, null],
    'optional' => [InstrumentTypeRequirement::MODE_OPTIONAL, null],
    'resolved required' => [InstrumentTypeRequirement::MODE_NOT_CONFIGURED, InstrumentTypeRequirement::MODE_REQUIRED],
    'resolved optional' => [InstrumentTypeRequirement::MODE_NOT_CONFIGURED, InstrumentTypeRequirement::MODE_OPTIONAL],
    'frozen required stays' => [InstrumentTypeRequirement::MODE_REQUIRED, InstrumentTypeRequirement::MODE_NOT_CONFIGURED],
]);

it('rejects an unresolved not-configured test profile requirement', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection, requirement: [
        'frozen_mode' => InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
        'resolved_mode' => null,
    ]);

    expect(fn () => recordCalibrationScopeRemarksService()->record(40, 8, 'note', $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'An unresolved not-configured test profile requirement cannot record scope remarks.');
});

it('rejects an invalid frozen requirement mode', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection, requirement: [
        'frozen_mode' => 'mandatory',
        'resolved_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
    ]);

    expect(fn () => recordCalibrationScopeRemarksService()->record(40, 8, 'note', $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'The test profile requirement mode cannot record scope remarks.');
});

it('rejects a calibration that is not internal', function (string $method) {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection, ['calibration_method' => $method]);

    expect(fn () => recordCalibrationScopeRemarksService()->record(40, 8, 'note', $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'Scope remarks can be recorded only for an internal calibration session.');

    expect(recordCalibrationScopeRemarksScope($connection)['remarks'])->toBeNull();
})->with([
    'external' => Calibration::METHOD_EXTERNAL,
    'unsupported' => 'FIELD',
]);

it('rejects a session that is not in progress', function (string $status) {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection, ['session_status' => $status]);

    expect(fn () => recordCalibrationScopeRemarksService()->record(40, 8, 'note', $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'Only an in-progress calibration session can record scope remarks.');

    expect($connection->table('calibration')->value('session_status'))->toBe($status)
        ->and(recordCalibrationScopeRemarksScope($connection)['remarks'])->toBeNull();
})->with([
    'completed' => Calibration::STATUS_COMPLETED,
    'cancelled' => Calibration::STATUS_CANCELLED,
    'unsupported' => 'archived',
]);

it('rejects a calibration id below 1 before reading the session', function (int $calibrationId) {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect(fn () => recordCalibrationScopeRemarksService()->record($calibrationId, 8, 'note', $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'The calibration id must be at least 1.');

    expect(recordCalibrationScopeRemarksSql($connection))->not->toContain('from "calibration"')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects a scope id below 1 before reading the session', function (int $scopeId) {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect(fn () => recordCalibrationScopeRemarksService()->record(40, $scopeId, 'note', $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'The calibration scope id must be at least 1.');

    expect(recordCalibrationScopeRemarksSql($connection))->not->toContain('from "calibration"');
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects a missing calibration', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection);

    expect(fn () => recordCalibrationScopeRemarksService()->record(41, 8, 'note', $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'The calibration does not exist.');
});

it('rejects a missing test profile requirement', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection, requirement: null);

    expect(fn () => recordCalibrationScopeRemarksService()->record(40, 8, 'note', $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'The test profile requirement snapshot does not exist.');
});

it('rejects an unknown scope', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection);

    expect(fn () => recordCalibrationScopeRemarksService()->record(40, 77, 'note', $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'This calibration scope does not belong to this calibration session.');
});

it('rejects a scope from another calibration', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    $snapshot = recordCalibrationScopeRemarksSeed($connection);
    $connection->table('calibration')->insert([
        'calibration_id' => 41,
        'instrument_type_id' => 12,
        'calibration_number' => 'CAL-41',
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'calibration_date' => null,
        'calibration_due' => null,
        'certificate_number' => null,
        'snapshot_instrument_name' => null,
        'remarks' => null,
        'resume' => null,
    ]);
    $connection->table('calibration_scope')->insert([
        'calibration_scope_id' => 77,
        'calibration_id' => 41,
        'instrument_type_id' => 12,
        'test_profile_id' => 5,
        'test_profile_version_id' => 7,
        'test_table_snapshot' => $snapshot,
        'scope_order' => 1,
        'remarks' => 'foreign',
        'created_at' => '2024-01-01 00:00:00',
    ]);

    expect(fn () => recordCalibrationScopeRemarksService()->record(40, 77, 'note', $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'This calibration scope does not belong to this calibration session.');

    expect(recordCalibrationScopeRemarksScope($connection, 77)['remarks'])->toBe('foreign');
});

it('rejects a scope whose instrument type does not match the calibration', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection, scope: ['instrument_type_id' => 99]);

    expect(fn () => recordCalibrationScopeRemarksService()->record(40, 8, 'note', $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'The calibration scope instrument type does not match the calibration.');

    expect(recordCalibrationScopeRemarksScope($connection)['remarks'])->toBeNull();
});

it('rejects an unpinned scope', function (?int $versionId, mixed $snapshot) {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection, scope: [
        'test_profile_version_id' => $versionId,
        'test_table_snapshot' => $snapshot,
    ]);

    expect(fn () => recordCalibrationScopeRemarksService()->record(40, 8, 'note', $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'The calibration scope is not fully pinned.');

    expect(recordCalibrationScopeRemarksScope($connection)['remarks'])->toBeNull();
})->with([
    'missing version and snapshot' => [null, null],
    'missing snapshot' => [7, null],
    'blank snapshot' => [7, '   '],
]);

it('rejects a malformed snapshot', function (string $snapshot) {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection, scope: ['test_table_snapshot' => $snapshot]);

    expect(fn () => recordCalibrationScopeRemarksService()->record(40, 8, 'note', $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'The frozen test table snapshot is malformed.');
})->with([
    'broken json' => '{',
    'missing shape' => '{"schema_version":2}',
]);

it('rejects a snapshot that is not schema version 2', function (string $snapshot) {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection, scope: ['test_table_snapshot' => $snapshot]);

    expect(fn () => recordCalibrationScopeRemarksService()->record(40, 8, 'note', $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'The frozen test table snapshot is not schema version 2.');
})->with([
    'version 1' => '{"schema_version":1}',
    'version 1 string' => '{"schema_version":"1"}',
    'unsupported' => '{"schema_version":3}',
]);

it('rejects a snapshot that does not match the pinned version', function (string $field, int $value) {
    $connection = recordCalibrationScopeRemarksConnection();
    $snapshot = recordCalibrationScopeRemarksSnapshot();
    $snapshot[$field] = $value;
    recordCalibrationScopeRemarksSeed($connection, scope: ['test_table_snapshot' => $snapshot]);

    expect(fn () => recordCalibrationScopeRemarksService()->record(40, 8, 'note', $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'The frozen test table snapshot does not match the pinned version.');
})->with([
    'version' => ['test_profile_version_id', 99],
    'profile' => ['test_profile_id', 99],
]);

it('records remarks on a pinned scope when another scope is incomplete', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection);
    $connection->table('calibration_scope')->insert([
        'calibration_scope_id' => 9,
        'calibration_id' => 40,
        'instrument_type_id' => 12,
        'test_profile_id' => null,
        'test_profile_version_id' => null,
        'test_table_snapshot' => null,
        'scope_order' => 2,
        'remarks' => null,
        'created_at' => '2024-01-01 00:00:00',
    ]);

    recordCalibrationScopeRemarksService()->record(40, 8, 'pinned table', $connection);

    expect(recordCalibrationScopeRemarksScope($connection, 8)['remarks'])->toBe('pinned table')
        ->and(recordCalibrationScopeRemarksScope($connection, 9)['remarks'])->toBeNull()
        ->and(recordCalibrationScopeRemarksScope($connection, 9)['test_profile_version_id'])->toBeNull();
});

it('rejects an unsafe connection before querying the session', function () {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection);
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('table');
    $unsafe->shouldNotReceive('transaction');

    expect(fn () => recordCalibrationScopeRemarksService()->record(40, 8, 'note', $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');

    expect(recordCalibrationScopeRemarksScope($connection)['remarks'])->toBeNull()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('uses the supplied connection when its name is empty or unregistered', function (string $connectionName) {
    $connection = recordCalibrationScopeRemarksConnection();
    recordCalibrationScopeRemarksSeed($connection);
    $config = new ReflectionProperty($connection, 'config');
    $settings = $config->getValue($connection);
    $settings['name'] = $connectionName;
    $config->setValue($connection, $settings);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    recordCalibrationScopeRemarksService()->record(40, 8, 'named', $connection);

    expect($connection->getName())->toBe($connectionName)
        ->and(recordCalibrationScopeRemarksScope($connection)['remarks'])->toBe('named')
        ->and(recordCalibrationScopeRemarksSql($connection))->toContain('update "calibration_scope"')
        ->and(recordCalibrationScopeRemarksSql($default))->not->toContain('calibration_scope')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty name' => '',
    'unregistered name' => 'unowned-record-scope-remarks',
]);
