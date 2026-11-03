<?php

use App\CalibrationSessions\CalibrationNumberAllocator;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\FakeCalibrationNumberAllocator;

function createStartCalibrationHttpTables(): void
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

    Schema::create('app_user', function (Blueprint $table): void {
        $table->unsignedBigInteger('app_user_id')->primary();
        $table->boolean('is_active')->default(true);
    });

    Schema::create('user_app_user_map', function (Blueprint $table): void {
        $table->unsignedBigInteger('user_app_user_map_id')->primary();
        $table->unsignedBigInteger('laravel_user_id');
        $table->unsignedBigInteger('app_user_id');
        $table->string('status', 20);
        $table->timestamp('created_at');
        $table->timestamp('inactivated_at')->nullable();
        $table->unique('laravel_user_id');
        $table->unique('app_user_id');
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
            started_by_app_user_id INTEGER NULL,
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
            CHECK (session_status IN ('in_progress', 'completed', 'cancelled'))
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

    Schema::create('calibration_standard_usage', function (Blueprint $table): void {
        $table->id('calibration_standard_usage_id');
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('standard_id')->nullable();
        $table->integer('usage_order');
    });

    Schema::create('calibration_scope', function (Blueprint $table): void {
        $table->id('calibration_scope_id');
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('test_profile_version_id')->nullable();
        $table->json('test_table_snapshot')->nullable();
    });
}

function seedStartCalibrationHttpSubject(): void
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
    DB::table('instrument')->insert([
        'instrument_id' => 1,
        'instrument_name' => 'Gauge',
        'serial_number' => 'SN-1001',
        'detail_location_id' => 8,
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('instrument_type')->insert([
        'instrument_type_id' => 11,
        'instrument_id' => 1,
        'type_name' => 'Weight',
        'brand' => 'Mettler',
        'model' => 'PB3002',
        'asset_number' => 'AST-11',
        'max_capacity' => 1000,
        'max_capacity_unit' => 'g',
        'resolution' => 0.1,
        'resolution_unit' => 'g',
        'calibration_interval_months' => 12,
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('calibration_provider')->insert([
        'provider_code' => 'INTERNAL_TEAM',
        'provider_name' => 'Internal Calibration Team',
        'provider_type' => 'INTERNAL',
        'created_at' => '2026-10-20 09:00:00',
    ]);
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
            'requirement_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
            'display_order' => 2,
        ],
    ]);
}

function mapStartCalibrationHttpActor(
    ?User $user = null,
    int $appUserId = 90,
    bool $mappingActive = true,
    bool $appUserActive = true,
): int {
    $user ??= auth()->user();
    assert($user instanceof User);

    if (! DB::table('app_user')->where('app_user_id', $appUserId)->exists()) {
        DB::table('app_user')->insert([
            'app_user_id' => $appUserId,
            'is_active' => $appUserActive ? 1 : 0,
        ]);
    } else {
        DB::table('app_user')->where('app_user_id', $appUserId)->update([
            'is_active' => $appUserActive ? 1 : 0,
        ]);
    }

    DB::table('user_app_user_map')->where('laravel_user_id', $user->id)->delete();
    DB::table('user_app_user_map')->insert([
        'user_app_user_map_id' => (int) $user->id,
        'laravel_user_id' => $user->id,
        'app_user_id' => $appUserId,
        'status' => $mappingActive ? 'active' : 'inactive',
        'created_at' => '2026-10-20 09:00:00',
        'inactivated_at' => $mappingActive ? null : '2026-10-20 09:00:00',
    ]);

    return $appUserId;
}

function bindStartCalibrationHttpAllocator(): FakeCalibrationNumberAllocator
{
    $allocator = new FakeCalibrationNumberAllocator;
    app()->instance(CalibrationNumberAllocator::class, $allocator);

    return $allocator;
}

function startCalibrationHttpPage(): Testable
{
    return Livewire::test('pages::instruments.start-calibration', [
        'instrument' => 1,
        'instrumentType' => 11,
    ]);
}

function insertStartCalibrationHttpSession(array $attributes): void
{
    DB::table('calibration')->insert(array_merge([
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_number' => $attributes['calibration_number'] ?? 'CAL.OPEN',
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'calibration_date' => null,
        'calibration_due' => null,
        'completed_at' => null,
        'cancelled_at' => null,
        'cancelled_reason' => null,
        'created_at' => '2026-10-20 09:00:00',
        'updated_at' => '2026-10-20 09:00:00',
    ], $attributes));
}
