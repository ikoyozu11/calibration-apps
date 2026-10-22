<?php

use App\CalibrationSessions\CalibrationNumberAllocator;
use App\CalibrationSessions\CalibrationSessionConnection;
use App\CalibrationSessions\StartCalibrationException;
use App\CalibrationSessions\StartCalibrationSession;
use App\Models\Calibration;
use App\Models\CalibrationEnvironmentParameterSnapshot;
use App\Models\CalibrationRequirementSnapshot;
use App\Models\InstrumentTypeRequirement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeCalibrationNumberAllocator;

function createStartCalibrationTables(): void
{
    Schema::create('location', function (Blueprint $table): void {
        $table->unsignedBigInteger('location_id')->primary();
        $table->string('location_code', 50);
        $table->string('location_name', 100);
        $table->timestamp('created_at');
    });

    Schema::create('detail_location', function (Blueprint $table): void {
        $table->unsignedBigInteger('detail_location_id')->primary();
        $table->unsignedBigInteger('location_id');
        $table->string('detail_location_code', 50);
        $table->string('detail_location_name', 100);
        $table->timestamp('created_at');
    });

    Schema::create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name', 150);
        $table->string('serial_number', 255)->nullable();
        $table->unsignedBigInteger('detail_location_id')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('instrument_type', function (Blueprint $table): void {
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

    Schema::create('calibration_provider', function (Blueprint $table): void {
        $table->id('calibration_provider_id');
        $table->string('provider_code', 50)->unique();
        $table->string('provider_name', 150);
        $table->string('provider_type', 20);
        $table->timestamp('created_at');
    });

    DB::statement("
        CREATE TABLE calibration (
            calibration_id INTEGER PRIMARY KEY AUTOINCREMENT,
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
            )
        )
    ");

    DB::statement("CREATE UNIQUE INDEX uq_calibration_in_progress_per_type ON calibration (instrument_type_id) WHERE session_status = 'in_progress'");

    Schema::create('instrument_type_requirement', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id');
        $table->string('requirement_category', 20);
        $table->string('requirement_mode', 20)->default('not_configured');
        $table->timestamp('updated_at');
        $table->primary(['instrument_type_id', 'requirement_category']);
    });

    Schema::create('calibration_requirement_snapshot', function (Blueprint $table): void {
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

    Schema::create('environment_parameter', function (Blueprint $table): void {
        $table->unsignedBigInteger('parameter_id')->primary();
        $table->string('parameter_code', 50);
        $table->string('parameter_name', 150);
        $table->string('unit', 50);
        $table->string('data_type', 20);
        $table->boolean('is_system')->default(false);
        $table->timestamp('created_at');
    });

    Schema::create('instrument_type_environment_parameter', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id');
        $table->unsignedBigInteger('parameter_id');
        $table->string('requirement_mode', 20);
        $table->integer('display_order');
        $table->primary(['instrument_type_id', 'parameter_id']);
    });

    Schema::create('calibration_environment_parameter_snapshot', function (Blueprint $table): void {
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

    Schema::create('calibration_environment_value', function (Blueprint $table): void {
        $table->id('calibration_environment_value_id');
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('parameter_id');
        $table->decimal('numeric_value')->nullable();
        $table->text('text_value')->nullable();
        $table->timestamp('recorded_at');
    });

    Schema::create('calibration_environment', function (Blueprint $table): void {
        $table->id('calibration_environment_id');
        $table->unsignedBigInteger('calibration_id');
        $table->decimal('temperature')->nullable();
        $table->decimal('relative_humidity')->nullable();
    });

    Schema::create('calibration_standard_usage', function (Blueprint $table): void {
        $table->id('calibration_standard_usage_id');
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('standard_id')->nullable();
        $table->integer('usage_order');
    });

    Schema::create('calibration_activity', function (Blueprint $table): void {
        $table->id('calibration_activity_id');
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('activity_id')->nullable();
        $table->integer('activity_order');
    });

    Schema::create('calibration_scope', function (Blueprint $table): void {
        $table->id('calibration_scope_id');
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('test_profile_version_id')->nullable();
        $table->json('test_table_snapshot')->nullable();
    });

    Schema::create('calibration_external_document', function (Blueprint $table): void {
        $table->id('document_id');
        $table->unsignedBigInteger('calibration_id');
        $table->string('storage_uuid');
        $table->boolean('is_active');
        $table->timestamp('uploaded_at');
    });

    Schema::create('certificate', function (Blueprint $table): void {
        $table->id('certificate_id');
        $table->unsignedBigInteger('calibration_id');
        $table->string('certificate_number');
        $table->timestamp('created_at');
    });
}

/**
 * @return array{allocator: FakeCalibrationNumberAllocator, service: StartCalibrationSession}
 */
function bindStartCalibrationServices(): array
{
    $allocator = new FakeCalibrationNumberAllocator;
    app()->instance(CalibrationNumberAllocator::class, $allocator);

    return [
        'allocator' => $allocator,
        'service' => app(StartCalibrationSession::class),
    ];
}

function seedStartCalibrationInstrumentType(): void
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
        'calibration_interval_months' => 12,
        'created_at' => '2026-10-20 09:00:00',
    ]);
}

function seedStartCalibrationIdentity(): void
{
    DB::table('location')->insert([
        'location_id' => 4,
        'location_code' => 'FMD',
        'location_name' => 'Filling Hall',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('detail_location')->insert([
        'detail_location_id' => 8,
        'location_id' => 4,
        'detail_location_code' => 'FM05-06',
        'detail_location_name' => 'Line 2',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('instrument')->where('instrument_id', 1)->update([
        'serial_number' => 'SN-1001',
        'detail_location_id' => 8,
    ]);
    DB::table('instrument_type')->where('instrument_type_id', 11)->update([
        'brand' => 'Mettler',
        'model' => 'PB3002',
        'asset_number' => 'AST-11',
        'max_capacity' => 1000,
        'max_capacity_unit' => 'g',
        'resolution' => 0.1,
        'resolution_unit' => 'g',
    ]);
}

function seedInternalCalibrationTeam(): void
{
    DB::table('calibration_provider')->insert([
        'provider_code' => 'INTERNAL_TEAM',
        'provider_name' => 'Internal Calibration Team',
        'provider_type' => 'INTERNAL',
        'created_at' => '2026-10-20 09:00:00',
    ]);
}

function seedStartCalibrationConfiguration(): void
{
    DB::table('instrument_type_requirement')->insert([
        [
            'instrument_type_id' => 11,
            'requirement_category' => InstrumentTypeRequirement::CATEGORY_STANDARDS,
            'requirement_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
            'updated_at' => '2026-10-20 09:00:00',
        ],
        [
            'instrument_type_id' => 11,
            'requirement_category' => InstrumentTypeRequirement::CATEGORY_ACTIVITY,
            'requirement_mode' => InstrumentTypeRequirement::MODE_OPTIONAL,
            'updated_at' => '2026-10-20 09:00:00',
        ],
        [
            'instrument_type_id' => 11,
            'requirement_category' => InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
            'requirement_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
            'updated_at' => '2026-10-20 09:00:00',
        ],
    ]);

    DB::table('environment_parameter')->insert([
        [
            'parameter_id' => 1,
            'parameter_code' => 'temperature',
            'parameter_name' => 'Temperature',
            'unit' => '°C',
            'data_type' => 'numeric',
            'is_system' => 1,
            'created_at' => '2026-10-20 09:00:00',
        ],
        [
            'parameter_id' => 2,
            'parameter_code' => 'relative_humidity',
            'parameter_name' => 'Relative Humidity',
            'unit' => '%RH',
            'data_type' => 'numeric',
            'is_system' => 1,
            'created_at' => '2026-10-20 09:00:00',
        ],
    ]);

    DB::table('instrument_type_environment_parameter')->insert([
        [
            'instrument_type_id' => 11,
            'parameter_id' => 1,
            'requirement_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
            'display_order' => 1,
        ],
        [
            'instrument_type_id' => 11,
            'parameter_id' => 2,
            'requirement_mode' => InstrumentTypeRequirement::MODE_OPTIONAL,
            'display_order' => 2,
        ],
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertHistoricalCalibration(array $attributes): void
{
    DB::table('calibration')->insert(array_merge([
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_provider_id' => 1,
        'calibration_date' => '2026-01-15',
        'calibration_due' => '2027-01-15',
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'completed_at' => null,
        'cancelled_at' => null,
        'cancelled_reason' => null,
        'created_at' => '2026-01-15 09:00:00',
        'updated_at' => '2026-01-15 09:00:00',
    ], $attributes));
}

function assertStartDidNotCreateResults(int $calibrationId): void
{
    expect(DB::table('calibration_environment_value')->where('calibration_id', $calibrationId)->count())->toBe(0)
        ->and(DB::table('calibration_environment')->where('calibration_id', $calibrationId)->count())->toBe(0)
        ->and(DB::table('calibration_standard_usage')->where('calibration_id', $calibrationId)->count())->toBe(0)
        ->and(DB::table('calibration_activity')->where('calibration_id', $calibrationId)->count())->toBe(0)
        ->and(DB::table('calibration_scope')->where('calibration_id', $calibrationId)->count())->toBe(0)
        ->and(DB::table('calibration_external_document')->where('calibration_id', $calibrationId)->count())->toBe(0)
        ->and(DB::table('certificate')->where('calibration_id', $calibrationId)->count())->toBe(0);
}

function assertOptionalIdentitySnapshotIsNull(Calibration $calibration): void
{
    expect($calibration->snapshot_instrument_name)->toBe('Gauge')
        ->and($calibration->snapshot_instrument_type_name)->toBe('Weight')
        ->and($calibration->snapshot_brand)->toBeNull()
        ->and($calibration->snapshot_model)->toBeNull()
        ->and($calibration->snapshot_serial_number)->toBeNull()
        ->and($calibration->snapshot_asset_number)->toBeNull()
        ->and($calibration->snapshot_max_capacity)->toBeNull()
        ->and($calibration->snapshot_max_capacity_unit)->toBeNull()
        ->and($calibration->snapshot_resolution)->toBeNull()
        ->and($calibration->snapshot_resolution_unit)->toBeNull()
        ->and($calibration->snapshot_location_id)->toBeNull()
        ->and($calibration->snapshot_detail_location_id)->toBeNull()
        ->and($calibration->snapshot_location_code)->toBeNull()
        ->and($calibration->snapshot_location_name)->toBeNull()
        ->and($calibration->snapshot_detail_location_code)->toBeNull()
        ->and($calibration->snapshot_detail_location_name)->toBeNull();
}

function assertPopulatedIdentitySnapshot(Calibration $calibration): void
{
    expect($calibration->snapshot_instrument_name)->toBe('Gauge')
        ->and($calibration->snapshot_instrument_type_name)->toBe('Weight')
        ->and($calibration->snapshot_brand)->toBe('Mettler')
        ->and($calibration->snapshot_model)->toBe('PB3002')
        ->and($calibration->snapshot_serial_number)->toBe('SN-1001')
        ->and($calibration->snapshot_asset_number)->toBe('AST-11')
        ->and((float) $calibration->snapshot_max_capacity)->toBe(1000.0)
        ->and($calibration->snapshot_max_capacity_unit)->toBe('g')
        ->and((float) $calibration->snapshot_resolution)->toBe(0.1)
        ->and($calibration->snapshot_resolution_unit)->toBe('g')
        ->and((int) $calibration->snapshot_location_id)->toBe(4)
        ->and((int) $calibration->snapshot_detail_location_id)->toBe(8)
        ->and($calibration->snapshot_location_code)->toBe('FMD')
        ->and($calibration->snapshot_location_name)->toBe('Filling Hall')
        ->and($calibration->snapshot_detail_location_code)->toBe('FM05-06')
        ->and($calibration->snapshot_detail_location_name)->toBe('Line 2');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Asia/Jakarta'));
});

it('creates an in-progress internal session with a generated calibration number', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    ['allocator' => $allocator, 'service' => $service] = bindStartCalibrationServices();

    $calibration = $service->start(11, Calibration::METHOD_INTERNAL);

    expect($calibration->session_status)->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($calibration->calibration_method)->toBe(Calibration::METHOD_INTERNAL)
        ->and($calibration->instrument_id)->toBe(1)
        ->and($calibration->instrument_type_id)->toBe(11)
        ->and($calibration->calibration_number)->toBe('CAL.VT0001/10/26')
        ->and($calibration->calibration_provider_id)->toBe(1)
        ->and($calibration->calibration_date)->toBeNull()
        ->and($calibration->calibration_due)->toBeNull()
        ->and($calibration->completed_at)->toBeNull()
        ->and($allocator->calls)->toBe(1);

    assertOptionalIdentitySnapshotIsNull($calibration);
    assertStartDidNotCreateResults((int) $calibration->calibration_id);
});

it('creates an in-progress external session without a provider or pdf', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    ['allocator' => $allocator, 'service' => $service] = bindStartCalibrationServices();

    $calibration = $service->start(11, Calibration::METHOD_EXTERNAL);

    expect($calibration->session_status)->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($calibration->calibration_method)->toBe(Calibration::METHOD_EXTERNAL)
        ->and($calibration->calibration_provider_id)->toBeNull()
        ->and($calibration->calibration_number)->toBe('CAL.VT0001/10/26')
        ->and($allocator->calls)->toBe(1)
        ->and(DB::table('calibration_external_document')->count())->toBe(0);

    assertOptionalIdentitySnapshotIsNull($calibration);
});

it('invokes the number allocator once per successful start', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    DB::table('instrument')->insert([
        'instrument_id' => 2,
        'instrument_name' => 'Scale',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('instrument_type')->insert([
        'instrument_type_id' => 21,
        'instrument_id' => 2,
        'type_name' => 'Balance',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    ['allocator' => $allocator, 'service' => $service] = bindStartCalibrationServices();

    $first = $service->start(11, Calibration::METHOD_INTERNAL);
    $second = $service->start(21, Calibration::METHOD_EXTERNAL);

    expect($allocator->calls)->toBe(2)
        ->and($first->calibration_number)->toBe('CAL.VT0001/10/26')
        ->and($second->calibration_number)->toBe('CAL.VT0002/10/26');
});

it('rejects a missing instrument type', function () {
    createStartCalibrationTables();
    seedInternalCalibrationTeam();
    ['service' => $service] = bindStartCalibrationServices();

    expect(fn () => $service->start(11, Calibration::METHOD_INTERNAL))
        ->toThrow(StartCalibrationException::class, 'The instrument type does not exist.')
        ->and(Calibration::query()->count())->toBe(0);
});

it('rejects a type whose parent instrument is missing', function () {
    createStartCalibrationTables();
    seedInternalCalibrationTeam();
    DB::table('instrument_type')->insert([
        'instrument_type_id' => 11,
        'instrument_id' => 88,
        'type_name' => 'Orphan',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    ['service' => $service] = bindStartCalibrationServices();

    expect(fn () => $service->start(11, Calibration::METHOD_INTERNAL))
        ->toThrow(StartCalibrationException::class, 'The parent instrument does not exist.')
        ->and(Calibration::query()->count())->toBe(0);
});

it('rejects a second in-progress session for the same instrument type', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    insertHistoricalCalibration([
        'calibration_number' => 'CAL.VT0009/01/26',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'calibration_date' => null,
        'calibration_due' => null,
        'completed_at' => null,
    ]);
    ['allocator' => $allocator, 'service' => $service] = bindStartCalibrationServices();

    expect(fn () => $service->start(11, Calibration::METHOD_EXTERNAL))
        ->toThrow(StartCalibrationException::class, 'An in-progress calibration session already exists for this instrument type.')
        ->and(Calibration::query()->count())->toBe(1)
        ->and($allocator->calls)->toBe(0);
});

it('starts after a completed historical session for the same type', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    insertHistoricalCalibration([
        'calibration_number' => 'CAL.VT0008/01/26',
        'session_status' => Calibration::STATUS_COMPLETED,
        'completed_at' => '2026-01-15 16:00:00',
    ]);
    ['service' => $service] = bindStartCalibrationServices();

    $calibration = $service->start(11, Calibration::METHOD_INTERNAL);

    expect($calibration->session_status)->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and(Calibration::query()->count())->toBe(2);
});

it('starts after a cancelled historical session for the same type', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    insertHistoricalCalibration([
        'calibration_number' => 'CAL.VT0007/01/26',
        'session_status' => Calibration::STATUS_CANCELLED,
        'calibration_date' => null,
        'calibration_due' => null,
        'cancelled_at' => '2026-01-16 10:00:00',
        'cancelled_reason' => 'Instrument withdrawn',
    ]);
    ['service' => $service] = bindStartCalibrationServices();

    $calibration = $service->start(11, Calibration::METHOD_EXTERNAL);

    expect($calibration->session_status)->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($calibration->calibration_number)->toBe('CAL.VT0001/10/26')
        ->and(Calibration::query()->count())->toBe(2);
});

it('snapshots all four requirement categories including omitted not_configured rows', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    ['service' => $service] = bindStartCalibrationServices();

    $calibration = $service->start(11, Calibration::METHOD_INTERNAL);
    $modes = CalibrationRequirementSnapshot::query()
        ->where('calibration_id', $calibration->calibration_id)
        ->pluck('frozen_mode', 'requirement_category');

    expect($modes->all())->toMatchArray([
        InstrumentTypeRequirement::CATEGORY_STANDARDS => InstrumentTypeRequirement::MODE_REQUIRED,
        InstrumentTypeRequirement::CATEGORY_ACTIVITY => InstrumentTypeRequirement::MODE_OPTIONAL,
        InstrumentTypeRequirement::CATEGORY_ENVIRONMENT => InstrumentTypeRequirement::MODE_REQUIRED,
        InstrumentTypeRequirement::CATEGORY_TEST_PROFILE => InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
    ])
        ->and(CalibrationRequirementSnapshot::query()->where('calibration_id', $calibration->calibration_id)->whereNotNull('configuration_confirmed_at')->count())->toBe(0);
});

it('keeps frozen requirement modes after the instrument type master changes', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    ['service' => $service] = bindStartCalibrationServices();

    $calibration = $service->start(11, Calibration::METHOD_INTERNAL);

    DB::table('instrument_type_requirement')
        ->where('instrument_type_id', 11)
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_STANDARDS)
        ->update(['requirement_mode' => InstrumentTypeRequirement::MODE_OPTIONAL]);
    DB::table('instrument_type_requirement')->insert([
        'instrument_type_id' => 11,
        'requirement_category' => InstrumentTypeRequirement::CATEGORY_TEST_PROFILE,
        'requirement_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
        'updated_at' => '2026-10-20 11:00:00',
    ]);

    $modes = CalibrationRequirementSnapshot::query()
        ->where('calibration_id', $calibration->calibration_id)
        ->pluck('frozen_mode', 'requirement_category');

    expect($modes->all())->toMatchArray([
        InstrumentTypeRequirement::CATEGORY_STANDARDS => InstrumentTypeRequirement::MODE_REQUIRED,
        InstrumentTypeRequirement::CATEGORY_ACTIVITY => InstrumentTypeRequirement::MODE_OPTIONAL,
        InstrumentTypeRequirement::CATEGORY_ENVIRONMENT => InstrumentTypeRequirement::MODE_REQUIRED,
        InstrumentTypeRequirement::CATEGORY_TEST_PROFILE => InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
    ]);
});

it('snapshots environment parameter configuration without measured values', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    ['service' => $service] = bindStartCalibrationServices();

    $calibration = $service->start(11, Calibration::METHOD_INTERNAL);
    $snapshots = CalibrationEnvironmentParameterSnapshot::query()
        ->where('calibration_id', $calibration->calibration_id)
        ->orderBy('display_order')
        ->get();

    expect($snapshots)->toHaveCount(2)
        ->and($snapshots[0]->parameter_code)->toBe('temperature')
        ->and($snapshots[0]->parameter_name)->toBe('Temperature')
        ->and($snapshots[0]->unit)->toBe('°C')
        ->and($snapshots[0]->data_type)->toBe('numeric')
        ->and($snapshots[0]->requirement_mode)->toBe(InstrumentTypeRequirement::MODE_REQUIRED)
        ->and($snapshots[0]->inclusion_confirmed)->toBeTrue()
        ->and($snapshots[0]->inclusion_reason)->toBeNull()
        ->and($snapshots[1]->parameter_code)->toBe('relative_humidity')
        ->and($snapshots[1]->requirement_mode)->toBe(InstrumentTypeRequirement::MODE_OPTIONAL)
        ->and(DB::table('calibration_environment_value')->count())->toBe(0)
        ->and(DB::table('calibration_environment')->count())->toBe(0);
});

it('does not calculate due dates from the instrument type interval', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    ['service' => $service] = bindStartCalibrationServices();

    $calibration = $service->start(11, Calibration::METHOD_INTERNAL);

    expect($calibration->calibration_date)->toBeNull()
        ->and($calibration->calibration_due)->toBeNull()
        ->and(DB::table('instrument_type')->where('instrument_type_id', 11)->value('calibration_interval_months'))->toBe(12);
});

it('keeps the historical completed model default when start is not used', function () {
    expect((new Calibration)->session_status)->toBe(Calibration::STATUS_COMPLETED)
        ->and((new Calibration)->calibration_number)->toBeNull();
});

it('rolls back the parent session and snapshots when a later write fails', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    DB::statement("
        CREATE TRIGGER abort_requirement_snapshot_test_profile
        BEFORE INSERT ON calibration_requirement_snapshot
        FOR EACH ROW
        WHEN NEW.requirement_category = 'test_profile'
        BEGIN
            SELECT RAISE(ABORT, 'forced snapshot failure');
        END
    ");
    ['allocator' => $allocator, 'service' => $service] = bindStartCalibrationServices();

    expect(fn () => $service->start(11, Calibration::METHOD_INTERNAL))
        ->toThrow(QueryException::class)
        ->and(Calibration::query()->count())->toBe(0)
        ->and(CalibrationRequirementSnapshot::query()->count())->toBe(0)
        ->and(CalibrationEnvironmentParameterSnapshot::query()->count())->toBe(0)
        ->and($allocator->calls)->toBe(1);
});

it('rejects an internal start when the internal team provider is missing', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedStartCalibrationConfiguration();
    ['allocator' => $allocator, 'service' => $service] = bindStartCalibrationServices();

    expect(fn () => $service->start(11, Calibration::METHOD_INTERNAL))
        ->toThrow(StartCalibrationException::class, 'Internal Calibration Team provider is not available.')
        ->and(Calibration::query()->count())->toBe(0)
        ->and($allocator->calls)->toBe(0);
});

it('rejects an unknown calibration method', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedInternalCalibrationTeam();
    ['allocator' => $allocator, 'service' => $service] = bindStartCalibrationServices();

    expect(fn () => $service->start(11, 'FIELD'))
        ->toThrow(StartCalibrationException::class, 'Calibration method must be INTERNAL or EXTERNAL.')
        ->and($allocator->calls)->toBe(0);
});

it('uses a caller-supplied database connection', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    ['service' => $service] = bindStartCalibrationServices();
    $connection = DB::connection();

    $calibration = $service->start(11, Calibration::METHOD_INTERNAL, $connection);

    expect($calibration->getConnectionName())->toBe($connection->getName())
        ->and($calibration->session_status)->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('snapshots instrument identity from current instrument and type masters', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedStartCalibrationIdentity();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    ['service' => $service] = bindStartCalibrationServices();

    $calibration = $service->start(11, Calibration::METHOD_INTERNAL);

    expect($calibration->instrument_id)->toBe(1)
        ->and($calibration->instrument_type_id)->toBe(11);

    assertPopulatedIdentitySnapshot($calibration);
    assertStartDidNotCreateResults((int) $calibration->calibration_id);
});

it('snapshots instrument identity for an external session', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedStartCalibrationIdentity();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    ['service' => $service] = bindStartCalibrationServices();

    $calibration = $service->start(11, Calibration::METHOD_EXTERNAL);

    expect($calibration->calibration_method)->toBe(Calibration::METHOD_EXTERNAL)
        ->and($calibration->calibration_provider_id)->toBeNull();

    assertPopulatedIdentitySnapshot($calibration);
});

it('keeps frozen identity after the instrument and type masters change', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedStartCalibrationIdentity();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    ['service' => $service] = bindStartCalibrationServices();

    $calibration = $service->start(11, Calibration::METHOD_INTERNAL);

    DB::table('location')->where('location_id', 4)->update([
        'location_code' => 'WH',
        'location_name' => 'Warehouse',
    ]);
    DB::table('detail_location')->where('detail_location_id', 8)->update([
        'detail_location_code' => 'WH-9',
        'detail_location_name' => 'Rack 9',
    ]);
    DB::table('instrument')->where('instrument_id', 1)->update([
        'instrument_name' => 'Renamed Gauge',
        'serial_number' => 'SN-CHANGED',
        'detail_location_id' => null,
    ]);
    DB::table('instrument_type')->where('instrument_type_id', 11)->update([
        'type_name' => 'Force',
        'brand' => 'Other',
        'model' => 'XX',
        'asset_number' => 'AST-99',
        'max_capacity' => 5,
        'max_capacity_unit' => 'kg',
        'resolution' => 1,
        'resolution_unit' => 'kg',
    ]);

    $frozen = Calibration::query()->findOrFail($calibration->calibration_id);

    assertPopulatedIdentitySnapshot($frozen);
});

it('rejects a start when the assigned location is missing', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    DB::table('instrument')->where('instrument_id', 1)->update([
        'detail_location_id' => 88,
    ]);
    ['allocator' => $allocator, 'service' => $service] = bindStartCalibrationServices();

    expect(fn () => $service->start(11, Calibration::METHOD_INTERNAL))
        ->toThrow(StartCalibrationException::class, 'The instrument location does not exist.')
        ->and(Calibration::query()->count())->toBe(0)
        ->and($allocator->calls)->toBe(0);
});

it('rolls back when the identity snapshot cannot be stored', function () {
    createStartCalibrationTables();
    seedStartCalibrationInstrumentType();
    seedInternalCalibrationTeam();
    seedStartCalibrationConfiguration();
    DB::statement("
        CREATE TRIGGER abort_identity_snapshot
        BEFORE INSERT ON calibration
        FOR EACH ROW
        WHEN NEW.snapshot_instrument_name IS NOT NULL
        BEGIN
            SELECT RAISE(ABORT, 'forced identity snapshot failure');
        END
    ");
    ['allocator' => $allocator, 'service' => $service] = bindStartCalibrationServices();

    expect(fn () => $service->start(11, Calibration::METHOD_INTERNAL))
        ->toThrow(QueryException::class)
        ->and(Calibration::query()->count())->toBe(0)
        ->and(CalibrationRequirementSnapshot::query()->count())->toBe(0)
        ->and(CalibrationEnvironmentParameterSnapshot::query()->count())->toBe(0)
        ->and($allocator->calls)->toBe(1);
});

it('refuses the production postgresql database name', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getName')->andReturn('guarded_pgsql');
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('getDatabaseName')->andReturn('calibration_db');
    $connection->shouldNotReceive('scalar');

    $service = new StartCalibrationSession(
        app(CalibrationSessionConnection::class),
        new FakeCalibrationNumberAllocator,
    );

    expect(fn () => $service->start(11, Calibration::METHOD_INTERNAL, $connection))
        ->toThrow(StartCalibrationException::class, 'Refusing to start a calibration session on the production database.');
});
