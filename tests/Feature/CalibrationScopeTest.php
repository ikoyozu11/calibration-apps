<?php

use App\Models\CalibrationScope;
use App\Models\TestProfileVersion;
use App\TestColumns\TestTableSnapshotBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createCalibrationScopeMappingTables(): void
{
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();

    Schema::create('calibration_scope', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_scope_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('instrument_type_id');
        $table->unsignedBigInteger('test_profile_id')->nullable();
        $table->unsignedBigInteger('test_profile_version_id')->nullable();
        $table->json('test_table_snapshot')->nullable();
        $table->integer('scope_order');
        $table->text('remarks')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('calibration_test_result', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_test_result_id')->primary();
        $table->unsignedBigInteger('calibration_scope_id');
        $table->unsignedBigInteger('test_point_id');
        $table->string('result_status', 20)->nullable();
        $table->text('remarks')->nullable();
        $table->timestamp('created_at');
        $table->decimal('standard_value', 18, 6)->nullable();
        $table->decimal('average_value', 18, 6)->nullable();
        $table->decimal('correction_value', 18, 6)->nullable();
    });

    DB::table('calibration')->insert([
        'calibration_id' => 8,
        'instrument_id' => 3,
        'calibration_provider_id' => 1,
        'calibration_number' => 'CAL-SCOPE-0001',
        'calibration_date' => '2026-06-12',
        'calibration_due' => '2027-06-12',
        'calibration_method' => 'INTERNAL',
        'calibration_result' => 'PASS',
        'certificate_number' => null,
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
        'updated_at' => '2026-06-12 09:00:00',
        'action_date' => null,
        'resume' => null,
        'customer_id' => null,
    ]);
}

it('maps a pinned version and json snapshot without changing legacy relations', function () {
    createCalibrationScopeMappingTables();
    $this->travelTo('2026-10-15 04:00:00');

    $snapshot = app(TestTableSnapshotBuilder::class)->build(
        TestProfileVersion::query()->findOrFail(11),
    );

    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => 21,
        'calibration_id' => 8,
        'instrument_type_id' => 31,
        'test_profile_id' => 1,
        'test_profile_version_id' => 11,
        'test_table_snapshot' => json_encode($snapshot),
        'scope_order' => 1,
        'remarks' => 'Pinned active version',
        'created_at' => '2026-10-15 04:00:00',
    ]);

    $scope = CalibrationScope::query()
        ->with(['instrumentType', 'testProfile', 'testProfileVersion', 'calibrationTestResults'])
        ->findOrFail(21);

    expect($scope->test_profile_id)->toBe(1)
        ->and($scope->test_profile_version_id)->toBe(11)
        ->and($scope->test_table_snapshot)->toBeArray()
        ->and($scope->test_table_snapshot['schema_version'])->toBe(2)
        ->and($scope->test_table_snapshot['test_profile_version_id'])->toBe(11)
        ->and($scope->instrumentType?->type_name)->toBe('Weight')
        ->and($scope->testProfile?->profile_name)->toBe('Main Test')
        ->and($scope->testProfileVersion?->status)->toBe('active')
        ->and($scope->testProfileVersion?->version_number)->toBe(1)
        ->and($scope->calibrationTestResults)->toHaveCount(0);
});

it('still reads a legacy scope that has no pinned version or snapshot', function () {
    createCalibrationScopeMappingTables();

    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => 22,
        'calibration_id' => 8,
        'instrument_type_id' => 31,
        'test_profile_id' => 1,
        'test_profile_version_id' => null,
        'test_table_snapshot' => null,
        'scope_order' => 1,
        'remarks' => 'Legacy scope',
        'created_at' => '2026-06-12 09:00:00',
    ]);
    DB::table('calibration_test_result')->insert([
        'calibration_test_result_id' => 901,
        'calibration_scope_id' => 22,
        'test_point_id' => 31,
        'result_status' => 'PASS',
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
        'standard_value' => '100.125000',
        'average_value' => '100.100000',
        'correction_value' => '0.025000',
    ]);

    $scope = CalibrationScope::query()
        ->with(['instrumentType', 'testProfile', 'testProfileVersion', 'calibrationTestResults.testPoint'])
        ->findOrFail(22);

    expect($scope->test_profile_version_id)->toBeNull()
        ->and($scope->test_table_snapshot)->toBeNull()
        ->and($scope->testProfileVersion)->toBeNull()
        ->and($scope->testProfile?->profile_name)->toBe('Main Test')
        ->and($scope->instrumentType?->type_name)->toBe('Weight')
        ->and($scope->calibrationTestResults)->toHaveCount(1)
        ->and($scope->calibrationTestResults->first()?->test_point_id)->toBe(31)
        ->and($scope->calibrationTestResults->first()?->testPoint?->point_order)->toBe(1)
        ->and((float) $scope->calibrationTestResults->first()?->standard_value)->toBe(100.125);
});
