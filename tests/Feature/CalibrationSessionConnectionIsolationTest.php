<?php

use App\CalibrationSessions\CalibrationSessionConnection;
use App\CalibrationSessions\StartCalibrationSession;
use App\Models\Calibration;
use App\Models\InstrumentType;
use App\Models\TestProfileVersion;
use App\TestColumns\ActiveTestTableScopeResolver;
use App\TestColumns\CalibrationScopePinning;
use App\TestColumns\TestTableSnapshotBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeCalibrationNumberAllocator;
use Tests\TestRefusingPostgresConnector;

const ISOLATION_SESSION_CONNECTION = 'session_sqlite';

function isolationRegisterSessionSqlite(): void
{
    config([
        'database.connections.'.ISOLATION_SESSION_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(ISOLATION_SESSION_CONNECTION);
}

function isolationConnection(string $name = ISOLATION_SESSION_CONNECTION)
{
    return DB::connection($name);
}

function isolationCreateSessionTables(string $connectionName): void
{
    $schema = Schema::connection($connectionName);

    $schema->create('location', function (Blueprint $table): void {
        $table->unsignedBigInteger('location_id')->primary();
        $table->string('location_code', 50);
        $table->string('location_name', 100);
        $table->timestamp('created_at');
    });

    $schema->create('detail_location', function (Blueprint $table): void {
        $table->unsignedBigInteger('detail_location_id')->primary();
        $table->unsignedBigInteger('location_id');
        $table->string('detail_location_code', 50);
        $table->string('detail_location_name', 100);
        $table->timestamp('created_at');
    });

    $schema->create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name', 150);
        $table->string('serial_number', 255)->nullable();
        $table->unsignedBigInteger('detail_location_id')->nullable();
        $table->timestamp('created_at');
    });

    $schema->create('instrument_type', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name');
        $table->string('brand')->nullable();
        $table->string('model')->nullable();
        $table->string('asset_number', 50)->nullable();
        $table->decimal('max_capacity')->nullable();
        $table->string('max_capacity_unit')->nullable();
        $table->decimal('resolution')->nullable();
        $table->string('resolution_unit')->nullable();
        $table->unsignedInteger('calibration_interval_months')->nullable();
        $table->timestamp('created_at');
    });

    $schema->create('calibration_provider', function (Blueprint $table): void {
        $table->id('calibration_provider_id');
        $table->string('provider_code', 50)->unique();
        $table->string('provider_name', 150);
        $table->string('provider_type', 20);
        $table->timestamp('created_at');
    });

    DB::connection($connectionName)->statement("
        CREATE TABLE calibration (
            calibration_id INTEGER PRIMARY KEY AUTOINCREMENT,
            instrument_id INTEGER NOT NULL,
            instrument_type_id INTEGER,
            calibration_provider_id INTEGER,
            calibration_number VARCHAR NOT NULL UNIQUE,
            calibration_date DATE,
            calibration_due DATE,
            calibration_method VARCHAR NOT NULL,
            calibration_result VARCHAR,
            certificate_number VARCHAR,
            remarks TEXT,
            session_status VARCHAR NOT NULL DEFAULT 'completed',
            completed_at DATETIME,
            cancelled_at DATETIME,
            cancelled_reason TEXT,
            cancelled_by_user_id INTEGER,
            snapshot_instrument_name VARCHAR,
            snapshot_instrument_type_name VARCHAR,
            snapshot_brand VARCHAR,
            snapshot_model VARCHAR,
            snapshot_serial_number VARCHAR,
            snapshot_asset_number VARCHAR,
            snapshot_max_capacity NUMERIC,
            snapshot_max_capacity_unit VARCHAR,
            snapshot_resolution NUMERIC,
            snapshot_resolution_unit VARCHAR,
            snapshot_location_id INTEGER,
            snapshot_detail_location_id INTEGER,
            snapshot_location_code VARCHAR,
            snapshot_location_name VARCHAR,
            snapshot_detail_location_code VARCHAR,
            snapshot_detail_location_name VARCHAR,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            action_date DATE,
            resume TEXT,
            customer_id INTEGER
        )
    ");

    DB::connection($connectionName)->statement(
        "CREATE UNIQUE INDEX uq_calibration_in_progress_per_type ON calibration (instrument_type_id) WHERE session_status = 'in_progress'",
    );

    $schema->create('instrument_type_requirement', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id');
        $table->string('requirement_category', 20);
        $table->string('requirement_mode', 20)->default('not_configured');
        $table->timestamp('updated_at');
        $table->primary(['instrument_type_id', 'requirement_category']);
    });

    $schema->create('calibration_requirement_snapshot', function (Blueprint $table): void {
        $table->id('calibration_requirement_snapshot_id');
        $table->unsignedBigInteger('calibration_id');
        $table->string('requirement_category', 20);
        $table->string('frozen_mode', 20);
        $table->string('resolved_mode', 20)->nullable();
        $table->timestamp('resolved_at')->nullable();
        $table->text('resolved_reason')->nullable();
        $table->timestamp('configuration_confirmed_at')->nullable();
        $table->text('configuration_confirmed_reason')->nullable();
        $table->unique(['calibration_id', 'requirement_category']);
    });

    $schema->create('environment_parameter', function (Blueprint $table): void {
        $table->unsignedBigInteger('parameter_id')->primary();
        $table->string('parameter_code', 50);
        $table->string('parameter_name', 150);
        $table->string('unit', 50);
        $table->string('data_type', 20);
        $table->boolean('is_system')->default(false);
        $table->timestamp('created_at');
    });

    $schema->create('instrument_type_environment_parameter', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id');
        $table->unsignedBigInteger('parameter_id');
        $table->string('requirement_mode', 20);
        $table->integer('display_order');
        $table->primary(['instrument_type_id', 'parameter_id']);
    });

    $schema->create('calibration_environment_parameter_snapshot', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('parameter_id');
        $table->string('parameter_code', 50);
        $table->string('parameter_name', 150);
        $table->string('unit', 50);
        $table->string('data_type', 20);
        $table->string('requirement_mode', 20);
        $table->integer('display_order');
        $table->timestamp('included_at');
        $table->boolean('inclusion_confirmed')->default(true);
        $table->text('inclusion_reason')->nullable();
        $table->primary(['calibration_id', 'parameter_id']);
    });

    $schema->create('test_profile', function (Blueprint $table): void {
        $table->unsignedBigInteger('test_profile_id')->primary();
        $table->unsignedBigInteger('instrument_type_id');
        $table->string('profile_name', 150);
        $table->string('display_title')->nullable();
        $table->text('description')->nullable();
        $table->timestamp('created_at');
        $table->timestamp('archived_at')->nullable();
    });

    $schema->create('test_profile_version', function (Blueprint $table): void {
        $table->unsignedBigInteger('test_profile_version_id')->primary();
        $table->unsignedBigInteger('test_profile_id');
        $table->integer('version_number');
        $table->string('status', 20);
        $table->string('profile_name', 150);
        $table->string('display_title')->nullable();
        $table->text('description')->nullable();
        $table->timestamp('published_at')->nullable();
        $table->timestamp('created_at');
    });

    $schema->create('test_column', function (Blueprint $table): void {
        $table->unsignedBigInteger('test_column_id')->primary();
        $table->unsignedBigInteger('test_profile_id');
        $table->unsignedBigInteger('test_profile_version_id')->nullable();
        $table->string('column_key', 63);
        $table->string('column_label', 150);
        $table->integer('column_order');
        $table->string('data_type', 20);
        $table->string('value_source', 20);
        $table->string('unit', 50)->nullable();
        $table->unsignedSmallInteger('display_scale')->nullable();
        $table->string('formula_code', 20)->nullable();
        $table->text('formula_inputs')->nullable();
        $table->string('completion_rule', 20)->default('not_configured');
        $table->timestamp('created_at');
    });

    $schema->create('test_point', function (Blueprint $table): void {
        $table->unsignedBigInteger('test_point_id')->primary();
        $table->unsignedBigInteger('test_profile_id');
        $table->unsignedBigInteger('test_profile_version_id')->nullable();
        $table->integer('point_order');
        $table->decimal('nominal_value', 18, 6)->nullable();
        $table->string('unit', 50)->nullable();
        $table->decimal('tolerance_plus', 18, 6)->nullable();
        $table->decimal('tolerance_minus', 18, 6)->nullable();
        $table->text('description')->nullable();
        $table->timestamp('created_at');
    });

    $schema->create('test_point_value', function (Blueprint $table): void {
        $table->unsignedBigInteger('test_point_value_id')->primary();
        $table->unsignedBigInteger('test_profile_id');
        $table->unsignedBigInteger('test_profile_version_id')->nullable();
        $table->unsignedBigInteger('test_point_id');
        $table->unsignedBigInteger('test_column_id');
        $table->decimal('numeric_value', 18, 6)->nullable();
        $table->text('text_value')->nullable();
        $table->string('unit', 50)->nullable();
        $table->timestamp('created_at');
    });

    $schema->create('calibration_scope', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_scope_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('instrument_type_id');
        $table->unsignedBigInteger('test_profile_id')->nullable();
        $table->unsignedBigInteger('test_profile_version_id')->nullable();
        $table->json('test_table_snapshot')->nullable();
        $table->integer('scope_order');
        $table->text('remarks')->nullable();
        $table->timestamp('created_at');
        $table->unique(['calibration_id', 'scope_order']);
    });
}

function isolationSeedStartMasters(string $connectionName): void
{
    $db = DB::connection($connectionName);

    $db->table('location')->insert([
        'location_id' => 4,
        'location_code' => 'FMD',
        'location_name' => 'Filling Hall',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    $db->table('detail_location')->insert([
        'detail_location_id' => 8,
        'location_id' => 4,
        'detail_location_code' => 'FM05-06',
        'detail_location_name' => 'Line 2',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    $db->table('instrument')->insert([
        'instrument_id' => 1,
        'instrument_name' => 'Gauge',
        'detail_location_id' => 8,
        'created_at' => '2026-10-20 09:00:00',
    ]);
    $db->table('instrument_type')->insert([
        'instrument_type_id' => 11,
        'instrument_id' => 1,
        'type_name' => 'Weight',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    $db->table('calibration_provider')->insert([
        'provider_code' => 'INTERNAL_TEAM',
        'provider_name' => 'Internal Calibration Team',
        'provider_type' => 'INTERNAL',
        'created_at' => '2026-10-20 09:00:00',
    ]);
}

function isolationSeedPinningFixture(string $connectionName, string $cellText): void
{
    $db = DB::connection($connectionName);

    $db->table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => null,
        'created_at' => '2026-10-14 09:00:00',
    ]);
    $db->table('instrument_type')->insert([
        'instrument_type_id' => 31,
        'instrument_id' => 3,
        'type_name' => 'Weight',
        'created_at' => '2026-10-14 09:00:00',
    ]);
    $db->table('calibration')->insert([
        'calibration_id' => 8,
        'instrument_id' => 3,
        'calibration_provider_id' => 1,
        'calibration_number' => 'CAL-PIN-0001',
        'calibration_date' => '2026-06-12',
        'calibration_due' => '2027-06-12',
        'calibration_method' => 'INTERNAL',
        'session_status' => 'completed',
        'created_at' => '2026-06-12 09:00:00',
        'updated_at' => '2026-06-12 09:00:00',
    ]);
    $db->table('test_profile')->insert([
        'test_profile_id' => 1,
        'instrument_type_id' => 31,
        'profile_name' => 'Main Test',
        'display_title' => 'Main Test',
        'description' => null,
        'created_at' => '2026-10-14 09:00:00',
    ]);
    $db->table('test_profile_version')->insert([
        'test_profile_version_id' => 11,
        'test_profile_id' => 1,
        'version_number' => 2,
        'status' => 'active',
        'profile_name' => 'Main Test',
        'display_title' => 'Main Test',
        'published_at' => '2026-10-14 09:00:00',
        'created_at' => '2026-10-14 09:00:00',
    ]);
    $db->table('test_column')->insert([
        'test_column_id' => 21,
        'test_profile_id' => 1,
        'test_profile_version_id' => 11,
        'column_key' => 'main_cell',
        'column_label' => 'main_cell',
        'column_order' => 1,
        'data_type' => 'text',
        'value_source' => 'template',
        'unit' => null,
        'display_scale' => null,
        'formula_code' => null,
        'formula_inputs' => '[]',
        'completion_rule' => 'required',
        'created_at' => '2026-10-14 09:00:00',
    ]);
    $db->table('test_point')->insert([
        'test_point_id' => 31,
        'test_profile_id' => 1,
        'test_profile_version_id' => 11,
        'point_order' => 1,
        'nominal_value' => null,
        'unit' => null,
        'tolerance_plus' => null,
        'tolerance_minus' => null,
        'description' => 'Main Test active',
        'created_at' => '2026-10-14 09:00:00',
    ]);
    $db->table('test_point_value')->insert([
        'test_point_value_id' => 41,
        'test_profile_id' => 1,
        'test_profile_version_id' => 11,
        'test_point_id' => 31,
        'test_column_id' => 21,
        'numeric_value' => null,
        'text_value' => $cellText,
        'unit' => null,
        'created_at' => '2026-10-14 09:00:00',
    ]);
}

function isolationSeedDefaultDecoyScope(): void
{
    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => 999,
        'calibration_id' => 8,
        'instrument_type_id' => 31,
        'test_profile_id' => 1,
        'test_profile_version_id' => 11,
        'test_table_snapshot' => json_encode(['schema_version' => 1, 'source' => 'default-decoy']),
        'scope_order' => 9,
        'remarks' => 'default-decoy',
        'created_at' => '2026-06-12 09:00:00',
    ]);
}

function isolationSeedDefaultDecoyCalibration(): void
{
    DB::table('calibration')->insert([
        'calibration_id' => 50,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_number' => 'CAL-DECOY',
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'session_status' => Calibration::STATUS_COMPLETED,
        'completed_at' => '2026-01-15 09:00:00',
        'created_at' => '2026-01-15 09:00:00',
        'updated_at' => '2026-01-15 09:00:00',
    ]);
}

beforeEach(function () {
    isolationRegisterSessionSqlite();
});

afterEach(function () {
    DB::purge(ISOLATION_SESSION_CONNECTION);
});

it('builds schema version 2 from the default connection when no session connection is supplied', function () {
    isolationCreateSessionTables('sqlite');
    isolationCreateSessionTables(ISOLATION_SESSION_CONNECTION);
    isolationSeedPinningFixture('sqlite', 'default-cell');
    isolationSeedPinningFixture(ISOLATION_SESSION_CONNECTION, 'session-cell');

    $snapshot = app(TestTableSnapshotBuilder::class)->build(
        TestProfileVersion::query()->findOrFail(11),
    );

    expect($snapshot['schema_version'])->toBe(2)
        ->and($snapshot['columns'][0]['completion_rule'])->toBe('required')
        ->and($snapshot['rows'][0]['template_values']['main_cell']['text_value'])->toBe('default-cell')
        ->and(DB::connection(ISOLATION_SESSION_CONNECTION)->table('test_point_value')->value('text_value'))->toBe('session-cell');
});

it('rebuilds the snapshot from the explicit session connection', function () {
    isolationCreateSessionTables('sqlite');
    isolationCreateSessionTables(ISOLATION_SESSION_CONNECTION);
    isolationSeedPinningFixture('sqlite', 'default-cell');
    isolationSeedPinningFixture(ISOLATION_SESSION_CONNECTION, 'session-cell');

    $snapshot = app(TestTableSnapshotBuilder::class)->build(
        TestProfileVersion::query()->findOrFail(11),
        isolationConnection(),
    );

    expect($snapshot['schema_version'])->toBe(2)
        ->and($snapshot['rows'][0]['template_values']['main_cell']['text_value'])->toBe('session-cell');
});

it('resolves active tables from the default connection when no session connection is supplied', function () {
    isolationCreateSessionTables('sqlite');
    isolationCreateSessionTables(ISOLATION_SESSION_CONNECTION);
    isolationSeedPinningFixture('sqlite', 'default-cell');
    isolationSeedPinningFixture(ISOLATION_SESSION_CONNECTION, 'session-cell');

    $plan = app(ActiveTestTableScopeResolver::class)->resolve(
        InstrumentType::query()->findOrFail(31),
    );

    expect($plan->candidates)->toHaveCount(1)
        ->and($plan->candidates[0]->snapshot['rows'][0]['template_values']['main_cell']['text_value'])->toBe('default-cell');
});

it('resolves active tables from the explicit session connection', function () {
    isolationCreateSessionTables('sqlite');
    isolationCreateSessionTables(ISOLATION_SESSION_CONNECTION);
    isolationSeedPinningFixture('sqlite', 'default-cell');
    isolationSeedPinningFixture(ISOLATION_SESSION_CONNECTION, 'session-cell');

    $plan = app(ActiveTestTableScopeResolver::class)->resolve(
        InstrumentType::query()->findOrFail(31),
        isolationConnection(),
    );

    expect($plan->candidates)->toHaveCount(1)
        ->and($plan->candidates[0]->snapshot['rows'][0]['template_values']['main_cell']['text_value'])->toBe('session-cell');
});

it('pins scopes only on the explicit session connection', function () {
    isolationCreateSessionTables('sqlite');
    isolationCreateSessionTables(ISOLATION_SESSION_CONNECTION);
    isolationSeedPinningFixture('sqlite', 'default-cell');
    isolationSeedPinningFixture(ISOLATION_SESSION_CONNECTION, 'session-cell');
    isolationSeedDefaultDecoyScope();

    $scopes = app(CalibrationScopePinning::class)->pin(
        Calibration::on(ISOLATION_SESSION_CONNECTION)->findOrFail(8),
        InstrumentType::on(ISOLATION_SESSION_CONNECTION)->findOrFail(31),
        isolationConnection(),
    );

    expect($scopes)->toHaveCount(1)
        ->and($scopes->first()?->getConnectionName())->toBe(ISOLATION_SESSION_CONNECTION)
        ->and($scopes->first()?->test_table_snapshot['schema_version'])->toBe(2)
        ->and($scopes->first()?->test_table_snapshot['rows'][0]['template_values']['main_cell']['text_value'])->toBe('session-cell')
        ->and(DB::connection(ISOLATION_SESSION_CONNECTION)->table('calibration_scope')->count())->toBe(1)
        ->and(DB::table('calibration_scope')->count())->toBe(1)
        ->and(DB::table('calibration_scope')->value('remarks'))->toBe('default-decoy');
});

it('rolls back pinning on the session connection without writing to the default connection', function () {
    isolationCreateSessionTables('sqlite');
    isolationCreateSessionTables(ISOLATION_SESSION_CONNECTION);
    isolationSeedPinningFixture('sqlite', 'default-cell');
    isolationSeedPinningFixture(ISOLATION_SESSION_CONNECTION, 'session-cell');
    isolationSeedDefaultDecoyScope();

    DB::connection(ISOLATION_SESSION_CONNECTION)->statement("
        CREATE TRIGGER abort_isolation_scope
        BEFORE INSERT ON calibration_scope
        BEGIN
            SELECT RAISE(ABORT, 'forced isolation pin failure');
        END
    ");

    expect(fn () => app(CalibrationScopePinning::class)->pin(
        Calibration::on(ISOLATION_SESSION_CONNECTION)->findOrFail(8),
        InstrumentType::on(ISOLATION_SESSION_CONNECTION)->findOrFail(31),
        isolationConnection(),
    ))->toThrow(QueryException::class);

    expect(DB::connection(ISOLATION_SESSION_CONNECTION)->table('calibration_scope')->count())->toBe(0)
        ->and(DB::table('calibration_scope')->count())->toBe(1)
        ->and(DB::table('calibration_scope')->value('remarks'))->toBe('default-decoy');
});

it('starts a calibration only on the explicit session connection', function () {
    isolationCreateSessionTables('sqlite');
    isolationCreateSessionTables(ISOLATION_SESSION_CONNECTION);
    isolationSeedStartMasters('sqlite');
    isolationSeedStartMasters(ISOLATION_SESSION_CONNECTION);
    isolationSeedDefaultDecoyCalibration();

    $service = new StartCalibrationSession(
        app(CalibrationSessionConnection::class),
        new FakeCalibrationNumberAllocator,
    );

    $calibration = $service->start(11, Calibration::METHOD_INTERNAL, isolationConnection());

    expect($calibration->getConnectionName())->toBe(ISOLATION_SESSION_CONNECTION)
        ->and($calibration->session_status)->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($calibration->snapshot_instrument_name)->toBe('Gauge')
        ->and(DB::connection(ISOLATION_SESSION_CONNECTION)->table('calibration')->where('session_status', 'in_progress')->count())->toBe(1)
        ->and(DB::connection(ISOLATION_SESSION_CONNECTION)->table('calibration_requirement_snapshot')->count())->toBe(4)
        ->and(DB::table('calibration')->count())->toBe(1)
        ->and(DB::table('calibration')->value('calibration_number'))->toBe('CAL-DECOY')
        ->and(DB::table('calibration_requirement_snapshot')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rolls back start on the session connection without writing to the default connection', function () {
    isolationCreateSessionTables('sqlite');
    isolationCreateSessionTables(ISOLATION_SESSION_CONNECTION);
    isolationSeedStartMasters('sqlite');
    isolationSeedStartMasters(ISOLATION_SESSION_CONNECTION);
    isolationSeedDefaultDecoyCalibration();

    DB::connection(ISOLATION_SESSION_CONNECTION)->statement("
        CREATE TRIGGER abort_isolation_start
        BEFORE INSERT ON calibration
        BEGIN
            SELECT RAISE(ABORT, 'forced isolation start failure');
        END
    ");

    $service = new StartCalibrationSession(
        app(CalibrationSessionConnection::class),
        new FakeCalibrationNumberAllocator,
    );

    expect(fn () => $service->start(11, Calibration::METHOD_INTERNAL, isolationConnection()))
        ->toThrow(QueryException::class);

    expect(DB::connection(ISOLATION_SESSION_CONNECTION)->table('calibration')->count())->toBe(0)
        ->and(DB::connection(ISOLATION_SESSION_CONNECTION)->table('calibration_requirement_snapshot')->count())->toBe(0)
        ->and(DB::connection(ISOLATION_SESSION_CONNECTION)->table('calibration_environment_parameter_snapshot')->count())->toBe(0)
        ->and(DB::table('calibration')->count())->toBe(1)
        ->and(DB::table('calibration')->value('calibration_number'))->toBe('CAL-DECOY')
        ->and(DB::table('calibration_requirement_snapshot')->count())->toBe(0)
        ->and(DB::table('calibration_environment_parameter_snapshot')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});
