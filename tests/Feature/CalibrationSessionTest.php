<?php

use App\Models\Calibration;
use App\Models\InstrumentType;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createCalibrationSessionConstraintTables(): void
{
    Schema::create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name', 150);
        $table->timestamp('created_at');
    });

    DB::statement('
        CREATE TABLE instrument_type (
            instrument_type_id INTEGER PRIMARY KEY,
            instrument_id INTEGER NOT NULL,
            type_name VARCHAR NOT NULL,
            created_at DATETIME NOT NULL,
            calibration_interval_months INTEGER,
            UNIQUE (instrument_type_id, instrument_id),
            CHECK (calibration_interval_months IS NULL OR calibration_interval_months > 0),
            FOREIGN KEY (instrument_id) REFERENCES instrument (instrument_id)
        )
    ');

    DB::statement("
        CREATE TABLE calibration (
            calibration_id INTEGER PRIMARY KEY,
            instrument_id INTEGER NOT NULL,
            instrument_type_id INTEGER,
            calibration_provider_id INTEGER,
            calibration_number VARCHAR NOT NULL UNIQUE,
            calibration_date DATE,
            calibration_due DATE,
            calibration_method VARCHAR NOT NULL,
            calibration_result VARCHAR,
            session_status VARCHAR NOT NULL DEFAULT 'completed',
            certificate_number VARCHAR,
            remarks TEXT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            CHECK (session_status IN ('in_progress', 'completed', 'cancelled')),
            CHECK (session_status <> 'in_progress' OR instrument_type_id IS NOT NULL),
            FOREIGN KEY (instrument_type_id, instrument_id) REFERENCES instrument_type (instrument_type_id, instrument_id)
        )
    ");

    DB::statement('CREATE UNIQUE INDEX uq_calibration_in_progress_per_type ON calibration (instrument_type_id) WHERE session_status = \'in_progress\'');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertSessionInstrument(array $attributes): void
{
    DB::table('instrument')->insert([
        'instrument_id' => $attributes['instrument_id'],
        'instrument_name' => $attributes['instrument_name'] ?? 'Gauge',
        'created_at' => '2026-01-01 00:00:00',
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertSessionType(array $attributes): void
{
    DB::table('instrument_type')->insert([
        'instrument_type_id' => $attributes['instrument_type_id'],
        'instrument_id' => $attributes['instrument_id'],
        'type_name' => $attributes['type_name'] ?? 'Weight',
        'created_at' => '2026-01-01 00:00:00',
        'calibration_interval_months' => $attributes['calibration_interval_months'] ?? null,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function sessionCalibrationAttributes(array $attributes): array
{
    return array_merge([
        'calibration_provider_id' => 1,
        'calibration_date' => '2026-10-01',
        'calibration_due' => '2027-10-01',
        'calibration_method' => 'INTERNAL',
        'calibration_result' => null,
        'session_status' => Calibration::STATUS_COMPLETED,
        'certificate_number' => null,
        'remarks' => null,
        'created_at' => '2026-10-01 09:00:00',
        'updated_at' => '2026-10-01 09:00:00',
    ], $attributes);
}

it('accepts each session status', function (string $status) {
    createCalibrationSessionConstraintTables();
    insertSessionInstrument(['instrument_id' => 1]);
    insertSessionType(['instrument_type_id' => 11, 'instrument_id' => 1]);

    DB::table('calibration')->insert(sessionCalibrationAttributes([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_number' => 'CAL-STATUS-'.$status,
        'session_status' => $status,
    ]));

    expect(Calibration::query()->where('session_status', $status)->count())->toBe(1);
})->with([
    'in progress' => [Calibration::STATUS_IN_PROGRESS],
    'completed' => [Calibration::STATUS_COMPLETED],
    'cancelled' => [Calibration::STATUS_CANCELLED],
]);

it('rejects an invalid session status', function () {
    createCalibrationSessionConstraintTables();
    insertSessionInstrument(['instrument_id' => 1]);
    insertSessionType(['instrument_type_id' => 11, 'instrument_id' => 1]);

    expect(fn () => DB::table('calibration')->insert(sessionCalibrationAttributes([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_number' => 'CAL-DRAFT',
        'session_status' => 'draft',
    ])))->toThrow(QueryException::class);
});

it('stores omitted session status as completed', function () {
    createCalibrationSessionConstraintTables();
    insertSessionInstrument(['instrument_id' => 1]);
    insertSessionType(['instrument_type_id' => 11, 'instrument_id' => 1]);

    $attributes = sessionCalibrationAttributes([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_number' => 'CAL-DEFAULT',
    ]);
    unset($attributes['session_status']);

    DB::table('calibration')->insert($attributes);

    expect(Calibration::query()->findOrFail(1)->session_status)->toBe(Calibration::STATUS_COMPLETED)
        ->and(Calibration::query()->completed()->count())->toBe(1);
});

it('maps a session to the instrument type of the same instrument', function () {
    createCalibrationSessionConstraintTables();
    insertSessionInstrument(['instrument_id' => 1]);
    insertSessionType(['instrument_type_id' => 11, 'instrument_id' => 1]);

    DB::table('calibration')->insert(sessionCalibrationAttributes([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_number' => 'CAL-TYPE',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
    ]));

    $calibration = Calibration::query()->with('instrumentType')->findOrFail(1);

    expect($calibration->instrument_type_id)->toBe(11)
        ->and($calibration->instrumentType)->toBeInstanceOf(InstrumentType::class)
        ->and($calibration->instrumentType?->instrument_id)->toBe(1)
        ->and($calibration->instrumentType?->calibrations()->count())->toBe(1);
});

it('rejects a session whose instrument type belongs to another instrument', function () {
    createCalibrationSessionConstraintTables();
    insertSessionInstrument(['instrument_id' => 1]);
    insertSessionInstrument(['instrument_id' => 2]);
    insertSessionType(['instrument_type_id' => 11, 'instrument_id' => 1]);
    insertSessionType(['instrument_type_id' => 21, 'instrument_id' => 2]);

    expect(fn () => DB::table('calibration')->insert(sessionCalibrationAttributes([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => 21,
        'calibration_number' => 'CAL-MISMATCH',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
    ])))->toThrow(QueryException::class);
});

it('accepts a null or positive calibration interval', function (?int $months) {
    createCalibrationSessionConstraintTables();
    insertSessionInstrument(['instrument_id' => 1]);
    insertSessionType([
        'instrument_type_id' => 11,
        'instrument_id' => 1,
        'calibration_interval_months' => $months,
    ]);

    expect(InstrumentType::query()->findOrFail(11)->calibration_interval_months)->toBe($months);
})->with([
    'null' => [null],
    'one month' => [1],
    'twelve months' => [12],
]);

it('rejects a zero or negative calibration interval', function (int $months) {
    createCalibrationSessionConstraintTables();
    insertSessionInstrument(['instrument_id' => 1]);

    expect(fn () => insertSessionType([
        'instrument_type_id' => 11,
        'instrument_id' => 1,
        'calibration_interval_months' => $months,
    ]))->toThrow(QueryException::class);
})->with([
    'zero' => [0],
    'negative' => [-1],
]);

it('rejects a second in-progress session for the same instrument type', function () {
    createCalibrationSessionConstraintTables();
    insertSessionInstrument(['instrument_id' => 1]);
    insertSessionType(['instrument_type_id' => 11, 'instrument_id' => 1]);

    DB::table('calibration')->insert(sessionCalibrationAttributes([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_number' => 'CAL-OPEN-1',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
    ]));

    expect(fn () => DB::table('calibration')->insert(sessionCalibrationAttributes([
        'calibration_id' => 2,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_number' => 'CAL-OPEN-2',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
    ])))->toThrow(QueryException::class);
});

it('allows in-progress sessions on different instrument types', function () {
    createCalibrationSessionConstraintTables();
    insertSessionInstrument(['instrument_id' => 1]);
    insertSessionType(['instrument_type_id' => 11, 'instrument_id' => 1, 'type_name' => 'Weight']);
    insertSessionType(['instrument_type_id' => 12, 'instrument_id' => 1, 'type_name' => 'Diameter']);

    DB::table('calibration')->insert([
        sessionCalibrationAttributes([
            'calibration_id' => 1,
            'instrument_id' => 1,
            'instrument_type_id' => 11,
            'calibration_number' => 'CAL-OPEN-WEIGHT',
            'session_status' => Calibration::STATUS_IN_PROGRESS,
        ]),
        sessionCalibrationAttributes([
            'calibration_id' => 2,
            'instrument_id' => 1,
            'instrument_type_id' => 12,
            'calibration_number' => 'CAL-OPEN-DIAMETER',
            'session_status' => Calibration::STATUS_IN_PROGRESS,
        ]),
    ]);

    expect(Calibration::query()->inProgress()->count())->toBe(2);
});

it('allows many completed sessions for the same instrument type', function () {
    createCalibrationSessionConstraintTables();
    insertSessionInstrument(['instrument_id' => 1]);
    insertSessionType(['instrument_type_id' => 11, 'instrument_id' => 1]);

    DB::table('calibration')->insert([
        sessionCalibrationAttributes([
            'calibration_id' => 1,
            'instrument_id' => 1,
            'instrument_type_id' => 11,
            'calibration_number' => 'CAL-DONE-1',
            'calibration_date' => '2025-10-01',
            'session_status' => Calibration::STATUS_COMPLETED,
        ]),
        sessionCalibrationAttributes([
            'calibration_id' => 2,
            'instrument_id' => 1,
            'instrument_type_id' => 11,
            'calibration_number' => 'CAL-DONE-2',
            'calibration_date' => '2026-10-01',
            'session_status' => Calibration::STATUS_COMPLETED,
        ]),
    ]);

    expect(Calibration::query()->completed()->count())->toBe(2);
});

it('does not let a cancelled session block a later in-progress session', function () {
    createCalibrationSessionConstraintTables();
    insertSessionInstrument(['instrument_id' => 1]);
    insertSessionType(['instrument_type_id' => 11, 'instrument_id' => 1]);

    DB::table('calibration')->insert(sessionCalibrationAttributes([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_number' => 'CAL-CANCELLED',
        'session_status' => Calibration::STATUS_CANCELLED,
    ]));

    DB::table('calibration')->insert(sessionCalibrationAttributes([
        'calibration_id' => 2,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_number' => 'CAL-NEXT',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
    ]));

    expect(Calibration::query()->cancelled()->count())->toBe(1)
        ->and(Calibration::query()->inProgress()->count())->toBe(1);
});

it('accepts an in-progress draft without a provider or dates', function () {
    createCalibrationSessionConstraintTables();
    insertSessionInstrument(['instrument_id' => 1]);
    insertSessionType(['instrument_type_id' => 11, 'instrument_id' => 1]);

    DB::table('calibration')->insert(sessionCalibrationAttributes([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_provider_id' => null,
        'calibration_date' => null,
        'calibration_due' => null,
        'calibration_number' => 'CAL.VT0001/10/26',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
    ]));

    expect(Calibration::query()->findOrFail(1)->calibration_provider_id)->toBeNull()
        ->and(Calibration::query()->findOrFail(1)->calibration_date)->toBeNull();
});

it('rejects an in-progress session without an instrument type', function () {
    createCalibrationSessionConstraintTables();
    insertSessionInstrument(['instrument_id' => 1]);

    expect(fn () => DB::table('calibration')->insert(sessionCalibrationAttributes([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => null,
        'calibration_number' => 'CAL-NO-TYPE',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
    ])))->toThrow(QueryException::class);
});
