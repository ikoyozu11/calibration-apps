<?php

use App\CalibrationSessions\CalibrationSessionCompleteness;
use App\Models\Calibration;
use App\TestColumns\TestTableSnapshotBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createCalibrationSessionFoundationTables(): void
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
            UNIQUE (instrument_type_id, instrument_id),
            FOREIGN KEY (instrument_id) REFERENCES instrument (instrument_id)
        )
    ');

    Schema::create('app_user', function (Blueprint $table): void {
        $table->unsignedBigInteger('app_user_id')->primary();
        $table->string('username', 100);
    });

    Schema::create('standard_master', function (Blueprint $table): void {
        $table->unsignedBigInteger('standard_id')->primary();
        $table->string('standard_code', 20);
        $table->string('instrument_name', 150);
    });

    Schema::create('calibration_activity_master', function (Blueprint $table): void {
        $table->unsignedBigInteger('activity_id')->primary();
        $table->string('activity_code', 30);
        $table->string('activity_name', 255);
    });

    DB::statement("
        CREATE TABLE calibration (
            calibration_id INTEGER PRIMARY KEY,
            instrument_id INTEGER NOT NULL,
            instrument_type_id INTEGER NOT NULL,
            calibration_provider_id INTEGER,
            calibration_number VARCHAR NOT NULL UNIQUE,
            calibration_date DATE,
            calibration_due DATE,
            calibration_method VARCHAR NOT NULL,
            session_status VARCHAR NOT NULL DEFAULT 'completed',
            completed_at DATETIME,
            cancelled_at DATETIME,
            cancelled_reason TEXT,
            cancelled_by_user_id INTEGER,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            CHECK (session_status IN ('in_progress', 'completed', 'cancelled')),
            CHECK (session_status <> 'in_progress' OR instrument_type_id IS NOT NULL),
            CHECK (
                calibration_date IS NULL
                OR calibration_due IS NULL
                OR calibration_due >= calibration_date
            ),
            CHECK (
                (
                    session_status = 'cancelled'
                    AND cancelled_at IS NOT NULL
                    AND cancelled_reason IS NOT NULL
                    AND length(trim(cancelled_reason)) > 0
                )
                OR (
                    session_status <> 'cancelled'
                    AND cancelled_at IS NULL
                    AND cancelled_reason IS NULL
                    AND cancelled_by_user_id IS NULL
                )
            ),
            CHECK (
                (session_status = 'completed' AND completed_at IS NOT NULL)
                OR (session_status <> 'completed' AND completed_at IS NULL)
            ),
            FOREIGN KEY (instrument_type_id, instrument_id) REFERENCES instrument_type (instrument_type_id, instrument_id),
            FOREIGN KEY (cancelled_by_user_id) REFERENCES app_user (app_user_id)
        )
    ");

    DB::statement("CREATE UNIQUE INDEX uq_calibration_in_progress_per_type ON calibration (instrument_type_id) WHERE session_status = 'in_progress'");

    DB::statement("
        CREATE TABLE calibration_requirement_snapshot (
            calibration_requirement_snapshot_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            requirement_category VARCHAR NOT NULL,
            frozen_mode VARCHAR NOT NULL,
            resolved_mode VARCHAR,
            resolved_at DATETIME,
            resolved_reason TEXT,
            UNIQUE (calibration_id, requirement_category),
            CHECK (requirement_category IN ('standards', 'activity', 'environment', 'test_profile')),
            CHECK (frozen_mode IN ('required', 'optional', 'not_configured')),
            CHECK (
                (resolved_mode IS NULL AND resolved_at IS NULL AND resolved_reason IS NULL)
                OR (
                    frozen_mode = 'not_configured'
                    AND resolved_mode IN ('required', 'optional')
                    AND resolved_at IS NOT NULL
                    AND resolved_reason IS NOT NULL
                )
            ),
            FOREIGN KEY (calibration_id) REFERENCES calibration (calibration_id)
        )
    ");

    Schema::create('calibration_scope', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_scope_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('test_profile_version_id')->nullable();
        $table->json('test_table_snapshot')->nullable();
        $table->unique(['calibration_scope_id', 'calibration_id']);
        $table->foreign('calibration_id')->references('calibration_id')->on('calibration');
    });

    DB::statement('
        CREATE TABLE calibration_test_profile_revision (
            revision_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            calibration_scope_id INTEGER NOT NULL,
            previous_structure TEXT NOT NULL,
            previous_results TEXT NOT NULL,
            proposed_mappings TEXT NOT NULL,
            confirmed_mappings TEXT NOT NULL,
            reason TEXT NOT NULL,
            revised_at DATETIME NOT NULL,
            CHECK (length(trim(reason)) > 0),
            FOREIGN KEY (calibration_scope_id, calibration_id) REFERENCES calibration_scope (calibration_scope_id, calibration_id)
        )
    ');

    DB::statement("
        CREATE TABLE calibration_external_document (
            document_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            storage_uuid VARCHAR NOT NULL UNIQUE,
            storage_path TEXT NOT NULL,
            original_filename TEXT NOT NULL,
            mime_type VARCHAR NOT NULL,
            byte_size INTEGER NOT NULL,
            sha256 VARCHAR NOT NULL,
            is_active INTEGER NOT NULL,
            uploaded_at DATETIME NOT NULL,
            superseded_at DATETIME,
            CHECK (mime_type = 'application/pdf'),
            CHECK (byte_size > 0),
            CHECK (length(sha256) = 64),
            CHECK ((is_active = 1 AND superseded_at IS NULL) OR (is_active = 0 AND superseded_at IS NOT NULL)),
            FOREIGN KEY (calibration_id) REFERENCES calibration (calibration_id)
        )
    ");

    DB::statement('CREATE UNIQUE INDEX uq_calibration_external_document_active ON calibration_external_document (calibration_id) WHERE is_active = 1');

    DB::statement("
        CREATE TRIGGER trg_calibration_external_document_supersede
        BEFORE INSERT ON calibration_external_document
        FOR EACH ROW
        WHEN NEW.is_active = 1
        BEGIN
            UPDATE calibration_external_document
            SET is_active = 0,
                superseded_at = COALESCE(superseded_at, datetime('now'))
            WHERE calibration_id = NEW.calibration_id
              AND is_active = 1
              AND document_id != NEW.document_id;
        END
    ");

    DB::statement('
        CREATE TABLE calibration_standard_usage (
            calibration_standard_usage_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            standard_instrument_id INTEGER,
            standard_id INTEGER,
            usage_order INTEGER NOT NULL,
            CHECK (standard_id IS NOT NULL OR standard_instrument_id IS NOT NULL),
            FOREIGN KEY (calibration_id) REFERENCES calibration (calibration_id),
            FOREIGN KEY (standard_id) REFERENCES standard_master (standard_id)
        )
    ');

    DB::statement('
        CREATE TABLE calibration_activity (
            calibration_activity_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            activity_type VARCHAR,
            activity_id INTEGER,
            activity_name VARCHAR(255),
            activity_order INTEGER NOT NULL,
            CHECK (activity_id IS NOT NULL OR (activity_type IS NOT NULL AND length(trim(activity_type)) > 0)),
            FOREIGN KEY (calibration_id) REFERENCES calibration (calibration_id),
            FOREIGN KEY (activity_id) REFERENCES calibration_activity_master (activity_id)
        )
    ');

    Schema::create('test_column', function (Blueprint $table): void {
        $table->unsignedBigInteger('test_column_id')->primary();
        $table->string('column_key', 63);
        $table->string('completion_rule', 20)->default('not_configured');
    });
}

/**
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function foundationCalibration(array $attributes): array
{
    return array_merge([
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_provider_id' => null,
        'calibration_date' => null,
        'calibration_due' => null,
        'calibration_method' => 'INTERNAL',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'completed_at' => null,
        'cancelled_at' => null,
        'cancelled_reason' => null,
        'created_at' => '2026-10-20 09:00:00',
        'updated_at' => '2026-10-20 09:00:00',
    ], $attributes);
}

function seedFoundationParents(): void
{
    DB::table('instrument')->insert([
        'instrument_id' => 1,
        'instrument_name' => 'Gauge',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('instrument_type')->insert([
        'instrument_type_id' => 11,
        'instrument_id' => 1,
        'type_name' => 'Weight',
        'created_at' => '2026-10-20 09:00:00',
    ]);
}

it('isolates this suite on sqlite memory', function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:')
        ->and(DB::getDriverName())->toBe('sqlite');
});

it('stores a draft with null dates and a null provider', function () {
    createCalibrationSessionFoundationTables();
    seedFoundationParents();

    DB::table('calibration')->insert(foundationCalibration([
        'calibration_id' => 1,
        'calibration_number' => 'CAL.VT0001/10/26',
    ]));

    $row = DB::table('calibration')->where('calibration_id', 1)->first();

    expect($row->calibration_date)->toBeNull()
        ->and($row->calibration_due)->toBeNull()
        ->and($row->calibration_provider_id)->toBeNull()
        ->and($row->session_status)->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('accepts either calibration date during in-progress save progress', function () {
    createCalibrationSessionFoundationTables();
    seedFoundationParents();

    DB::table('calibration')->insert(foundationCalibration([
        'calibration_id' => 1,
        'calibration_number' => 'CAL.VT0001/10/26',
        'calibration_date' => '2026-10-20',
    ]));

    expect(DB::table('calibration')->where('calibration_id', 1)->value('calibration_due'))->toBeNull();

    DB::table('calibration')->where('calibration_id', 1)->update([
        'calibration_date' => null,
        'calibration_due' => '2027-10-20',
    ]);

    expect(DB::table('calibration')->where('calibration_id', 1)->value('calibration_date'))->toBeNull()
        ->and(DB::table('calibration')->where('calibration_id', 1)->value('calibration_due'))->toBe('2027-10-20');
});

it('rejects both dates when due is before the calibration date', function () {
    createCalibrationSessionFoundationTables();
    seedFoundationParents();

    expect(fn () => DB::table('calibration')->insert(foundationCalibration([
        'calibration_id' => 1,
        'calibration_number' => 'CAL.VT0001/10/26',
        'calibration_date' => '2026-10-20',
        'calibration_due' => '2026-10-19',
    ])))->toThrow(QueryException::class);
});

it('requires a cancellation reason and timestamp together', function () {
    createCalibrationSessionFoundationTables();
    seedFoundationParents();

    expect(fn () => DB::table('calibration')->insert(foundationCalibration([
        'calibration_id' => 1,
        'calibration_number' => 'CAL.VT0001/10/26',
        'session_status' => Calibration::STATUS_CANCELLED,
        'cancelled_at' => '2026-10-20 10:00:00',
        'cancelled_reason' => null,
    ])))->toThrow(QueryException::class);
});

it('stores a cancelled audit row when reason and timestamp are present', function () {
    createCalibrationSessionFoundationTables();
    seedFoundationParents();

    DB::table('calibration')->insert(foundationCalibration([
        'calibration_id' => 1,
        'calibration_number' => 'CAL.VT0001/10/26',
        'session_status' => Calibration::STATUS_CANCELLED,
        'cancelled_at' => '2026-10-20 10:00:00',
        'cancelled_reason' => 'Instrument withdrawn',
    ]));

    expect(DB::table('calibration')->where('calibration_id', 1)->value('cancelled_reason'))
        ->toBe('Instrument withdrawn');
});

it('freezes requirement modes and allows a not-configured mode to resolve once', function () {
    createCalibrationSessionFoundationTables();
    seedFoundationParents();
    DB::table('calibration')->insert(foundationCalibration([
        'calibration_id' => 1,
        'calibration_number' => 'CAL.VT0001/10/26',
    ]));

    DB::table('calibration_requirement_snapshot')->insert([
        'calibration_requirement_snapshot_id' => 1,
        'calibration_id' => 1,
        'requirement_category' => 'environment',
        'frozen_mode' => 'not_configured',
        'resolved_mode' => 'optional',
        'resolved_at' => '2026-10-20 11:00:00',
        'resolved_reason' => 'No chamber available',
    ]);

    expect(CalibrationSessionCompleteness::effectiveMode('not_configured', 'optional'))->toBe('optional');
});

it('rejects resolving a frozen required requirement', function () {
    createCalibrationSessionFoundationTables();
    seedFoundationParents();
    DB::table('calibration')->insert(foundationCalibration([
        'calibration_id' => 1,
        'calibration_number' => 'CAL.VT0001/10/26',
    ]));

    expect(fn () => DB::table('calibration_requirement_snapshot')->insert([
        'calibration_requirement_snapshot_id' => 1,
        'calibration_id' => 1,
        'requirement_category' => 'standards',
        'frozen_mode' => 'required',
        'resolved_mode' => 'optional',
        'resolved_at' => '2026-10-20 11:00:00',
        'resolved_reason' => 'should not overwrite',
    ]))->toThrow(QueryException::class);
});

it('rejects a revision whose scope belongs to another session', function () {
    createCalibrationSessionFoundationTables();
    seedFoundationParents();
    DB::table('instrument')->insert([
        'instrument_id' => 2,
        'instrument_name' => 'Other',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('instrument_type')->insert([
        'instrument_type_id' => 21,
        'instrument_id' => 2,
        'type_name' => 'Other',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('calibration')->insert([
        foundationCalibration([
            'calibration_id' => 1,
            'calibration_number' => 'CAL.VT0001/10/26',
        ]),
        foundationCalibration([
            'calibration_id' => 2,
            'instrument_id' => 2,
            'instrument_type_id' => 21,
            'calibration_number' => 'CAL.VT0002/10/26',
        ]),
    ]);
    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => 10,
        'calibration_id' => 1,
    ]);

    expect(fn () => DB::table('calibration_test_profile_revision')->insert([
        'revision_id' => 1,
        'calibration_id' => 2,
        'calibration_scope_id' => 10,
        'previous_structure' => '{}',
        'previous_results' => '{}',
        'proposed_mappings' => '{}',
        'confirmed_mappings' => '{}',
        'reason' => 'Switch profile',
        'revised_at' => '2026-10-20 12:00:00',
    ]))->toThrow(QueryException::class);
});

it('stores a revision only when the scope belongs to the same session', function () {
    createCalibrationSessionFoundationTables();
    seedFoundationParents();
    DB::table('calibration')->insert(foundationCalibration([
        'calibration_id' => 1,
        'calibration_number' => 'CAL.VT0001/10/26',
    ]));
    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => 10,
        'calibration_id' => 1,
    ]);

    DB::table('calibration_test_profile_revision')->insert([
        'revision_id' => 1,
        'calibration_id' => 1,
        'calibration_scope_id' => 10,
        'previous_structure' => '{}',
        'previous_results' => '{}',
        'proposed_mappings' => '{}',
        'confirmed_mappings' => '{}',
        'reason' => 'Engineer confirmed mapping',
        'revised_at' => '2026-10-20 12:00:00',
    ]);

    expect(DB::table('calibration_test_profile_revision')->count())->toBe(1);
});

it('supersedes the previous active external document before the replacement becomes active', function () {
    createCalibrationSessionFoundationTables();
    seedFoundationParents();
    DB::table('calibration')->insert(foundationCalibration([
        'calibration_id' => 1,
        'calibration_method' => 'EXTERNAL',
        'calibration_number' => 'CAL.VT0001/10/26',
    ]));

    DB::table('calibration_external_document')->insert([
        'document_id' => 1,
        'calibration_id' => 1,
        'storage_uuid' => '11111111-1111-1111-1111-111111111111',
        'storage_path' => '11111111-1111-1111-1111-111111111111.pdf',
        'original_filename' => 'vendor-old.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => 1024,
        'sha256' => str_repeat('a', 64),
        'is_active' => 1,
        'uploaded_at' => '2026-10-20 09:00:00',
        'superseded_at' => null,
    ]);
    DB::table('calibration_external_document')->insert([
        'document_id' => 2,
        'calibration_id' => 1,
        'storage_uuid' => '22222222-2222-2222-2222-222222222222',
        'storage_path' => '22222222-2222-2222-2222-222222222222.pdf',
        'original_filename' => 'vendor-new.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => 2048,
        'sha256' => str_repeat('b', 64),
        'is_active' => 1,
        'uploaded_at' => '2026-10-20 10:00:00',
        'superseded_at' => null,
    ]);

    $rows = DB::table('calibration_external_document')->orderBy('document_id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->is_active)->toBe(0)
        ->and($rows[0]->superseded_at)->not->toBeNull()
        ->and($rows[0]->original_filename)->toBe('vendor-old.pdf')
        ->and($rows[1]->is_active)->toBe(1)
        ->and($rows[1]->superseded_at)->toBeNull();
});

it('rejects a non-pdf external document', function () {
    createCalibrationSessionFoundationTables();
    seedFoundationParents();
    DB::table('calibration')->insert(foundationCalibration([
        'calibration_id' => 1,
        'calibration_method' => 'EXTERNAL',
        'calibration_number' => 'CAL.VT0001/10/26',
    ]));

    expect(fn () => DB::table('calibration_external_document')->insert([
        'document_id' => 1,
        'calibration_id' => 1,
        'storage_uuid' => '11111111-1111-1111-1111-111111111111',
        'storage_path' => 'file.bin',
        'original_filename' => 'file.bin',
        'mime_type' => 'application/octet-stream',
        'byte_size' => 10,
        'sha256' => str_repeat('a', 64),
        'is_active' => 1,
        'uploaded_at' => '2026-10-20 09:00:00',
        'superseded_at' => null,
    ]))->toThrow(QueryException::class);
});

it('defaults new test columns to not configured instead of required or optional', function () {
    createCalibrationSessionFoundationTables();

    DB::table('test_column')->insert([
        'test_column_id' => 1,
        'column_key' => 'average',
    ]);

    expect(DB::table('test_column')->where('test_column_id', 1)->value('completion_rule'))
        ->toBe('not_configured');
});

it('accepts a standard usage row that snapshots a master without an instrument fk', function () {
    createCalibrationSessionFoundationTables();
    seedFoundationParents();
    DB::table('standard_master')->insert([
        'standard_id' => 5,
        'standard_code' => 'STD-1',
        'instrument_name' => 'Mass set',
    ]);
    DB::table('calibration')->insert(foundationCalibration([
        'calibration_id' => 1,
        'calibration_number' => 'CAL.VT0001/10/26',
    ]));

    DB::table('calibration_standard_usage')->insert([
        'calibration_standard_usage_id' => 1,
        'calibration_id' => 1,
        'standard_id' => 5,
        'standard_instrument_id' => null,
        'usage_order' => 1,
    ]);

    expect(DB::table('calibration_standard_usage')->where('calibration_standard_usage_id', 1)->value('standard_id'))
        ->toBe(5);
});

it('stores a 255-character activity name from the master catalog', function () {
    createCalibrationSessionFoundationTables();
    seedFoundationParents();
    $name = str_repeat('A', 255);
    DB::table('calibration_activity_master')->insert([
        'activity_id' => 1,
        'activity_code' => 'ACT-1',
        'activity_name' => $name,
    ]);
    DB::table('calibration')->insert(foundationCalibration([
        'calibration_id' => 1,
        'calibration_number' => 'CAL.VT0001/10/26',
    ]));

    DB::table('calibration_activity')->insert([
        'calibration_activity_id' => 1,
        'calibration_id' => 1,
        'activity_id' => 1,
        'activity_type' => null,
        'activity_name' => $name,
        'activity_order' => 1,
    ]);

    expect(DB::table('calibration_activity')->where('calibration_activity_id', 1)->value('activity_name'))
        ->toBe($name);
});

it('accepts a schema version 2 snapshot that includes completion rules', function () {
    $snapshot = [
        'schema_version' => 2,
        'duration_format' => 'hh:mm:ss',
        'test_profile_id' => 1,
        'test_profile_version_id' => 1,
        'version_number' => 4,
        'profile_name' => 'Main Test',
        'columns' => [[
            'column_key' => 'average',
            'column_label' => 'Average',
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

    expect(TestTableSnapshotBuilder::isWellformed($snapshot))->toBeTrue();
});

it('rejects a schema version 2 snapshot that omits completion rules', function () {
    $snapshot = [
        'schema_version' => 2,
        'duration_format' => 'hh:mm:ss',
        'test_profile_id' => 1,
        'test_profile_version_id' => 1,
        'version_number' => 4,
        'profile_name' => 'Main Test',
        'columns' => [[
            'column_key' => 'average',
            'column_label' => 'Average',
            'column_order' => 1,
            'data_type' => 'numeric',
            'value_source' => 'engineer',
            'formula_inputs' => [],
        ]],
        'rows' => [[
            'point_order' => 1,
            'template_values' => [],
        ]],
    ];

    expect(TestTableSnapshotBuilder::isWellformed($snapshot))->toBeFalse();
});
