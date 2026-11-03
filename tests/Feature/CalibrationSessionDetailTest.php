<?php

use App\Authorization\ApplicationRoleName;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createCalibrationSessionDetailTables(): void
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
        $table->string('snapshot_instrument_name')->nullable();
        $table->string('snapshot_instrument_type_name')->nullable();
        $table->string('snapshot_brand')->nullable();
        $table->string('snapshot_model')->nullable();
        $table->string('snapshot_serial_number')->nullable();
        $table->string('snapshot_asset_number')->nullable();
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
        $table->text('description')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('test_point', function (Blueprint $table): void {
        $table->unsignedBigInteger('test_point_id')->primary();
        $table->unsignedBigInteger('test_profile_id');
        $table->integer('point_order');
        $table->string('description')->nullable();
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
    });

    Schema::create('calibration_activity', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_activity_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->string('activity_type', 100);
        $table->integer('activity_order');
        $table->timestamp('created_at');
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
    });

    Schema::create('instrument_type_requirement', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id');
        $table->string('requirement_category', 20);
        $table->string('requirement_mode', 20);
        $table->primary(['instrument_type_id', 'requirement_category']);
    });
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertCalibrationSessionDetailSubject(array $overrides = []): void
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

function insertCalibrationSessionDetailRequirements(): void
{
    foreach (InstrumentTypeRequirement::CATEGORIES as $index => $category) {
        DB::table('calibration_requirement_snapshot')->insert([
            'calibration_requirement_snapshot_id' => $index + 1,
            'calibration_id' => 5,
            'requirement_category' => $category,
            'frozen_mode' => $category === InstrumentTypeRequirement::CATEGORY_TEST_PROFILE
                ? InstrumentTypeRequirement::MODE_NOT_CONFIGURED
                : InstrumentTypeRequirement::MODE_REQUIRED,
            'resolved_mode' => $category === InstrumentTypeRequirement::CATEGORY_TEST_PROFILE
                ? InstrumentTypeRequirement::MODE_OPTIONAL
                : null,
        ]);
    }
}

/**
 * @return array<string, mixed>
 */
function calibrationSessionDetailSnapshot(): array
{
    return [
        'schema_version' => 2,
        'captured_at' => '2026-10-29T01:00:00Z',
        'duration_format' => 'hh:mm:ss',
        'test_profile_id' => 2,
        'test_profile_version_id' => 20,
        'version_number' => 1,
        'profile_name' => 'Weight Table',
        'display_title' => 'Frozen Weight',
        'description' => null,
        'columns' => [
            [
                'column_key' => 'reading',
                'column_label' => 'As Found',
                'column_order' => 2,
                'data_type' => 'numeric',
                'value_source' => 'engineer',
                'unit' => 'g',
                'display_scale' => 3,
                'formula_code' => null,
                'formula_inputs' => [],
                'completion_rule' => 'required',
            ],
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
                'column_key' => 'deviation',
                'column_label' => 'Deviation',
                'column_order' => 3,
                'data_type' => 'numeric',
                'value_source' => 'calculated',
                'unit' => 'g',
                'display_scale' => null,
                'formula_code' => 'reading-nominal',
                'formula_inputs' => ['reading', 'nominal'],
                'completion_rule' => 'optional',
            ],
        ],
        'rows' => [
            [
                'test_point_id' => 302,
                'point_order' => 2,
                'nominal_value' => '200.0',
                'unit' => 'g',
                'template_values' => [
                    'nominal' => ['numeric_value' => '200.0', 'text_value' => null, 'unit' => 'g'],
                ],
            ],
            [
                'test_point_id' => 301,
                'point_order' => 1,
                'nominal_value' => '100.5',
                'unit' => 'g',
                'template_values' => [
                    'nominal' => ['numeric_value' => '100.5', 'text_value' => null, 'unit' => 'g'],
                ],
            ],
        ],
    ];
}

afterEach(function (): void {
    Model::preventLazyLoading(false);
});

it('renders in-progress completed and cancelled session badges apart from due standing', function (string $status, string $label) {
    createCalibrationSessionDetailTables();
    insertCalibrationSessionDetailSubject([
        'session_status' => $status,
        'calibration_due' => '2025-01-01',
    ]);
    $this->travelTo(Carbon::parse('2026-10-08'));

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Session Status')
        ->assertSee($label)
        ->assertDontSee('Add Standard')
        ->assertDontSee('Calibration Scope');

    if ($status === Calibration::STATUS_IN_PROGRESS) {
        $this->get(route('calibrations.show', 5))
            ->assertSee('Cancel Calibration')
            ->assertSee('Pin Test Tables')
            ->assertSee('Instrument Type')
            ->assertSee('Calibration Method')
            ->assertDontSee('Calibration Information')
            ->assertDontSee('Current Due Status');
    } else {
        $this->get(route('calibrations.show', 5))
            ->assertDontSee('Cancel Calibration')
            ->assertDontSee('Pin Test Tables')
            ->assertSee('Calibration Information')
            ->assertSee('Current Due Status')
            ->assertSee('Overdue')
            ->assertDontSee('Calibrated Types');
    }

    if ($status !== Calibration::STATUS_IN_PROGRESS) {
        $this->get(route('calibrations.show', 5))->assertDontSee('Complete Calibration');
    }
})->with([
    'in progress' => [Calibration::STATUS_IN_PROGRESS, 'In Progress'],
    'completed' => [Calibration::STATUS_COMPLETED, 'Completed'],
    'cancelled' => [Calibration::STATUS_CANCELLED, 'Cancelled'],
]);

it('shows the in-progress date and resume workflow without technical requirement controls', function () {
    createCalibrationSessionDetailTables();
    insertCalibrationSessionDetailSubject();
    insertCalibrationSessionDetailRequirements();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Calibration Dates')
        ->assertSee('Save Dates')
        ->assertSee('Resume')
        ->assertSee('Save Resume')
        ->assertDontSee('Requirement Modes')
        ->assertDontSee('Resolve Requirement')
        ->assertDontSee('id="completion-date"');
});

it('keeps a missing due date from becoming the session status', function () {
    createCalibrationSessionDetailTables();
    insertCalibrationSessionDetailSubject([
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'calibration_due' => null,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertSee('In Progress')
        ->assertDontSee('Calibration Information')
        ->assertDontSee('Current Due Status')
        ->assertDontSee('On Track')
        ->assertDontSee('Overdue');
});

it('renders frozen identity and leaves optional snapshot fields empty', function () {
    createCalibrationSessionDetailTables();
    insertCalibrationSessionDetailSubject([
        'snapshot_brand' => null,
        'snapshot_model' => null,
        'snapshot_asset_number' => null,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertSee('Frozen Gauge')
        ->assertSee('Frozen Type')
        ->assertDontSee('FZN-1')
        ->assertDontSee('Live Gauge')
        ->assertDontSee('Live Type')
        ->assertDontSee('LIVE-SN')
        ->assertDontSee('Live Brand');
});

it('shows the unpinned test components empty state and no used standards', function () {
    createCalibrationSessionDetailTables();
    insertCalibrationSessionDetailSubject();
    insertCalibrationSessionDetailRequirements();
    Model::preventLazyLoading();

    $before = sessionDetailFingerprint();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Test Components have not been pinned to this session yet.')
        ->assertSee('No standards used.')
        ->assertDontSee('Requirement Modes')
        ->assertDontSee('Resolve Requirement')
        ->assertSee('Pin Test Tables')
        ->assertSee('An Active Test Table is required before this session can pin Test Components.')
        ->assertDontSee('Add Standard')
        ->assertDontSee('Start Calibration')
        ->assertSee('Cancel Calibration');

    expect(sessionDetailFingerprint())->toBe($before);
});

it('renders a schema version 2 snapshot in frozen order with manual values only', function () {
    createCalibrationSessionDetailTables();
    insertCalibrationSessionDetailSubject();
    insertCalibrationSessionDetailRequirements();
    DB::table('test_profile')->insert([
        'test_profile_id' => 2,
        'instrument_type_id' => 11,
        'profile_name' => 'Live Master Profile',
        'description' => null,
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('test_point')->insert([
        'test_point_id' => 301,
        'test_profile_id' => 2,
        'point_order' => 1,
        'description' => 'LIVE MASTER POINT',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => 8,
        'calibration_id' => 5,
        'instrument_type_id' => 11,
        'test_profile_id' => 2,
        'test_profile_version_id' => 20,
        'test_table_snapshot' => json_encode(calibrationSessionDetailSnapshot()),
        'scope_order' => 1,
        'remarks' => null,
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('calibration_test_result')->insert([
        'calibration_test_result_id' => 70,
        'calibration_scope_id' => 8,
        'test_point_id' => 301,
        'created_at' => '2026-10-20 09:00:00',
        'average_value' => '999.000000',
        'correction_value' => '8.000000',
    ]);
    DB::table('calibration_result_value')->insert([
        [
            'calibration_result_value_id' => 1,
            'calibration_test_result_id' => 70,
            'column_key' => 'reading',
            'value_source' => 'engineer',
            'data_type' => 'numeric',
            'numeric_value' => '100.125',
            'text_value' => null,
            'unit' => 'g',
            'created_at' => '2026-10-20 09:00:00',
        ],
        [
            'calibration_result_value_id' => 2,
            'calibration_test_result_id' => 70,
            'column_key' => 'deviation',
            'value_source' => 'engineer',
            'data_type' => 'numeric',
            'numeric_value' => '12.5',
            'text_value' => null,
            'unit' => 'g',
            'created_at' => '2026-10-20 09:00:00',
        ],
    ]);
    Model::preventLazyLoading();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Test Components')
        ->assertSee('Frozen Weight')
        ->assertSeeInOrder(['Nominal', 'As Found', 'Deviation'])
        ->assertSee('100.5')
        ->assertSee('100.125')
        ->assertSee('12.5')
        ->assertSee('200.0')
        ->assertDontSee('LIVE MASTER POINT')
        ->assertDontSee('-0.375')
        ->assertDontSee('Pin Test Tables');
});

it('renders standard usage snapshots when standard_instrument_id is null', function () {
    createCalibrationSessionDetailTables();
    insertCalibrationSessionDetailSubject();
    DB::table('calibration_standard_usage')->insert([
        'calibration_standard_usage_id' => 4,
        'calibration_id' => 5,
        'standard_instrument_id' => null,
        'standard_id' => 9,
        'usage_order' => 1,
        'usage_purpose' => 'Reference',
        'remarks' => 'Keep',
        'created_at' => '2026-10-20 09:00:00',
        'standard_range' => '0-100',
        'standard_unit' => 'g',
        'snapshot_standard_code' => 'STD-9',
        'snapshot_instrument_name' => 'Frozen Standard',
        'snapshot_type_specification' => 'Class E2',
        'snapshot_serial_number' => 'SN-STD',
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertSee('Frozen Standard')
        ->assertSee('STD-9')
        ->assertSee('Class E2')
        ->assertSee('SN-STD')
        ->assertSee('0-100')
        ->assertSee('Reference')
        ->assertDontSee('No standards used.')
        ->assertDontSee('Add Standard');
});

it('does not show internal pinning copy on an external session', function () {
    createCalibrationSessionDetailTables();
    insertCalibrationSessionDetailSubject([
        'calibration_method' => Calibration::METHOD_EXTERNAL,
        'session_status' => Calibration::STATUS_COMPLETED,
        'certificate_number' => 'CERT-EXT',
        'calibration_result' => 'PASS',
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertSee('EXTERNAL')
        ->assertSee('Completed')
        ->assertSee('CERT-EXT')
        ->assertDontSee('Calibration Result')
        ->assertDontSee('Test Components have not been pinned to this session yet.')
        ->assertDontSee('Requirement Modes')
        ->assertSee('No standards used.');
});

it('shows an unavailable state for a legacy snapshot instead of live master values', function () {
    createCalibrationSessionDetailTables();
    insertCalibrationSessionDetailSubject();
    DB::table('test_profile')->insert([
        'test_profile_id' => 2,
        'instrument_type_id' => 11,
        'profile_name' => 'Live Master Profile',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('test_point')->insert([
        'test_point_id' => 301,
        'test_profile_id' => 2,
        'point_order' => 1,
        'description' => 'LIVE MASTER POINT',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => 9,
        'calibration_id' => 5,
        'instrument_type_id' => 11,
        'test_profile_id' => 2,
        'test_profile_version_id' => 20,
        'test_table_snapshot' => json_encode([
            'schema_version' => 1,
            'duration_format' => 'hh:mm:ss',
            'test_profile_id' => 2,
            'test_profile_version_id' => 20,
            'version_number' => 1,
            'profile_name' => 'Legacy',
            'columns' => [],
            'rows' => [],
        ]),
        'scope_order' => 1,
        'remarks' => null,
        'created_at' => '2026-10-20 09:00:00',
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertSee('This Test Table snapshot is unavailable.')
        ->assertDontSee('LIVE MASTER POINT');
});

it('lets a viewer read the session without write actions', function () {
    createCalibrationSessionDetailTables();
    insertCalibrationSessionDetailSubject();
    actingAsRole(ApplicationRoleName::Viewer);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('In Progress')
        ->assertSee('Frozen Gauge')
        ->assertDontSee('Pin Test Tables')
        ->assertDontSee('Add Standard')
        ->assertDontSee('>Complete Calibration</button>', false)
        ->assertDontSee('Cancel Calibration')
        ->assertDontSee('Start Calibration');
});

/**
 * @return array<string, mixed>
 */
function sessionDetailFingerprint(): array
{
    return [
        'calibration' => DB::table('calibration')->orderBy('calibration_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'scopes' => DB::table('calibration_scope')->orderBy('calibration_scope_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'results' => DB::table('calibration_test_result')->orderBy('calibration_test_result_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'values' => DB::table('calibration_result_value')->orderBy('calibration_result_value_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'usages' => DB::table('calibration_standard_usage')->orderBy('calibration_standard_usage_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'requirements' => DB::table('calibration_requirement_snapshot')->orderBy('calibration_requirement_snapshot_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'type_requirements' => DB::table('instrument_type_requirement')->orderBy('requirement_category')->get()->map(fn (object $row): array => (array) $row)->all(),
    ];
}
