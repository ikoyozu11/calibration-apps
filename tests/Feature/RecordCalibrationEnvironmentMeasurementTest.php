<?php

use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\RecordCalibrationEnvironmentMeasurement;
use App\CalibrationSessions\RecordCalibrationEnvironmentMeasurementException;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestRefusingPostgresConnector;

const RECORD_ENVIRONMENT_MEASUREMENT_CONNECTION = 'record_environment_measurement_sqlite';

afterEach(function () {
    Carbon::setTestNow();
});

function recordEnvironmentMeasurementConnection(): Connection
{
    Carbon::setTestNow('2026-10-22 08:15:00');

    config([
        'database.connections.'.RECORD_ENVIRONMENT_MEASUREMENT_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(RECORD_ENVIRONMENT_MEASUREMENT_CONNECTION);

    $connection = DB::connection(RECORD_ENVIRONMENT_MEASUREMENT_CONNECTION);
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
        CREATE TABLE environment_parameter (
            parameter_id INTEGER PRIMARY KEY,
            parameter_code VARCHAR(50) NOT NULL,
            parameter_name VARCHAR(150) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            data_type VARCHAR(20) NOT NULL,
            is_system INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE (parameter_code)
        )
    ');
    $connection->unprepared('
        CREATE TABLE instrument_type_environment_parameter (
            instrument_type_id INTEGER NOT NULL,
            parameter_id INTEGER NOT NULL,
            requirement_mode VARCHAR(20) NOT NULL,
            display_order INTEGER NOT NULL,
            PRIMARY KEY (instrument_type_id, parameter_id)
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
            included_at TEXT NOT NULL,
            inclusion_confirmed INTEGER NOT NULL,
            inclusion_reason TEXT NULL,
            PRIMARY KEY (calibration_id, parameter_id),
            UNIQUE (calibration_id, parameter_code),
            UNIQUE (calibration_id, display_order),
            FOREIGN KEY (calibration_id) REFERENCES calibration (calibration_id),
            CHECK (inclusion_confirmed OR inclusion_reason IS NOT NULL)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_environment_value (
            calibration_environment_value_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            parameter_id INTEGER NOT NULL,
            numeric_value TEXT NULL,
            text_value TEXT NULL,
            recorded_at TEXT NOT NULL,
            UNIQUE (calibration_id, parameter_id),
            FOREIGN KEY (calibration_id, parameter_id)
                REFERENCES calibration_environment_parameter_snapshot (calibration_id, parameter_id),
            CHECK (numeric_value IS NULL OR text_value IS NULL),
            CHECK (text_value IS NULL OR length(trim(text_value)) > 0)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_environment (
            calibration_environment_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL UNIQUE,
            temperature_value TEXT NULL,
            temperature_unit TEXT NULL,
            humidity_value TEXT NULL,
            humidity_unit TEXT NULL,
            pressure_value TEXT NULL,
            pressure_unit TEXT NULL,
            remarks TEXT NULL,
            created_at TEXT NOT NULL
        )
    ');

    return $connection;
}

function recordEnvironmentMeasurementService(): RecordCalibrationEnvironmentMeasurement
{
    return app(RecordCalibrationEnvironmentMeasurement::class);
}

/**
 * @param  array<string, mixed>  $calibration
 * @param  array<string, mixed>|null  $snapshot
 */
function recordEnvironmentMeasurementSeed(Connection $connection, array $calibration = [], ?array $snapshot = []): void
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

    if ($snapshot !== null) {
        $connection->table('calibration_requirement_snapshot')->insert(array_merge([
            'calibration_requirement_snapshot_id' => 1,
            'calibration_id' => 40,
            'requirement_category' => InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
            'frozen_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
            'resolved_mode' => null,
            'resolved_at' => null,
            'resolved_reason' => null,
            'resolved_by_user_id' => null,
            'configuration_confirmed_at' => null,
            'configuration_confirmed_reason' => null,
            'configuration_confirmed_by_user_id' => null,
        ], $snapshot));
    }

    recordEnvironmentMeasurementCatalog($connection, 1, 'temperature', 'Temperature', '°C', 'numeric', 1);
    recordEnvironmentMeasurementCatalog($connection, 2, 'condition', 'Condition', 'note', 'text', 0);
    recordEnvironmentMeasurementCatalog($connection, 4, 'pressure', 'Pressure', 'kPa', 'numeric', 0);
    $connection->table('instrument_type_environment_parameter')->insert([
        'instrument_type_id' => 12,
        'parameter_id' => 1,
        'requirement_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
        'display_order' => 1,
    ]);
    recordEnvironmentMeasurementParameter($connection, 40, 1, 'temperature', 'Temperature', '°C', 'numeric', InstrumentTypeRequirement::MODE_REQUIRED, 1, 1, null);
    recordEnvironmentMeasurementParameter($connection, 40, 2, 'condition', 'Condition', 'note', 'text', InstrumentTypeRequirement::MODE_OPTIONAL, 2, 1, null);
    $connection->table('calibration_environment')->insert([
        'calibration_environment_id' => 1,
        'calibration_id' => 40,
        'temperature_value' => '20',
        'temperature_unit' => '°C',
        'humidity_value' => null,
        'humidity_unit' => null,
        'pressure_value' => null,
        'pressure_unit' => null,
        'remarks' => 'legacy',
        'created_at' => '2024-01-01 00:00:00',
    ]);
}

function recordEnvironmentMeasurementCatalog(
    Connection $connection,
    int $parameterId,
    string $code,
    string $name,
    string $unit,
    string $dataType,
    int $isSystem,
): void {
    $connection->table('environment_parameter')->insert([
        'parameter_id' => $parameterId,
        'parameter_code' => $code,
        'parameter_name' => $name,
        'unit' => $unit,
        'data_type' => $dataType,
        'is_system' => $isSystem,
        'created_at' => '2024-01-01 00:00:00',
    ]);
}

function recordEnvironmentMeasurementParameter(
    Connection $connection,
    int $calibrationId,
    int $parameterId,
    string $code,
    string $name,
    string $unit,
    string $dataType,
    string $mode,
    int $displayOrder,
    int $inclusionConfirmed,
    ?string $inclusionReason,
): void {
    $connection->table('calibration_environment_parameter_snapshot')->insert([
        'calibration_id' => $calibrationId,
        'parameter_id' => $parameterId,
        'parameter_code' => $code,
        'parameter_name' => $name,
        'unit' => $unit,
        'data_type' => $dataType,
        'requirement_mode' => $mode,
        'display_order' => $displayOrder,
        'included_at' => '2024-01-01 00:00:00',
        'inclusion_confirmed' => $inclusionConfirmed,
        'inclusion_reason' => $inclusionReason,
    ]);
}

function recordEnvironmentMeasurementSql(Connection $connection): string
{
    return implode("\n", array_column($connection->getQueryLog(), 'query'));
}

/**
 * @return array<string, mixed>
 */
function recordEnvironmentMeasurementSession(Connection $connection, int $calibrationId = 40): array
{
    return (array) $connection->table('calibration')->where('calibration_id', $calibrationId)->first();
}

/**
 * @return array<string, mixed>
 */
function recordEnvironmentMeasurementValue(Connection $connection, int $parameterId, int $calibrationId = 40): array
{
    return (array) $connection->table('calibration_environment_value')
        ->where('calibration_id', $calibrationId)
        ->where('parameter_id', $parameterId)
        ->first();
}

/**
 * @return list<array<string, mixed>>
 */
function recordEnvironmentMeasurementTable(Connection $connection, string $table): array
{
    $order = [
        'calibration' => 'calibration_id',
        'calibration_requirement_snapshot' => 'calibration_requirement_snapshot_id',
        'environment_parameter' => 'parameter_id',
        'instrument_type_environment_parameter' => 'parameter_id',
        'calibration_environment_parameter_snapshot' => 'display_order',
        'calibration_environment' => 'calibration_environment_id',
        'calibration_environment_value' => 'calibration_environment_value_id',
    ];

    return array_map(
        fn (object $row): array => (array) $row,
        $connection->table($table)->orderBy($order[$table])->get()->all(),
    );
}

it('inserts a numeric measurement and leaves frozen rows unchanged', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);
    $session = recordEnvironmentMeasurementSession($connection);
    $requirement = recordEnvironmentMeasurementTable($connection, 'calibration_requirement_snapshot');
    $parameters = recordEnvironmentMeasurementTable($connection, 'calibration_environment_parameter_snapshot');
    $catalog = recordEnvironmentMeasurementTable($connection, 'environment_parameter');
    $assignments = recordEnvironmentMeasurementTable($connection, 'instrument_type_environment_parameter');
    $legacy = recordEnvironmentMeasurementTable($connection, 'calibration_environment');
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    recordEnvironmentMeasurementService()->record(40, 1, '25.5', $connection);

    $value = recordEnvironmentMeasurementValue($connection, 1);
    $sql = recordEnvironmentMeasurementSql($connection);
    $calibration = strpos($sql, 'from "calibration"');
    $requirementSql = strpos($sql, 'from "calibration_requirement_snapshot"');
    $parameterSql = strpos($sql, 'from "calibration_environment_parameter_snapshot"');
    $valueSql = strpos($sql, 'from "calibration_environment_value"');
    $insert = strpos($sql, 'insert into "calibration_environment_value"');

    expect($value)->toMatchArray([
        'calibration_environment_value_id' => 1,
        'calibration_id' => 40,
        'parameter_id' => 1,
        'numeric_value' => '25.5',
        'text_value' => null,
        'recorded_at' => '2026-10-22 08:15:00',
    ])
        ->and($connection->table('calibration_environment_value')->count())->toBe(1)
        ->and(recordEnvironmentMeasurementSession($connection))->toBe($session)
        ->and(recordEnvironmentMeasurementTable($connection, 'calibration_requirement_snapshot'))->toBe($requirement)
        ->and(recordEnvironmentMeasurementTable($connection, 'calibration_environment_parameter_snapshot'))->toBe($parameters)
        ->and(recordEnvironmentMeasurementTable($connection, 'environment_parameter'))->toBe($catalog)
        ->and(recordEnvironmentMeasurementTable($connection, 'instrument_type_environment_parameter'))->toBe($assignments)
        ->and(recordEnvironmentMeasurementTable($connection, 'calibration_environment'))->toBe($legacy)
        ->and($sql)->not->toContain('update "environment_parameter"')
        ->and($sql)->not->toContain('insert into "environment_parameter"')
        ->and($sql)->not->toContain('instrument_type_environment_parameter')
        ->and($sql)->not->toContain('update "calibration_environment"')
        ->and($sql)->not->toContain('insert into "calibration_environment"')
        ->and($sql)->not->toContain('delete from "calibration_environment"')
        ->and($sql)->not->toContain('update "calibration_requirement_snapshot"')
        ->and($sql)->not->toContain('update "calibration_environment_parameter_snapshot"')
        ->and($calibration)->toBeInt()->toBeLessThan($requirementSql)
        ->and($requirementSql)->toBeLessThan($parameterSql)
        ->and($parameterSql)->toBeLessThan($valueSql)
        ->and($valueSql)->toBeLessThan($insert)
        ->and($connection->getName())->toBe(RECORD_ENVIRONMENT_MEASUREMENT_CONNECTION)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('updates the same numeric row and refreshes recorded_at', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);
    $service = recordEnvironmentMeasurementService();
    $service->record(40, 1, '25.5', $connection);
    Carbon::setTestNow('2026-10-22 09:30:00');

    $service->record(40, 1, '-0.25', $connection);

    expect(recordEnvironmentMeasurementValue($connection, 1))->toMatchArray([
        'calibration_environment_value_id' => 1,
        'parameter_id' => 1,
        'numeric_value' => '-0.25',
        'text_value' => null,
        'recorded_at' => '2026-10-22 09:30:00',
    ])
        ->and($connection->table('calibration_environment_value')->count())->toBe(1);
});

it('inserts and updates a text measurement without trimming it', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);
    $service = recordEnvironmentMeasurementService();
    $service->record(40, 2, '  Lab note  ', $connection);
    Carbon::setTestNow('2026-10-22 09:30:00');

    $service->record(40, 2, 'Stable', $connection);

    expect(recordEnvironmentMeasurementValue($connection, 2))->toMatchArray([
        'calibration_environment_value_id' => 1,
        'parameter_id' => 2,
        'numeric_value' => null,
        'text_value' => 'Stable',
        'recorded_at' => '2026-10-22 09:30:00',
    ]);

    $connection->table('calibration_environment_value')->delete();
    $service->record(40, 2, '  Lab note  ', $connection);

    expect(recordEnvironmentMeasurementValue($connection, 2)['text_value'])->toBe('  Lab note  ')
        ->and(recordEnvironmentMeasurementValue($connection, 2)['numeric_value'])->toBeNull();
});

it('records numeric and text parameters in the same session', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);
    $service = recordEnvironmentMeasurementService();

    $service->record(40, 1, '25.5', $connection);
    $service->record(40, 2, 'Stable', $connection);

    expect($connection->table('calibration_environment_value')->orderBy('parameter_id')->pluck('parameter_id')->all())->toBe([1, 2])
        ->and(recordEnvironmentMeasurementValue($connection, 1)['numeric_value'])->toBe('25.5')
        ->and(recordEnvironmentMeasurementValue($connection, 2)['text_value'])->toBe('Stable');
});

it('stores zero, negative zero, and an unrounded decimal string', function (string $value) {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);

    recordEnvironmentMeasurementService()->record(40, 1, $value, $connection);

    expect(recordEnvironmentMeasurementValue($connection, 1)['numeric_value'])->toBe($value)
        ->and(recordEnvironmentMeasurementValue($connection, 1)['text_value'])->toBeNull();
})->with([
    'zero' => '0',
    'negative zero' => '-0',
    'negative decimal' => '-5',
    'fraction' => '0.0001',
    'preserved scale' => '0.00010',
    'long decimal' => '1234567890.1234567890',
]);

it('rejects a numeric measurement that is not a decimal string', function (string $value) {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);

    expect(fn () => recordEnvironmentMeasurementService()->record(40, 1, $value, $connection))
        ->toThrow(RecordCalibrationEnvironmentMeasurementException::class, 'The environment measurement must be a decimal number.');

    expect($connection->table('calibration_environment_value')->count())->toBe(0);
})->with([
    'empty' => '',
    'whitespace' => '   ',
    'malformed' => '1.2.3',
    'leading dot' => '.5',
    'trailing dot' => '5.',
    'exponent' => '1e2',
    'plus sign' => '+25',
    'alphabetic' => 'warm',
    'NaN' => 'NaN',
    'Infinity' => 'Infinity',
    'comma' => '25,5',
]);

it('rejects blank text for a text parameter', function (string $value) {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);

    expect(fn () => recordEnvironmentMeasurementService()->record(40, 2, $value, $connection))
        ->toThrow(RecordCalibrationEnvironmentMeasurementException::class, 'The environment measurement text must not be blank.');

    expect($connection->table('calibration_environment_value')->count())->toBe(0);
})->with([
    'empty' => '',
    'whitespace' => " \t ",
]);

it('deletes an existing measurement when the value is null', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);
    $service = recordEnvironmentMeasurementService();
    $service->record(40, 1, '25.5', $connection);
    $service->record(40, 2, 'Stable', $connection);
    $parameters = recordEnvironmentMeasurementTable($connection, 'calibration_environment_parameter_snapshot');

    $service->record(40, 1, null, $connection);

    expect($connection->table('calibration_environment_value')->where('parameter_id', 1)->count())->toBe(0)
        ->and($connection->table('calibration_environment_value')->whereNull('numeric_value')->whereNull('text_value')->count())->toBe(0)
        ->and(recordEnvironmentMeasurementValue($connection, 2)['text_value'])->toBe('Stable')
        ->and(recordEnvironmentMeasurementTable($connection, 'calibration_environment_parameter_snapshot'))->toBe($parameters)
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($connection->table('calibration_environment_parameter_snapshot')->where('parameter_id', 1)->value('requirement_mode'))->toBe(InstrumentTypeRequirement::MODE_REQUIRED);
});

it('leaves a missing measurement absent when the value is null', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    recordEnvironmentMeasurementService()->record(40, 1, null, $connection);

    $sql = recordEnvironmentMeasurementSql($connection);

    expect($connection->table('calibration_environment_value')->count())->toBe(0)
        ->and($sql)->not->toContain('insert into "calibration_environment_value"')
        ->and($sql)->not->toContain('delete from "calibration_environment_value"');
});

it('records a measurement when the effective environment mode is required or optional', function (string $frozen, ?string $resolved) {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection, snapshot: [
        'frozen_mode' => $frozen,
        'resolved_mode' => $resolved,
    ]);

    recordEnvironmentMeasurementService()->record(40, 2, 'Stable', $connection);

    expect($connection->table('calibration_environment_value')->count())->toBe(1)
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('frozen_mode'))->toBe($frozen)
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('resolved_mode'))->toBe($resolved);
})->with([
    'frozen required' => [InstrumentTypeRequirement::MODE_REQUIRED, 'mandatory'],
    'frozen optional' => [InstrumentTypeRequirement::MODE_OPTIONAL, null],
    'resolved required' => [InstrumentTypeRequirement::MODE_NOT_CONFIGURED, InstrumentTypeRequirement::MODE_REQUIRED],
    'resolved optional' => [InstrumentTypeRequirement::MODE_NOT_CONFIGURED, InstrumentTypeRequirement::MODE_OPTIONAL],
]);

it('rejects an unresolved not-configured environment requirement', function (?string $resolved) {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection, snapshot: [
        'frozen_mode' => InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
        'resolved_mode' => $resolved,
    ]);

    expect(fn () => recordEnvironmentMeasurementService()->record(40, 1, '25', $connection))
        ->toThrow(RecordCalibrationEnvironmentMeasurementException::class, 'An unresolved not-configured environment requirement cannot record a measurement.');

    expect($connection->table('calibration_environment_value')->count())->toBe(0);
})->with([
    'null resolved mode' => null,
    'resolved mode outside required and optional' => 'mandatory',
]);

it('rejects a frozen environment mode outside the known requirement modes', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection, snapshot: [
        'frozen_mode' => 'mandatory',
        'resolved_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
    ]);

    expect(fn () => recordEnvironmentMeasurementService()->record(40, 1, '25', $connection))
        ->toThrow(RecordCalibrationEnvironmentMeasurementException::class, 'The environment requirement mode cannot record a measurement.');

    expect($connection->table('calibration_environment_value')->count())->toBe(0);
});

it('rejects a missing environment requirement snapshot', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection, snapshot: null);

    expect(fn () => recordEnvironmentMeasurementService()->record(40, 1, '25', $connection))
        ->toThrow(RecordCalibrationEnvironmentMeasurementException::class, 'The environment requirement snapshot does not exist.');

    expect($connection->table('calibration_environment_value')->count())->toBe(0);
});

it('rejects a parameter that is not frozen on this session', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);

    expect(fn () => recordEnvironmentMeasurementService()->record(40, 99, '25', $connection))
        ->toThrow(RecordCalibrationEnvironmentMeasurementException::class, 'This parameter is not frozen on this calibration session.');

    expect($connection->table('calibration_environment_value')->count())->toBe(0);
});

it('rejects a parameter frozen only on another calibration', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);
    $connection->table('calibration')->insert([
        'calibration_id' => 41,
        'instrument_type_id' => 12,
        'calibration_number' => 'CAL-41',
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'certificate_number' => 'CERT-41',
        'snapshot_instrument_name' => 'Other',
    ]);
    recordEnvironmentMeasurementCatalog($connection, 9, 'vibration', 'Vibration', 'Hz', 'numeric', 0);
    recordEnvironmentMeasurementParameter($connection, 41, 9, 'vibration', 'Vibration', 'Hz', 'numeric', InstrumentTypeRequirement::MODE_REQUIRED, 1, 1, null);

    expect(fn () => recordEnvironmentMeasurementService()->record(40, 9, '3', $connection))
        ->toThrow(RecordCalibrationEnvironmentMeasurementException::class, 'This parameter is not frozen on this calibration session.');

    expect($connection->table('calibration_environment_value')->count())->toBe(0)
        ->and($connection->table('calibration_environment_parameter_snapshot')->where('calibration_id', 41)->where('parameter_id', 9)->value('parameter_name'))->toBe('Vibration');
});

it('rejects a frozen data type outside numeric and text', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);
    recordEnvironmentMeasurementParameter($connection, 40, 4, 'pressure', 'Pressure', 'kPa', 'boolean', InstrumentTypeRequirement::MODE_OPTIONAL, 3, 1, null);

    expect(fn () => recordEnvironmentMeasurementService()->record(40, 4, '1', $connection))
        ->toThrow(RecordCalibrationEnvironmentMeasurementException::class, 'The frozen environment parameter data type is not supported.');

    expect($connection->table('calibration_environment_value')->count())->toBe(0)
        ->and($connection->table('environment_parameter')->where('parameter_id', 4)->value('data_type'))->toBe('numeric')
        ->and($connection->table('calibration_environment_parameter_snapshot')->where('parameter_id', 4)->value('data_type'))->toBe('boolean');
});

it('rejects an external calibration session', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection, [
        'calibration_method' => Calibration::METHOD_EXTERNAL,
    ]);

    expect(fn () => recordEnvironmentMeasurementService()->record(40, 1, '25', $connection))
        ->toThrow(RecordCalibrationEnvironmentMeasurementException::class, 'Environment measurements can be recorded only for an internal calibration session.');

    expect($connection->table('calibration')->where('calibration_id', 40)->value('calibration_method'))->toBe(Calibration::METHOD_EXTERNAL)
        ->and($connection->table('calibration_environment_value')->count())->toBe(0);
});

it('rejects a calibration method outside internal and external', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection, [
        'calibration_method' => 'FIELD',
    ]);

    expect(fn () => recordEnvironmentMeasurementService()->record(40, 1, '25', $connection))
        ->toThrow(RecordCalibrationEnvironmentMeasurementException::class, 'Environment measurements can be recorded only for an internal calibration session.');
});

it('rejects a session that is not in progress', function (string $status) {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection, [
        'session_status' => $status,
    ]);

    expect(fn () => recordEnvironmentMeasurementService()->record(40, 1, '25', $connection))
        ->toThrow(RecordCalibrationEnvironmentMeasurementException::class, 'Only an in-progress calibration session can record environment measurements.');

    expect($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe($status)
        ->and($connection->table('calibration_environment_value')->count())->toBe(0);
})->with([
    'completed' => Calibration::STATUS_COMPLETED,
    'cancelled' => Calibration::STATUS_CANCELLED,
    'unsupported' => 'archived',
]);

it('rejects a missing calibration', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);

    expect(fn () => recordEnvironmentMeasurementService()->record(41, 1, '25', $connection))
        ->toThrow(RecordCalibrationEnvironmentMeasurementException::class, 'The calibration does not exist.');
});

it('rejects a calibration id below 1 before reading the session', function (int $calibrationId) {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect(fn () => recordEnvironmentMeasurementService()->record($calibrationId, 1, '25', $connection))
        ->toThrow(RecordCalibrationEnvironmentMeasurementException::class, 'The calibration id must be at least 1.');

    expect(recordEnvironmentMeasurementSql($connection))->not->toContain('from "calibration"')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects a parameter id below 1 before reading the session', function (int $parameterId) {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect(fn () => recordEnvironmentMeasurementService()->record(40, $parameterId, '25', $connection))
        ->toThrow(RecordCalibrationEnvironmentMeasurementException::class, 'The parameter id must be at least 1.');

    expect(recordEnvironmentMeasurementSql($connection))->not->toContain('from "calibration"');
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('records a measurement before configuration confirmation', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);

    recordEnvironmentMeasurementService()->record(40, 1, '25.5', $connection);

    expect(recordEnvironmentMeasurementValue($connection, 1)['numeric_value'])->toBe('25.5')
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('configuration_confirmed_at'))->toBeNull()
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('configuration_confirmed_reason'))->toBeNull()
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('configuration_confirmed_by_user_id'))->toBeNull();
});

it('records a measurement after configuration confirmation without changing it', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection, snapshot: [
        'configuration_confirmed_at' => '2026-10-21 12:00:00',
        'configuration_confirmed_reason' => 'Bench checked',
        'configuration_confirmed_by_user_id' => 7,
    ]);

    recordEnvironmentMeasurementService()->record(40, 1, '25.5', $connection);

    expect(recordEnvironmentMeasurementValue($connection, 1)['numeric_value'])->toBe('25.5')
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('configuration_confirmed_at'))->toBe('2026-10-21 12:00:00')
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('configuration_confirmed_reason'))->toBe('Bench checked')
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('configuration_confirmed_by_user_id'))->toBe(7);
});

it('records a value when inclusion is not confirmed and leaves that flag unchanged', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);
    $connection->table('calibration_environment_parameter_snapshot')
        ->where('calibration_id', 40)
        ->where('parameter_id', 1)
        ->update([
            'inclusion_confirmed' => 0,
            'inclusion_reason' => 'pending',
        ]);

    recordEnvironmentMeasurementService()->record(40, 1, '25.5', $connection);

    expect(recordEnvironmentMeasurementValue($connection, 1)['numeric_value'])->toBe('25.5')
        ->and($connection->table('calibration_environment_parameter_snapshot')->where('parameter_id', 1)->value('inclusion_confirmed'))->toBe(0)
        ->and($connection->table('calibration_environment_parameter_snapshot')->where('parameter_id', 1)->value('inclusion_reason'))->toBe('pending');
});

it('rejects an unsafe connection before querying the session', function () {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('table');
    $unsafe->shouldNotReceive('transaction');

    expect(fn () => recordEnvironmentMeasurementService()->record(40, 1, '25', $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');

    expect($connection->table('calibration_environment_value')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('uses the supplied connection when its name is empty or unregistered', function (string $connectionName) {
    $connection = recordEnvironmentMeasurementConnection();
    recordEnvironmentMeasurementSeed($connection);
    $config = new ReflectionProperty($connection, 'config');
    $settings = $config->getValue($connection);
    $settings['name'] = $connectionName;
    $config->setValue($connection, $settings);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    recordEnvironmentMeasurementService()->record(40, 1, '25.5', $connection);

    expect($connection->getName())->toBe($connectionName)
        ->and(recordEnvironmentMeasurementValue($connection, 1)['numeric_value'])->toBe('25.5')
        ->and(recordEnvironmentMeasurementSql($connection))->toContain('insert into "calibration_environment_value"')
        ->and(recordEnvironmentMeasurementSql($default))->not->toContain('calibration_environment_value')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty name' => '',
    'unregistered name' => 'unowned-record-environment-measurement',
]);
