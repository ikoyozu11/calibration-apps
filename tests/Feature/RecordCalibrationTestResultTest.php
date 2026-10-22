<?php

use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\RecordCalibrationTestResult;
use App\CalibrationSessions\RecordCalibrationTestResultException;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestRefusingPostgresConnector;

const RECORD_TEST_RESULT_CONNECTION = 'record_test_result_sqlite';

afterEach(function () {
    Carbon::setTestNow();
});

function recordCalibrationTestResultConnection(): Connection
{
    Carbon::setTestNow('2026-10-22 08:15:00');

    config([
        'database.connections.'.RECORD_TEST_RESULT_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(RECORD_TEST_RESULT_CONNECTION);

    $connection = DB::connection(RECORD_TEST_RESULT_CONNECTION);
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
            snapshot_instrument_name TEXT NULL
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
            FOREIGN KEY (calibration_test_result_id) REFERENCES calibration_test_result (calibration_test_result_id),
            CHECK (numeric_value IS NULL OR text_value IS NULL),
            CHECK (text_value IS NULL OR length(trim(text_value)) > 0),
            CHECK (unit IS NULL OR length(trim(unit)) > 0),
            CHECK (unit IS NULL OR length(unit) <= 50),
            CHECK (value_source IN (\'template\', \'engineer\', \'calculated\')),
            CHECK (data_type IN (\'numeric\', \'text\', \'duration\'))
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

    return $connection;
}

function recordCalibrationTestResultService(): RecordCalibrationTestResult
{
    return app(RecordCalibrationTestResult::class);
}

/**
 * @param  array<string, mixed>  $calibration
 * @param  array<string, mixed>|null  $requirement
 * @param  array<string, mixed>  $scope
 */
function recordCalibrationTestResultSeed(Connection $connection, array $calibration = [], ?array $requirement = [], array $scope = []): ?string
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

    $snapshot = array_key_exists('test_table_snapshot', $scope)
        ? $scope['test_table_snapshot']
        : recordCalibrationTestResultSnapshot();
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
        'remarks' => 'scope note',
        'created_at' => '2024-01-01 00:00:00',
    ], $scope));

    return is_string($json) ? $json : null;
}

/**
 * @param  list<string>  $inputs
 * @return array<string, mixed>
 */
function recordCalibrationTestResultColumn(
    string $key,
    string $label,
    int $order,
    string $dataType,
    string $source,
    ?string $unit,
    string $rule,
    ?string $formula = null,
    array $inputs = [],
    ?int $scale = null,
): array {
    return [
        'column_key' => $key,
        'column_label' => $label,
        'column_order' => $order,
        'data_type' => $dataType,
        'value_source' => $source,
        'unit' => $unit,
        'display_scale' => $scale,
        'formula_code' => $formula,
        'formula_inputs' => $inputs,
        'completion_rule' => $rule,
    ];
}

/**
 * @return array<string, mixed>
 */
function recordCalibrationTestResultRow(int $pointId, int $order): array
{
    return [
        'test_point_id' => $pointId,
        'point_order' => $order,
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
    ];
}

/**
 * @return array<string, mixed>
 */
function recordCalibrationTestResultSnapshot(): array
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
            recordCalibrationTestResultColumn('reading', 'Reading', 1, 'numeric', 'engineer', 'g', 'required', null, [], 2),
            recordCalibrationTestResultColumn('average', 'Average', 2, 'numeric', 'calculated', 'g', 'optional', 'average', ['reading']),
            recordCalibrationTestResultColumn('note', 'Note', 3, 'text', 'engineer', null, 'optional'),
            recordCalibrationTestResultColumn('elapsed', 'Elapsed', 4, 'duration', 'engineer', 's', 'optional'),
            recordCalibrationTestResultColumn('nominal', 'Nominal', 5, 'numeric', 'template', 'g', 'required'),
            recordCalibrationTestResultColumn('spare', 'Spare', 6, 'numeric', 'engineer', 'g', 'not_configured'),
        ],
        'rows' => [
            recordCalibrationTestResultRow(11, 1),
            recordCalibrationTestResultRow(12, 2),
        ],
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function recordCalibrationTestResultInsertResult(Connection $connection, array $overrides = []): void
{
    $connection->table('calibration_test_result')->insert(array_merge([
        'calibration_test_result_id' => 1,
        'calibration_scope_id' => 8,
        'test_point_id' => 11,
        'result_status' => null,
        'remarks' => null,
        'created_at' => '2020-01-01 00:00:00',
        'standard_value' => null,
        'average_value' => null,
        'correction_value' => null,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function recordCalibrationTestResultInsertCell(Connection $connection, array $overrides = []): void
{
    $connection->table('calibration_result_value')->insert(array_merge([
        'calibration_result_value_id' => 1,
        'calibration_test_result_id' => 1,
        'column_key' => 'reading',
        'value_source' => 'engineer',
        'data_type' => 'numeric',
        'numeric_value' => '1',
        'text_value' => null,
        'unit' => 'g',
        'created_at' => '2020-01-01 00:00:00',
    ], $overrides));
}

function recordCalibrationTestResultSql(Connection $connection): string
{
    return implode("\n", array_column($connection->getQueryLog(), 'query'));
}

/**
 * @return array<string, mixed>
 */
function recordCalibrationTestResultSession(Connection $connection, int $calibrationId = 40): array
{
    return (array) $connection->table('calibration')->where('calibration_id', $calibrationId)->first();
}

/**
 * @return list<array<string, mixed>>
 */
function recordCalibrationTestResultRows(Connection $connection, string $table): array
{
    $order = [
        'calibration' => 'calibration_id',
        'calibration_requirement_snapshot' => 'calibration_requirement_snapshot_id',
        'calibration_scope' => 'calibration_scope_id',
        'calibration_test_result' => 'calibration_test_result_id',
        'calibration_result_value' => 'calibration_result_value_id',
        'calibration_reading' => 'calibration_reading_id',
    ];

    return array_map(
        fn (object $row): array => (array) $row,
        $connection->table($table)->orderBy($order[$table])->get()->all(),
    );
}

it('inserts the first numeric test result cell and creates its parent row', function () {
    $connection = recordCalibrationTestResultConnection();
    $snapshot = recordCalibrationTestResultSeed($connection);
    $session = recordCalibrationTestResultSession($connection);
    $requirement = recordCalibrationTestResultRows($connection, 'calibration_requirement_snapshot');
    $scopes = recordCalibrationTestResultRows($connection, 'calibration_scope');
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1.239', $connection);

    $sql = recordCalibrationTestResultSql($connection);
    $calibration = strpos($sql, 'from "calibration"');
    $requirementSql = strpos($sql, 'from "calibration_requirement_snapshot"');
    $scopeSql = strpos($sql, 'from "calibration_scope"');
    $resultSql = strpos($sql, 'from "calibration_test_result"');
    $valueSql = strpos($sql, 'from "calibration_result_value"');
    $insert = strpos($sql, 'insert into "calibration_test_result"');

    expect(recordCalibrationTestResultRows($connection, 'calibration_test_result'))->toBe([
        [
            'calibration_test_result_id' => 1,
            'calibration_scope_id' => 8,
            'test_point_id' => 11,
            'result_status' => null,
            'remarks' => null,
            'created_at' => '2026-10-22 08:15:00',
            'standard_value' => null,
            'average_value' => null,
            'correction_value' => null,
        ],
    ])
        ->and(recordCalibrationTestResultRows($connection, 'calibration_result_value'))->toBe([
            [
                'calibration_result_value_id' => 1,
                'calibration_test_result_id' => 1,
                'column_key' => 'reading',
                'value_source' => 'engineer',
                'data_type' => 'numeric',
                'numeric_value' => '1.239',
                'text_value' => null,
                'unit' => 'g',
                'created_at' => '2026-10-22 08:15:00',
            ],
        ])
        ->and(recordCalibrationTestResultSession($connection))->toBe($session)
        ->and(recordCalibrationTestResultRows($connection, 'calibration_requirement_snapshot'))->toBe($requirement)
        ->and(recordCalibrationTestResultRows($connection, 'calibration_scope'))->toBe($scopes)
        ->and($connection->table('calibration_scope')->where('calibration_scope_id', 8)->value('test_table_snapshot'))->toBe($snapshot)
        ->and($connection->table('calibration_scope')->where('calibration_scope_id', 8)->value('test_profile_version_id'))->toBe(7)
        ->and($connection->table('calibration_reading')->count())->toBe(0)
        ->and($sql)->not->toContain('standard_value')
        ->and($sql)->not->toContain('average_value')
        ->and($sql)->not->toContain('correction_value')
        ->and($sql)->not->toContain('from "test_')
        ->and($sql)->not->toContain('calibration_reading')
        ->and($sql)->not->toContain('insert into "calibration_scope"')
        ->and($sql)->not->toContain('update "calibration"')
        ->and($sql)->not->toContain('update "calibration_scope"')
        ->and($sql)->not->toContain('update "calibration_requirement_snapshot"')
        ->and($calibration)->toBeInt()->toBeLessThan($requirementSql)
        ->and($requirementSql)->toBeLessThan($scopeSql)
        ->and($scopeSql)->toBeLessThan($resultSql)
        ->and($resultSql)->toBeLessThan($valueSql)
        ->and($valueSql)->toBeLessThan($insert)
        ->and($sql)->toContain('order by "scope_order" asc, "calibration_scope_id" asc')
        ->and($sql)->toContain('order by "calibration_test_result_id" asc')
        ->and($sql)->toContain('order by "calibration_test_result_id" asc, "column_key" asc')
        ->and($connection->getName())->toBe(RECORD_TEST_RESULT_CONNECTION)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('reuses an existing parent result row', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    recordCalibrationTestResultInsertResult($connection, ['calibration_test_result_id' => 4]);

    recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '25.5', $connection);

    expect($connection->table('calibration_test_result')->count())->toBe(1)
        ->and((array) $connection->table('calibration_test_result')->first())->toMatchArray([
            'calibration_test_result_id' => 4,
            'created_at' => '2020-01-01 00:00:00',
            'remarks' => null,
            'standard_value' => null,
            'average_value' => null,
            'correction_value' => null,
        ])
        ->and((array) $connection->table('calibration_result_value')->first())->toMatchArray([
            'calibration_test_result_id' => 4,
            'column_key' => 'reading',
            'numeric_value' => '25.5',
            'value_source' => 'engineer',
        ]);
});

it('updates the same cell and preserves its id and created_at', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    recordCalibrationTestResultInsertResult($connection);
    recordCalibrationTestResultInsertCell($connection);
    Carbon::setTestNow('2026-10-22 09:30:00');

    recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '-0.25', $connection);

    expect(recordCalibrationTestResultRows($connection, 'calibration_result_value'))->toBe([
        [
            'calibration_result_value_id' => 1,
            'calibration_test_result_id' => 1,
            'column_key' => 'reading',
            'value_source' => 'engineer',
            'data_type' => 'numeric',
            'numeric_value' => '-0.25',
            'text_value' => null,
            'unit' => 'g',
            'created_at' => '2020-01-01 00:00:00',
        ],
    ])
        ->and($connection->table('calibration_test_result')->count())->toBe(1)
        ->and($connection->table('calibration_test_result')->value('created_at'))->toBe('2020-01-01 00:00:00');
});

it('stores a manual numeric string unchanged', function (string $value) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);

    recordCalibrationTestResultService()->record(40, 8, 11, 'reading', $value, $connection);

    expect($connection->table('calibration_result_value')->value('numeric_value'))->toBe($value)
        ->and($connection->table('calibration_result_value')->value('text_value'))->toBeNull();
})->with([
    'zero' => '0',
    'negative' => '-12.5',
    'long decimal' => '1234567890.12345678901234567890',
]);

it('rejects an invalid numeric value', function (string $value) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', $value, $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The test result must be a decimal number.');

    expect($connection->table('calibration_test_result')->count())->toBe(0)
        ->and($connection->table('calibration_result_value')->count())->toBe(0);
})->with([
    'blank' => '',
    'whitespace' => ' ',
    'letters' => 'abc',
    'comma' => '1,5',
    'exponent' => '1e2',
    'nan' => 'NaN',
    'infinity' => 'Infinity',
    'plus' => '+1',
    'trailing dot' => '1.',
]);

it('inserts a text cell and preserves the supplied text', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);

    recordCalibrationTestResultService()->record(40, 8, 11, 'note', '  as typed  ', $connection);

    expect((array) $connection->table('calibration_result_value')->first())->toMatchArray([
        'column_key' => 'note',
        'value_source' => 'engineer',
        'data_type' => 'text',
        'numeric_value' => null,
        'text_value' => '  as typed  ',
        'unit' => null,
    ]);
});

it('updates a text cell without trimming it', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    recordCalibrationTestResultInsertResult($connection);
    recordCalibrationTestResultInsertCell($connection, [
        'column_key' => 'note',
        'data_type' => 'text',
        'numeric_value' => null,
        'text_value' => 'old',
        'unit' => null,
    ]);

    recordCalibrationTestResultService()->record(40, 8, 11, 'note', ' kept ', $connection);

    expect($connection->table('calibration_result_value')->value('text_value'))->toBe(' kept ')
        ->and($connection->table('calibration_result_value')->value('numeric_value'))->toBeNull()
        ->and($connection->table('calibration_result_value')->value('calibration_result_value_id'))->toBe(1);
});

it('rejects blank text', function (string $value) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'note', $value, $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The test result text must not be blank.');

    expect($connection->table('calibration_result_value')->count())->toBe(0);
})->with([
    'empty' => '',
    'whitespace' => '   ',
]);

it('stores a whole-number duration in numeric_value', function (string $value) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);

    recordCalibrationTestResultService()->record(40, 8, 11, 'elapsed', $value, $connection);

    expect((array) $connection->table('calibration_result_value')->first())->toMatchArray([
        'column_key' => 'elapsed',
        'data_type' => 'duration',
        'numeric_value' => $value,
        'text_value' => null,
        'unit' => 's',
        'value_source' => 'engineer',
    ]);
})->with([
    'zero' => '0',
    'one' => '1',
    'sixty' => '60',
    'hour' => '3600',
]);

it('rejects a duration that is not a non-negative whole number', function (string $value) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'elapsed', $value, $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The test result duration must be a whole number of seconds.');

    expect($connection->table('calibration_result_value')->count())->toBe(0);
})->with([
    'negative' => '-1',
    'fraction' => '1.5',
    'clock' => '00:01:30',
    'letters' => 'abc',
    'blank' => '',
    'whitespace' => ' ',
]);

it('preserves a null frozen unit', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);

    recordCalibrationTestResultService()->record(40, 8, 11, 'note', 'lab', $connection);

    expect($connection->table('calibration_result_value')->value('unit'))->toBeNull();
});

it('copies a frozen unit without trimming it', function () {
    $connection = recordCalibrationTestResultConnection();
    $snapshot = recordCalibrationTestResultSnapshot();
    $snapshot['columns'][0]['unit'] = ' g ';
    recordCalibrationTestResultSeed($connection, scope: ['test_table_snapshot' => $snapshot]);

    recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection);

    expect($connection->table('calibration_result_value')->value('unit'))->toBe(' g ');
});

it('rejects a frozen unit that cannot be stored', function (mixed $unit) {
    $connection = recordCalibrationTestResultConnection();
    $snapshot = recordCalibrationTestResultSnapshot();
    $snapshot['columns'][0]['unit'] = $unit;
    recordCalibrationTestResultSeed($connection, scope: ['test_table_snapshot' => $snapshot]);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The frozen column unit cannot be stored on a test result.');

    expect($connection->table('calibration_result_value')->count())->toBe(0);
})->with([
    'whitespace' => '   ',
    'too long' => '123456789012345678901234567890123456789012345678901',
    'not a string' => 12,
]);

it('stores a calculated column as an engineer value without evaluating its formula', function () {
    $connection = recordCalibrationTestResultConnection();
    $snapshot = recordCalibrationTestResultSeed($connection);

    recordCalibrationTestResultService()->record(40, 8, 11, 'average', '4', $connection);

    expect((array) $connection->table('calibration_result_value')->first())->toMatchArray([
        'column_key' => 'average',
        'value_source' => 'engineer',
        'numeric_value' => '4',
        'text_value' => null,
    ])
        ->and($connection->table('calibration_scope')->value('test_table_snapshot'))->toBe($snapshot)
        ->and($snapshot)->toContain('"formula_code":"average"')
        ->and($snapshot)->toContain('"reading"');
});

it('replaces a stored calculated source with engineer on update', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    recordCalibrationTestResultInsertResult($connection);
    recordCalibrationTestResultInsertCell($connection, [
        'column_key' => 'average',
        'value_source' => 'calculated',
        'numeric_value' => '1',
    ]);

    recordCalibrationTestResultService()->record(40, 8, 11, 'average', '5', $connection);

    expect($connection->table('calibration_result_value')->value('value_source'))->toBe('engineer')
        ->and($connection->table('calibration_result_value')->value('numeric_value'))->toBe('5')
        ->and($connection->table('calibration_result_value')->value('calibration_result_value_id'))->toBe(1);
});

it('rejects a template column and leaves template values untouched', function () {
    $connection = recordCalibrationTestResultConnection();
    $snapshot = recordCalibrationTestResultSeed($connection);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'nominal', '100', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'A template column cannot be entered manually.');

    expect($connection->table('calibration_test_result')->count())->toBe(0)
        ->and($connection->table('calibration_result_value')->count())->toBe(0)
        ->and($connection->table('calibration_scope')->value('test_table_snapshot'))->toBe($snapshot)
        ->and($snapshot)->toContain('"numeric_value":"100"');
});

it('records a required cell while an optional cell stays empty', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);

    recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection);

    expect($connection->table('calibration_result_value')->pluck('column_key')->all())->toBe(['reading'])
        ->and($connection->table('calibration_test_result')->where('test_point_id', 12)->count())->toBe(0)
        ->and($connection->table('calibration')->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('records an optional cell', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);

    recordCalibrationTestResultService()->record(40, 8, 11, 'average', '2', $connection);

    expect($connection->table('calibration_result_value')->value('column_key'))->toBe('average')
        ->and($connection->table('calibration_result_value')->value('value_source'))->toBe('engineer');
});

it('rejects a not-configured column', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'spare', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'A not-configured test component cannot be entered.');

    expect($connection->table('calibration_result_value')->count())->toBe(0);
});

it('applies the frozen data type when an existing cell is updated', function () {
    $connection = recordCalibrationTestResultConnection();
    $snapshot = recordCalibrationTestResultSnapshot();
    $snapshot['columns'][0]['data_type'] = 'text';
    $snapshot['columns'][0]['unit'] = null;
    recordCalibrationTestResultSeed($connection, scope: ['test_table_snapshot' => $snapshot]);
    recordCalibrationTestResultInsertResult($connection);
    recordCalibrationTestResultInsertCell($connection);

    recordCalibrationTestResultService()->record(40, 8, 11, 'reading', 'kept', $connection);

    expect((array) $connection->table('calibration_result_value')->first())->toMatchArray([
        'calibration_result_value_id' => 1,
        'data_type' => 'text',
        'numeric_value' => null,
        'text_value' => 'kept',
        'unit' => null,
        'created_at' => '2020-01-01 00:00:00',
    ]);
});

it('deletes the cell and the empty parent when the last cell is cleared', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    recordCalibrationTestResultInsertResult($connection, ['remarks' => '   ']);
    recordCalibrationTestResultInsertCell($connection);

    recordCalibrationTestResultService()->record(40, 8, 11, 'reading', null, $connection);

    expect($connection->table('calibration_result_value')->count())->toBe(0)
        ->and($connection->table('calibration_test_result')->count())->toBe(0)
        ->and($connection->table('calibration_scope')->count())->toBe(1)
        ->and($connection->table('calibration')->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('does nothing when the cell is already absent', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);

    recordCalibrationTestResultService()->record(40, 8, 11, 'reading', null, $connection);

    expect($connection->table('calibration_test_result')->count())->toBe(0)
        ->and($connection->table('calibration_result_value')->count())->toBe(0);
});

it('keeps the parent and the other cell when one cell is cleared', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    recordCalibrationTestResultInsertResult($connection, ['remarks' => 'keep me']);
    recordCalibrationTestResultInsertCell($connection);
    recordCalibrationTestResultInsertCell($connection, [
        'calibration_result_value_id' => 2,
        'column_key' => 'note',
        'data_type' => 'text',
        'numeric_value' => null,
        'text_value' => 'stay',
        'unit' => null,
    ]);

    recordCalibrationTestResultService()->record(40, 8, 11, 'reading', null, $connection);

    expect($connection->table('calibration_test_result')->count())->toBe(1)
        ->and($connection->table('calibration_test_result')->value('remarks'))->toBe('keep me')
        ->and($connection->table('calibration_result_value')->pluck('column_key')->all())->toBe(['note'])
        ->and($connection->table('calibration_result_value')->value('text_value'))->toBe('stay');
});

it('clears a required cell while the session stays in progress', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    recordCalibrationTestResultInsertResult($connection);
    recordCalibrationTestResultInsertCell($connection, [
        'calibration_result_value_id' => 2,
        'column_key' => 'note',
        'data_type' => 'text',
        'numeric_value' => null,
        'text_value' => 'stay',
        'unit' => null,
    ]);

    recordCalibrationTestResultService()->record(40, 8, 11, 'reading', null, $connection);

    expect($connection->table('calibration_result_value')->where('column_key', 'reading')->count())->toBe(0)
        ->and($connection->table('calibration')->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('leaves another scope, test point, and column unchanged', function () {
    $connection = recordCalibrationTestResultConnection();
    $snapshot = recordCalibrationTestResultSeed($connection);
    $connection->table('calibration_scope')->insert([
        'calibration_scope_id' => 9,
        'calibration_id' => 40,
        'instrument_type_id' => 12,
        'test_profile_id' => 5,
        'test_profile_version_id' => 7,
        'test_table_snapshot' => $snapshot,
        'scope_order' => 2,
        'remarks' => 'other scope',
        'created_at' => '2024-01-01 00:00:00',
    ]);
    recordCalibrationTestResultInsertResult($connection, [
        'calibration_test_result_id' => 5,
        'calibration_scope_id' => 9,
    ]);
    recordCalibrationTestResultInsertCell($connection, [
        'calibration_result_value_id' => 8,
        'calibration_test_result_id' => 5,
        'numeric_value' => '7',
    ]);
    recordCalibrationTestResultInsertResult($connection, [
        'calibration_test_result_id' => 6,
        'test_point_id' => 12,
    ]);
    recordCalibrationTestResultInsertCell($connection, [
        'calibration_result_value_id' => 9,
        'calibration_test_result_id' => 6,
        'numeric_value' => '8',
    ]);
    recordCalibrationTestResultInsertResult($connection);
    recordCalibrationTestResultInsertCell($connection, [
        'calibration_result_value_id' => 10,
        'column_key' => 'note',
        'data_type' => 'text',
        'numeric_value' => null,
        'text_value' => 'stay',
        'unit' => null,
    ]);

    recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '3', $connection);

    expect($connection->table('calibration_result_value')->where('calibration_result_value_id', 8)->value('numeric_value'))->toBe('7')
        ->and($connection->table('calibration_result_value')->where('calibration_result_value_id', 9)->value('numeric_value'))->toBe('8')
        ->and($connection->table('calibration_result_value')->where('calibration_result_value_id', 10)->value('text_value'))->toBe('stay')
        ->and($connection->table('calibration_scope')->where('calibration_scope_id', 9)->value('remarks'))->toBe('other scope');
});

it('rejects an unknown scope', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);

    expect(fn () => recordCalibrationTestResultService()->record(40, 77, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'This calibration scope does not belong to this calibration session.');
});

it('rejects a scope from another calibration', function () {
    $connection = recordCalibrationTestResultConnection();
    $snapshot = recordCalibrationTestResultSeed($connection);
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
    ]);
    $connection->table('calibration_scope')->insert([
        'calibration_scope_id' => 77,
        'calibration_id' => 41,
        'instrument_type_id' => 12,
        'test_profile_id' => 5,
        'test_profile_version_id' => 7,
        'test_table_snapshot' => $snapshot,
        'scope_order' => 1,
        'remarks' => null,
        'created_at' => '2024-01-01 00:00:00',
    ]);

    expect(fn () => recordCalibrationTestResultService()->record(40, 77, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'This calibration scope does not belong to this calibration session.');

    expect($connection->table('calibration_result_value')->count())->toBe(0);
});

it('rejects a scope whose instrument type does not match the calibration', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection, scope: ['instrument_type_id' => 99]);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The calibration scope instrument type does not match the calibration.');
});

it('rejects an unpinned scope', function (?int $versionId, mixed $snapshot) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection, scope: [
        'test_profile_version_id' => $versionId,
        'test_table_snapshot' => $snapshot,
    ]);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The calibration scope is not fully pinned.');
})->with([
    'missing version and snapshot' => [null, null],
    'missing snapshot' => [7, null],
    'blank snapshot' => [7, '   '],
]);

it('rejects a malformed snapshot', function (string $snapshot) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection, scope: ['test_table_snapshot' => $snapshot]);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The frozen test table snapshot is malformed.');
})->with([
    'broken json' => '{',
    'missing shape' => '{"schema_version":2}',
]);

it('rejects a snapshot that is not schema version 2', function (string $snapshot) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection, scope: ['test_table_snapshot' => $snapshot]);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The frozen test table snapshot is not schema version 2.');
})->with([
    'integer text' => '{"schema_version":1}',
    'string' => '{"schema_version":"1"}',
]);

it('rejects a snapshot that does not match the pinned version', function (string $field, int $value) {
    $connection = recordCalibrationTestResultConnection();
    $snapshot = recordCalibrationTestResultSnapshot();
    $snapshot[$field] = $value;
    recordCalibrationTestResultSeed($connection, scope: ['test_table_snapshot' => $snapshot]);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The frozen test table snapshot does not match the pinned version.');
})->with([
    'version' => ['test_profile_version_id', 99],
    'profile' => ['test_profile_id', 99],
]);

it('rejects a test point that is not in the frozen snapshot', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 99, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'This test point is not in the frozen test table.');
});

it('rejects a column that is not in the frozen snapshot', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'not_in_table', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'This column is not in the frozen test table.');
});

it('rejects a duplicated frozen test point', function () {
    $connection = recordCalibrationTestResultConnection();
    $snapshot = recordCalibrationTestResultSnapshot();
    $snapshot['rows'][] = recordCalibrationTestResultRow(11, 3);
    recordCalibrationTestResultSeed($connection, scope: ['test_table_snapshot' => $snapshot]);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The frozen test table identity is ambiguous.');
});

it('rejects a duplicated frozen column', function () {
    $connection = recordCalibrationTestResultConnection();
    $snapshot = recordCalibrationTestResultSnapshot();
    $snapshot['columns'][] = recordCalibrationTestResultColumn('reading', 'Reading again', 7, 'numeric', 'engineer', 'g', 'required');
    recordCalibrationTestResultSeed($connection, scope: ['test_table_snapshot' => $snapshot]);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The frozen test table identity is ambiguous.');
});

it('rejects a missing test profile requirement', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection, requirement: null);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The test profile requirement snapshot does not exist.');
});

it('rejects an unresolved not-configured test profile requirement', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection, requirement: [
        'frozen_mode' => InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
        'resolved_mode' => null,
    ]);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'An unresolved not-configured test profile requirement cannot record a test result.');
});

it('rejects an invalid frozen requirement mode', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection, requirement: [
        'frozen_mode' => 'mandatory',
        'resolved_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
    ]);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The test profile requirement mode cannot record a test result.');
});

it('records when the test profile requirement is optional', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection, requirement: [
        'frozen_mode' => InstrumentTypeRequirement::MODE_OPTIONAL,
    ]);

    recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection);

    expect($connection->table('calibration_result_value')->value('numeric_value'))->toBe('1');
});

it('records when a not-configured requirement was resolved', function (string $resolved) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection, requirement: [
        'frozen_mode' => InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
        'resolved_mode' => $resolved,
    ]);

    recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection);

    expect($connection->table('calibration_result_value')->value('numeric_value'))->toBe('1')
        ->and($connection->table('calibration_requirement_snapshot')->value('resolved_mode'))->toBe($resolved);
})->with([
    'required' => InstrumentTypeRequirement::MODE_REQUIRED,
    'optional' => InstrumentTypeRequirement::MODE_OPTIONAL,
]);

it('keeps a frozen required mode when the resolved mode is not configured', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection, requirement: [
        'frozen_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
        'resolved_mode' => InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
    ]);

    recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection);

    expect($connection->table('calibration_result_value')->value('numeric_value'))->toBe('1');
});

it('rejects a calibration that is not internal', function (string $method) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection, ['calibration_method' => $method]);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'Test results can be recorded only for an internal calibration session.');

    expect($connection->table('calibration_result_value')->count())->toBe(0);
})->with([
    'external' => Calibration::METHOD_EXTERNAL,
    'unsupported' => 'FIELD',
]);

it('rejects a session that is not in progress', function (string $status) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection, ['session_status' => $status]);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'Only an in-progress calibration session can record test results.');

    expect($connection->table('calibration')->value('session_status'))->toBe($status)
        ->and($connection->table('calibration_result_value')->count())->toBe(0);
})->with([
    'completed' => Calibration::STATUS_COMPLETED,
    'cancelled' => Calibration::STATUS_CANCELLED,
    'unsupported' => 'archived',
]);

it('rejects a calibration id below 1 before reading the session', function (int $calibrationId) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect(fn () => recordCalibrationTestResultService()->record($calibrationId, 8, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The calibration id must be at least 1.');

    expect(recordCalibrationTestResultSql($connection))->not->toContain('from "calibration"')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects a scope id below 1 before reading the session', function (int $scopeId) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect(fn () => recordCalibrationTestResultService()->record(40, $scopeId, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The calibration scope id must be at least 1.');

    expect(recordCalibrationTestResultSql($connection))->not->toContain('from "calibration"');
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects a test point id below 1 before reading the session', function (int $testPointId) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, $testPointId, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The test point id must be at least 1.');

    expect(recordCalibrationTestResultSql($connection))->not->toContain('from "calibration"');
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects an invalid column key before reading the session', function (string $columnKey) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, $columnKey, '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The column key is not a valid result identity.');

    expect(recordCalibrationTestResultSql($connection))->not->toContain('from "calibration"');
})->with([
    'blank' => '',
    'whitespace' => ' ',
    'uppercase' => 'Average',
    'leading digit' => '1reading',
    'hyphen' => 'a-b',
    'too long' => 'a'.str_repeat('b', 63),
]);

it('rejects a missing calibration', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);

    expect(fn () => recordCalibrationTestResultService()->record(41, 8, 11, 'reading', '1', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The calibration does not exist.');
});

it('rejects duplicate parent result rows', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    recordCalibrationTestResultInsertResult($connection, ['calibration_test_result_id' => 1]);
    recordCalibrationTestResultInsertResult($connection, ['calibration_test_result_id' => 2]);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '9', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'This test point has more than one result row.');

    expect($connection->table('calibration_test_result')->count())->toBe(2)
        ->and($connection->table('calibration_result_value')->count())->toBe(0);
});

it('rejects duplicate result cells', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    recordCalibrationTestResultInsertResult($connection);
    recordCalibrationTestResultInsertCell($connection, ['calibration_result_value_id' => 1, 'numeric_value' => '1']);
    recordCalibrationTestResultInsertCell($connection, ['calibration_result_value_id' => 2, 'numeric_value' => '2']);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '9', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'This test result cell is duplicated.');

    expect($connection->table('calibration_result_value')->orderBy('calibration_result_value_id')->pluck('numeric_value')->all())->toBe(['1', '2']);
});

it('refuses to remove a parent that still has remarks', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    recordCalibrationTestResultInsertResult($connection, ['remarks' => 'keep me']);
    recordCalibrationTestResultInsertCell($connection);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', null, $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The result row still holds remarks, readings, or legacy values and cannot be removed.');

    expect($connection->table('calibration_test_result')->value('remarks'))->toBe('keep me')
        ->and($connection->table('calibration_result_value')->count())->toBe(1);
});

it('refuses to remove a parent that still has a legacy value', function (string $column) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    recordCalibrationTestResultInsertResult($connection, [$column => '0']);
    recordCalibrationTestResultInsertCell($connection);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', null, $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The result row still holds remarks, readings, or legacy values and cannot be removed.');

    expect($connection->table('calibration_test_result')->value($column))->toBe('0')
        ->and($connection->table('calibration_result_value')->count())->toBe(1);
})->with([
    'standard' => 'standard_value',
    'average' => 'average_value',
    'correction' => 'correction_value',
]);

it('refuses to remove a parent that still has a reading', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    recordCalibrationTestResultInsertResult($connection);
    recordCalibrationTestResultInsertCell($connection);
    $connection->table('calibration_reading')->insert([
        'calibration_reading_id' => 1,
        'calibration_test_result_id' => 1,
        'reading_order' => 1,
        'reference_value' => '10',
        'instrument_value' => '10',
        'error_value' => null,
        'uncertainty_value' => null,
        'unit' => 'g',
        'created_at' => '2020-01-01 00:00:00',
    ]);

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', null, $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'The result row still holds remarks, readings, or legacy values and cannot be removed.');

    expect(recordCalibrationTestResultRows($connection, 'calibration_reading'))->toHaveCount(1)
        ->and($connection->table('calibration_reading')->value('reference_value'))->toBe('10')
        ->and($connection->table('calibration_result_value')->count())->toBe(1)
        ->and($connection->table('calibration_test_result')->count())->toBe(1);
});

it('rejects an unsafe connection before querying the session', function () {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('table');
    $unsafe->shouldNotReceive('transaction');

    expect(fn () => recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '1', $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');

    expect($connection->table('calibration_result_value')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('uses the supplied connection when its name is empty or unregistered', function (string $connectionName) {
    $connection = recordCalibrationTestResultConnection();
    recordCalibrationTestResultSeed($connection);
    $config = new ReflectionProperty($connection, 'config');
    $settings = $config->getValue($connection);
    $settings['name'] = $connectionName;
    $config->setValue($connection, $settings);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    recordCalibrationTestResultService()->record(40, 8, 11, 'reading', '25.5', $connection);

    expect($connection->getName())->toBe($connectionName)
        ->and($connection->table('calibration_result_value')->value('numeric_value'))->toBe('25.5')
        ->and(recordCalibrationTestResultSql($connection))->toContain('insert into "calibration_result_value"')
        ->and(recordCalibrationTestResultSql($default))->not->toContain('calibration_result_value')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty name' => '',
    'unregistered name' => 'unowned-record-test-result',
]);
