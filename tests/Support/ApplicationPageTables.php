<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
