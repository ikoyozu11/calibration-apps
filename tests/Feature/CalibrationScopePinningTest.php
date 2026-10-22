<?php

use App\Models\Calibration;
use App\Models\CalibrationScope;
use App\Models\InstrumentType;
use App\TestColumns\CalibrationScopePinException;
use App\TestColumns\CalibrationScopePinning;
use App\TestColumns\TestProfileLifecycle;
use App\TestColumns\TestTableSnapshotException;
use App\TestColumns\TestTableVersionWriter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createPinningHarness(): void
{
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

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
        $table->unique(['calibration_id', 'scope_order']);
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

    DB::table('instrument')->insert([
        'instrument_id' => 9,
        'instrument_name' => 'Other',
        'detail_location_id' => null,
        'description' => null,
        'created_at' => '2026-10-14 09:00:00',
    ]);
    DB::table('instrument_type')->insert([
        'instrument_type_id' => 91,
        'instrument_id' => 9,
        'type_name' => 'Other Type',
        'description' => null,
        'created_at' => '2026-10-14 09:00:00',
    ]);

    DB::table('calibration')->insert([
        'calibration_id' => 8,
        'instrument_id' => 3,
        'calibration_provider_id' => 1,
        'calibration_number' => 'CAL-PIN-0001',
        'calibration_date' => '2026-06-12',
        'calibration_due' => '2027-06-12',
        'calibration_method' => 'INTERNAL',
        'calibration_result' => null,
        'certificate_number' => null,
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
        'updated_at' => '2026-06-12 09:00:00',
        'action_date' => null,
        'resume' => null,
        'customer_id' => null,
    ]);
}

function insertPinningTable(
    int $profileId,
    int $versionId,
    int $pointId,
    int $columnId,
    int $valueId,
    string $profileName,
    string $status,
    int $versionNumber,
    string $columnKey,
    string $template,
    int $typeId = 31,
): void {
    if (! DB::table('test_profile')->where('test_profile_id', $profileId)->exists()) {
        DB::table('test_profile')->insert([
            'test_profile_id' => $profileId,
            'instrument_type_id' => $typeId,
            'profile_name' => $profileName,
            'display_title' => $profileName,
            'description' => null,
            'created_at' => '2026-10-14 09:00:00',
        ]);
    }

    insertTestProfileVersion([
        'test_profile_version_id' => $versionId,
        'test_profile_id' => $profileId,
        'version_number' => $versionNumber,
        'status' => $status,
        'profile_name' => $profileName,
        'display_title' => $profileName,
        'published_at' => $status === 'draft' ? null : '2026-10-14 09:00:00',
    ]);

    DB::table('test_column')->insert([
        'test_column_id' => $columnId,
        'test_profile_id' => $profileId,
        'test_profile_version_id' => $versionId,
        'column_key' => $columnKey,
        'column_label' => $columnKey,
        'column_order' => 1,
        'data_type' => 'text',
        'value_source' => 'template',
        'unit' => null,
        'display_scale' => null,
        'formula_code' => null,
        'formula_inputs' => '[]',
        'created_at' => '2026-10-14 09:00:00',
    ]);
    DB::table('test_point')->insert([
        'test_point_id' => $pointId,
        'test_profile_id' => $profileId,
        'test_profile_version_id' => $versionId,
        'point_order' => 1,
        'nominal_value' => null,
        'unit' => null,
        'tolerance_plus' => null,
        'tolerance_minus' => null,
        'description' => $profileName.' '.$status,
        'created_at' => '2026-10-14 09:00:00',
    ]);
    DB::table('test_point_value')->insert([
        'test_point_value_id' => $valueId,
        'test_profile_id' => $profileId,
        'test_profile_version_id' => $versionId,
        'test_point_id' => $pointId,
        'test_column_id' => $columnId,
        'numeric_value' => null,
        'text_value' => $template,
        'unit' => null,
        'created_at' => '2026-10-14 09:00:00',
    ]);
}

function pinType(int $typeId = 31): InstrumentType
{
    return InstrumentType::query()->findOrFail($typeId);
}

function pinCalibration(): Calibration
{
    return Calibration::query()->findOrFail(8);
}

it('pins one active test table as one calibration scope', function () {
    createPinningHarness();
    insertPinningTable(1, 11, 31, 21, 41, 'Main Test', 'active', 2, 'main_cell', 'main-100');

    $scopes = app(CalibrationScopePinning::class)->pin(pinCalibration(), pinType());

    expect($scopes)->toHaveCount(1)
        ->and($scopes->first()?->calibration_id)->toBe(8)
        ->and($scopes->first()?->instrument_type_id)->toBe(31)
        ->and($scopes->first()?->test_profile_id)->toBe(1)
        ->and($scopes->first()?->test_profile_version_id)->toBe(11)
        ->and($scopes->first()?->scope_order)->toBe(1)
        ->and($scopes->first()?->test_table_snapshot['schema_version'])->toBe(2)
        ->and($scopes->first()?->test_table_snapshot['test_profile_version_id'])->toBe(11)
        ->and($scopes->first()?->test_table_snapshot['columns'][0]['completion_rule'])->toBe('not_configured')
        ->and($scopes->first()?->test_table_snapshot['rows'][0]['template_values']['main_cell']['text_value'])->toBe('main-100');
});

it('pins every active test table on the type as its own scope', function () {
    createPinningHarness();
    insertPinningTable(8, 21, 81, 81, 81, 'Sodiline', 'active', 1, 'sodiline_cell', 'sodiline-200');
    insertPinningTable(1, 11, 31, 21, 41, 'Main Test', 'active', 2, 'main_cell', 'main-100');

    $scopes = app(CalibrationScopePinning::class)->pin(pinCalibration(), pinType());

    expect($scopes)->toHaveCount(2)
        ->and($scopes->pluck('test_profile_id')->all())->toBe([1, 8])
        ->and($scopes->pluck('scope_order')->all())->toBe([1, 2])
        ->and($scopes->pluck('test_profile_version_id')->all())->toBe([11, 21])
        ->and($scopes[0]->test_table_snapshot['test_profile_version_id'])->toBe(11)
        ->and($scopes[1]->test_table_snapshot['test_profile_version_id'])->toBe(21);
});

it('pins only the active table when the type also has a draft table', function () {
    createPinningHarness();
    insertPinningTable(8, 21, 81, 81, 81, 'Sodiline', 'draft', 1, 'draft_secret', 'draft-secret');
    insertPinningTable(1, 11, 31, 21, 41, 'Main Test', 'active', 2, 'main_cell', 'main-100');

    $scopes = app(CalibrationScopePinning::class)->pin(pinCalibration(), pinType());

    expect($scopes)->toHaveCount(1)
        ->and($scopes->first()?->test_profile_id)->toBe(1)
        ->and(json_encode($scopes->first()?->test_table_snapshot))->not->toContain('draft-secret');
});

it('rejects pinning when the type has no active test table', function () {
    createPinningHarness();
    insertPinningTable(8, 21, 81, 81, 81, 'Sodiline', 'draft', 1, 'draft_secret', 'draft-secret');

    expect(fn () => app(CalibrationScopePinning::class)->pin(pinCalibration(), pinType()))
        ->toThrow(CalibrationScopePinException::class, 'No active Test Table is available for this instrument type.');

    expect(DB::table('calibration_scope')->count())->toBe(0);
});

it('rejects pinning when the instrument type belongs to another instrument', function () {
    createPinningHarness();
    insertPinningTable(1, 11, 31, 21, 41, 'Main Test', 'active', 1, 'main_cell', 'main-100');

    expect(fn () => app(CalibrationScopePinning::class)->pin(pinCalibration(), pinType(91)))
        ->toThrow(CalibrationScopePinException::class, 'This instrument type does not belong to the calibration instrument.');

    expect(DB::table('calibration_scope')->count())->toBe(0);
});

it('returns the existing pinned scopes instead of inserting duplicates', function () {
    createPinningHarness();
    insertPinningTable(1, 11, 31, 21, 41, 'Main Test', 'active', 1, 'main_cell', 'main-100');
    $pinning = app(CalibrationScopePinning::class);

    $first = $pinning->pin(pinCalibration(), pinType());
    $second = $pinning->pin(pinCalibration(), pinType());

    expect($second)->toHaveCount(1)
        ->and($second->first()?->calibration_scope_id)->toBe($first->first()?->calibration_scope_id)
        ->and(DB::table('calibration_scope')->count())->toBe(1);
});

it('rejects a second pin when existing scopes for the type are not pinned', function () {
    createPinningHarness();
    insertPinningTable(1, 11, 31, 21, 41, 'Main Test', 'active', 1, 'main_cell', 'main-100');

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

    expect(fn () => app(CalibrationScopePinning::class)->pin(pinCalibration(), pinType()))
        ->toThrow(CalibrationScopePinException::class, 'This calibration already has unpinned scopes for this instrument type.');

    expect(DB::table('calibration_scope')->count())->toBe(1)
        ->and(CalibrationScope::query()->find(22)?->test_profile_version_id)->toBeNull()
        ->and(CalibrationScope::query()->find(22)?->test_table_snapshot)->toBeNull()
        ->and(CalibrationScope::query()->find(22)?->calibrationTestResults)->toHaveCount(0);
});

it('rolls back every scope when snapshot building fails after the first table', function () {
    createPinningHarness();
    insertPinningTable(1, 11, 31, 21, 41, 'Main Test', 'active', 1, 'main_cell', 'main-100');
    insertPinningTable(8, 21, 81, 81, 81, 'Sodiline', 'active', 1, 'soak_time', 'not-used');

    DB::table('test_column')->where('test_column_id', 81)->update([
        'data_type' => 'duration',
        'column_label' => 'Soak Time',
    ]);
    DB::table('test_point_value')->where('test_point_value_id', 81)->update([
        'text_value' => null,
        'numeric_value' => '90.5',
        'unit' => 's',
    ]);

    expect(fn () => app(CalibrationScopePinning::class)->pin(pinCalibration(), pinType()))
        ->toThrow(TestTableSnapshotException::class, 'Duration template values must be whole seconds.');

    expect(DB::table('calibration_scope')->count())->toBe(0)
        ->and(DB::table('calibration')->count())->toBe(1);
});

it('does not pin an archived active test table', function () {
    createPinningHarness();
    insertPinningTable(8, 21, 81, 81, 81, 'Sodiline', 'active', 1, 'sodiline_cell', 'sodiline-200');
    insertPinningTable(1, 11, 31, 21, 41, 'Main Test', 'active', 2, 'main_cell', 'main-100');

    DB::table('test_profile')->where('test_profile_id', 1)->update([
        'archived_at' => '2026-10-16 09:00:00',
    ]);

    $scopes = app(CalibrationScopePinning::class)->pin(pinCalibration(), pinType());

    expect($scopes)->toHaveCount(1)
        ->and($scopes->first()?->test_profile_id)->toBe(8)
        ->and($scopes->first()?->test_profile_version_id)->toBe(21);
});

it('keeps a pinned snapshot after the table is archived', function () {
    createPinningHarness();
    insertPinningTable(1, 11, 31, 21, 41, 'Main Test', 'active', 1, 'main_cell', 'main-100');

    $scopes = app(CalibrationScopePinning::class)->pin(pinCalibration(), pinType());
    $frozen = $scopes->first()?->test_table_snapshot;

    app(TestProfileLifecycle::class)->archive(1);

    $reloaded = CalibrationScope::query()->findOrFail($scopes->first()->calibration_scope_id);

    expect($reloaded->test_profile_version_id)->toBe(11)
        ->and($reloaded->test_table_snapshot)->toBe($frozen)
        ->and(DB::table('test_profile')->where('test_profile_id', 1)->value('archived_at'))->not->toBeNull();
});

it('keeps the pinned snapshot after a later publish', function () {
    createPinningHarness();
    insertPinningTable(1, 11, 31, 21, 41, 'Main Test', 'active', 1, 'main_cell', 'main-100');

    $scopes = app(CalibrationScopePinning::class)->pin(pinCalibration(), pinType());
    $frozen = $scopes->first()?->test_table_snapshot;
    $versionId = $scopes->first()?->test_profile_version_id;

    $writer = app(TestTableVersionWriter::class);
    $draftId = $writer->fork(11);
    $writer->publish(1);

    $reloaded = CalibrationScope::query()->findOrFail($scopes->first()->calibration_scope_id);

    expect($reloaded->test_profile_version_id)->toBe($versionId)
        ->and($reloaded->test_profile_version_id)->toBe(11)
        ->and($reloaded->test_table_snapshot['test_profile_version_id'])->toBe(11)
        ->and($reloaded->test_table_snapshot['rows'][0]['test_point_id'])->toBe(31)
        ->and($reloaded->test_table_snapshot)->toBe($frozen)
        ->and(DB::table('test_profile_version')->where('test_profile_version_id', $draftId)->value('status'))->toBe('active');
});

it('still reads legacy result columns on an unpinned scope', function () {
    createPinningHarness();
    insertPinningTable(1, 11, 31, 21, 41, 'Main Test', 'active', 1, 'main_cell', 'main-100');

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
        ->with(['testProfile', 'calibrationTestResults.testPoint'])
        ->findOrFail(22);

    expect($scope->test_table_snapshot)->toBeNull()
        ->and($scope->testProfile?->profile_name)->toBe('Main Test')
        ->and($scope->calibrationTestResults->first()?->testPoint?->point_order)->toBe(1)
        ->and((float) $scope->calibrationTestResults->first()?->standard_value)->toBe(100.125);
});
