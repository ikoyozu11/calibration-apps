<?php

use App\Authorization\ApplicationCapability;
use App\Authorization\ResolveApplicationRole;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use App\Models\TestProfileVersion;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function createCalibrationSessionPinningUiTables(): void
{
    Schema::create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name', 150);
        $table->string('serial_number')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('instrument_type', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name', 150);
        $table->string('brand')->nullable();
        $table->string('model')->nullable();
        $table->string('asset_number')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('calibration', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->unsignedBigInteger('instrument_type_id')->nullable();
        $table->unsignedBigInteger('calibration_provider_id')->nullable();
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
        $table->string('session_status', 20)->default('completed');
        $table->timestamp('completed_at')->nullable();
        $table->timestamp('cancelled_at')->nullable();
        $table->text('cancelled_reason')->nullable();
        $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
        $table->unsignedBigInteger('started_by_app_user_id')->nullable();
        $table->string('snapshot_instrument_name')->nullable();
        $table->string('snapshot_instrument_type_name')->nullable();
        $table->string('snapshot_brand')->nullable();
        $table->string('snapshot_model')->nullable();
        $table->string('snapshot_serial_number')->nullable();
        $table->string('snapshot_asset_number')->nullable();
        $table->unsignedBigInteger('snapshot_location_id')->nullable();
        $table->unsignedBigInteger('snapshot_detail_location_id')->nullable();
        $table->string('snapshot_location_code')->nullable();
        $table->string('snapshot_location_name')->nullable();
        $table->string('snapshot_detail_location_code')->nullable();
        $table->string('snapshot_detail_location_name')->nullable();
        $table->string('external_vendor_name', 255)->nullable();
    });

    Schema::create('calibration_provider', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_provider_id')->primary();
        $table->string('provider_code', 50);
        $table->string('provider_name', 100);
        $table->string('provider_type', 50)->nullable();
    });

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

    Schema::create('test_profile', function (Blueprint $table): void {
        $table->unsignedBigInteger('test_profile_id')->primary();
        $table->unsignedBigInteger('instrument_type_id');
        $table->string('profile_name', 150);
        $table->string('display_title', 150)->nullable();
        $table->text('description')->nullable();
        $table->timestamp('archived_at')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('test_profile_version', function (Blueprint $table): void {
        $table->unsignedBigInteger('test_profile_version_id')->primary();
        $table->unsignedBigInteger('test_profile_id');
        $table->integer('version_number');
        $table->string('status', 20);
        $table->string('profile_name', 150);
        $table->string('display_title', 150)->nullable();
        $table->text('description')->nullable();
        $table->timestamp('published_at')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('test_column', function (Blueprint $table): void {
        $table->unsignedBigInteger('test_column_id')->primary();
        $table->unsignedBigInteger('test_profile_id');
        $table->unsignedBigInteger('test_profile_version_id');
        $table->string('column_key', 63);
        $table->string('column_label', 150);
        $table->integer('column_order');
        $table->string('data_type', 20);
        $table->string('value_source', 20);
        $table->string('unit', 20)->nullable();
        $table->integer('display_scale')->nullable();
        $table->string('formula_code', 40)->nullable();
        $table->text('formula_inputs')->nullable();
        $table->string('completion_rule', 20);
        $table->timestamp('created_at');
    });

    Schema::create('test_point', function (Blueprint $table): void {
        $table->unsignedBigInteger('test_point_id')->primary();
        $table->unsignedBigInteger('test_profile_id');
        $table->unsignedBigInteger('test_profile_version_id')->nullable();
        $table->integer('point_order');
        $table->string('nominal_value')->nullable();
        $table->string('unit', 20)->nullable();
        $table->string('tolerance_plus')->nullable();
        $table->string('tolerance_minus')->nullable();
        $table->string('description')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('test_point_value', function (Blueprint $table): void {
        $table->unsignedBigInteger('test_point_value_id')->primary();
        $table->unsignedBigInteger('test_profile_id');
        $table->unsignedBigInteger('test_profile_version_id');
        $table->unsignedBigInteger('test_point_id');
        $table->unsignedBigInteger('test_column_id');
        $table->string('numeric_value')->nullable();
        $table->string('text_value')->nullable();
        $table->string('unit', 20)->nullable();
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

    Schema::create('calibration_reading', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_reading_id')->primary();
        $table->unsignedBigInteger('calibration_test_result_id');
        $table->integer('reading_order');
        $table->timestamp('created_at');
        $table->string('reference_value')->nullable();
        $table->string('instrument_value')->nullable();
        $table->string('error_value')->nullable();
        $table->string('uncertainty_value')->nullable();
        $table->string('unit')->nullable();
    });

    Schema::create('calibration_result_value', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_result_value_id')->primary();
        $table->unsignedBigInteger('calibration_test_result_id');
        $table->string('column_key', 63);
        $table->string('value_source', 20);
        $table->string('data_type', 20);
        $table->string('numeric_value')->nullable();
        $table->string('text_value')->nullable();
        $table->string('unit')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('calibration_environment', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_environment_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->timestamp('created_at');
        $table->decimal('temperature_value', 18, 6)->nullable();
        $table->string('temperature_unit')->nullable();
        $table->decimal('humidity_value', 18, 6)->nullable();
        $table->string('humidity_unit')->nullable();
        $table->decimal('pressure_value', 18, 6)->nullable();
        $table->string('pressure_unit')->nullable();
        $table->text('remarks')->nullable();
    });

    Schema::create('calibration_activity', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_activity_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->string('activity_type', 100);
        $table->unsignedBigInteger('activity_id')->nullable();
        $table->string('activity_name')->nullable();
        $table->integer('activity_order');
        $table->timestamp('created_at');
        $table->text('activity_description')->nullable();
        $table->string('activity_result')->nullable();
    });

    Schema::create('calibration_activity_master', function (Blueprint $table): void {
        $table->unsignedBigInteger('activity_id')->primary();
        $table->string('activity_code', 30);
        $table->string('activity_name', 255);
        $table->boolean('is_active')->default(true);
        $table->timestamp('created_at');
        $table->timestamp('updated_at');
    });

    Schema::create('calibration_standard_usage', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_standard_usage_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('standard_instrument_id')->nullable();
        $table->unsignedBigInteger('standard_id')->nullable();
        $table->integer('usage_order');
        $table->string('usage_purpose', 150)->nullable();
        $table->text('remarks')->nullable();
        $table->timestamp('created_at');
        $table->string('standard_range')->nullable();
        $table->string('standard_unit')->nullable();
        $table->string('snapshot_standard_code')->nullable();
        $table->string('snapshot_instrument_name')->nullable();
        $table->string('snapshot_type_specification')->nullable();
        $table->string('snapshot_serial_number')->nullable();
    });

    Schema::create('calibration_requirement_snapshot', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_requirement_snapshot_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->string('requirement_category', 20);
        $table->string('frozen_mode', 20);
        $table->string('resolved_mode', 20)->nullable();
        $table->timestamp('resolved_at')->nullable();
        $table->text('resolved_reason')->nullable();
        $table->unsignedBigInteger('resolved_by_user_id')->nullable();
        $table->timestamp('configuration_confirmed_at')->nullable();
        $table->text('configuration_confirmed_reason')->nullable();
        $table->unsignedBigInteger('configuration_confirmed_by_user_id')->nullable();
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
        $table->unique('parameter_code');
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
        $table->string('numeric_value')->nullable();
        $table->text('text_value')->nullable();
        $table->timestamp('recorded_at');
    });

    Schema::create('instrument_type_requirement', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id');
        $table->string('requirement_category', 20);
        $table->string('requirement_mode', 20);
        $table->primary(['instrument_type_id', 'requirement_category']);
    });

    Schema::create('standard_master', function (Blueprint $table): void {
        $table->unsignedBigInteger('standard_id')->primary();
        $table->string('standard_code', 20);
        $table->string('instrument_name', 150);
        $table->string('type_specification', 150)->nullable();
        $table->string('serial_number', 150)->nullable();
        $table->timestamp('created_at');
        $table->timestamp('updated_at');
    });

    Schema::create('instrument_type_standard_assignment', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_standard_assignment_id')->primary();
        $table->unsignedBigInteger('instrument_type_id');
        $table->unsignedBigInteger('standard_id')->nullable();
        $table->unsignedBigInteger('standard_instrument_id')->nullable();
        $table->integer('assignment_order');
        $table->string('standard_display_name')->nullable();
        $table->string('default_range', 150)->nullable();
        $table->string('default_unit', 50)->nullable();
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

    Schema::create('calibration_external_document', function (Blueprint $table): void {
        $table->id('document_id');
        $table->unsignedBigInteger('calibration_id');
        $table->string('storage_uuid');
        $table->string('storage_path');
        $table->string('original_filename');
        $table->string('mime_type');
        $table->unsignedInteger('byte_size');
        $table->string('sha256');
        $table->boolean('is_active');
        $table->timestamp('uploaded_at');
        $table->timestamp('superseded_at')->nullable();
        $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
    });
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertCalibrationSessionPinningUiSubject(array $overrides = []): void
{
    DB::table('instrument')->insert([
        'instrument_id' => 1,
        'instrument_name' => 'Live Gauge',
        'serial_number' => 'LIVE-SN',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('instrument_type')->insert([
        'instrument_type_id' => 11,
        'instrument_id' => 1,
        'type_name' => 'Live Type',
        'brand' => 'Live Brand',
        'model' => 'Live Model',
        'asset_number' => 'LIVE-AST',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('calibration')->insert(array_merge([
        'calibration_id' => 5,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_number' => 'CAL.SESS.5',
        'calibration_date' => null,
        'calibration_due' => null,
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'snapshot_instrument_name' => 'Frozen Gauge',
        'snapshot_instrument_type_name' => 'Frozen Type',
        'snapshot_brand' => 'Frozen Brand',
        'snapshot_model' => 'Frozen Model',
        'snapshot_serial_number' => 'FZN-1',
        'snapshot_asset_number' => 'AST-F',
        'created_at' => '2026-10-20 09:00:00',
        'updated_at' => '2026-10-20 09:00:00',
    ], $overrides));
}

function insertCalibrationSessionPinningUiRequirements(
    string $frozenMode = InstrumentTypeRequirement::MODE_REQUIRED,
    ?string $resolvedMode = null,
    string $standardsFrozenMode = InstrumentTypeRequirement::MODE_REQUIRED,
    ?string $standardsResolvedMode = null,
    string $activityFrozenMode = InstrumentTypeRequirement::MODE_REQUIRED,
    ?string $activityResolvedMode = null,
    string $environmentFrozenMode = InstrumentTypeRequirement::MODE_REQUIRED,
    ?string $environmentResolvedMode = null,
): void {
    foreach (InstrumentTypeRequirement::CATEGORIES as $index => $category) {
        $frozen = InstrumentTypeRequirement::MODE_REQUIRED;
        $resolved = null;

        if ($category === InstrumentTypeRequirement::CATEGORY_TEST_PROFILE) {
            $frozen = $frozenMode;
            $resolved = $resolvedMode;
        } elseif ($category === InstrumentTypeRequirement::CATEGORY_STANDARDS) {
            $frozen = $standardsFrozenMode;
            $resolved = $standardsResolvedMode;
        } elseif ($category === InstrumentTypeRequirement::CATEGORY_ACTIVITY) {
            $frozen = $activityFrozenMode;
            $resolved = $activityResolvedMode;
        } elseif ($category === InstrumentTypeRequirement::CATEGORY_ENVIRONMENT) {
            $frozen = $environmentFrozenMode;
            $resolved = $environmentResolvedMode;
        }

        DB::table('calibration_requirement_snapshot')->insert([
            'calibration_requirement_snapshot_id' => $index + 1,
            'calibration_id' => 5,
            'requirement_category' => $category,
            'frozen_mode' => $frozen,
            'resolved_mode' => $resolved,
            'resolved_at' => $resolved !== null ? '2026-10-20 09:00:00' : null,
            'resolved_reason' => $resolved !== null ? 'chosen at the bench' : null,
            'resolved_by_user_id' => $resolved !== null ? 90 : null,
        ]);
    }
}

function seedCalibrationSessionPinningUiActiveTable(
    int $profileId = 20,
    int $versionId = 200,
    string $status = TestProfileVersion::STATUS_ACTIVE,
    ?string $archivedAt = null,
    int $typeId = 11,
): void {
    DB::table('test_profile')->insert([
        'test_profile_id' => $profileId,
        'instrument_type_id' => $typeId,
        'profile_name' => 'Profile '.$profileId,
        'display_title' => 'Table '.$profileId,
        'description' => 'Frozen description '.$profileId,
        'archived_at' => $archivedAt,
        'created_at' => '2026-10-22 07:00:00',
    ]);
    DB::table('test_profile_version')->insert([
        'test_profile_version_id' => $versionId,
        'test_profile_id' => $profileId,
        'version_number' => 1,
        'status' => $status,
        'profile_name' => 'Profile '.$profileId,
        'display_title' => 'Table '.$profileId,
        'description' => 'Frozen description '.$profileId,
        'published_at' => '2026-10-22 07:30:00',
        'created_at' => '2026-10-22 07:00:00',
    ]);
    DB::table('test_column')->insert([
        [
            'test_column_id' => ($versionId * 10) + 1,
            'test_profile_id' => $profileId,
            'test_profile_version_id' => $versionId,
            'column_key' => 'standard_value',
            'column_label' => 'Standard',
            'column_order' => 1,
            'data_type' => 'numeric',
            'value_source' => 'template',
            'unit' => 'kPa',
            'display_scale' => 1,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'completion_rule' => 'optional',
            'created_at' => '2026-10-22 07:00:00',
        ],
        [
            'test_column_id' => ($versionId * 10) + 2,
            'test_profile_id' => $profileId,
            'test_profile_version_id' => $versionId,
            'column_key' => 'reading',
            'column_label' => 'Reading',
            'column_order' => 2,
            'data_type' => 'numeric',
            'value_source' => 'engineer',
            'unit' => 'kPa',
            'display_scale' => 1,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'completion_rule' => 'required',
            'created_at' => '2026-10-22 07:00:00',
        ],
        [
            'test_column_id' => ($versionId * 10) + 3,
            'test_profile_id' => $profileId,
            'test_profile_version_id' => $versionId,
            'column_key' => 'average',
            'column_label' => 'Average',
            'column_order' => 3,
            'data_type' => 'numeric',
            'value_source' => 'calculated',
            'unit' => 'kPa',
            'display_scale' => 1,
            'formula_code' => 'average',
            'formula_inputs' => '["reading"]',
            'completion_rule' => 'optional',
            'created_at' => '2026-10-22 07:00:00',
        ],
        [
            'test_column_id' => ($versionId * 10) + 4,
            'test_profile_id' => $profileId,
            'test_profile_version_id' => $versionId,
            'column_key' => 'deviation',
            'column_label' => 'Deviation',
            'column_order' => 4,
            'data_type' => 'numeric',
            'value_source' => 'calculated',
            'unit' => 'kPa',
            'display_scale' => 1,
            'formula_code' => 'deviation',
            'formula_inputs' => '["standard_value","reading"]',
            'completion_rule' => 'optional',
            'created_at' => '2026-10-22 07:00:00',
        ],
    ]);
    DB::table('test_point')->insert([
        [
            'test_point_id' => ($versionId * 10) + 1,
            'test_profile_id' => $profileId,
            'test_profile_version_id' => $versionId,
            'point_order' => 1,
            'nominal_value' => '10',
            'unit' => 'kPa',
            'tolerance_plus' => '0.2',
            'tolerance_minus' => '0.2',
            'description' => 'Low',
            'created_at' => '2026-10-22 07:00:00',
        ],
        [
            'test_point_id' => ($versionId * 10) + 2,
            'test_profile_id' => $profileId,
            'test_profile_version_id' => $versionId,
            'point_order' => 2,
            'nominal_value' => '20',
            'unit' => 'kPa',
            'tolerance_plus' => '0.2',
            'tolerance_minus' => '0.2',
            'description' => 'High',
            'created_at' => '2026-10-22 07:00:00',
        ],
    ]);
    DB::table('test_point_value')->insert([
        'test_point_value_id' => ($versionId * 10) + 1,
        'test_profile_id' => $profileId,
        'test_profile_version_id' => $versionId,
        'test_point_id' => ($versionId * 10) + 1,
        'test_column_id' => ($versionId * 10) + 1,
        'numeric_value' => '10',
        'text_value' => null,
        'unit' => 'kPa',
        'created_at' => '2026-10-22 07:00:00',
    ]);
}

/**
 * @return array<string, mixed>
 */
function calibrationSessionEntrySnapshot(int $profileId = 5, int $versionId = 7): array
{
    return [
        'schema_version' => 2,
        'captured_at' => '2026-10-22T01:00:00Z',
        'duration_format' => 'hh:mm:ss',
        'test_profile_id' => $profileId,
        'test_profile_version_id' => $versionId,
        'version_number' => 1,
        'profile_name' => 'Entry Table',
        'display_title' => 'Frozen Entry',
        'description' => null,
        'columns' => [
            [
                'column_key' => 'nominal',
                'column_label' => 'Nominal',
                'column_order' => 1,
                'data_type' => 'numeric',
                'value_source' => 'template',
                'unit' => 'g',
                'display_scale' => 1,
                'formula_code' => null,
                'formula_inputs' => [],
                'completion_rule' => 'required',
            ],
            [
                'column_key' => 'reading',
                'column_label' => 'Reading',
                'column_order' => 2,
                'data_type' => 'numeric',
                'value_source' => 'engineer',
                'unit' => 'g',
                'display_scale' => 2,
                'formula_code' => null,
                'formula_inputs' => [],
                'completion_rule' => 'required',
            ],
            [
                'column_key' => 'correction',
                'column_label' => 'Correction',
                'column_order' => 3,
                'data_type' => 'numeric',
                'value_source' => 'calculated',
                'unit' => 'g',
                'display_scale' => 3,
                'formula_code' => 'standard_minus_reading',
                'formula_inputs' => ['nominal', 'reading'],
                'completion_rule' => 'optional',
            ],
            [
                'column_key' => 'average',
                'column_label' => 'Average',
                'column_order' => 4,
                'data_type' => 'numeric',
                'value_source' => 'calculated',
                'unit' => 'g',
                'display_scale' => null,
                'formula_code' => 'average',
                'formula_inputs' => ['reading'],
                'completion_rule' => 'optional',
            ],
            [
                'column_key' => 'note',
                'column_label' => 'Note',
                'column_order' => 5,
                'data_type' => 'text',
                'value_source' => 'engineer',
                'unit' => null,
                'display_scale' => null,
                'formula_code' => null,
                'formula_inputs' => [],
                'completion_rule' => 'optional',
            ],
            [
                'column_key' => 'elapsed',
                'column_label' => 'Elapsed',
                'column_order' => 6,
                'data_type' => 'duration',
                'value_source' => 'engineer',
                'unit' => 's',
                'display_scale' => null,
                'formula_code' => null,
                'formula_inputs' => [],
                'completion_rule' => 'optional',
            ],
        ],
        'rows' => [
            [
                'test_point_id' => 11,
                'point_order' => 1,
                'nominal_value' => '100.5',
                'unit' => 'g',
                'tolerance_plus' => null,
                'tolerance_minus' => null,
                'description' => null,
                'template_values' => [
                    'nominal' => ['numeric_value' => '100.5', 'text_value' => null, 'unit' => 'g'],
                ],
            ],
            [
                'test_point_id' => 12,
                'point_order' => 2,
                'nominal_value' => '200.0',
                'unit' => 'g',
                'tolerance_plus' => null,
                'tolerance_minus' => null,
                'description' => null,
                'template_values' => [
                    'nominal' => ['numeric_value' => '200.0', 'text_value' => null, 'unit' => 'g'],
                ],
            ],
        ],
    ];
}

function insertCalibrationSessionEntryPinnedScope(
    array $snapshot = [],
    int $scopeId = 8,
    int $profileId = 5,
    int $versionId = 7,
): void {
    if ($snapshot === []) {
        $snapshot = calibrationSessionEntrySnapshot($profileId, $versionId);
    }

    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => $scopeId,
        'calibration_id' => 5,
        'instrument_type_id' => 11,
        'test_profile_id' => $profileId,
        'test_profile_version_id' => $versionId,
        'test_table_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'scope_order' => $scopeId,
        'remarks' => null,
        'created_at' => '2026-10-20 09:00:00',
    ]);
}

function insertCalibrationSessionResultParent(
    int $resultId,
    int $scopeId,
    int $testPointId,
): void {
    DB::table('calibration_test_result')->insert([
        'calibration_test_result_id' => $resultId,
        'calibration_scope_id' => $scopeId,
        'test_point_id' => $testPointId,
        'result_status' => null,
        'remarks' => null,
        'created_at' => '2026-10-22 08:15:00',
        'standard_value' => null,
        'average_value' => null,
        'correction_value' => null,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertCalibrationSessionResultCell(array $overrides = []): void
{
    $cellId = (int) ($overrides['calibration_result_value_id'] ?? ((int) DB::table('calibration_result_value')->max('calibration_result_value_id')) + 1);

    DB::table('calibration_result_value')->insert(array_merge([
        'calibration_result_value_id' => $cellId,
        'calibration_test_result_id' => 70,
        'column_key' => 'nominal',
        'value_source' => 'template',
        'data_type' => 'numeric',
        'numeric_value' => '100.5',
        'text_value' => null,
        'unit' => 'g',
        'created_at' => '2026-10-22 08:15:00',
    ], $overrides));
}

function insertCalibrationSessionStandardMaster(
    int $standardId,
    string $code,
    string $name,
    ?string $type = null,
    ?string $serial = null,
): void {
    DB::table('standard_master')->insert([
        'standard_id' => $standardId,
        'standard_code' => $code,
        'instrument_name' => $name,
        'type_specification' => $type,
        'serial_number' => $serial,
        'created_at' => '2024-01-01 00:00:00',
        'updated_at' => '2024-01-01 00:00:00',
    ]);
}

function insertCalibrationSessionStandardAssignment(
    int $assignmentId,
    int $instrumentTypeId,
    int $standardId,
    ?string $range,
    ?string $unit,
    int $order,
): void {
    DB::table('instrument_type_standard_assignment')->insert([
        'instrument_type_standard_assignment_id' => $assignmentId,
        'instrument_type_id' => $instrumentTypeId,
        'standard_id' => $standardId,
        'standard_instrument_id' => null,
        'assignment_order' => $order,
        'standard_display_name' => 'Display '.$standardId,
        'default_range' => $range,
        'default_unit' => $unit,
    ]);
}

function seedCalibrationSessionStandardUsageCatalog(): void
{
    insertCalibrationSessionStandardMaster(5, 'STD-5', 'Pressure Gauge', '0.1 class', 'SN-5');
    insertCalibrationSessionStandardMaster(6, 'STD-6', 'Temperature Probe', null, 'SN-6');
    insertCalibrationSessionStandardMaster(7, 'STD-7', 'Unassigned Calibrator', 'Class 1', 'SN-7');
    insertCalibrationSessionStandardMaster(8, 'STD-8', 'Other Type Standard', 'Other', 'SN-8');
    insertCalibrationSessionStandardAssignment(1, 11, 5, '0-100', 'kPa', 1);
    insertCalibrationSessionStandardAssignment(2, 11, 6, '0-10', 'bar', 2);
    insertCalibrationSessionStandardAssignment(3, 99, 8, '1-2', 'V', 1);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertCalibrationSessionActivityMaster(
    int $activityId,
    string $code,
    string $name,
    bool $active = true,
): void {
    DB::table('calibration_activity_master')->insert([
        'activity_id' => $activityId,
        'activity_code' => $code,
        'activity_name' => $name,
        'is_active' => $active ? 1 : 0,
        'created_at' => '2024-01-01 00:00:00',
        'updated_at' => '2024-01-01 00:00:00',
    ]);
}

function seedCalibrationSessionActivityCatalog(): void
{
    insertCalibrationSessionActivityMaster(10, 'ACT-10', 'Cleaning', true);
    insertCalibrationSessionActivityMaster(11, 'ACT-11', 'Adjustment', true);
    insertCalibrationSessionActivityMaster(12, 'ACT-12', 'Retired Check', false);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertCalibrationSessionEnvironmentParameter(
    int $parameterId,
    string $code,
    string $name,
    string $unit,
    string $dataType,
    bool $system = false,
): void {
    DB::table('environment_parameter')->insert([
        'parameter_id' => $parameterId,
        'parameter_code' => $code,
        'parameter_name' => $name,
        'unit' => $unit,
        'data_type' => $dataType,
        'is_system' => $system ? 1 : 0,
        'created_at' => '2024-01-01 00:00:00',
    ]);
}

function insertCalibrationSessionEnvironmentAssignment(
    int $instrumentTypeId,
    int $parameterId,
    string $mode,
    int $order,
): void {
    DB::table('instrument_type_environment_parameter')->insert([
        'instrument_type_id' => $instrumentTypeId,
        'parameter_id' => $parameterId,
        'requirement_mode' => $mode,
        'display_order' => $order,
    ]);
}

function insertCalibrationSessionEnvironmentSnapshot(array $overrides = []): void
{
    DB::table('calibration_environment_parameter_snapshot')->insert(array_merge([
        'calibration_id' => 5,
        'parameter_id' => 1,
        'parameter_code' => 'temperature',
        'parameter_name' => 'Temperature',
        'unit' => '°C',
        'data_type' => 'numeric',
        'requirement_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
        'display_order' => 1,
        'included_at' => '2026-10-20 09:00:00',
        'inclusion_confirmed' => 1,
        'inclusion_reason' => null,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertCalibrationSessionEnvironmentValue(array $overrides = []): void
{
    $valueId = (int) ($overrides['calibration_environment_value_id'] ?? ((int) DB::table('calibration_environment_value')->max('calibration_environment_value_id')) + 1);

    DB::table('calibration_environment_value')->insert(array_merge([
        'calibration_environment_value_id' => $valueId,
        'calibration_id' => 5,
        'parameter_id' => 1,
        'numeric_value' => '23.5',
        'text_value' => null,
        'recorded_at' => '2026-10-22 08:15:00',
    ], $overrides));
}

function seedCalibrationSessionEnvironmentCatalog(bool $assignToSessionType = true): void
{
    insertCalibrationSessionEnvironmentParameter(1, 'temperature', 'Temperature', '°C', 'numeric', true);
    insertCalibrationSessionEnvironmentParameter(2, 'relative_humidity', 'Relative Humidity', '%RH', 'numeric', true);
    insertCalibrationSessionEnvironmentParameter(3, 'condition', 'Condition', 'note', 'text', false);
    insertCalibrationSessionEnvironmentParameter(4, 'pressure', 'Pressure', 'kPa', 'numeric', false);

    if ($assignToSessionType) {
        insertCalibrationSessionEnvironmentAssignment(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1);
        insertCalibrationSessionEnvironmentAssignment(11, 2, InstrumentTypeRequirement::MODE_REQUIRED, 2);
        insertCalibrationSessionEnvironmentAssignment(11, 3, InstrumentTypeRequirement::MODE_OPTIONAL, 3);
    }

    insertCalibrationSessionEnvironmentSnapshot();
    insertCalibrationSessionEnvironmentSnapshot([
        'parameter_id' => 2,
        'parameter_code' => 'relative_humidity',
        'parameter_name' => 'Relative Humidity',
        'unit' => '%RH',
        'data_type' => 'numeric',
        'requirement_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
        'display_order' => 2,
    ]);
    insertCalibrationSessionEnvironmentSnapshot([
        'parameter_id' => 3,
        'parameter_code' => 'condition',
        'parameter_name' => 'Condition',
        'unit' => 'note',
        'data_type' => 'text',
        'requirement_mode' => InstrumentTypeRequirement::MODE_OPTIONAL,
        'display_order' => 3,
    ]);
}

function insertCalibrationSessionActivity(array $overrides = []): void
{
    $activityRowId = (int) ($overrides['calibration_activity_id'] ?? ((int) DB::table('calibration_activity')->max('calibration_activity_id')) + 1);

    DB::table('calibration_activity')->insert(array_merge([
        'calibration_activity_id' => $activityRowId,
        'calibration_id' => 5,
        'activity_type' => 'Cleaning',
        'activity_id' => 10,
        'activity_name' => 'Cleaning',
        'activity_order' => 1,
        'created_at' => '2026-10-22 08:15:00',
        'activity_description' => 'Wiped the bench',
        'activity_result' => 'Pass',
    ], $overrides));
}

function insertCalibrationSessionStandardUsage(array $overrides = []): void
{
    $usageId = (int) ($overrides['calibration_standard_usage_id'] ?? ((int) DB::table('calibration_standard_usage')->max('calibration_standard_usage_id')) + 1);

    DB::table('calibration_standard_usage')->insert(array_merge([
        'calibration_standard_usage_id' => $usageId,
        'calibration_id' => 5,
        'standard_instrument_id' => null,
        'standard_id' => 5,
        'usage_order' => 1,
        'usage_purpose' => 'Working standard',
        'remarks' => 'Bench A',
        'created_at' => '2026-10-22 08:15:00',
        'standard_range' => '0-100',
        'standard_unit' => 'kPa',
        'snapshot_standard_code' => 'STD-5',
        'snapshot_instrument_name' => 'Pressure Gauge',
        'snapshot_type_specification' => '0.1 class',
        'snapshot_serial_number' => 'SN-5',
    ], $overrides));
}

function calibrationSessionPinningUiPage(): Testable
{
    return Livewire::test('pages::calibrations.show', [
        'calibration' => 5,
    ]);
}

/**
 * @return array<string, mixed>
 */
function calibrationSessionPinningUiFingerprint(): array
{
    return [
        'calibration' => DB::table('calibration')->orderBy('calibration_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'scopes' => DB::table('calibration_scope')->orderBy('calibration_scope_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'results' => DB::table('calibration_test_result')->orderBy('calibration_test_result_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'values' => DB::table('calibration_result_value')->orderBy('calibration_result_value_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'requirements' => DB::table('calibration_requirement_snapshot')->orderBy('calibration_requirement_snapshot_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'usages' => DB::table('calibration_standard_usage')->orderBy('calibration_standard_usage_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'masters' => DB::table('standard_master')->orderBy('standard_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'assignments' => DB::table('instrument_type_standard_assignment')->orderBy('instrument_type_standard_assignment_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'activities' => DB::table('calibration_activity')->orderBy('calibration_activity_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'activity_masters' => DB::table('calibration_activity_master')->orderBy('activity_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'environment_parameters' => DB::table('environment_parameter')->orderBy('parameter_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'environment_assignments' => DB::table('instrument_type_environment_parameter')->orderBy('parameter_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'environment_snapshots' => DB::table('calibration_environment_parameter_snapshot')->orderBy('display_order')->orderBy('parameter_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'environment_values' => DB::table('calibration_environment_value')->orderBy('calibration_environment_value_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'providers' => Schema::hasTable('calibration_provider')
            ? DB::table('calibration_provider')->orderBy('calibration_provider_id')->get()->map(fn (object $row): array => (array) $row)->all()
            : [],
        'documents' => Schema::hasTable('calibration_external_document')
            ? DB::table('calibration_external_document')->orderBy('document_id')->get()->map(fn (object $row): array => (array) $row)->all()
            : [],
    ];
}

function insertCalibrationSessionInternalProvider(int $providerId = 77): void
{
    DB::table('calibration_provider')->insert([
        'calibration_provider_id' => $providerId,
        'provider_code' => 'INTERNAL_TEAM',
        'provider_name' => 'Internal Calibration Team',
        'provider_type' => 'internal',
    ]);
}

function applyCalibrationSessionFrozenLocation(int $providerId = 77): void
{
    DB::table('calibration')->where('calibration_id', 5)->update([
        'snapshot_location_id' => 4,
        'snapshot_detail_location_id' => 8,
        'snapshot_location_code' => 'LAB',
        'snapshot_location_name' => 'Laboratory',
        'snapshot_detail_location_code' => 'B1',
        'snapshot_detail_location_name' => 'Bench 1',
        'calibration_provider_id' => $providerId,
    ]);
}

function confirmCalibrationSessionEnvironment(string $reason = 'bench confirmed'): void
{
    DB::table('calibration_requirement_snapshot')
        ->where('calibration_id', 5)
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
        ->update([
            'configuration_confirmed_at' => '2026-10-22 09:30:00',
            'configuration_confirmed_reason' => $reason,
            'configuration_confirmed_by_user_id' => 90,
        ]);
}

function denySessionCompleteWhileAllowingSessionRecord(): void
{
    Gate::define(ApplicationCapability::SessionComplete->value, static fn (): bool => false);
    Gate::define(ApplicationCapability::SessionRecord->value, static fn (): bool => true);
}

function restoreApplicationCapabilityGates(): void
{
    $roles = app(ResolveApplicationRole::class);

    foreach (ApplicationCapability::cases() as $capability) {
        Gate::define($capability->value, function (?User $user) use ($roles, $capability): bool {
            if ($user === null) {
                return false;
            }

            return $roles->allows((int) $user->id, $capability);
        });
    }
}
