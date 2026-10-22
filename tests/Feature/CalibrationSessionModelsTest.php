<?php

use App\Models\Calibration;
use App\Models\CalibrationActivity;
use App\Models\CalibrationActivityMaster;
use App\Models\CalibrationEnvironmentParameterSnapshot;
use App\Models\CalibrationEnvironmentValue;
use App\Models\CalibrationExternalDocument;
use App\Models\CalibrationRequirementSnapshot;
use App\Models\CalibrationSessionSetting;
use App\Models\CalibrationStandardUsage;
use App\Models\CalibrationTestProfileRevision;
use App\Models\EnvironmentParameter;
use App\Models\InstrumentType;
use App\Models\InstrumentTypeEnvironmentParameter;
use App\Models\InstrumentTypeRequirement;
use App\Models\StandardMaster;
use App\Models\TestColumn;
use DateTimeInterface;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createCalibrationSessionModelTables(): void
{
    Schema::create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name', 150);
        $table->timestamp('created_at');
    });

    Schema::create('instrument_type', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name');
        $table->timestamp('created_at');
    });

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

    Schema::create('standard_master', function (Blueprint $table): void {
        $table->unsignedBigInteger('standard_id')->primary();
        $table->string('standard_code', 20);
        $table->string('instrument_name', 150);
        $table->timestamps();
    });

    Schema::create('calibration_activity_master', function (Blueprint $table): void {
        $table->unsignedBigInteger('activity_id')->primary();
        $table->string('activity_code', 30);
        $table->string('activity_name', 255);
        $table->timestamps();
    });

    Schema::create('calibration', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->unsignedBigInteger('instrument_type_id')->nullable();
        $table->unsignedBigInteger('calibration_provider_id')->nullable();
        $table->string('calibration_number');
        $table->date('calibration_date')->nullable();
        $table->date('calibration_due')->nullable();
        $table->string('calibration_method');
        $table->string('session_status', 20)->default('completed');
        $table->timestamp('completed_at')->nullable();
        $table->timestamp('cancelled_at')->nullable();
        $table->text('cancelled_reason')->nullable();
        $table->unsignedBigInteger('snapshot_location_id')->nullable();
        $table->unsignedBigInteger('snapshot_detail_location_id')->nullable();
        $table->timestamps();
    });

    Schema::create('instrument_type_requirement', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id');
        $table->string('requirement_category', 20);
        $table->string('requirement_mode', 20)->default('not_configured');
        $table->timestamp('updated_at');
        $table->primary(['instrument_type_id', 'requirement_category']);
    });

    Schema::create('calibration_requirement_snapshot', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_requirement_snapshot_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->string('requirement_category', 20);
        $table->string('frozen_mode', 20);
        $table->string('resolved_mode', 20)->nullable();
        $table->timestamp('resolved_at')->nullable();
        $table->text('resolved_reason')->nullable();
        $table->timestamp('configuration_confirmed_at')->nullable();
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
        $table->unsignedBigInteger('calibration_environment_value_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('parameter_id');
        $table->decimal('numeric_value')->nullable();
        $table->text('text_value')->nullable();
        $table->timestamp('recorded_at');
    });

    Schema::create('calibration_scope', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_scope_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->json('test_table_snapshot')->nullable();
    });

    Schema::create('calibration_test_profile_revision', function (Blueprint $table): void {
        $table->unsignedBigInteger('revision_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('calibration_scope_id');
        $table->unsignedBigInteger('previous_test_profile_id')->nullable();
        $table->json('previous_structure');
        $table->json('previous_results');
        $table->json('proposed_mappings');
        $table->json('confirmed_mappings');
        $table->json('compatible_result_ids');
        $table->json('incompatible_result_ids');
        $table->text('reason');
        $table->timestamp('revised_at');
    });

    Schema::create('calibration_session_setting', function (Blueprint $table): void {
        $table->text('setting_key')->primary();
        $table->text('setting_value');
    });

    Schema::create('calibration_external_document', function (Blueprint $table): void {
        $table->unsignedBigInteger('document_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->string('storage_uuid');
        $table->text('storage_path');
        $table->text('original_filename');
        $table->string('mime_type', 100);
        $table->unsignedBigInteger('byte_size');
        $table->string('sha256', 64);
        $table->boolean('is_active');
        $table->timestamp('uploaded_at');
        $table->timestamp('superseded_at')->nullable();
    });

    Schema::create('calibration_standard_usage', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_standard_usage_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('standard_instrument_id')->nullable();
        $table->unsignedBigInteger('standard_id')->nullable();
        $table->integer('usage_order');
        $table->timestamp('created_at');
    });

    Schema::create('calibration_activity', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_activity_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->string('activity_type')->nullable();
        $table->unsignedBigInteger('activity_id')->nullable();
        $table->string('activity_name', 255)->nullable();
        $table->integer('activity_order');
        $table->timestamp('created_at');
    });
}

function seedCalibrationSessionModelParents(): void
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
    DB::table('location')->insert([
        'location_id' => 4,
        'location_code' => 'LAB',
        'location_name' => 'Laboratory',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('detail_location')->insert([
        'detail_location_id' => 8,
        'location_id' => 4,
        'detail_location_code' => 'LAB-1',
        'detail_location_name' => 'Bench 1',
        'created_at' => '2026-10-20 09:00:00',
    ]);
}

it('maps each foundation model to its table and primary key', function (string $class, string $table, string $key, bool $incrementing) {
    $model = new $class;

    expect($model->getTable())->toBe($table)
        ->and($model->getKeyName())->toBe($key)
        ->and($model->getIncrementing())->toBe($incrementing);
})->with([
    'type requirement' => [InstrumentTypeRequirement::class, 'instrument_type_requirement', 'instrument_type_id', false],
    'requirement snapshot' => [CalibrationRequirementSnapshot::class, 'calibration_requirement_snapshot', 'calibration_requirement_snapshot_id', true],
    'environment parameter' => [EnvironmentParameter::class, 'environment_parameter', 'parameter_id', true],
    'type environment parameter' => [InstrumentTypeEnvironmentParameter::class, 'instrument_type_environment_parameter', 'instrument_type_id', false],
    'environment snapshot' => [CalibrationEnvironmentParameterSnapshot::class, 'calibration_environment_parameter_snapshot', 'calibration_id', false],
    'environment value' => [CalibrationEnvironmentValue::class, 'calibration_environment_value', 'calibration_environment_value_id', true],
    'test profile revision' => [CalibrationTestProfileRevision::class, 'calibration_test_profile_revision', 'revision_id', true],
    'session setting' => [CalibrationSessionSetting::class, 'calibration_session_setting', 'setting_key', false],
    'external document' => [CalibrationExternalDocument::class, 'calibration_external_document', 'document_id', true],
]);

it('keeps historical calibrations completed when session status is omitted', function () {
    $calibration = new Calibration;

    expect($calibration->session_status)->toBe(Calibration::STATUS_COMPLETED)
        ->and($calibration->calibration_number)->toBeNull()
        ->and($calibration->calibration_provider_id)->toBeNull()
        ->and($calibration->completed_at)->toBeNull();
});

it('does not allocate a calibration number from model events or mass assignment', function () {
    $calibration = new Calibration;

    expect($calibration->calibration_number)->toBeNull()
        ->and($calibration->session_status)->toBe(Calibration::STATUS_COMPLETED)
        ->and($calibration->getEventDispatcher()?->hasListeners('eloquent.creating: '.Calibration::class) ?? false)->toBeFalse();

    expect(fn () => $calibration->fill([
        'calibration_number' => 'CAL.VT0001/10/26',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
    ]))->toThrow(MassAssignmentException::class);

    expect($calibration->calibration_number)->toBeNull()
        ->and($calibration->session_status)->toBe(Calibration::STATUS_COMPLETED);
});

it('does not change an explicit in-progress status when the session is stored', function () {
    createCalibrationSessionModelTables();
    seedCalibrationSessionModelParents();

    $calibration = new Calibration;
    $calibration->forceFill([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_number' => 'CAL.VT0001/10/26',
        'calibration_method' => 'INTERNAL',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'created_at' => '2026-10-20 09:00:00',
        'updated_at' => '2026-10-20 09:00:00',
    ]);
    $calibration->save();

    expect($calibration->fresh()->session_status)->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($calibration->fresh()->calibration_number)->toBe('CAL.VT0001/10/26');
});

it('casts nullable dates, completion timestamps, and environment flags', function () {
    createCalibrationSessionModelTables();
    seedCalibrationSessionModelParents();

    DB::table('calibration')->insert([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_provider_id' => null,
        'calibration_number' => 'CAL.VT0001/10/26',
        'calibration_date' => null,
        'calibration_due' => null,
        'calibration_method' => 'EXTERNAL',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'completed_at' => null,
        'cancelled_at' => null,
        'created_at' => '2026-10-20 09:00:00',
        'updated_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('environment_parameter')->insert([
        'parameter_id' => 1,
        'parameter_code' => 'temperature',
        'parameter_name' => 'Temperature',
        'unit' => '°C',
        'data_type' => 'numeric',
        'is_system' => 1,
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('calibration_environment_parameter_snapshot')->insert([
        'calibration_id' => 1,
        'parameter_id' => 1,
        'parameter_code' => 'temperature',
        'parameter_name' => 'Temperature',
        'unit' => '°C',
        'data_type' => 'numeric',
        'requirement_mode' => 'required',
        'display_order' => 1,
        'included_at' => '2026-10-20 09:00:00',
        'inclusion_confirmed' => 1,
    ]);

    $calibration = Calibration::query()->findOrFail(1);
    $parameter = EnvironmentParameter::query()->findOrFail(1);
    $snapshot = $calibration->environmentParameterSnapshots->first();

    expect($calibration->calibration_date)->toBeNull()
        ->and($calibration->calibration_due)->toBeNull()
        ->and($calibration->calibration_provider_id)->toBeNull()
        ->and($calibration->completed_at)->toBeNull()
        ->and($parameter->is_system)->toBeTrue()
        ->and($snapshot)->not->toBeNull()
        ->and($snapshot?->inclusion_confirmed)->toBeTrue()
        ->and($snapshot?->included_at)->toBeInstanceOf(DateTimeInterface::class);
});

it('loads calibration session relationships including the active vendor pdf', function () {
    createCalibrationSessionModelTables();
    seedCalibrationSessionModelParents();

    DB::table('calibration')->insert([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_number' => 'CAL.VT0001/10/26',
        'calibration_method' => 'EXTERNAL',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'snapshot_location_id' => 4,
        'snapshot_detail_location_id' => 8,
        'created_at' => '2026-10-20 09:00:00',
        'updated_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('calibration_requirement_snapshot')->insert([
        'calibration_requirement_snapshot_id' => 1,
        'calibration_id' => 1,
        'requirement_category' => 'environment',
        'frozen_mode' => 'required',
    ]);
    DB::table('environment_parameter')->insert([
        'parameter_id' => 1,
        'parameter_code' => 'temperature',
        'parameter_name' => 'Temperature',
        'unit' => '°C',
        'data_type' => 'numeric',
        'is_system' => 1,
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('calibration_environment_parameter_snapshot')->insert([
        'calibration_id' => 1,
        'parameter_id' => 1,
        'parameter_code' => 'temperature',
        'parameter_name' => 'Temperature',
        'unit' => '°C',
        'data_type' => 'numeric',
        'requirement_mode' => 'required',
        'display_order' => 1,
        'included_at' => '2026-10-20 09:00:00',
        'inclusion_confirmed' => 1,
    ]);
    DB::table('calibration_environment_value')->insert([
        'calibration_environment_value_id' => 1,
        'calibration_id' => 1,
        'parameter_id' => 1,
        'numeric_value' => 23.5,
        'recorded_at' => '2026-10-20 09:05:00',
    ]);
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
        'compatible_result_ids' => '[]',
        'incompatible_result_ids' => '[]',
        'reason' => 'Engineer confirmed mapping',
        'revised_at' => '2026-10-20 12:00:00',
    ]);
    DB::table('calibration_external_document')->insert([
        [
            'document_id' => 1,
            'calibration_id' => 1,
            'storage_uuid' => '11111111-1111-1111-1111-111111111111',
            'storage_path' => '11111111-1111-1111-1111-111111111111.pdf',
            'original_filename' => 'vendor-old.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => 1024,
            'sha256' => str_repeat('a', 64),
            'is_active' => 0,
            'uploaded_at' => '2026-10-20 09:00:00',
            'superseded_at' => '2026-10-20 10:00:00',
        ],
        [
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
        ],
    ]);

    $calibration = Calibration::query()
        ->with([
            'requirementSnapshots',
            'environmentParameterSnapshots',
            'environmentValues.parameter',
            'externalDocuments',
            'activeExternalDocument',
            'testProfileRevisions.calibrationScope',
            'snapshotLocation',
            'snapshotDetailLocation',
        ])
        ->findOrFail(1);

    expect($calibration->requirementSnapshots)->toHaveCount(1)
        ->and($calibration->environmentParameterSnapshots)->toHaveCount(1)
        ->and($calibration->environmentValues)->toHaveCount(1)
        ->and((float) $calibration->environmentValues->first()->numeric_value)->toBe(23.5)
        ->and($calibration->environmentValues->first()->parameter?->parameter_code)->toBe('temperature')
        ->and($calibration->environmentParameterSnapshots->first()?->value?->numeric_value)->not->toBeNull()
        ->and($calibration->externalDocuments)->toHaveCount(2)
        ->and($calibration->activeExternalDocument?->original_filename)->toBe('vendor-new.pdf')
        ->and($calibration->activeExternalDocument?->is_active)->toBeTrue()
        ->and($calibration->testProfileRevisions)->toHaveCount(1)
        ->and($calibration->testProfileRevisions->first()->previous_structure)->toBe([])
        ->and($calibration->testProfileRevisions->first()->calibrationScope?->calibration_scope_id)->toBe(10)
        ->and($calibration->snapshotLocation?->location_code)->toBe('LAB')
        ->and($calibration->snapshotDetailLocation?->detail_location_code)->toBe('LAB-1');
});

it('loads instrument type requirement and environment configuration relationships', function () {
    createCalibrationSessionModelTables();
    seedCalibrationSessionModelParents();

    DB::table('instrument_type_requirement')->insert([
        'instrument_type_id' => 11,
        'requirement_category' => InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
        'requirement_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
        'updated_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('environment_parameter')->insert([
        'parameter_id' => 1,
        'parameter_code' => EnvironmentParameter::CODE_TEMPERATURE,
        'parameter_name' => 'Temperature',
        'unit' => '°C',
        'data_type' => EnvironmentParameter::DATA_TYPE_NUMERIC,
        'is_system' => 1,
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('instrument_type_environment_parameter')->insert([
        'instrument_type_id' => 11,
        'parameter_id' => 1,
        'requirement_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
        'display_order' => 1,
    ]);

    $type = InstrumentType::query()
        ->with(['requirements', 'environmentParameters.parameter'])
        ->findOrFail(11);

    expect($type->requirements)->toHaveCount(1)
        ->and($type->requirements->first()->requirement_mode)->toBe('required')
        ->and($type->environmentParameters)->toHaveCount(1)
        ->and($type->environmentParameters->first()->parameter?->parameter_code)->toBe('temperature');
});

it('loads standard and activity master relationships from session child rows', function () {
    createCalibrationSessionModelTables();
    seedCalibrationSessionModelParents();

    DB::table('calibration')->insert([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_number' => 'CAL.VT0001/10/26',
        'calibration_method' => 'INTERNAL',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'created_at' => '2026-10-20 09:00:00',
        'updated_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('standard_master')->insert([
        'standard_id' => 5,
        'standard_code' => 'STD-1',
        'instrument_name' => 'Master Weight',
        'created_at' => '2026-10-20 09:00:00',
        'updated_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('calibration_activity_master')->insert([
        'activity_id' => 7,
        'activity_code' => 'ACT-1',
        'activity_name' => 'Visual inspection',
        'created_at' => '2026-10-20 09:00:00',
        'updated_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('calibration_standard_usage')->insert([
        'calibration_standard_usage_id' => 1,
        'calibration_id' => 1,
        'standard_instrument_id' => null,
        'standard_id' => 5,
        'usage_order' => 1,
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('calibration_activity')->insert([
        'calibration_activity_id' => 1,
        'calibration_id' => 1,
        'activity_type' => null,
        'activity_id' => 7,
        'activity_name' => 'Visual inspection',
        'activity_order' => 1,
        'created_at' => '2026-10-20 09:00:00',
    ]);

    $usage = CalibrationStandardUsage::query()->with('standardMaster')->findOrFail(1);
    $activity = CalibrationActivity::query()->with('activityMaster')->findOrFail(1);

    expect($usage->standard_instrument_id)->toBeNull()
        ->and($usage->standardMaster?->standard_code)->toBe('STD-1')
        ->and($activity->activity_type)->toBeNull()
        ->and($activity->activityMaster?->activity_name)->toBe('Visual inspection')
        ->and(StandardMaster::query()->findOrFail(5)->calibrationUsages)->toHaveCount(1)
        ->and(CalibrationActivityMaster::query()->findOrFail(7)->calibrationActivities)->toHaveCount(1);
});

it('hides the private storage path when an external document is serialized', function () {
    createCalibrationSessionModelTables();
    seedCalibrationSessionModelParents();

    DB::table('calibration')->insert([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_number' => 'CAL.VT0001/10/26',
        'calibration_method' => 'EXTERNAL',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'created_at' => '2026-10-20 09:00:00',
        'updated_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('calibration_external_document')->insert([
        'document_id' => 1,
        'calibration_id' => 1,
        'storage_uuid' => '11111111-1111-1111-1111-111111111111',
        'storage_path' => '11111111-1111-1111-1111-111111111111.pdf',
        'original_filename' => 'vendor.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => 1024,
        'sha256' => str_repeat('a', 64),
        'is_active' => 1,
        'uploaded_at' => '2026-10-20 09:00:00',
        'superseded_at' => null,
    ]);

    $document = CalibrationExternalDocument::query()->findOrFail(1);

    expect($document->storage_path)->toBe('11111111-1111-1111-1111-111111111111.pdf')
        ->and($document->toArray())->not->toHaveKey('storage_path')
        ->and(CalibrationExternalDocument::query()->active()->count())->toBe(1);
});

it('stores session settings by string key and defaults test columns to not configured', function () {
    createCalibrationSessionModelTables();

    DB::table('calibration_session_setting')->insert([
        'setting_key' => 'external_pdf_max_bytes',
        'setting_value' => '20971520',
    ]);

    $setting = CalibrationSessionSetting::query()->findOrFail('external_pdf_max_bytes');
    $column = new TestColumn;

    expect($setting->setting_value)->toBe('20971520')
        ->and($setting->getKeyType())->toBe('string')
        ->and($column->completion_rule)->toBe('not_configured');
});
