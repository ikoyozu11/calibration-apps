<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createCalibrationIndexTables(): void
{
    Schema::create('location', function (Blueprint $table): void {
        $table->unsignedBigInteger('location_id')->primary();
        $table->string('location_code');
        $table->string('location_name');
        $table->timestamp('created_at')->nullable();
    });

    Schema::create('detail_location', function (Blueprint $table): void {
        $table->unsignedBigInteger('detail_location_id')->primary();
        $table->unsignedBigInteger('location_id');
        $table->string('detail_location_code');
        $table->string('detail_location_name');
        $table->timestamp('created_at')->nullable();
    });

    Schema::create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name');
        $table->string('serial_number')->nullable();
        $table->unsignedBigInteger('detail_location_id')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('instrument_type', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name');
        $table->string('serial_number')->nullable();
        $table->string('asset_number')->nullable();
        $table->string('brand')->nullable();
        $table->string('model')->nullable();
        addInstrumentTypeIntervalColumn($table);
    });

    Schema::create('calibration', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->date('calibration_date')->nullable();
        $table->date('calibration_due')->nullable();
        $table->string('calibration_result', 20)->nullable();
        $table->string('certificate_number', 100)->nullable();
        $table->string('snapshot_instrument_name')->nullable();
        $table->string('snapshot_instrument_type_name')->nullable();
        $table->string('snapshot_brand')->nullable();
        $table->string('snapshot_model')->nullable();
        $table->string('snapshot_serial_number')->nullable();
        $table->string('snapshot_asset_number')->nullable();
        $table->timestamp('created_at')->nullable();
        addCalibrationSessionColumns($table);
        $table->timestamp('completed_at')->nullable();
        $table->timestamp('cancelled_at')->nullable();
        $table->text('cancelled_reason')->nullable();
    });

    Schema::create('calibration_scope', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_scope_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('instrument_type_id');
        $table->integer('scope_order');
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertCalibrationIndexInstrument(array $attributes): void
{
    DB::table('instrument')->insert([
        'instrument_id' => $attributes['instrument_id'],
        'instrument_name' => $attributes['instrument_name'],
        'serial_number' => $attributes['serial_number'] ?? null,
        'created_at' => '2026-01-01 00:00:00',
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertCalibrationIndexType(array $attributes): void
{
    DB::table('instrument_type')->insert([
        'instrument_type_id' => $attributes['instrument_type_id'],
        'instrument_id' => $attributes['instrument_id'],
        'type_name' => $attributes['type_name'],
        'asset_number' => $attributes['asset_number'] ?? null,
        'brand' => $attributes['brand'] ?? null,
        'model' => $attributes['model'] ?? null,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertCalibrationIndexScope(array $attributes): void
{
    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => $attributes['calibration_scope_id'],
        'calibration_id' => $attributes['calibration_id'],
        'instrument_type_id' => $attributes['instrument_type_id'],
        'scope_order' => $attributes['scope_order'] ?? 1,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertCalibrationIndexRecord(array $attributes): void
{
    DB::table('calibration')->insert([
        'calibration_id' => $attributes['calibration_id'],
        'instrument_id' => $attributes['instrument_id'],
        'instrument_type_id' => $attributes['instrument_type_id'] ?? null,
        'session_status' => $attributes['session_status'] ?? 'completed',
        'calibration_date' => $attributes['calibration_date'] ?? null,
        'calibration_due' => $attributes['calibration_due'] ?? null,
        'calibration_result' => $attributes['calibration_result'] ?? null,
        'certificate_number' => $attributes['certificate_number'] ?? null,
        'created_at' => $attributes['created_at'] ?? (($attributes['calibration_date'] ?? null) === null
            ? '2026-01-01 00:00:00'
            : $attributes['calibration_date'].' 00:00:00'),
    ]);
}

function createAuthenticationPageTables(): void
{
    if (Schema::hasTable('instrument')) {
        return;
    }

    Schema::create('location', function (Blueprint $table): void {
        $table->unsignedBigInteger('location_id')->primary();
        $table->string('location_name');
    });

    Schema::create('detail_location', function (Blueprint $table): void {
        $table->unsignedBigInteger('detail_location_id')->primary();
        $table->unsignedBigInteger('location_id');
        $table->string('detail_location_name');
    });

    Schema::create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name');
        $table->string('serial_number')->nullable();
        $table->unsignedBigInteger('detail_location_id')->nullable();
        $table->text('description')->nullable();
        $table->timestamp('created_at')->nullable();
    });

    Schema::create('instrument_type', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name')->nullable();
        $table->string('serial_number')->nullable();
        $table->string('asset_number')->nullable();
        $table->string('brand')->nullable();
        $table->string('model')->nullable();
        $table->timestamp('created_at')->nullable();
        addInstrumentTypeIntervalColumn($table);
    });

    Schema::create('calibration', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->date('calibration_date')->nullable();
        $table->date('calibration_due')->nullable();
        $table->string('calibration_result', 20)->nullable();
        $table->string('certificate_number', 100)->nullable();
        addCalibrationSessionColumns($table);
    });

    Schema::create('standard_master', function (Blueprint $table): void {
        $table->unsignedBigInteger('standard_id')->primary();
        $table->string('instrument_name');
        $table->string('type_specification')->nullable();
        $table->string('serial_number')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    Schema::create('calibration_activity_master', function (Blueprint $table): void {
        $table->unsignedBigInteger('activity_id')->primary();
        $table->string('activity_code')->nullable();
        $table->string('activity_name');
        $table->string('status', 20)->default('active');
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
}
