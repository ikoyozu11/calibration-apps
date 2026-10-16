<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createInstrumentDetailTestTables(bool $withVersioning = true): void
{
    Schema::create('location', function (Blueprint $table): void {
        $table->unsignedBigInteger('location_id')->primary();
        $table->string('location_name', 150);
    });

    Schema::create('detail_location', function (Blueprint $table): void {
        $table->unsignedBigInteger('detail_location_id')->primary();
        $table->unsignedBigInteger('location_id');
        $table->string('detail_location_name', 150);
    });

    Schema::create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name', 150);
        $table->string('serial_number')->nullable();
        $table->unsignedBigInteger('detail_location_id')->nullable();
        $table->text('description')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('instrument_type', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name', 150);
        $table->text('description')->nullable();
        $table->timestamp('created_at');
        $table->string('asset_number')->nullable();
        $table->string('serial_number')->nullable();
        $table->decimal('max_capacity')->nullable();
        $table->string('max_capacity_unit')->nullable();
        $table->decimal('resolution')->nullable();
        $table->string('resolution_unit')->nullable();
        $table->string('brand')->nullable();
        $table->string('model')->nullable();
    });

    Schema::create('test_profile', function (Blueprint $table): void {
        $table->unsignedBigInteger('test_profile_id')->primary();
        $table->unsignedBigInteger('instrument_type_id');
        $table->string('profile_name', 150);
        $table->string('display_title')->nullable();
        $table->unique(['instrument_type_id', 'profile_name']);
        $table->text('description')->nullable();
        $table->timestamp('created_at');
    });

    if ($withVersioning) {
        Schema::create('test_profile_version', function (Blueprint $table): void {
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
    }

    Schema::create('test_point', function (Blueprint $table) use ($withVersioning): void {
        $table->unsignedBigInteger('test_point_id')->primary();
        $table->unsignedBigInteger('test_profile_id');
        if ($withVersioning) {
            $table->unsignedBigInteger('test_profile_version_id')->nullable();
        }
        $table->integer('point_order');
        $table->decimal('nominal_value', 18, 6)->nullable();
        $table->string('unit', 50)->nullable();
        $table->decimal('tolerance_plus', 18, 6)->nullable();
        $table->decimal('tolerance_minus', 18, 6)->nullable();
        $table->text('description')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('test_column', function (Blueprint $table) use ($withVersioning): void {
        $table->unsignedBigInteger('test_column_id')->primary();
        $table->unsignedBigInteger('test_profile_id');
        if ($withVersioning) {
            $table->unsignedBigInteger('test_profile_version_id')->nullable();
        }
        $table->string('column_key', 63);
        $table->string('column_label', 150);
        $table->integer('column_order');
        $table->string('data_type', 20);
        $table->string('value_source', 20);
        $table->string('unit', 50)->nullable();
        $table->unsignedSmallInteger('display_scale')->nullable();
        $table->string('formula_code', 20)->nullable();
        $table->text('formula_inputs')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('test_point_value', function (Blueprint $table) use ($withVersioning): void {
        $table->unsignedBigInteger('test_point_value_id')->primary();
        $table->unsignedBigInteger('test_profile_id');
        if ($withVersioning) {
            $table->unsignedBigInteger('test_profile_version_id')->nullable();
        }
        $table->unsignedBigInteger('test_point_id');
        $table->unsignedBigInteger('test_column_id');
        $table->decimal('numeric_value', 18, 6)->nullable();
        $table->text('text_value')->nullable();
        $table->string('unit', 50)->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('calibration', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->unsignedBigInteger('calibration_provider_id');
        $table->string('calibration_number', 100);
        $table->date('calibration_date')->nullable();
        $table->date('calibration_due')->nullable();
        $table->string('calibration_method', 20);
        $table->string('calibration_result', 20)->nullable();
        $table->string('certificate_number', 100)->nullable();
        $table->text('remarks')->nullable();
        $table->timestamps();
        $table->date('action_date')->nullable();
        $table->text('resume')->nullable();
        $table->unsignedBigInteger('customer_id')->nullable();
    });
}

function insertInstrumentDetailSubject(): void
{
    DB::table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => null,
        'description' => null,
        'created_at' => '2026-10-07 09:17:33',
    ]);

    DB::table('instrument_type')->insert([
        [
            'instrument_type_id' => 31,
            'instrument_id' => 3,
            'type_name' => 'Weight',
            'description' => null,
            'created_at' => '2026-10-07 09:17:33',
        ],
        [
            'instrument_type_id' => 32,
            'instrument_id' => 3,
            'type_name' => 'Diameter',
            'description' => null,
            'created_at' => '2026-10-07 09:17:33',
        ],
    ]);
}

/**
 * @param  array{
 *     test_profile_id: int,
 *     profile_name: string,
 *     test_profile_version_id?: int,
 *     version_number?: int,
 *     status?: string,
 *     display_title?: ?string,
 *     description?: ?string,
 *     published_at?: ?string,
 *     created_at?: string
 * }  $attributes
 */
function insertTestProfileVersion(array $attributes): int
{
    $id = $attributes['test_profile_version_id'] ?? ((int) DB::table('test_profile_version')->max('test_profile_version_id')) + 1;
    $status = $attributes['status'] ?? 'draft';

    DB::table('test_profile_version')->insert([
        'test_profile_version_id' => $id,
        'test_profile_id' => $attributes['test_profile_id'],
        'version_number' => $attributes['version_number'] ?? 1,
        'status' => $status,
        'profile_name' => $attributes['profile_name'],
        'display_title' => $attributes['display_title'] ?? null,
        'description' => $attributes['description'] ?? null,
        'published_at' => $status === 'draft' ? null : ($attributes['published_at'] ?? '2026-10-14 09:00:00'),
        'created_at' => $attributes['created_at'] ?? '2026-10-14 09:00:00',
    ]);

    return $id;
}

function insertColumnManagerFixture(): void
{
    insertInstrumentDetailSubject();

    DB::table('test_profile')->insert([
        'test_profile_id' => 1,
        'instrument_type_id' => 31,
        'profile_name' => 'Main Test',
        'display_title' => null,
        'description' => null,
        'created_at' => '2026-10-14 09:00:00',
    ]);
    insertTestProfileVersion([
        'test_profile_version_id' => 1,
        'test_profile_id' => 1,
        'profile_name' => 'Main Test',
    ]);
    DB::table('test_point')->insert([
        'test_point_id' => 10,
        'test_profile_id' => 1,
        'test_profile_version_id' => 1,
        'point_order' => 1,
        'nominal_value' => 100,
        'unit' => 'g',
        'tolerance_plus' => 0.1,
        'tolerance_minus' => -0.1,
        'description' => 'Legacy point',
        'created_at' => '2026-10-14 09:00:00',
    ]);
}

function insertDynamicRowFixture(): void
{
    insertInstrumentDetailSubject();

    DB::table('test_profile')->insert([
        'test_profile_id' => 1,
        'instrument_type_id' => 31,
        'profile_name' => 'Main Test',
        'display_title' => null,
        'description' => null,
        'created_at' => '2026-10-14 09:00:00',
    ]);
    insertTestProfileVersion([
        'test_profile_version_id' => 1,
        'test_profile_id' => 1,
        'profile_name' => 'Main Test',
    ]);
    DB::table('test_column')->insert([
        [
            'test_column_id' => 1,
            'test_profile_id' => 1,
            'test_profile_version_id' => 1,
            'column_key' => 'standard_value',
            'column_label' => 'Standard Value',
            'column_order' => 1,
            'data_type' => 'numeric',
            'value_source' => 'template',
            'unit' => 'g',
            'display_scale' => null,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_column_id' => 2,
            'test_profile_id' => 1,
            'test_profile_version_id' => 1,
            'column_key' => 'note',
            'column_label' => 'Note',
            'column_order' => 2,
            'data_type' => 'text',
            'value_source' => 'template',
            'unit' => null,
            'display_scale' => null,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_column_id' => 3,
            'test_profile_id' => 1,
            'test_profile_version_id' => 1,
            'column_key' => 'reading',
            'column_label' => 'Reading',
            'column_order' => 3,
            'data_type' => 'numeric',
            'value_source' => 'engineer',
            'unit' => 'g',
            'display_scale' => 3,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'created_at' => '2026-10-14 09:00:00',
        ],
    ]);
}

function insertActiveVersionTestTable(): void
{
    insertInstrumentDetailSubject();

    DB::table('test_profile')->insert([
        'test_profile_id' => 1,
        'instrument_type_id' => 31,
        'profile_name' => 'Main Test',
        'display_title' => 'Voltage table',
        'description' => 'Source notes',
        'created_at' => '2026-10-14 09:00:00',
    ]);
    insertTestProfileVersion([
        'test_profile_version_id' => 11,
        'test_profile_id' => 1,
        'version_number' => 1,
        'status' => 'active',
        'profile_name' => 'Main Test',
        'display_title' => 'Voltage table',
        'description' => 'Source notes',
        'published_at' => '2026-10-14 09:00:00',
    ]);
    DB::table('test_column')->insert([
        [
            'test_column_id' => 21,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'column_key' => 'standard_value',
            'column_label' => 'Standard Value',
            'column_order' => 1,
            'data_type' => 'numeric',
            'value_source' => 'template',
            'unit' => 'g',
            'display_scale' => 2,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_column_id' => 22,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'column_key' => 'reading',
            'column_label' => 'Reading',
            'column_order' => 2,
            'data_type' => 'numeric',
            'value_source' => 'engineer',
            'unit' => 'g',
            'display_scale' => 3,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_column_id' => 23,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'column_key' => 'correction',
            'column_label' => 'Correction',
            'column_order' => 3,
            'data_type' => 'numeric',
            'value_source' => 'calculated',
            'unit' => 'g',
            'display_scale' => 3,
            'formula_code' => 'standard_minus_reading',
            'formula_inputs' => '["standard_value","reading"]',
            'created_at' => '2026-10-14 09:00:00',
        ],
    ]);
    DB::table('test_point')->insert([
        'test_point_id' => 31,
        'test_profile_id' => 1,
        'test_profile_version_id' => 11,
        'point_order' => 1,
        'nominal_value' => 100,
        'unit' => 'g',
        'tolerance_plus' => 0.1,
        'tolerance_minus' => -0.1,
        'description' => 'First point',
        'created_at' => '2026-10-14 09:00:00',
    ]);
    DB::table('test_point_value')->insert([
        [
            'test_point_value_id' => 41,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'test_point_id' => 31,
            'test_column_id' => 21,
            'numeric_value' => null,
            'text_value' => null,
            'unit' => 'g',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_point_value_id' => 42,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'test_point_id' => 31,
            'test_column_id' => 22,
            'numeric_value' => '99.5',
            'text_value' => null,
            'unit' => 'g',
            'created_at' => '2026-10-14 09:00:00',
        ],
    ]);
}

function insertTwoVersionTestTable(): void
{
    insertInstrumentDetailSubject();

    DB::table('test_profile')->insert([
        'test_profile_id' => 1,
        'instrument_type_id' => 31,
        'profile_name' => 'Main Test',
        'display_title' => 'Voltage table',
        'description' => null,
        'created_at' => '2026-10-14 09:00:00',
    ]);

    insertTestProfileVersion([
        'test_profile_version_id' => 11,
        'test_profile_id' => 1,
        'version_number' => 1,
        'status' => 'active',
        'profile_name' => 'Main Test',
        'display_title' => 'Voltage table',
        'published_at' => '2026-10-14 09:00:00',
    ]);
    insertTestProfileVersion([
        'test_profile_version_id' => 12,
        'test_profile_id' => 1,
        'version_number' => 2,
        'status' => 'draft',
        'profile_name' => 'Main Test',
        'display_title' => 'Voltage table',
    ]);

    DB::table('test_column')->insert([
        [
            'test_column_id' => 21,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'column_key' => 'active_voltage',
            'column_label' => 'Active Voltage',
            'column_order' => 1,
            'data_type' => 'text',
            'value_source' => 'template',
            'unit' => null,
            'display_scale' => null,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_column_id' => 22,
            'test_profile_id' => 1,
            'test_profile_version_id' => 12,
            'column_key' => 'draft_current',
            'column_label' => 'Draft Current',
            'column_order' => 1,
            'data_type' => 'text',
            'value_source' => 'template',
            'unit' => null,
            'display_scale' => null,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'created_at' => '2026-10-14 09:00:00',
        ],
    ]);
    DB::table('test_point')->insert([
        [
            'test_point_id' => 31,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'point_order' => 1,
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_point_id' => 32,
            'test_profile_id' => 1,
            'test_profile_version_id' => 12,
            'point_order' => 1,
            'created_at' => '2026-10-14 09:00:00',
        ],
    ]);
    DB::table('test_point_value')->insert([
        [
            'test_point_value_id' => 41,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'test_point_id' => 31,
            'test_column_id' => 21,
            'numeric_value' => null,
            'text_value' => 'active-cell-100',
            'unit' => null,
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_point_value_id' => 42,
            'test_profile_id' => 1,
            'test_profile_version_id' => 12,
            'test_point_id' => 32,
            'test_column_id' => 22,
            'numeric_value' => null,
            'text_value' => 'draft-cell-200',
            'unit' => null,
            'created_at' => '2026-10-14 09:00:00',
        ],
    ]);
}
