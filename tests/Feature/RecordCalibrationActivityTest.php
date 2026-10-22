<?php

use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\RecordCalibrationActivity;
use App\CalibrationSessions\RecordCalibrationActivityException;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestRefusingPostgresConnector;

const RECORD_CALIBRATION_ACTIVITY_CONNECTION = 'record_calibration_activity_sqlite';

afterEach(function () {
    Carbon::setTestNow();
});

function recordCalibrationActivityConnection(): Connection
{
    Carbon::setTestNow('2026-10-22 08:15:00');

    config([
        'database.connections.'.RECORD_CALIBRATION_ACTIVITY_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(RECORD_CALIBRATION_ACTIVITY_CONNECTION);

    $connection = DB::connection(RECORD_CALIBRATION_ACTIVITY_CONNECTION);
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
        CREATE TABLE calibration_activity_master (
            activity_id INTEGER PRIMARY KEY,
            activity_code VARCHAR(30) NOT NULL,
            activity_name VARCHAR(255) NOT NULL,
            is_active INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (activity_code)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_activity (
            calibration_activity_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            activity_type TEXT NULL,
            activity_id INTEGER NULL,
            activity_name VARCHAR(255) NULL,
            activity_description TEXT NULL,
            activity_result TEXT NULL,
            activity_order INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            CHECK (
                activity_id IS NOT NULL
                OR (activity_type IS NOT NULL AND length(trim(activity_type)) > 0)
            ),
            CHECK (activity_name IS NULL OR length(trim(activity_name)) > 0),
            FOREIGN KEY (calibration_id) REFERENCES calibration (calibration_id),
            FOREIGN KEY (activity_id) REFERENCES calibration_activity_master (activity_id)
        )
    ');

    return $connection;
}

function recordCalibrationActivityService(): RecordCalibrationActivity
{
    return app(RecordCalibrationActivity::class);
}

/**
 * @param  array<string, mixed>  $calibration
 * @param  array<string, mixed>|null  $snapshot
 */
function recordCalibrationActivitySeed(Connection $connection, array $calibration = [], ?array $snapshot = []): void
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
            'requirement_category' => InstrumentTypeRequirement::CATEGORY_ACTIVITY,
            'frozen_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
            'resolved_mode' => null,
            'resolved_at' => null,
            'resolved_reason' => null,
            'resolved_by_user_id' => null,
        ], $snapshot));
    }

    recordCalibrationActivityMaster($connection, 5, 'ACT-0005', 'Cleaning', 1);
    recordCalibrationActivityMaster($connection, 6, 'ACT-0006', 'Verification', 1);
}

function recordCalibrationActivityMaster(
    Connection $connection,
    int $activityId,
    string $code,
    string $name,
    int $isActive,
): void {
    $connection->table('calibration_activity_master')->insert([
        'activity_id' => $activityId,
        'activity_code' => $code,
        'activity_name' => $name,
        'is_active' => $isActive,
        'created_at' => '2024-01-01 00:00:00',
        'updated_at' => '2024-01-01 00:00:00',
    ]);
}

function recordCalibrationActivitySql(Connection $connection): string
{
    return implode("\n", array_column($connection->getQueryLog(), 'query'));
}

/**
 * @return array<string, mixed>
 */
function recordCalibrationActivitySession(Connection $connection, int $calibrationId = 40): array
{
    return (array) $connection->table('calibration')->where('calibration_id', $calibrationId)->first();
}

/**
 * @return array<string, mixed>
 */
function recordCalibrationActivityRow(Connection $connection, int $calibrationActivityId): array
{
    return (array) $connection->table('calibration_activity')
        ->where('calibration_activity_id', $calibrationActivityId)
        ->first();
}

function recordCalibrationActivityForeign(Connection $connection): void
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
        'requirement_category' => InstrumentTypeRequirement::CATEGORY_ACTIVITY,
        'frozen_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
        'resolved_mode' => null,
    ]);
    $connection->table('calibration_activity')->insert([
        'calibration_activity_id' => 90,
        'calibration_id' => 41,
        'activity_type' => 'Verification',
        'activity_id' => 6,
        'activity_name' => 'Verification',
        'activity_description' => 'Foreign',
        'activity_result' => 'Stay',
        'activity_order' => 1,
        'created_at' => '2024-02-02 00:00:00',
    ]);
}

it('adds the first catalog activity at order 1 with both frozen names', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    $session = recordCalibrationActivitySession($connection);
    $master = (array) $connection->table('calibration_activity_master')->where('activity_id', 5)->first();
    $snapshot = (array) $connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->first();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    recordCalibrationActivityService()->add(40, 5, 'Cleaning All Module', 'Done', $connection);

    $activity = recordCalibrationActivityRow($connection, 1);
    $sql = recordCalibrationActivitySql($connection);
    $calibration = strpos($sql, 'from "calibration"');
    $requirement = strpos($sql, 'from "calibration_requirement_snapshot"');
    $activities = strpos($sql, 'from "calibration_activity"');
    $catalog = strpos($sql, 'from "calibration_activity_master"');
    $insert = strpos($sql, 'insert into "calibration_activity"');

    expect($activity)->toMatchArray([
        'calibration_activity_id' => 1,
        'calibration_id' => 40,
        'activity_type' => 'Cleaning',
        'activity_id' => 5,
        'activity_name' => 'Cleaning',
        'activity_description' => 'Cleaning All Module',
        'activity_result' => 'Done',
        'activity_order' => 1,
        'created_at' => '2026-10-22 08:15:00',
    ])
        ->and($activity['activity_name'])->toBe($activity['activity_type'])
        ->and($connection->table('calibration_activity')->count())->toBe(1)
        ->and(recordCalibrationActivitySession($connection))->toBe($session)
        ->and((array) $connection->table('calibration_activity_master')->where('activity_id', 5)->first())->toBe($master)
        ->and((array) $connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->first())->toBe($snapshot)
        ->and($sql)->not->toContain('update "calibration_activity_master"')
        ->and($sql)->not->toContain('insert into "calibration_activity_master"')
        ->and($sql)->not->toContain('delete from "calibration_activity_master"')
        ->and($sql)->not->toContain('update "calibration_requirement_snapshot"')
        ->and($sql)->not->toContain('certificate')
        ->and($calibration)->toBeInt()->toBeLessThan($requirement)
        ->and($requirement)->toBeLessThan($activities)
        ->and($activities)->toBeLessThan($catalog)
        ->and($catalog)->toBeLessThan($insert)
        ->and($connection->getName())->toBe(RECORD_CALIBRATION_ACTIVITY_CONNECTION)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('adds a second distinct activity at the next order with null description and result', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    $service = recordCalibrationActivityService();
    $service->add(40, 5, 'Cleaning All Module', 'Done', $connection);

    $service->add(40, 6, null, null, $connection);

    expect(recordCalibrationActivityRow($connection, 2))->toMatchArray([
        'calibration_id' => 40,
        'activity_type' => 'Verification',
        'activity_id' => 6,
        'activity_name' => 'Verification',
        'activity_description' => null,
        'activity_result' => null,
        'activity_order' => 2,
    ])
        ->and(recordCalibrationActivityRow($connection, 2)['activity_name'])->toBe('Verification')
        ->and($connection->table('calibration_activity')->orderBy('activity_order')->pluck('activity_order')->all())->toBe([1, 2])
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('records an activity when the effective activity mode is required or optional', function (string $frozen, ?string $resolved) {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection, snapshot: [
        'frozen_mode' => $frozen,
        'resolved_mode' => $resolved,
    ]);

    recordCalibrationActivityService()->add(40, 5, null, null, $connection);

    expect($connection->table('calibration_activity')->count())->toBe(1)
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('frozen_mode'))->toBe($frozen)
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('resolved_mode'))->toBe($resolved);
})->with([
    'frozen required' => [InstrumentTypeRequirement::MODE_REQUIRED, 'mandatory'],
    'frozen optional' => [InstrumentTypeRequirement::MODE_OPTIONAL, null],
    'resolved required' => [InstrumentTypeRequirement::MODE_NOT_CONFIGURED, InstrumentTypeRequirement::MODE_REQUIRED],
    'resolved optional' => [InstrumentTypeRequirement::MODE_NOT_CONFIGURED, InstrumentTypeRequirement::MODE_OPTIONAL],
]);

it('updates description and result without changing identity', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    $service = recordCalibrationActivityService();
    $service->add(40, 5, 'Initial', 'First', $connection);
    $before = recordCalibrationActivityRow($connection, 1);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $service->update(40, 1, 'Adjusted', 'Second', $connection);

    $sql = recordCalibrationActivitySql($connection);

    expect(recordCalibrationActivityRow($connection, 1))->toMatchArray([
        'activity_description' => 'Adjusted',
        'activity_result' => 'Second',
        'activity_id' => 5,
        'activity_name' => 'Cleaning',
        'activity_type' => 'Cleaning',
        'activity_order' => 1,
        'created_at' => $before['created_at'],
    ])
        ->and($connection->table('calibration_activity')->count())->toBe(1)
        ->and($sql)->not->toContain('calibration_activity_master')
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('clears description and result when update values are null', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    $service = recordCalibrationActivityService();
    $service->add(40, 5, 'Initial', 'First', $connection);

    $service->update(40, 1, null, null, $connection);

    expect(recordCalibrationActivityRow($connection, 1))->toMatchArray([
        'activity_description' => null,
        'activity_result' => null,
        'activity_id' => 5,
        'activity_name' => 'Cleaning',
        'activity_type' => 'Cleaning',
    ]);
});

it('removes one activity and compacts the remaining order', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    recordCalibrationActivityMaster($connection, 7, 'ACT-0007', 'Leakage test', 1);
    $service = recordCalibrationActivityService();
    $service->add(40, 5, null, null, $connection);
    $service->add(40, 6, null, null, $connection);
    $service->add(40, 7, null, null, $connection);

    $service->remove(40, 2, $connection);

    expect($connection->table('calibration_activity')->orderBy('activity_order')->pluck('calibration_activity_id')->all())->toBe([1, 3])
        ->and($connection->table('calibration_activity')->orderBy('activity_order')->pluck('activity_order')->all())->toBe([1, 2])
        ->and(recordCalibrationActivityRow($connection, 1)['activity_name'])->toBe('Cleaning')
        ->and(recordCalibrationActivityRow($connection, 1)['activity_type'])->toBe('Cleaning')
        ->and(recordCalibrationActivityRow($connection, 1)['activity_id'])->toBe(5)
        ->and(recordCalibrationActivityRow($connection, 3)['activity_name'])->toBe('Leakage test')
        ->and(recordCalibrationActivityRow($connection, 3)['activity_order'])->toBe(2)
        ->and($connection->table('calibration_activity_master')->count())->toBe(3)
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('allows the last required activity to be removed while the session is in progress', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    $service = recordCalibrationActivityService();
    $service->add(40, 5, null, null, $connection);

    $service->remove(40, 1, $connection);

    expect($connection->table('calibration_activity')->count())->toBe(0)
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('frozen_mode'))->toBe(InstrumentTypeRequirement::MODE_REQUIRED);
});

it('keeps both frozen names after the master activity is renamed', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    recordCalibrationActivityService()->add(40, 5, 'Cleaning All Module', null, $connection);

    $connection->table('calibration_activity_master')->where('activity_id', 5)->update([
        'activity_name' => 'Renamed Cleaning',
        'updated_at' => '2026-10-22 09:00:00',
    ]);

    expect(recordCalibrationActivityRow($connection, 1))->toMatchArray([
        'activity_name' => 'Cleaning',
        'activity_type' => 'Cleaning',
        'activity_description' => 'Cleaning All Module',
    ]);
});

it('keeps an existing activity after the master is deactivated', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    recordCalibrationActivityService()->add(40, 5, 'Cleaning All Module', 'Done', $connection);
    $before = recordCalibrationActivityRow($connection, 1);

    $connection->table('calibration_activity_master')->where('activity_id', 5)->update([
        'is_active' => 0,
        'updated_at' => '2026-10-22 09:00:00',
    ]);

    expect(recordCalibrationActivityRow($connection, 1))->toBe($before);
});

it('leaves a legacy free-text activity unchanged when a catalog activity is added', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    $legacy = [
        'calibration_activity_id' => 8,
        'calibration_id' => 40,
        'activity_type' => 'Legacy step',
        'activity_id' => null,
        'activity_name' => null,
        'activity_description' => 'Keep this text',
        'activity_result' => 'Kept',
        'activity_order' => 1,
        'created_at' => '2020-01-01 00:00:00',
    ];
    $connection->table('calibration_activity')->insert($legacy);

    recordCalibrationActivityService()->add(40, 5, null, null, $connection);

    expect(recordCalibrationActivityRow($connection, 8))->toBe($legacy)
        ->and(recordCalibrationActivityRow($connection, 9))->toMatchArray([
            'activity_id' => 5,
            'activity_name' => 'Cleaning',
            'activity_type' => 'Cleaning',
            'activity_order' => 2,
        ]);
});

it('includes a legacy activity when remaining rows are renumbered', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    $service = recordCalibrationActivityService();
    $service->add(40, 5, 'First', null, $connection);
    $legacy = [
        'calibration_activity_id' => 8,
        'calibration_id' => 40,
        'activity_type' => 'Legacy step',
        'activity_id' => null,
        'activity_name' => null,
        'activity_description' => 'Keep this text',
        'activity_result' => 'Kept',
        'activity_order' => 2,
        'created_at' => '2020-01-01 00:00:00',
    ];
    $connection->table('calibration_activity')->insert($legacy);
    $service->add(40, 6, 'Third', null, $connection);

    $service->remove(40, 1, $connection);

    expect(recordCalibrationActivityRow($connection, 8))->toMatchArray([
        'activity_type' => 'Legacy step',
        'activity_id' => null,
        'activity_name' => null,
        'activity_description' => 'Keep this text',
        'activity_result' => 'Kept',
        'activity_order' => 1,
        'created_at' => '2020-01-01 00:00:00',
    ])
        ->and(recordCalibrationActivityRow($connection, 9))->toMatchArray([
            'activity_id' => 6,
            'activity_name' => 'Verification',
            'activity_type' => 'Verification',
            'activity_order' => 2,
        ])
        ->and($connection->table('calibration_activity')->orderBy('activity_order')->pluck('calibration_activity_id')->all())->toBe([8, 9]);
});

it('rejects an unresolved not-configured activity requirement', function (?string $resolved) {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection, snapshot: [
        'frozen_mode' => InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
        'resolved_mode' => $resolved,
    ]);

    expect(fn () => recordCalibrationActivityService()->add(40, 5, null, null, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'An unresolved not-configured activity requirement cannot record activity.');

    expect($connection->table('calibration_activity')->count())->toBe(0)
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('resolved_mode'))->toBe($resolved);
})->with([
    'null resolved mode' => null,
    'resolved mode outside required and optional' => 'mandatory',
]);

it('rejects a frozen activity mode outside the known requirement modes', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection, snapshot: [
        'frozen_mode' => 'mandatory',
        'resolved_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
    ]);

    expect(fn () => recordCalibrationActivityService()->add(40, 5, null, null, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'The activity requirement mode cannot record activity.');

    expect($connection->table('calibration_activity')->count())->toBe(0);
});

it('rejects a missing activity requirement snapshot', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection, snapshot: null);

    expect(fn () => recordCalibrationActivityService()->add(40, 5, null, null, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'The activity requirement snapshot does not exist.');

    expect($connection->table('calibration_activity')->count())->toBe(0);
});

it('rejects a missing activity master', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);

    expect(fn () => recordCalibrationActivityService()->add(40, 99, null, null, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'The activity master record does not exist.');

    expect($connection->table('calibration_activity')->count())->toBe(0);
});

it('rejects an inactive activity master', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    recordCalibrationActivityMaster($connection, 8, 'ACT-0008', 'Retired step', 0);

    expect(fn () => recordCalibrationActivityService()->add(40, 8, null, null, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'This activity master is inactive.');

    expect($connection->table('calibration_activity')->count())->toBe(0)
        ->and($connection->table('calibration_activity_master')->where('activity_id', 8)->value('is_active'))->toBe(0);
});

it('rejects a blank activity master name', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    recordCalibrationActivityMaster($connection, 8, 'ACT-0008', '   ', 1);

    expect(fn () => recordCalibrationActivityService()->add(40, 8, null, null, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'The activity master name is blank.');

    expect($connection->table('calibration_activity')->count())->toBe(0);
});

it('rejects the same activity more than once in one session', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    recordCalibrationActivityService()->add(40, 5, 'Keep', 'Original', $connection);

    expect(fn () => recordCalibrationActivityService()->add(40, 5, 'Other', 'Duplicate', $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'This activity is already used on this calibration session.');

    expect($connection->table('calibration_activity')->count())->toBe(1)
        ->and(recordCalibrationActivityRow($connection, 1))->toMatchArray([
            'activity_description' => 'Keep',
            'activity_result' => 'Original',
            'activity_name' => 'Cleaning',
            'activity_type' => 'Cleaning',
        ]);
});

it('rejects an external calibration session', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection, [
        'calibration_method' => Calibration::METHOD_EXTERNAL,
    ]);
    $service = recordCalibrationActivityService();

    expect(fn () => $service->add(40, 5, null, null, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'Activity records can be changed only for an internal calibration session.');
    expect(fn () => $service->update(40, 1, 'p', 'r', $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'Activity records can be changed only for an internal calibration session.');
    expect(fn () => $service->remove(40, 1, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'Activity records can be changed only for an internal calibration session.');

    expect($connection->table('calibration_activity')->count())->toBe(0)
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('calibration_method'))->toBe(Calibration::METHOD_EXTERNAL)
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('rejects a calibration method outside internal and external', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection, [
        'calibration_method' => 'FIELD',
    ]);

    expect(fn () => recordCalibrationActivityService()->add(40, 5, null, null, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'Activity records can be changed only for an internal calibration session.');

    expect($connection->table('calibration')->where('calibration_id', 40)->value('calibration_method'))->toBe('FIELD');
});

it('rejects a session that is not in progress', function (string $status) {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection, [
        'session_status' => $status,
    ]);
    $service = recordCalibrationActivityService();

    expect(fn () => $service->add(40, 5, null, null, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'Only an in-progress calibration session can record activity.');
    expect(fn () => $service->update(40, 1, 'p', 'r', $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'Only an in-progress calibration session can record activity.');
    expect(fn () => $service->remove(40, 1, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'Only an in-progress calibration session can record activity.');

    expect($connection->table('calibration_activity')->count())->toBe(0)
        ->and($connection->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe($status);
})->with([
    'completed' => Calibration::STATUS_COMPLETED,
    'cancelled' => Calibration::STATUS_CANCELLED,
    'unsupported' => 'archived',
]);

it('rejects a missing calibration', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);

    expect(fn () => recordCalibrationActivityService()->add(41, 5, null, null, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'The calibration does not exist.');

    expect($connection->table('calibration_activity')->count())->toBe(0);
});

it('rejects a calibration id below 1 before reading the session', function (int $calibrationId) {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect(fn () => recordCalibrationActivityService()->add($calibrationId, 5, null, null, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'The calibration id must be at least 1.');

    expect(recordCalibrationActivitySql($connection))->not->toContain('from "calibration"')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects an activity id below 1 before reading the session', function (int $activityId) {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect(fn () => recordCalibrationActivityService()->add(40, $activityId, null, null, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'The activity id must be at least 1.');

    expect(recordCalibrationActivitySql($connection))->not->toContain('from "calibration"');
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects a calibration activity id below 1 before reading the session', function (string $operation, int $calibrationActivityId) {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $service = recordCalibrationActivityService();

    $call = $operation === 'update'
        ? fn () => $service->update(40, $calibrationActivityId, null, null, $connection)
        : fn () => $service->remove(40, $calibrationActivityId, $connection);

    expect($call)->toThrow(RecordCalibrationActivityException::class, 'The calibration activity id must be at least 1.');
    expect(recordCalibrationActivitySql($connection))->not->toContain('from "calibration"');
})->with([
    'update zero' => ['update', 0],
    'update negative' => ['update', -1],
    'remove zero' => ['remove', 0],
    'remove negative' => ['remove', -1],
]);

it('rejects an update of an activity row from another session', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    recordCalibrationActivityForeign($connection);
    $foreign = recordCalibrationActivityRow($connection, 90);

    expect(fn () => recordCalibrationActivityService()->update(40, 90, 'Moved', 'No', $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'The calibration activity row belongs to another calibration session.');

    expect(recordCalibrationActivityRow($connection, 90))->toBe($foreign)
        ->and($connection->table('calibration_activity')->where('calibration_id', 40)->count())->toBe(0);
});

it('rejects removal of an activity row from another session', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    recordCalibrationActivityForeign($connection);

    expect(fn () => recordCalibrationActivityService()->remove(40, 90, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'The calibration activity row belongs to another calibration session.');

    expect($connection->table('calibration_activity')->where('calibration_activity_id', 90)->value('calibration_id'))->toBe(41)
        ->and($connection->table('calibration')->where('calibration_id', 41)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('rejects a calibration activity id that does not exist', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);

    expect(fn () => recordCalibrationActivityService()->update(40, 90, null, null, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'The calibration activity row does not exist.');
    expect(fn () => recordCalibrationActivityService()->remove(40, 90, $connection))
        ->toThrow(RecordCalibrationActivityException::class, 'The calibration activity row does not exist.');
});

it('rejects an unsafe connection before querying the session', function () {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('table');
    $unsafe->shouldNotReceive('transaction');

    expect(fn () => recordCalibrationActivityService()->add(40, 5, null, null, $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');

    expect($connection->table('calibration_activity')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('uses the supplied connection when its name is empty or unregistered', function (string $connectionName) {
    $connection = recordCalibrationActivityConnection();
    recordCalibrationActivitySeed($connection);
    $config = new ReflectionProperty($connection, 'config');
    $settings = $config->getValue($connection);
    $settings['name'] = $connectionName;
    $config->setValue($connection, $settings);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    recordCalibrationActivityService()->add(40, 5, null, null, $connection);

    expect($connection->getName())->toBe($connectionName)
        ->and($connection->table('calibration_activity')->where('calibration_id', 40)->count())->toBe(1)
        ->and(recordCalibrationActivitySql($connection))->toContain('insert into "calibration_activity"')
        ->and(recordCalibrationActivitySql($default))->not->toContain('calibration_activity')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty name' => '',
    'unregistered name' => 'unowned-record-calibration-activity',
]);
