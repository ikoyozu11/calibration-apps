<?php

use App\StagingPreflight;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('isolates automated tests on sqlite memory', function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:')
        ->and((string) config('database.connections.sqlite.url'))->toBe('')
        ->and(DB::getDriverName())->toBe('sqlite')
        ->and(config('database.staging_preflight_http'))->toBeFalse();
});

it('does not expose the staging preflight route by default', function () {
    $this->get('/__staging-preflight')->assertNotFound();
});

it('reports missing staging schema columns', function () {
    $report = app(StagingPreflight::class)->inspect();

    expect($report['database'])->toBe(':memory:')
        ->and($report['database_ok'])->toBeFalse()
        ->and($report['schema_ok'])->toBeFalse()
        ->and($report['ok'])->toBeFalse()
        ->and($report['schema']['calibration.session_status'])->toBeFalse()
        ->and($report['schema']['test_profile_version'])->toBeFalse()
        ->and($report['schema']['calibration_activity_master'])->toBeFalse();
});

it('detects the session schema when the tables exist', function () {
    Schema::create('instrument_type', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id')->primary();
        $table->integer('calibration_interval_months')->nullable();
    });
    Schema::create('calibration', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->string('session_status', 20)->default('completed');
        $table->unsignedBigInteger('instrument_type_id')->nullable();
    });
    Schema::create('calibration_scope', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_scope_id')->primary();
        $table->json('test_table_snapshot')->nullable();
    });
    Schema::create('test_profile_version', function (Blueprint $table): void {
        $table->unsignedBigInteger('test_profile_version_id')->primary();
    });
    Schema::create('calibration_result_value', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_result_value_id')->primary();
    });
    Schema::create('instrument_type_standard_assignment', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_standard_assignment_id')->primary();
        $table->string('standard_display_name', 255)->nullable();
        $table->unsignedBigInteger('standard_id')->nullable();
    });
    Schema::create('standard_master', function (Blueprint $table): void {
        $table->unsignedBigInteger('standard_id')->primary();
    });
    Schema::create('calibration_activity_master', function (Blueprint $table): void {
        $table->unsignedBigInteger('activity_id')->primary();
    });

    $report = app(StagingPreflight::class)->inspect();

    expect($report['schema_ok'])->toBeTrue()
        ->and($report['database_ok'])->toBeFalse()
        ->and($report['ok'])->toBeFalse()
        ->and($report['schema']['calibration.session_status'])->toBeTrue()
        ->and($report['schema']['calibration.instrument_type_id'])->toBeTrue()
        ->and($report['schema']['instrument_type.calibration_interval_months'])->toBeTrue()
        ->and($report['schema']['test_profile_version'])->toBeTrue()
        ->and($report['schema']['calibration_result_value'])->toBeTrue()
        ->and($report['schema']['calibration_scope.test_table_snapshot'])->toBeTrue()
        ->and($report['schema']['instrument_type_standard_assignment'])->toBeTrue()
        ->and($report['schema']['instrument_type_standard_assignment.standard_display_name'])->toBeTrue()
        ->and($report['schema']['standard_master'])->toBeTrue()
        ->and($report['schema']['instrument_type_standard_assignment.standard_id'])->toBeTrue()
        ->and($report['schema']['calibration_activity_master'])->toBeTrue();
});
