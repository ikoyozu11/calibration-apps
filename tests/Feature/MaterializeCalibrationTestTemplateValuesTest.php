<?php

use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\MaterializeCalibrationTestTemplateValues;
use App\CalibrationSessions\MaterializeCalibrationTestTemplateValuesException;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestRefusingPostgresConnector;

const MATERIALIZE_TEMPLATE_CONNECTION = 'materialize_calibration_test_template_sqlite';

afterEach(function () {
    Carbon::setTestNow();
});

function materializeCalibrationTemplateConnection(): Connection
{
    Carbon::setTestNow('2026-10-22 08:15:00');

    config([
        'database.connections.'.MATERIALIZE_TEMPLATE_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(MATERIALIZE_TEMPLATE_CONNECTION);

    $connection = DB::connection(MATERIALIZE_TEMPLATE_CONNECTION);
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

function materializeCalibrationTemplateService(): MaterializeCalibrationTestTemplateValues
{
    return app(MaterializeCalibrationTestTemplateValues::class);
}

/**
 * @param  array<string, mixed>  $calibration
 * @param  array<string, mixed>|null  $requirement
 * @param  array<string, mixed>|null  $scope
 */
function materializeCalibrationTemplateSeed(Connection $connection, array $calibration = [], ?array $requirement = [], ?array $scope = []): ?string
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

    if ($scope === null) {
        return null;
    }

    $snapshot = array_key_exists('test_table_snapshot', $scope)
        ? $scope['test_table_snapshot']
        : materializeCalibrationTemplateSnapshot();
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
function materializeCalibrationTemplateColumn(
    string $key,
    string $label,
    int $order,
    string $dataType,
    string $source,
    ?string $unit,
    string $rule,
    ?string $formula = null,
    array $inputs = [],
): array {
    return [
        'column_key' => $key,
        'column_label' => $label,
        'column_order' => $order,
        'data_type' => $dataType,
        'value_source' => $source,
        'unit' => $unit,
        'display_scale' => null,
        'formula_code' => $formula,
        'formula_inputs' => $inputs,
        'completion_rule' => $rule,
    ];
}

/**
 * @param  array<string, array{numeric_value: ?string, text_value: ?string, unit: ?string}>  $values
 * @return array<string, mixed>
 */
function materializeCalibrationTemplateRow(int $pointId, int $order, array $values = []): array
{
    return [
        'test_point_id' => $pointId,
        'point_order' => $order,
        'nominal_value' => '100',
        'unit' => 'g',
        'tolerance_plus' => '0.1',
        'tolerance_minus' => '0.1',
        'description' => null,
        'template_values' => $values,
    ];
}

/**
 * @return array{numeric_value: ?string, text_value: ?string, unit: ?string}
 */
function materializeCalibrationTemplateEntry(?string $numeric, ?string $text, ?string $unit = null): array
{
    return [
        'numeric_value' => $numeric,
        'text_value' => $text,
        'unit' => $unit,
    ];
}

/**
 * @param  list<array<string, mixed>>|null  $columns
 * @param  list<array<string, mixed>>|null  $rows
 * @return array<string, mixed>
 */
function materializeCalibrationTemplateSnapshot(?array $columns = null, ?array $rows = null): array
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
        'columns' => $columns ?? [
            materializeCalibrationTemplateColumn('nominal', 'Nominal', 1, 'numeric', 'template', 'kg', 'required'),
            materializeCalibrationTemplateColumn('average', 'Average', 2, 'numeric', 'calculated', 'kg', 'optional', 'average', ['nominal']),
            materializeCalibrationTemplateColumn('spare', 'Spare', 3, 'numeric', 'engineer', 'kg', 'not_configured'),
        ],
        'rows' => $rows ?? [
            materializeCalibrationTemplateRow(11, 1, [
                'nominal' => materializeCalibrationTemplateEntry('100.5', null, 'g'),
            ]),
        ],
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function materializeCalibrationTemplateInsertResult(Connection $connection, array $overrides = []): void
{
    $connection->table('calibration_test_result')->insert(array_merge([
        'calibration_test_result_id' => 4,
        'calibration_scope_id' => 8,
        'test_point_id' => 11,
        'result_status' => 'recorded',
        'remarks' => 'keep me',
        'created_at' => '2020-01-01 00:00:00',
        'standard_value' => '10',
        'average_value' => '9',
        'correction_value' => '1',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function materializeCalibrationTemplateInsertCell(Connection $connection, array $overrides = []): void
{
    $connection->table('calibration_result_value')->insert(array_merge([
        'calibration_result_value_id' => 6,
        'calibration_test_result_id' => 4,
        'column_key' => 'nominal',
        'value_source' => 'template',
        'data_type' => 'numeric',
        'numeric_value' => '100.5',
        'text_value' => null,
        'unit' => 'g',
        'created_at' => '2020-01-01 00:00:00',
    ], $overrides));
}

function materializeCalibrationTemplateSql(Connection $connection): string
{
    return implode("\n", array_column($connection->getQueryLog(), 'query'));
}

/**
 * @return array<string, mixed>
 */
function materializeCalibrationTemplateSession(Connection $connection, int $calibrationId = 40): array
{
    return (array) $connection->table('calibration')->where('calibration_id', $calibrationId)->first();
}

/**
 * @return list<array<string, mixed>>
 */
function materializeCalibrationTemplateRows(Connection $connection, string $table): array
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

/**
 * @param  list<array<string, mixed>>  $columns
 * @param  list<array<string, mixed>>  $rows
 */
function materializeCalibrationTemplateUse(Connection $connection, array $columns, array $rows, array $calibration = [], ?array $requirement = []): ?string
{
    return materializeCalibrationTemplateSeed($connection, $calibration, $requirement, [
        'test_table_snapshot' => materializeCalibrationTemplateSnapshot($columns, $rows),
    ]);
}

it('materializes a required numeric template cell and leaves the snapshot unchanged', function () {
    $connection = materializeCalibrationTemplateConnection();
    $snapshot = materializeCalibrationTemplateSeed($connection);
    $session = materializeCalibrationTemplateSession($connection);
    $requirement = materializeCalibrationTemplateRows($connection, 'calibration_requirement_snapshot');
    $scopes = materializeCalibrationTemplateRows($connection, 'calibration_scope');
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    materializeCalibrationTemplateService()->materialize(40, $connection);

    $sql = materializeCalibrationTemplateSql($connection);
    $calibration = strpos($sql, 'from "calibration"');
    $requirementSql = strpos($sql, 'from "calibration_requirement_snapshot"');
    $scopeSql = strpos($sql, 'from "calibration_scope"');
    $resultSql = strpos($sql, 'from "calibration_test_result"');
    $valueSql = strpos($sql, 'from "calibration_result_value"');
    $insert = strpos($sql, 'insert into "calibration_test_result"');

    expect(materializeCalibrationTemplateRows($connection, 'calibration_test_result'))->toBe([
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
        ->and(materializeCalibrationTemplateRows($connection, 'calibration_result_value'))->toBe([
            [
                'calibration_result_value_id' => 1,
                'calibration_test_result_id' => 1,
                'column_key' => 'nominal',
                'value_source' => 'template',
                'data_type' => 'numeric',
                'numeric_value' => '100.5',
                'text_value' => null,
                'unit' => 'g',
                'created_at' => '2026-10-22 08:15:00',
            ],
        ])
        ->and(materializeCalibrationTemplateSession($connection))->toBe($session)
        ->and(materializeCalibrationTemplateRows($connection, 'calibration_requirement_snapshot'))->toBe($requirement)
        ->and(materializeCalibrationTemplateRows($connection, 'calibration_scope'))->toBe($scopes)
        ->and($connection->table('calibration_scope')->value('test_table_snapshot'))->toBe($snapshot)
        ->and($connection->table('calibration_scope')->value('test_profile_version_id'))->toBe(7)
        ->and($snapshot)->toContain('"formula_code":"average"')
        ->and($snapshot)->toContain('"unit":"kg"')
        ->and($sql)->not->toContain('standard_value')
        ->and($sql)->not->toContain('from "test_')
        ->and($sql)->not->toContain('calibration_reading')
        ->and($sql)->not->toContain('update "calibration"')
        ->and($sql)->not->toContain('update "calibration_scope"')
        ->and($sql)->not->toContain('update "calibration_requirement_snapshot"')
        ->and($sql)->not->toContain('update "calibration_test_result"')
        ->and($calibration)->toBeInt()->toBeLessThan($requirementSql)
        ->and($requirementSql)->toBeLessThan($scopeSql)
        ->and($scopeSql)->toBeLessThan($resultSql)
        ->and($resultSql)->toBeLessThan($valueSql)
        ->and($valueSql)->toBeLessThan($insert)
        ->and($sql)->toContain('order by "scope_order" asc, "calibration_scope_id" asc')
        ->and($sql)->toContain('order by "calibration_test_result_id" asc')
        ->and($sql)->toContain('order by "calibration_test_result_id" asc, "column_key" asc')
        ->and($connection->getName())->toBe(MATERIALIZE_TEMPLATE_CONNECTION)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('materializes a required text template cell', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('label', 'Label', 1, 'text', 'template', 'kg', 'required'),
    ], [
        materializeCalibrationTemplateRow(11, 1, [
            'label' => materializeCalibrationTemplateEntry(null, '  as typed  ', null),
        ]),
    ]);

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect((array) $connection->table('calibration_result_value')->first())->toMatchArray([
        'column_key' => 'label',
        'value_source' => 'template',
        'data_type' => 'text',
        'numeric_value' => null,
        'text_value' => '  as typed  ',
        'unit' => null,
    ]);
});

it('materializes a required duration template cell', function (string $value) {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('elapsed', 'Elapsed', 1, 'duration', 'template', 'h', 'required'),
    ], [
        materializeCalibrationTemplateRow(11, 1, [
            'elapsed' => materializeCalibrationTemplateEntry($value, null, 's'),
        ]),
    ]);

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect((array) $connection->table('calibration_result_value')->first())->toMatchArray([
        'column_key' => 'elapsed',
        'data_type' => 'duration',
        'numeric_value' => $value,
        'text_value' => null,
        'unit' => 's',
        'value_source' => 'template',
    ]);
})->with([
    'zero' => '0',
    'one' => '1',
    'sixty' => '60',
    'hour' => '3600',
]);

it('materializes a present optional template cell', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('nominal', 'Nominal', 1, 'numeric', 'template', 'kg', 'optional'),
    ], [
        materializeCalibrationTemplateRow(11, 1, [
            'nominal' => materializeCalibrationTemplateEntry('2', null, 'g'),
        ]),
    ]);

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect($connection->table('calibration_result_value')->value('numeric_value'))->toBe('2')
        ->and($connection->table('calibration_result_value')->value('value_source'))->toBe('template');
});

it('skips an optional template cell without a frozen value', function (array $values) {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('label', 'Label', 1, 'text', 'template', 'kg', 'optional'),
    ], [
        materializeCalibrationTemplateRow(11, 1, $values),
    ]);

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect($connection->table('calibration_test_result')->count())->toBe(0)
        ->and($connection->table('calibration_result_value')->count())->toBe(0);
})->with([
    'missing key' => [[]],
    'null entry' => [['label' => null]],
    'null fields' => [['label' => materializeCalibrationTemplateEntry(null, null, null)]],
    'blank text' => [['label' => materializeCalibrationTemplateEntry(null, '   ', null)]],
]);

it('rejects a required template value that is missing or null', function (array $values) {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('nominal', 'Nominal', 1, 'numeric', 'template', 'kg', 'required'),
    ], [
        materializeCalibrationTemplateRow(11, 1, $values),
    ]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'A required template value is missing.');

    expect($connection->table('calibration_test_result')->count())->toBe(0)
        ->and($connection->table('calibration_result_value')->count())->toBe(0);
})->with([
    'missing key' => [[]],
    'null entry' => [['nominal' => null]],
    'null fields' => [['nominal' => materializeCalibrationTemplateEntry(null, null, 'g')]],
]);

it('rejects required blank template text', function (string $text) {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('label', 'Label', 1, 'text', 'template', null, 'required'),
    ], [
        materializeCalibrationTemplateRow(11, 1, [
            'label' => materializeCalibrationTemplateEntry(null, $text, null),
        ]),
    ]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'The frozen template text is invalid.');

    expect($connection->table('calibration_result_value')->count())->toBe(0);
})->with([
    'empty' => '',
    'whitespace' => '   ',
]);

it('rejects a malformed required numeric template', function (string $value) {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('nominal', 'Nominal', 1, 'numeric', 'template', null, 'required'),
    ], [
        materializeCalibrationTemplateRow(11, 1, [
            'nominal' => materializeCalibrationTemplateEntry($value, null, null),
        ]),
    ]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'The frozen template numeric value is invalid.');

    expect($connection->table('calibration_result_value')->count())->toBe(0);
})->with([
    'comma' => '1,5',
    'exponent' => '1e2',
    'nan' => 'NaN',
    'infinity' => 'Infinity',
    'plus' => '+1',
    'blank' => '',
]);

it('stores a numeric template string unchanged', function (string $value) {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('nominal', 'Nominal', 1, 'numeric', 'template', 'kg', 'required'),
    ], [
        materializeCalibrationTemplateRow(11, 1, [
            'nominal' => materializeCalibrationTemplateEntry($value, null, 'g'),
        ]),
    ]);

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect($connection->table('calibration_result_value')->value('numeric_value'))->toBe($value)
        ->and($connection->table('calibration_result_value')->value('unit'))->toBe('g');
})->with([
    'zero' => '0',
    'negative' => '-12.5',
    'long decimal' => '1234567890.12345678901234567890',
]);

it('rejects a duration template that is not a non-negative whole number', function (string $value) {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('elapsed', 'Elapsed', 1, 'duration', 'template', null, 'required'),
    ], [
        materializeCalibrationTemplateRow(11, 1, [
            'elapsed' => materializeCalibrationTemplateEntry($value, null, null),
        ]),
    ]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'The frozen template duration is invalid.');

    expect($connection->table('calibration_result_value')->count())->toBe(0);
})->with([
    'negative' => '-1',
    'fraction' => '1.5',
    'clock' => '00:01:30',
    'blank' => '',
]);

it('preserves a null template unit and does not copy the column unit', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('nominal', 'Nominal', 1, 'numeric', 'template', 'kg', 'required'),
    ], [
        materializeCalibrationTemplateRow(11, 1, [
            'nominal' => materializeCalibrationTemplateEntry('1', null, null),
        ]),
    ]);

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect($connection->table('calibration_result_value')->value('unit'))->toBeNull()
        ->and($connection->table('calibration_result_value')->value('numeric_value'))->toBe('1');
});

it('rejects a template unit that cannot be stored', function (mixed $unit) {
    $connection = materializeCalibrationTemplateConnection();
    $entry = [
        'numeric_value' => '1',
        'text_value' => null,
        'unit' => $unit,
    ];
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('nominal', 'Nominal', 1, 'numeric', 'template', 'kg', 'required'),
    ], [
        materializeCalibrationTemplateRow(11, 1, [
            'nominal' => $entry,
        ]),
    ]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'The frozen template unit cannot be stored on a test result.');

    expect($connection->table('calibration_result_value')->count())->toBe(0);
})->with([
    'whitespace' => '   ',
    'too long' => '123456789012345678901234567890123456789012345678901',
    'not a string' => 12,
]);

it('materializes every frozen row and shares one parent across template columns', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('nominal', 'Nominal', 1, 'numeric', 'template', 'kg', 'required'),
        materializeCalibrationTemplateColumn('label', 'Label', 2, 'text', 'template', 'kg', 'required'),
    ], [
        materializeCalibrationTemplateRow(11, 1, [
            'nominal' => materializeCalibrationTemplateEntry('1', null, 'g'),
            'label' => materializeCalibrationTemplateEntry(null, 'low', null),
        ]),
        materializeCalibrationTemplateRow(12, 2, [
            'nominal' => materializeCalibrationTemplateEntry('2', null, 'g'),
            'label' => materializeCalibrationTemplateEntry(null, 'high', null),
        ]),
    ]);

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect($connection->table('calibration_test_result')->count())->toBe(2)
        ->and($connection->table('calibration_result_value')->count())->toBe(4)
        ->and($connection->table('calibration_result_value')->where('calibration_test_result_id', 1)->count())->toBe(2)
        ->and($connection->table('calibration_result_value')->where('column_key', 'label')->pluck('text_value')->all())->toBe(['low', 'high']);
});

it('materializes every pinned scope', function () {
    $connection = materializeCalibrationTemplateConnection();
    $snapshot = materializeCalibrationTemplateSeed($connection);
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

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect($connection->table('calibration_test_result')->orderBy('calibration_scope_id')->pluck('calibration_scope_id')->all())->toBe([8, 9])
        ->and($connection->table('calibration_result_value')->count())->toBe(2)
        ->and($connection->table('calibration_scope')->where('calibration_scope_id', 9)->value('remarks'))->toBe('other scope');
});

it('reuses a parent and preserves remarks, legacy values, and readings', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection);
    materializeCalibrationTemplateInsertResult($connection);
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
    $parent = (array) $connection->table('calibration_test_result')->first();
    $reading = materializeCalibrationTemplateRows($connection, 'calibration_reading');

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect($connection->table('calibration_test_result')->count())->toBe(1)
        ->and((array) $connection->table('calibration_test_result')->first())->toBe($parent)
        ->and(materializeCalibrationTemplateRows($connection, 'calibration_reading'))->toBe($reading)
        ->and($connection->table('calibration_result_value')->value('calibration_test_result_id'))->toBe(4)
        ->and($connection->table('calibration_result_value')->value('value_source'))->toBe('template');
});

it('creates no parent when the pinned table has no template columns', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('reading', 'Reading', 1, 'numeric', 'engineer', 'g', 'required'),
        materializeCalibrationTemplateColumn('average', 'Average', 2, 'numeric', 'calculated', 'g', 'optional', 'average', ['reading']),
    ], [
        materializeCalibrationTemplateRow(11, 1, []),
    ]);

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect($connection->table('calibration_test_result')->count())->toBe(0)
        ->and($connection->table('calibration_result_value')->count())->toBe(0);
});

it('rejects a required test profile with no scopes', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection, scope: null);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'A required test profile has no pinned test table.');
});

it('does nothing when an optional test profile has no scopes', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection, requirement: [
        'frozen_mode' => InstrumentTypeRequirement::MODE_OPTIONAL,
    ], scope: null);

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect($connection->table('calibration_scope')->count())->toBe(0)
        ->and($connection->table('calibration_test_result')->count())->toBe(0);
});

it('rejects a scope set that is not fully pinned', function (?int $versionId, mixed $snapshot) {
    $connection = materializeCalibrationTemplateConnection();
    $pinned = materializeCalibrationTemplateSeed($connection);
    $connection->table('calibration_scope')->insert([
        'calibration_scope_id' => 9,
        'calibration_id' => 40,
        'instrument_type_id' => 12,
        'test_profile_id' => 5,
        'test_profile_version_id' => $versionId,
        'test_table_snapshot' => $snapshot,
        'scope_order' => 2,
        'remarks' => null,
        'created_at' => '2024-01-01 00:00:00',
    ]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'A calibration scope is not fully pinned.');

    expect($connection->table('calibration_result_value')->count())->toBe(0)
        ->and($connection->table('calibration_scope')->where('calibration_scope_id', 8)->value('test_table_snapshot'))->toBe($pinned);
})->with([
    'missing snapshot' => [7, null],
    'blank snapshot' => [7, '   '],
    'missing version' => [null, '{"schema_version":2}'],
]);

it('rejects a snapshot that is not schema version 2', function (string $snapshot) {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection, scope: ['test_table_snapshot' => $snapshot]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'The frozen test table snapshot is not schema version 2.');
})->with([
    'integer text' => '{"schema_version":1}',
    'string' => '{"schema_version":"1"}',
]);

it('rejects a malformed snapshot', function (string $snapshot) {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection, scope: ['test_table_snapshot' => $snapshot]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'The frozen test table snapshot is malformed.');
})->with([
    'broken json' => '{',
    'missing shape' => '{"schema_version":2}',
]);

it('rejects a snapshot that does not match the pinned version', function (string $field, int $value) {
    $connection = materializeCalibrationTemplateConnection();
    $snapshot = materializeCalibrationTemplateSnapshot();
    $snapshot[$field] = $value;
    materializeCalibrationTemplateSeed($connection, scope: ['test_table_snapshot' => $snapshot]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'The frozen test table snapshot does not match the pinned version.');
})->with([
    'version' => ['test_profile_version_id', 99],
    'profile' => ['test_profile_id', 99],
]);

it('rejects a scope whose instrument type does not match the calibration', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection, scope: ['instrument_type_id' => 99]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'The calibration scope instrument type does not match the calibration.');

    expect($connection->table('calibration_result_value')->count())->toBe(0);
});

it('rejects a duplicated frozen test point', function () {
    $connection = materializeCalibrationTemplateConnection();
    $row = materializeCalibrationTemplateRow(11, 1, [
        'nominal' => materializeCalibrationTemplateEntry('1', null, 'g'),
    ]);
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('nominal', 'Nominal', 1, 'numeric', 'template', null, 'required'),
    ], [$row, materializeCalibrationTemplateRow(11, 2, [
        'nominal' => materializeCalibrationTemplateEntry('2', null, 'g'),
    ])]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'The frozen test table row identity is ambiguous.');
});

it('rejects a duplicated frozen column', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('nominal', 'Nominal', 1, 'numeric', 'template', null, 'required'),
        materializeCalibrationTemplateColumn('nominal', 'Nominal again', 2, 'numeric', 'template', null, 'required'),
    ], [
        materializeCalibrationTemplateRow(11, 1, [
            'nominal' => materializeCalibrationTemplateEntry('1', null, null),
        ]),
    ]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'The frozen test table column identity is ambiguous.');
});

it('rejects a not-configured template column', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('nominal', 'Nominal', 1, 'numeric', 'template', null, 'not_configured'),
    ], [
        materializeCalibrationTemplateRow(11, 1, [
            'nominal' => materializeCalibrationTemplateEntry('1', null, null),
        ]),
    ]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'A not-configured template column cannot be materialized.');

    expect($connection->table('calibration_result_value')->count())->toBe(0);
});

it('leaves an identical template cell and its created_at unchanged', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection);
    materializeCalibrationTemplateInsertResult($connection, [
        'remarks' => null,
        'result_status' => null,
        'standard_value' => null,
        'average_value' => null,
        'correction_value' => null,
    ]);
    materializeCalibrationTemplateInsertCell($connection);
    Carbon::setTestNow('2026-10-22 09:30:00');

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect(materializeCalibrationTemplateRows($connection, 'calibration_result_value'))->toBe([
        [
            'calibration_result_value_id' => 6,
            'calibration_test_result_id' => 4,
            'column_key' => 'nominal',
            'value_source' => 'template',
            'data_type' => 'numeric',
            'numeric_value' => '100.5',
            'text_value' => null,
            'unit' => 'g',
            'created_at' => '2020-01-01 00:00:00',
        ],
    ])
        ->and($connection->table('calibration_test_result')->count())->toBe(1);
});

it('is idempotent when materialize is called again', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection);
    $service = materializeCalibrationTemplateService();
    $service->materialize(40, $connection);
    Carbon::setTestNow('2026-10-22 09:30:00');

    $service->materialize(40, $connection);

    expect($connection->table('calibration_result_value')->count())->toBe(1)
        ->and($connection->table('calibration_test_result')->count())->toBe(1)
        ->and($connection->table('calibration_result_value')->value('calibration_result_value_id'))->toBe(1)
        ->and($connection->table('calibration_result_value')->value('created_at'))->toBe('2026-10-22 08:15:00')
        ->and($connection->table('calibration_result_value')->value('numeric_value'))->toBe('100.5');
});

it('treats an equivalent decimal representation as the same template cell', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('nominal', 'Nominal', 1, 'numeric', 'template', null, 'required'),
    ], [
        materializeCalibrationTemplateRow(11, 1, [
            'nominal' => materializeCalibrationTemplateEntry('100.5', null, 'g'),
        ]),
    ]);
    materializeCalibrationTemplateInsertResult($connection, [
        'remarks' => null,
        'result_status' => null,
        'standard_value' => null,
        'average_value' => null,
        'correction_value' => null,
    ]);
    materializeCalibrationTemplateInsertCell($connection, ['numeric_value' => '100.50']);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect($connection->table('calibration_result_value')->value('numeric_value'))->toBe('100.50')
        ->and($connection->table('calibration_result_value')->value('calibration_result_value_id'))->toBe(6)
        ->and($connection->table('calibration_result_value')->value('created_at'))->toBe('2020-01-01 00:00:00')
        ->and(materializeCalibrationTemplateSql($connection))->not->toContain('update "calibration_result_value"')
        ->and($connection->table('calibration_result_value')->count())->toBe(1);
});

it('rejects an existing cell that is not the same template value', function (array $cell, string $message) {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection);
    materializeCalibrationTemplateInsertResult($connection);
    materializeCalibrationTemplateInsertCell($connection, $cell);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, $message);

    expect($connection->table('calibration_result_value')->count())->toBe(1)
        ->and((array) $connection->table('calibration_result_value')->first())->toMatchArray($cell);
})->with([
    'engineer source' => [['value_source' => 'engineer'], 'An existing result cell conflicts with the frozen template value.'],
    'calculated source' => [['value_source' => 'calculated'], 'An existing result cell conflicts with the frozen template value.'],
    'different value' => [['numeric_value' => '2'], 'An existing result cell conflicts with the frozen template value.'],
    'different unit' => [['unit' => 'kg'], 'An existing result cell conflicts with the frozen template value.'],
    'different type' => [[
        'data_type' => 'text',
        'numeric_value' => null,
        'text_value' => '100.5',
        'unit' => null,
    ], 'An existing result cell conflicts with the frozen template value.'],
]);

it('rolls back cells inserted earlier in the call when a later cell conflicts', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('nominal', 'Nominal', 1, 'numeric', 'template', null, 'required'),
        materializeCalibrationTemplateColumn('label', 'Label', 2, 'text', 'template', null, 'required'),
    ], [
        materializeCalibrationTemplateRow(11, 1, [
            'nominal' => materializeCalibrationTemplateEntry('1', null, 'g'),
            'label' => materializeCalibrationTemplateEntry(null, 'bench', null),
        ]),
    ]);
    materializeCalibrationTemplateInsertResult($connection);
    materializeCalibrationTemplateInsertCell($connection, [
        'column_key' => 'label',
        'value_source' => 'engineer',
        'data_type' => 'text',
        'numeric_value' => null,
        'text_value' => 'stay',
        'unit' => null,
    ]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'An existing result cell conflicts with the frozen template value.');

    expect($connection->table('calibration_result_value')->where('column_key', 'nominal')->count())->toBe(0)
        ->and($connection->table('calibration_result_value')->value('text_value'))->toBe('stay')
        ->and($connection->table('calibration_result_value')->value('value_source'))->toBe('engineer')
        ->and($connection->table('calibration_test_result')->count())->toBe(1);
});

it('rolls back when a later required template value is missing', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateUse($connection, [
        materializeCalibrationTemplateColumn('nominal', 'Nominal', 1, 'numeric', 'template', null, 'required'),
    ], [
        materializeCalibrationTemplateRow(11, 1, [
            'nominal' => materializeCalibrationTemplateEntry('1', null, 'g'),
        ]),
        materializeCalibrationTemplateRow(12, 2, []),
    ]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'A required template value is missing.');

    expect($connection->table('calibration_test_result')->count())->toBe(0)
        ->and($connection->table('calibration_result_value')->count())->toBe(0);
});

it('rejects duplicate parent result rows', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection);
    materializeCalibrationTemplateInsertResult($connection, ['calibration_test_result_id' => 1]);
    materializeCalibrationTemplateInsertResult($connection, ['calibration_test_result_id' => 2]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'This test point has more than one result row.');

    expect($connection->table('calibration_test_result')->count())->toBe(2)
        ->and($connection->table('calibration_result_value')->count())->toBe(0);
});

it('rejects duplicate result cells', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection);
    materializeCalibrationTemplateInsertResult($connection, ['calibration_test_result_id' => 1]);
    materializeCalibrationTemplateInsertCell($connection, [
        'calibration_result_value_id' => 1,
        'calibration_test_result_id' => 1,
        'numeric_value' => '1',
    ]);
    materializeCalibrationTemplateInsertCell($connection, [
        'calibration_result_value_id' => 2,
        'calibration_test_result_id' => 1,
        'numeric_value' => '2',
    ]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'This test result cell is duplicated.');

    expect($connection->table('calibration_result_value')->orderBy('calibration_result_value_id')->pluck('numeric_value')->all())->toBe(['1', '2']);
});

it('rejects an unresolved not-configured test profile requirement', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection, requirement: [
        'frozen_mode' => InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
        'resolved_mode' => null,
    ]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'An unresolved not-configured test profile requirement cannot materialize template values.');
});

it('rejects an invalid frozen requirement mode', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection, requirement: [
        'frozen_mode' => 'mandatory',
        'resolved_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
    ]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'The test profile requirement mode cannot materialize template values.');
});

it('materializes when the test profile requirement is optional', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection, requirement: [
        'frozen_mode' => InstrumentTypeRequirement::MODE_OPTIONAL,
    ]);

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect($connection->table('calibration_result_value')->value('numeric_value'))->toBe('100.5');
});

it('materializes when a not-configured requirement was resolved', function (string $resolved) {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection, requirement: [
        'frozen_mode' => InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
        'resolved_mode' => $resolved,
    ]);

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect($connection->table('calibration_result_value')->value('numeric_value'))->toBe('100.5')
        ->and($connection->table('calibration_requirement_snapshot')->value('resolved_mode'))->toBe($resolved);
})->with([
    'required' => InstrumentTypeRequirement::MODE_REQUIRED,
    'optional' => InstrumentTypeRequirement::MODE_OPTIONAL,
]);

it('keeps a frozen required mode when the resolved mode is not configured', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection, requirement: [
        'frozen_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
        'resolved_mode' => InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
    ]);

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect($connection->table('calibration_result_value')->value('numeric_value'))->toBe('100.5');
});

it('rejects a calibration that is not internal', function (string $method) {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection, ['calibration_method' => $method]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'Template values can be materialized only for an internal calibration session.');

    expect($connection->table('calibration_result_value')->count())->toBe(0);
})->with([
    'external' => Calibration::METHOD_EXTERNAL,
    'unsupported' => 'FIELD',
]);

it('rejects a session that is not in progress', function (string $status) {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection, ['session_status' => $status]);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'Only an in-progress calibration session can materialize template values.');

    expect($connection->table('calibration')->value('session_status'))->toBe($status)
        ->and($connection->table('calibration_result_value')->count())->toBe(0);
})->with([
    'completed' => Calibration::STATUS_COMPLETED,
    'cancelled' => Calibration::STATUS_CANCELLED,
    'unsupported' => 'archived',
]);

it('rejects a calibration id below 1 before reading the session', function (int $calibrationId) {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect(fn () => materializeCalibrationTemplateService()->materialize($calibrationId, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'The calibration id must be at least 1.');

    expect(materializeCalibrationTemplateSql($connection))->not->toContain('from "calibration"')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects a missing calibration', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection);

    expect(fn () => materializeCalibrationTemplateService()->materialize(41, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'The calibration does not exist.');
});

it('rejects a missing test profile requirement', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection, requirement: null);

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $connection))
        ->toThrow(MaterializeCalibrationTestTemplateValuesException::class, 'The test profile requirement snapshot does not exist.');
});

it('rejects an unsafe connection before querying the session', function () {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection);
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('table');
    $unsafe->shouldNotReceive('transaction');

    expect(fn () => materializeCalibrationTemplateService()->materialize(40, $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');

    expect($connection->table('calibration_result_value')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('uses the supplied connection when its name is empty or unregistered', function (string $connectionName) {
    $connection = materializeCalibrationTemplateConnection();
    materializeCalibrationTemplateSeed($connection);
    $config = new ReflectionProperty($connection, 'config');
    $settings = $config->getValue($connection);
    $settings['name'] = $connectionName;
    $config->setValue($connection, $settings);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    materializeCalibrationTemplateService()->materialize(40, $connection);

    expect($connection->getName())->toBe($connectionName)
        ->and($connection->table('calibration_result_value')->value('numeric_value'))->toBe('100.5')
        ->and(materializeCalibrationTemplateSql($connection))->toContain('insert into "calibration_result_value"')
        ->and(materializeCalibrationTemplateSql($default))->not->toContain('calibration_result_value')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty name' => '',
    'unregistered name' => 'unowned-materialize-template',
]);
