<?php

use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\RecordCalibrationStandardUsage;
use App\CalibrationSessions\RecordCalibrationStandardUsageException;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestRefusingPostgresConnector;

const RECORD_STANDARD_USAGE_CONNECTION = 'record_standard_usage_sqlite';

afterEach(function () {
    Carbon::setTestNow();
});

function recordStandardUsageConnection(): Connection
{
    Carbon::setTestNow('2026-10-22 08:15:00');

    config([
        'database.connections.'.RECORD_STANDARD_USAGE_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(RECORD_STANDARD_USAGE_CONNECTION);

    $connection = DB::connection(RECORD_STANDARD_USAGE_CONNECTION);
    $connection->unprepared('
        CREATE TABLE calibration (
            calibration_id INTEGER PRIMARY KEY,
            instrument_type_id INTEGER NULL,
            calibration_number VARCHAR(50) NULL,
            calibration_method VARCHAR(20) NULL,
            session_status VARCHAR(20) NOT NULL,
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
            UNIQUE (calibration_id, requirement_category),
            FOREIGN KEY (calibration_id) REFERENCES calibration (calibration_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE standard_master (
            standard_id INTEGER PRIMARY KEY,
            standard_code VARCHAR(20) NOT NULL,
            instrument_name VARCHAR(150) NOT NULL,
            type_specification VARCHAR(150) NULL,
            serial_number VARCHAR(150) NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (standard_code)
        )
    ');
    $connection->unprepared('
        CREATE TABLE instrument_type_standard_assignment (
            instrument_type_standard_assignment_id INTEGER PRIMARY KEY,
            instrument_type_id INTEGER NOT NULL,
            standard_id INTEGER NULL,
            standard_instrument_id INTEGER NULL,
            assignment_order INTEGER NOT NULL CHECK (assignment_order > 0),
            standard_display_name VARCHAR(255) NULL,
            default_range VARCHAR(150) NULL,
            default_unit VARCHAR(50) NULL,
            UNIQUE (instrument_type_id, standard_id),
            UNIQUE (instrument_type_id, assignment_order)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_standard_usage (
            calibration_standard_usage_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            standard_instrument_id INTEGER NULL,
            standard_id INTEGER NULL,
            usage_order INTEGER NOT NULL,
            usage_purpose VARCHAR(150) NULL,
            remarks TEXT NULL,
            created_at TEXT NOT NULL,
            standard_range VARCHAR(150) NULL,
            standard_unit VARCHAR(50) NULL,
            snapshot_standard_code VARCHAR(20) NULL,
            snapshot_instrument_name VARCHAR(150) NULL,
            snapshot_type_specification VARCHAR(150) NULL,
            snapshot_serial_number VARCHAR(150) NULL,
            CHECK (standard_id IS NOT NULL OR standard_instrument_id IS NOT NULL),
            FOREIGN KEY (calibration_id) REFERENCES calibration (calibration_id),
            FOREIGN KEY (standard_id) REFERENCES standard_master (standard_id)
        )
    ');

    return $connection;
}

function recordStandardUsageService(): RecordCalibrationStandardUsage
{
    return app(RecordCalibrationStandardUsage::class);
}

/**
 * @param  array<string, mixed>  $calibration
 * @param  array<string, mixed>|null  $snapshot
 */
function recordStandardUsageSeed(Connection $connection, array $calibration = [], ?array $snapshot = []): void
{
    $connection->table('calibration')->insert(array_merge([
        'calibration_id' => 40,
        'instrument_type_id' => 12,
        'calibration_number' => 'CAL-40',
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'certificate_number' => 'CERT-40',
        'snapshot_instrument_name' => 'Gauge',
    ], $calibration));

    if ($snapshot !== null) {
        $connection->table('calibration_requirement_snapshot')->insert(array_merge([
            'calibration_requirement_snapshot_id' => 1,
            'calibration_id' => 40,
            'requirement_category' => InstrumentTypeRequirement::CATEGORY_STANDARDS,
            'frozen_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
            'resolved_mode' => null,
            'resolved_at' => null,
            'resolved_reason' => null,
            'resolved_by_user_id' => null,
        ], $snapshot));
    }

    recordStandardUsageMaster($connection, 5, 'STD-5', 'Pressure Gauge', '0.1 class', 'SN-5');
    recordStandardUsageMaster($connection, 6, 'STD-6', 'Temperature Probe', null, 'SN-6');
    recordStandardUsageAssignment($connection, 1, 12, 5, '0-100', 'kPa', 1);
    recordStandardUsageAssignment($connection, 2, 12, 6, '0-10', 'bar', 2);
}

function recordStandardUsageMaster(
    Connection $connection,
    int $standardId,
    string $code,
    string $name,
    ?string $type,
    ?string $serial,
): void {
    $connection->table('standard_master')->insert([
        'standard_id' => $standardId,
        'standard_code' => $code,
        'instrument_name' => $name,
        'type_specification' => $type,
        'serial_number' => $serial,
        'created_at' => '2024-01-01 00:00:00',
        'updated_at' => '2024-01-01 00:00:00',
    ]);
}

function recordStandardUsageAssignment(
    Connection $connection,
    int $assignmentId,
    int $instrumentTypeId,
    int $standardId,
    ?string $range,
    ?string $unit,
    int $order,
): void {
    $connection->table('instrument_type_standard_assignment')->insert([
        'instrument_type_standard_assignment_id' => $assignmentId,
        'instrument_type_id' => $instrumentTypeId,
        'standard_id' => $standardId,
        'standard_instrument_id' => null,
        'assignment_order' => $order,
        'standard_display_name' => 'Display '.$standardId,
        'default_range' => $range,
        'default_unit' => $unit,
    ]);
}

function recordStandardUsageSql(Connection $connection): string
{
    return implode("\n", array_column($connection->getQueryLog(), 'query'));
}

/**
 * @return array<string, mixed>
 */
function recordStandardUsageSession(Connection $connection, int $calibrationId = 40): array
{
    return (array) $connection->table('calibration')->where('calibration_id', $calibrationId)->first();
}

/**
 * @return array<string, mixed>
 */
function recordStandardUsageUsage(Connection $connection, int $usageId): array
{
    return (array) $connection->table('calibration_standard_usage')
        ->where('calibration_standard_usage_id', $usageId)
        ->first();
}

it('adds the first assigned standard at usage order 1 with the master snapshot and assignment defaults', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    $session = recordStandardUsageSession($connection);
    $master = (array) $connection->table('standard_master')->where('standard_id', 5)->first();
    $assignment = (array) $connection->table('instrument_type_standard_assignment')->where('instrument_type_standard_assignment_id', 1)->first();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    recordStandardUsageService()->add(40, 5, null, null, 'Working standard', 'Bench A', $connection);

    $usage = recordStandardUsageUsage($connection, 1);
    $sql = recordStandardUsageSql($connection);
    $calibration = strpos($sql, 'from "calibration"');
    $snapshot = strpos($sql, 'from "calibration_requirement_snapshot"');
    $usages = strpos($sql, 'from "calibration_standard_usage"');
    $assigned = strpos($sql, 'from "instrument_type_standard_assignment"');
    $standard = strpos($sql, 'from "standard_master"');
    $insert = strpos($sql, 'insert into "calibration_standard_usage"');

    expect($usage)->toMatchArray([
        'calibration_standard_usage_id' => 1,
        'calibration_id' => 40,
        'standard_instrument_id' => null,
        'standard_id' => 5,
        'usage_order' => 1,
        'usage_purpose' => 'Working standard',
        'remarks' => 'Bench A',
        'created_at' => '2026-10-22 08:15:00',
        'standard_range' => '0-100',
        'standard_unit' => 'kPa',
        'snapshot_standard_code' => 'STD-5',
        'snapshot_instrument_name' => 'Pressure Gauge',
        'snapshot_type_specification' => '0.1 class',
        'snapshot_serial_number' => 'SN-5',
    ])
        ->and($connection->table('calibration_standard_usage')->count())->toBe(1)
        ->and($connection->table('instrument_type_standard_assignment')->count())->toBe(2)
        ->and(recordStandardUsageSession($connection))->toBe($session)
        ->and((array) $connection->table('standard_master')->where('standard_id', 5)->first())->toBe($master)
        ->and((array) $connection->table('instrument_type_standard_assignment')->where('instrument_type_standard_assignment_id', 1)->first())->toBe($assignment)
        ->and($sql)->not->toContain('update "standard_master"')
        ->and($sql)->not->toContain('insert into "standard_master"')
        ->and($sql)->not->toContain('delete from "standard_master"')
        ->and($sql)->not->toContain('update "instrument_type_standard_assignment"')
        ->and($sql)->not->toContain('insert into "instrument_type_standard_assignment"')
        ->and($sql)->not->toContain('certificate')
        ->and($calibration)->toBeInt()->toBeLessThan($snapshot)
        ->and($snapshot)->toBeLessThan($usages)
        ->and($usages)->toBeLessThan($assigned)
        ->and($assigned)->toBeLessThan($standard)
        ->and($standard)->toBeLessThan($insert)
        ->and($connection->getName())->toBe(RECORD_STANDARD_USAGE_CONNECTION)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('adds a second distinct standard at the next usage order', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    $service = recordStandardUsageService();
    $service->add(40, 5, null, null, null, null, $connection);

    $service->add(40, 6, null, null, null, null, $connection);

    expect(recordStandardUsageUsage($connection, 2))->toMatchArray([
        'calibration_id' => 40,
        'standard_instrument_id' => null,
        'standard_id' => 6,
        'usage_order' => 2,
        'usage_purpose' => null,
        'remarks' => null,
        'standard_range' => '0-10',
        'standard_unit' => 'bar',
        'snapshot_standard_code' => 'STD-6',
        'snapshot_instrument_name' => 'Temperature Probe',
        'snapshot_type_specification' => null,
        'snapshot_serial_number' => 'SN-6',
    ])
        ->and($connection->table('calibration_standard_usage')->orderBy('usage_order')->pluck('usage_order')->all())->toBe([1, 2])
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('stores an explicit range and unit instead of the assignment defaults', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);

    recordStandardUsageService()->add(40, 5, '1-5', 'psi', null, null, $connection);

    expect(recordStandardUsageUsage($connection, 1))->toMatchArray([
        'standard_range' => '1-5',
        'standard_unit' => 'psi',
    ])
        ->and($connection->table('instrument_type_standard_assignment')->where('standard_id', 5)->value('default_range'))->toBe('0-100');
});

it('records a standard when the effective standards mode is required or optional', function (string $frozen, ?string $resolved) {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection, snapshot: [
        'frozen_mode' => $frozen,
        'resolved_mode' => $resolved,
    ]);

    recordStandardUsageService()->add(40, 5, null, null, null, null, $connection);

    expect($connection->table('calibration_standard_usage')->count())->toBe(1)
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('frozen_mode'))->toBe($frozen)
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('resolved_mode'))->toBe($resolved);
})->with([
    'frozen required' => [InstrumentTypeRequirement::MODE_REQUIRED, 'mandatory'],
    'frozen optional' => [InstrumentTypeRequirement::MODE_OPTIONAL, null],
    'resolved required' => [InstrumentTypeRequirement::MODE_NOT_CONFIGURED, InstrumentTypeRequirement::MODE_REQUIRED],
    'resolved optional' => [InstrumentTypeRequirement::MODE_NOT_CONFIGURED, InstrumentTypeRequirement::MODE_OPTIONAL],
]);

it('updates range, unit, purpose, and remarks without changing identity', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    $service = recordStandardUsageService();
    $service->add(40, 5, '1-5', 'psi', 'Initial', 'First', $connection);
    $before = recordStandardUsageUsage($connection, 1);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $service->update(40, 1, '2-8', 'mbar', 'Adjusted', 'Second', $connection);

    $after = recordStandardUsageUsage($connection, 1);
    $sql = recordStandardUsageSql($connection);

    expect($after)->toMatchArray([
        'standard_range' => '2-8',
        'standard_unit' => 'mbar',
        'usage_purpose' => 'Adjusted',
        'remarks' => 'Second',
        'standard_id' => 5,
        'standard_instrument_id' => null,
        'usage_order' => 1,
        'created_at' => $before['created_at'],
        'snapshot_standard_code' => 'STD-5',
        'snapshot_instrument_name' => 'Pressure Gauge',
        'snapshot_type_specification' => '0.1 class',
        'snapshot_serial_number' => 'SN-5',
    ])
        ->and($connection->table('calibration_standard_usage')->count())->toBe(1)
        ->and($sql)->not->toContain('standard_master')
        ->and($sql)->not->toContain('instrument_type_standard_assignment')
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('clears range, unit, purpose, and remarks when update values are null', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    $service = recordStandardUsageService();
    $service->add(40, 5, '1-5', 'psi', 'Initial', 'First', $connection);

    $service->update(40, 1, null, null, null, null, $connection);

    expect(recordStandardUsageUsage($connection, 1))->toMatchArray([
        'standard_range' => null,
        'standard_unit' => null,
        'usage_purpose' => null,
        'remarks' => null,
        'standard_id' => 5,
        'snapshot_standard_code' => 'STD-5',
    ])
        ->and($connection->table('instrument_type_standard_assignment')->where('standard_id', 5)->value('default_range'))->toBe('0-100');
});

it('removes one usage row and compacts the remaining order', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    recordStandardUsageMaster($connection, 7, 'STD-7', 'Flow Meter', 'Turbine', 'SN-7');
    recordStandardUsageAssignment($connection, 3, 12, 7, '1-2', 'L/s', 3);
    $service = recordStandardUsageService();
    $service->add(40, 5, null, null, null, null, $connection);
    $service->add(40, 6, null, null, null, null, $connection);
    $service->add(40, 7, null, null, null, null, $connection);
    $first = recordStandardUsageUsage($connection, 1);
    $third = recordStandardUsageUsage($connection, 3);

    $service->remove(40, 2, $connection);

    expect($connection->table('calibration_standard_usage')->orderBy('usage_order')->pluck('calibration_standard_usage_id')->all())->toBe([1, 3])
        ->and($connection->table('calibration_standard_usage')->orderBy('usage_order')->pluck('usage_order')->all())->toBe([1, 2])
        ->and(recordStandardUsageUsage($connection, 1)['snapshot_standard_code'])->toBe($first['snapshot_standard_code'])
        ->and(recordStandardUsageUsage($connection, 1)['standard_id'])->toBe(5)
        ->and(recordStandardUsageUsage($connection, 3)['snapshot_standard_code'])->toBe($third['snapshot_standard_code'])
        ->and(recordStandardUsageUsage($connection, 3)['standard_id'])->toBe(7)
        ->and(recordStandardUsageUsage($connection, 3)['usage_order'])->toBe(2)
        ->and($connection->table('standard_master')->count())->toBe(3)
        ->and($connection->table('instrument_type_standard_assignment')->count())->toBe(3)
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('allows the last required usage row to be removed while the session is in progress', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    $service = recordStandardUsageService();
    $service->add(40, 5, null, null, null, null, $connection);

    $service->remove(40, 1, $connection);

    expect($connection->table('calibration_standard_usage')->count())->toBe(0)
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('frozen_mode'))->toBe(InstrumentTypeRequirement::MODE_REQUIRED);
});

it('keeps the stored snapshot after the master standard changes', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    recordStandardUsageService()->add(40, 5, null, null, null, null, $connection);

    $connection->table('standard_master')->where('standard_id', 5)->update([
        'standard_code' => 'STD-X',
        'instrument_name' => 'Renamed Gauge',
        'type_specification' => 'Changed',
        'serial_number' => 'SN-X',
        'updated_at' => '2026-10-22 09:00:00',
    ]);

    expect(recordStandardUsageUsage($connection, 1))->toMatchArray([
        'snapshot_standard_code' => 'STD-5',
        'snapshot_instrument_name' => 'Pressure Gauge',
        'snapshot_type_specification' => '0.1 class',
        'snapshot_serial_number' => 'SN-5',
        'standard_range' => '0-100',
        'standard_unit' => 'kPa',
    ]);
});

it('keeps the stored range and unit after assignment defaults change', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    recordStandardUsageService()->add(40, 5, null, null, null, null, $connection);

    $connection->table('instrument_type_standard_assignment')->where('standard_id', 5)->update([
        'default_range' => '9-9',
        'default_unit' => 'psi',
    ]);

    expect(recordStandardUsageUsage($connection, 1))->toMatchArray([
        'standard_range' => '0-100',
        'standard_unit' => 'kPa',
        'snapshot_standard_code' => 'STD-5',
    ]);
});

it('leaves an existing legacy usage row unchanged when another standard is added', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    $legacy = [
        'calibration_standard_usage_id' => 8,
        'calibration_id' => 40,
        'standard_instrument_id' => 9,
        'standard_id' => null,
        'usage_order' => 1,
        'usage_purpose' => 'Legacy',
        'remarks' => 'Keep',
        'created_at' => '2020-01-01 00:00:00',
        'standard_range' => '1-2',
        'standard_unit' => 'mm',
        'snapshot_standard_code' => null,
        'snapshot_instrument_name' => null,
        'snapshot_type_specification' => null,
        'snapshot_serial_number' => null,
    ];
    $connection->table('calibration_standard_usage')->insert($legacy);

    recordStandardUsageService()->add(40, 5, null, null, null, null, $connection);

    expect(recordStandardUsageUsage($connection, 8))->toBe($legacy)
        ->and(recordStandardUsageUsage($connection, 9))->toMatchArray([
            'standard_id' => 5,
            'standard_instrument_id' => null,
            'usage_order' => 2,
            'snapshot_standard_code' => 'STD-5',
        ]);
});

it('rejects an unresolved not-configured standards requirement', function (?string $resolved) {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection, snapshot: [
        'frozen_mode' => InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
        'resolved_mode' => $resolved,
    ]);

    expect(fn () => recordStandardUsageService()->add(40, 5, null, null, null, null, $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'An unresolved not-configured standards requirement cannot record standard usage.');

    expect($connection->table('calibration_standard_usage')->count())->toBe(0)
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('resolved_mode'))->toBe($resolved);
})->with([
    'null resolved mode' => null,
    'resolved mode outside required and optional' => 'mandatory',
]);

it('rejects a frozen standards mode outside the known requirement modes', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection, snapshot: [
        'frozen_mode' => 'mandatory',
        'resolved_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
    ]);

    expect(fn () => recordStandardUsageService()->add(40, 5, null, null, null, null, $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'The standards requirement mode cannot record standard usage.');

    expect($connection->table('calibration_standard_usage')->count())->toBe(0);
});

it('rejects a missing standards requirement snapshot', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection, snapshot: null);

    expect(fn () => recordStandardUsageService()->add(40, 5, null, null, null, null, $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'The standards requirement snapshot does not exist.');

    expect($connection->table('calibration_standard_usage')->count())->toBe(0);
});

it('rejects a standard that is not assigned to the session instrument type', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    $connection->table('instrument_type_standard_assignment')->where('standard_id', 5)->delete();
    recordStandardUsageAssignment($connection, 9, 99, 5, '8-8', 'V', 1);

    expect(fn () => recordStandardUsageService()->add(40, 5, null, null, null, null, $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'This standard is not assigned to the session instrument type.');

    expect($connection->table('calibration_standard_usage')->count())->toBe(0)
        ->and($connection->table('standard_master')->where('standard_id', 5)->value('instrument_name'))->toBe('Pressure Gauge')
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('instrument_type_id'))->toBe(12);
});

it('rejects a missing standard master', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    $connection->table('standard_master')->where('standard_id', 5)->delete();

    expect(fn () => recordStandardUsageService()->add(40, 5, null, null, null, null, $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'The standard master record does not exist.');

    expect($connection->table('calibration_standard_usage')->count())->toBe(0)
        ->and($connection->table('instrument_type_standard_assignment')->where('standard_id', 5)->count())->toBe(1);
});

it('rejects the same standard more than once in one session', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    recordStandardUsageService()->add(40, 5, '1-5', 'psi', 'Keep', 'Original', $connection);

    expect(fn () => recordStandardUsageService()->add(40, 5, '9-9', 'bar', 'Other', 'Duplicate', $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'This standard is already used on this calibration session.');

    expect($connection->table('calibration_standard_usage')->count())->toBe(1)
        ->and(recordStandardUsageUsage($connection, 1))->toMatchArray([
            'standard_range' => '1-5',
            'standard_unit' => 'psi',
            'usage_purpose' => 'Keep',
            'remarks' => 'Original',
        ]);
});

it('rejects an external calibration session', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection, [
        'calibration_method' => Calibration::METHOD_EXTERNAL,
    ]);

    expect(fn () => recordStandardUsageService()->add(40, 5, null, null, null, null, $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'Standard usage can be recorded only for an internal calibration session.');

    expect($connection->table('calibration_standard_usage')->count())->toBe(0)
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('calibration_method'))->toBe(Calibration::METHOD_EXTERNAL)
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('rejects a session that is not in progress', function (string $status) {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection, [
        'session_status' => $status,
    ]);
    $service = recordStandardUsageService();

    expect(fn () => $service->add(40, 5, null, null, null, null, $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'Only an in-progress calibration session can record standard usage.');
    expect(fn () => $service->update(40, 1, '1', 'u', 'p', 'r', $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'Only an in-progress calibration session can record standard usage.');
    expect(fn () => $service->remove(40, 1, $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'Only an in-progress calibration session can record standard usage.');

    expect($connection->table('calibration_standard_usage')->count())->toBe(0)
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe($status);
})->with([
    'completed' => Calibration::STATUS_COMPLETED,
    'cancelled' => Calibration::STATUS_CANCELLED,
]);

it('rejects a missing calibration', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);

    expect(fn () => recordStandardUsageService()->add(41, 5, null, null, null, null, $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'The calibration does not exist.');

    expect($connection->table('calibration_standard_usage')->count())->toBe(0);
});

it('rejects a calibration id below 1 before reading the session', function (int $calibrationId) {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect(fn () => recordStandardUsageService()->add($calibrationId, 5, null, null, null, null, $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'The calibration id must be at least 1.');

    expect(recordStandardUsageSql($connection))->not->toContain('from "calibration"')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects a standard id below 1 before reading the session', function (int $standardId) {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect(fn () => recordStandardUsageService()->add(40, $standardId, null, null, null, null, $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'The standard id must be at least 1.');

    expect(recordStandardUsageSql($connection))->not->toContain('from "calibration"');
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects a usage id below 1 before reading the session', function (string $operation, int $usageId) {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $service = recordStandardUsageService();

    $call = $operation === 'update'
        ? fn () => $service->update(40, $usageId, null, null, null, null, $connection)
        : fn () => $service->remove(40, $usageId, $connection);

    expect($call)->toThrow(RecordCalibrationStandardUsageException::class, 'The usage id must be at least 1.');
    expect(recordStandardUsageSql($connection))->not->toContain('from "calibration"');
})->with([
    'update zero' => ['update', 0],
    'update negative' => ['update', -1],
    'remove zero' => ['remove', 0],
    'remove negative' => ['remove', -1],
]);

it('rejects an update of a usage row from another session', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    recordStandardUsageForeign($connection);
    $foreign = recordStandardUsageUsage($connection, 90);

    expect(fn () => recordStandardUsageService()->update(40, 90, '9-9', 'psi', 'Moved', 'No', $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'The standard usage row belongs to another calibration session.');

    expect(recordStandardUsageUsage($connection, 90))->toBe($foreign)
        ->and($connection->table('calibration_standard_usage')->where('calibration_id', 40)->count())->toBe(0);
});

it('rejects removal of a usage row from another session', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    recordStandardUsageForeign($connection);

    expect(fn () => recordStandardUsageService()->remove(40, 90, $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'The standard usage row belongs to another calibration session.');

    expect($connection->table('calibration_standard_usage')->where('calibration_standard_usage_id', 90)->value('calibration_id'))->toBe(41)
        ->and($connection->table('calibration')->where('calibration_id', 41)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('rejects a usage id that does not exist', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);

    expect(fn () => recordStandardUsageService()->update(40, 90, null, null, null, null, $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'The standard usage row does not exist.');
    expect(fn () => recordStandardUsageService()->remove(40, 90, $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'The standard usage row does not exist.');
});

it('rejects an unsafe connection before querying the session', function () {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('table');
    $unsafe->shouldNotReceive('transaction');

    expect(fn () => recordStandardUsageService()->add(40, 5, null, null, null, null, $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');

    expect($connection->table('calibration_standard_usage')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('uses the supplied connection when its name is empty or unregistered', function (string $connectionName) {
    $connection = recordStandardUsageConnection();
    recordStandardUsageSeed($connection);
    $config = new ReflectionProperty($connection, 'config');
    $settings = $config->getValue($connection);
    $settings['name'] = $connectionName;
    $config->setValue($connection, $settings);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    recordStandardUsageService()->add(40, 5, null, null, null, null, $connection);

    expect($connection->getName())->toBe($connectionName)
        ->and($connection->table('calibration_standard_usage')->where('calibration_id', 40)->count())->toBe(1)
        ->and(recordStandardUsageSql($connection))->toContain('insert into "calibration_standard_usage"')
        ->and(recordStandardUsageSql($default))->not->toContain('calibration_standard_usage')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty name' => '',
    'unregistered name' => 'unowned-record-standard-usage',
]);

function recordStandardUsageForeign(Connection $connection): void
{
    $connection->table('calibration')->insert([
        'calibration_id' => 41,
        'instrument_type_id' => 12,
        'calibration_number' => 'CAL-41',
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'certificate_number' => 'CERT-41',
        'snapshot_instrument_name' => 'Other Gauge',
    ]);
    $connection->table('calibration_requirement_snapshot')->insert([
        'calibration_requirement_snapshot_id' => 2,
        'calibration_id' => 41,
        'requirement_category' => InstrumentTypeRequirement::CATEGORY_STANDARDS,
        'frozen_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
        'resolved_mode' => null,
    ]);
    $connection->table('calibration_standard_usage')->insert([
        'calibration_standard_usage_id' => 90,
        'calibration_id' => 41,
        'standard_instrument_id' => null,
        'standard_id' => 6,
        'usage_order' => 1,
        'usage_purpose' => 'Foreign',
        'remarks' => 'Stay',
        'created_at' => '2024-02-02 00:00:00',
        'standard_range' => '0-10',
        'standard_unit' => 'bar',
        'snapshot_standard_code' => 'STD-6',
        'snapshot_instrument_name' => 'Temperature Probe',
        'snapshot_type_specification' => null,
        'snapshot_serial_number' => 'SN-6',
    ]);
}
