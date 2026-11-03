<?php

use App\CalibrationSessions\CalibrationSessionDetailPresenter;
use App\Models\Calibration;
use App\Models\CalibrationActivity;
use App\Models\CalibrationEnvironmentParameterSnapshot;
use App\Models\CalibrationEnvironmentValue;
use App\Models\CalibrationExternalDocument;
use App\Models\CalibrationRequirementSnapshot;
use App\Models\CalibrationScope;
use App\Models\CalibrationStandardUsage;
use App\Models\CalibrationTestResult;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @template TModel of Model
 *
 * @param  class-string<TModel>  $class
 * @param  array<string, mixed>  $attributes
 * @return TModel
 */
function presenterModel(string $class, array $attributes): Model
{
    return (new $class)->forceFill($attributes);
}

it('labels session statuses without using due standing', function () {
    expect(CalibrationSessionDetailPresenter::sessionStatusLabel(Calibration::STATUS_IN_PROGRESS))->toBe('In Progress')
        ->and(CalibrationSessionDetailPresenter::sessionStatusLabel(Calibration::STATUS_COMPLETED))->toBe('Completed')
        ->and(CalibrationSessionDetailPresenter::sessionStatusLabel(Calibration::STATUS_CANCELLED))->toBe('Cancelled');
});

it('prefers frozen identity over live master values', function () {
    $calibration = presenterModel(Calibration::class, [
        'snapshot_instrument_name' => 'Frozen Gauge',
        'snapshot_instrument_type_name' => 'Frozen Type',
        'snapshot_brand' => 'Frozen Brand',
        'snapshot_model' => 'Frozen Model',
        'snapshot_serial_number' => 'FZN-1',
        'snapshot_asset_number' => 'AST-F',
        'calibration_method' => Calibration::METHOD_INTERNAL,
    ]);
    $calibration->setRelation('instrument', presenterModel(Instrument::class, [
        'instrument_name' => 'Live Gauge',
        'serial_number' => 'LIVE-1',
    ]));
    $calibration->setRelation('instrumentType', presenterModel(InstrumentType::class, [
        'type_name' => 'Live Type',
        'brand' => 'Live Brand',
        'model' => 'Live Model',
        'asset_number' => 'AST-L',
    ]));

    expect(CalibrationSessionDetailPresenter::identity($calibration))->toBe([
        'name' => 'Frozen Gauge',
        'type' => 'Frozen Type',
        'brand' => 'Frozen Brand',
        'model' => 'Frozen Model',
        'serial_number' => 'FZN-1',
        'asset_number' => 'AST-F',
    ]);
});

it('renders null optional identity fields as em dashes', function () {
    $calibration = presenterModel(Calibration::class, [
        'snapshot_instrument_name' => 'Frozen Gauge',
        'snapshot_instrument_type_name' => null,
        'snapshot_brand' => null,
        'snapshot_model' => null,
        'snapshot_serial_number' => null,
        'snapshot_asset_number' => null,
        'calibration_method' => Calibration::METHOD_INTERNAL,
    ]);
    $calibration->setRelation('instrument', presenterModel(Instrument::class, [
        'instrument_name' => 'Live Gauge',
        'serial_number' => null,
    ]));
    $calibration->setRelation('instrumentType', null);

    expect(CalibrationSessionDetailPresenter::identity($calibration))->toBe([
        'name' => 'Frozen Gauge',
        'type' => '—',
        'brand' => '—',
        'model' => '—',
        'serial_number' => '—',
        'asset_number' => '—',
    ]);
});

it('uses standard usage snapshots when the instrument id is null', function () {
    $calibration = presenterModel(Calibration::class, ['calibration_method' => Calibration::METHOD_INTERNAL]);
    $usage = presenterModel(CalibrationStandardUsage::class, [
        'calibration_standard_usage_id' => 4,
        'standard_instrument_id' => null,
        'usage_order' => 1,
        'usage_purpose' => 'Reference',
        'remarks' => 'Keep',
        'standard_range' => '0-100',
        'standard_unit' => 'g',
        'snapshot_standard_code' => 'STD-9',
        'snapshot_instrument_name' => 'Frozen Standard',
        'snapshot_type_specification' => 'Class E2',
        'snapshot_serial_number' => 'SN-STD',
    ]);
    $usage->setRelation('instrument', null);
    $calibration->setRelation('standardUsages', new Collection([$usage]));

    expect(CalibrationSessionDetailPresenter::standards($calibration))->toBe([[
        'key' => 4,
        'standard_id' => null,
        'usage_order' => 1,
        'name' => 'Frozen Standard',
        'code' => 'STD-9',
        'type' => 'Class E2',
        'serial_number' => 'SN-STD',
        'purpose' => 'Reference',
        'range' => '0-100',
        'unit' => 'g',
        'remarks' => 'Keep',
    ]]);
});

it('uses frozen activity names and falls back to legacy activity type', function () {
    $calibration = presenterModel(Calibration::class, ['calibration_method' => Calibration::METHOD_INTERNAL]);
    $catalog = presenterModel(CalibrationActivity::class, [
        'calibration_activity_id' => 3,
        'activity_id' => 10,
        'activity_order' => 1,
        'activity_name' => 'Frozen Cleaning',
        'activity_type' => 'Frozen Cleaning',
        'activity_description' => 'Wiped',
        'activity_result' => 'Pass',
    ]);
    $legacy = presenterModel(CalibrationActivity::class, [
        'calibration_activity_id' => 4,
        'activity_id' => null,
        'activity_order' => 2,
        'activity_name' => null,
        'activity_type' => 'Legacy Free Text',
        'activity_description' => null,
        'activity_result' => null,
    ]);
    $calibration->setRelation('activities', new Collection([$legacy, $catalog]));

    expect(CalibrationSessionDetailPresenter::activities($calibration))->toBe([
        [
            'key' => 3,
            'activity_id' => 10,
            'activity_order' => 1,
            'name' => 'Frozen Cleaning',
            'description' => 'Wiped',
            'result' => 'Pass',
        ],
        [
            'key' => 4,
            'activity_id' => null,
            'activity_order' => 2,
            'name' => 'Legacy Free Text',
            'description' => '—',
            'result' => '—',
        ],
    ]);
});

it('presents frozen environment parameters and confirmation without using live master values', function () {
    $calibration = presenterModel(Calibration::class, ['calibration_method' => Calibration::METHOD_INTERNAL]);
    $temperature = presenterModel(CalibrationEnvironmentParameterSnapshot::class, [
        'parameter_id' => 1,
        'parameter_code' => 'temperature',
        'parameter_name' => 'Frozen Temperature',
        'unit' => '°C',
        'data_type' => 'numeric',
        'requirement_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
        'display_order' => 1,
    ]);
    $humidity = presenterModel(CalibrationEnvironmentParameterSnapshot::class, [
        'parameter_id' => 2,
        'parameter_code' => 'relative_humidity',
        'parameter_name' => 'Frozen Humidity',
        'unit' => '%RH',
        'data_type' => 'numeric',
        'requirement_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
        'display_order' => 2,
    ]);
    $condition = presenterModel(CalibrationEnvironmentParameterSnapshot::class, [
        'parameter_id' => 3,
        'parameter_code' => 'condition',
        'parameter_name' => 'Frozen Condition',
        'unit' => 'note',
        'data_type' => 'text',
        'requirement_mode' => InstrumentTypeRequirement::MODE_OPTIONAL,
        'display_order' => 3,
    ]);
    $value = presenterModel(CalibrationEnvironmentValue::class, [
        'parameter_id' => 1,
        'numeric_value' => '23.50',
        'text_value' => null,
    ]);
    $requirement = presenterModel(CalibrationRequirementSnapshot::class, [
        'requirement_category' => InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
        'configuration_confirmed_at' => '2026-10-22 09:30:00',
        'configuration_confirmed_reason' => 'bench checked',
    ]);
    $calibration->setRelation('environmentParameterSnapshots', new Collection([$condition, $humidity, $temperature]));
    $calibration->setRelation('environmentValues', new Collection([$value]));
    $calibration->setRelation('requirementSnapshots', new Collection([$requirement]));

    expect(CalibrationSessionDetailPresenter::environmentParameters($calibration))->toBe([
        [
            'key' => 1,
            'parameter_id' => 1,
            'code' => 'temperature',
            'name' => 'Frozen Temperature',
            'unit' => '°C',
            'data_type' => 'numeric',
            'requirement_mode' => 'required',
            'required' => true,
            'value' => '23.50',
            'input' => '23.50',
            'has_value' => true,
            'missing_required' => false,
        ],
        [
            'key' => 2,
            'parameter_id' => 2,
            'code' => 'relative_humidity',
            'name' => 'Frozen Humidity',
            'unit' => '%RH',
            'data_type' => 'numeric',
            'requirement_mode' => 'required',
            'required' => true,
            'value' => '—',
            'input' => '',
            'has_value' => false,
            'missing_required' => true,
        ],
        [
            'key' => 3,
            'parameter_id' => 3,
            'code' => 'condition',
            'name' => 'Frozen Condition',
            'unit' => 'note',
            'data_type' => 'text',
            'requirement_mode' => 'optional',
            'required' => false,
            'value' => '—',
            'input' => '',
            'has_value' => false,
            'missing_required' => false,
        ],
    ])
        ->and(CalibrationSessionDetailPresenter::environmentConfirmation($calibration))->toBe([
            'confirmed' => true,
            'confirmed_at' => '2026-10-22 09:30:00',
            'reason' => 'bench checked',
        ]);
});

it('classifies unpinned, missing, complete, conflicting, and invalid template states', function () {
    $unpinned = presenterModel(Calibration::class, [
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'instrument_type_id' => 11,
    ]);
    $unpinnedScope = presenterModel(CalibrationScope::class, [
        'calibration_scope_id' => 8,
        'instrument_type_id' => 11,
        'test_profile_id' => 2,
        'test_profile_version_id' => 20,
        'scope_order' => 1,
        'test_table_snapshot' => null,
    ]);
    $unpinnedScope->setRelation('calibrationTestResults', new Collection);
    $unpinned->setRelation('calibrationScopes', new Collection([$unpinnedScope]));
    expect(CalibrationSessionDetailPresenter::templateMaterialization($unpinned, new Collection)['scopes'][0])->toMatchArray([
        'key' => 8,
        'state' => 'unpinned',
        'recoverable' => false,
    ]);

    $missing = presenterModel(Calibration::class, [
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'instrument_type_id' => 11,
    ]);
    $missingScope = presenterModel(CalibrationScope::class, [
        'calibration_scope_id' => 8,
        'instrument_type_id' => 11,
        'test_profile_id' => 2,
        'test_profile_version_id' => 20,
        'scope_order' => 1,
        'test_table_snapshot' => presenterDetailSnapshot(),
    ]);
    $missingScope->setRelation('calibrationTestResults', new Collection);
    $missing->setRelation('calibrationScopes', new Collection([$missingScope]));
    expect(CalibrationSessionDetailPresenter::templateMaterialization($missing, new Collection)['scopes'][0])->toMatchArray([
        'key' => 8,
        'state' => 'missing_template',
        'recoverable' => true,
    ]);

    $completeResult = presenterModel(CalibrationTestResult::class, [
        'calibration_test_result_id' => 70,
        'calibration_scope_id' => 8,
        'test_point_id' => 301,
    ]);
    $completeResultTwo = presenterModel(CalibrationTestResult::class, [
        'calibration_test_result_id' => 71,
        'calibration_scope_id' => 8,
        'test_point_id' => 302,
    ]);
    $completeScope = presenterModel(CalibrationScope::class, [
        'calibration_scope_id' => 8,
        'instrument_type_id' => 11,
        'test_profile_id' => 2,
        'test_profile_version_id' => 20,
        'scope_order' => 1,
        'test_table_snapshot' => presenterDetailSnapshot(),
    ]);
    $completeScope->setRelation('calibrationTestResults', new Collection([$completeResult, $completeResultTwo]));
    $complete = presenterModel(Calibration::class, [
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'instrument_type_id' => 11,
    ]);
    $complete->setRelation('calibrationScopes', new Collection([$completeScope]));
    expect(CalibrationSessionDetailPresenter::templateMaterialization($complete, new Collection([
        (object) ['calibration_test_result_id' => 70, 'column_key' => 'nominal', 'value_source' => 'template', 'data_type' => 'numeric', 'numeric_value' => '100.5', 'text_value' => null, 'unit' => 'g'],
        (object) ['calibration_test_result_id' => 71, 'column_key' => 'nominal', 'value_source' => 'template', 'data_type' => 'numeric', 'numeric_value' => '200.0', 'text_value' => null, 'unit' => 'g'],
    ]))['scopes'][0]['state'])->toBe('complete');

    $conflictScope = presenterModel(CalibrationScope::class, [
        'calibration_scope_id' => 8,
        'instrument_type_id' => 11,
        'test_profile_id' => 2,
        'test_profile_version_id' => 20,
        'scope_order' => 1,
        'test_table_snapshot' => presenterDetailSnapshot(),
    ]);
    $conflictScope->setRelation('calibrationTestResults', new Collection([$completeResult, $completeResultTwo]));
    $conflict = presenterModel(Calibration::class, [
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'instrument_type_id' => 11,
    ]);
    $conflict->setRelation('calibrationScopes', new Collection([$conflictScope]));
    expect(CalibrationSessionDetailPresenter::templateMaterialization($conflict, new Collection([
        (object) ['calibration_test_result_id' => 70, 'column_key' => 'nominal', 'value_source' => 'engineer', 'data_type' => 'numeric', 'numeric_value' => '100.5', 'text_value' => null, 'unit' => 'g'],
        (object) ['calibration_test_result_id' => 71, 'column_key' => 'nominal', 'value_source' => 'template', 'data_type' => 'numeric', 'numeric_value' => '200.0', 'text_value' => null, 'unit' => 'g'],
    ]))['scopes'][0]['state'])->toBe('conflict');

    $invalidSnapshot = presenterDetailSnapshot();
    $invalidSnapshot['schema_version'] = 1;
    $invalidScope = presenterModel(CalibrationScope::class, [
        'calibration_scope_id' => 8,
        'instrument_type_id' => 11,
        'test_profile_id' => 2,
        'test_profile_version_id' => 20,
        'scope_order' => 1,
        'test_table_snapshot' => $invalidSnapshot,
    ]);
    $invalidScope->setRelation('calibrationTestResults', new Collection);
    $invalid = presenterModel(Calibration::class, [
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'instrument_type_id' => 11,
    ]);
    $invalid->setRelation('calibrationScopes', new Collection([$invalidScope]));
    expect(CalibrationSessionDetailPresenter::templateMaterialization($invalid, new Collection)['scopes'][0]['state'])->toBe('invalid_snapshot');
});

it('shows an unpinned state when an internal session has no scopes', function () {
    $calibration = presenterModel(Calibration::class, ['calibration_method' => Calibration::METHOD_INTERNAL]);
    $calibration->setRelation('calibrationScopes', new Collection);

    expect(CalibrationSessionDetailPresenter::testComponents($calibration, new Collection))->toBe([
        'state' => 'unpinned',
        'tables' => [],
    ]);
});

it('hides test components for external sessions', function () {
    $calibration = presenterModel(Calibration::class, ['calibration_method' => Calibration::METHOD_EXTERNAL]);
    $calibration->setRelation('calibrationScopes', new Collection);

    expect(CalibrationSessionDetailPresenter::testComponents($calibration, new Collection)['state'])->toBe('hidden')
        ->and(CalibrationSessionDetailPresenter::isInternal($calibration))->toBeFalse()
        ->and(CalibrationSessionDetailPresenter::isExternal($calibration))->toBeTrue();
});

it('presents a vendor snapshot and document metadata without storage paths', function () {
    $calibration = presenterModel(Calibration::class, [
        'calibration_method' => Calibration::METHOD_EXTERNAL,
        'external_vendor_name' => 'Acme Labs',
    ]);
    $active = presenterModel(CalibrationExternalDocument::class, [
        'document_id' => 2,
        'original_filename' => 'second.pdf',
        'is_active' => true,
        'uploaded_at' => '2026-10-22 11:00:00',
        'storage_path' => 'secret-uuid.pdf',
        'storage_uuid' => '11111111-1111-1111-1111-000000000002',
    ]);
    $replaced = presenterModel(CalibrationExternalDocument::class, [
        'document_id' => 1,
        'original_filename' => 'first.pdf',
        'is_active' => false,
        'uploaded_at' => '2026-10-22 10:00:00',
        'storage_path' => 'secret-uuid-old.pdf',
        'storage_uuid' => '11111111-1111-1111-1111-000000000001',
    ]);
    $calibration->setRelation('externalDocuments', new Collection([$replaced, $active]));

    $documents = CalibrationSessionDetailPresenter::vendorDocuments($calibration);

    expect(CalibrationSessionDetailPresenter::recordedVendorName($calibration))->toBe('Acme Labs')
        ->and($documents)->toHaveCount(2)
        ->and($documents[0]['filename'])->toBe('first.pdf')
        ->and($documents[0]['status'])->toBe('Replaced')
        ->and($documents[1]['filename'])->toBe('second.pdf')
        ->and($documents[1]['status'])->toBe('Active')
        ->and(json_encode($documents))->not->toContain('secret-uuid')
        ->and(json_encode($documents))->not->toContain('storage_path');
});

it('treats a blank vendor snapshot as absent', function () {
    $calibration = presenterModel(Calibration::class, [
        'calibration_method' => Calibration::METHOD_EXTERNAL,
        'external_vendor_name' => '   ',
    ]);
    $calibration->setRelation('externalDocuments', new Collection);

    expect(CalibrationSessionDetailPresenter::recordedVendorName($calibration))->toBeNull()
        ->and(CalibrationSessionDetailPresenter::vendorDocuments($calibration))->toBe([]);
});

it('renders frozen column and row order with template and engineer values and no formula result', function () {
    $calibration = presenterModel(Calibration::class, ['calibration_method' => Calibration::METHOD_INTERNAL]);
    $scope = presenterModel(CalibrationScope::class, [
        'calibration_scope_id' => 8,
        'test_profile_version_id' => 20,
        'test_table_snapshot' => presenterDetailSnapshot(),
    ]);
    $result = presenterModel(CalibrationTestResult::class, [
        'calibration_test_result_id' => 70,
        'test_point_id' => 301,
    ]);
    $scope->setRelation('calibrationTestResults', new Collection([$result]));
    $calibration->setRelation('calibrationScopes', new Collection([$scope]));

    $presented = CalibrationSessionDetailPresenter::testComponents($calibration, new Collection([
        (object) [
            'calibration_test_result_id' => 70,
            'column_key' => 'reading',
            'data_type' => 'numeric',
            'numeric_value' => '100.125',
            'text_value' => null,
        ],
        (object) [
            'calibration_test_result_id' => 70,
            'column_key' => 'deviation',
            'data_type' => 'numeric',
            'numeric_value' => '12.5',
            'text_value' => null,
        ],
    ]));

    expect($presented['state'])->toBe('tables')
        ->and($presented['tables'][0]['state'])->toBe('snapshot')
        ->and(array_column($presented['tables'][0]['columns'], 'label'))->toBe(['Nominal', 'As Found', 'Deviation'])
        ->and(array_column($presented['tables'][0]['rows'], 'point_order'))->toBe(['1', '2'])
        ->and($presented['tables'][0]['rows'][0]['cells'])->toBe(['100.5', '100.125', '12.5'])
        ->and($presented['tables'][0]['rows'][1]['cells'])->toBe(['200.0', '—', '—']);
});

it('marks template cells read-only and engineer or calculated cells editable', function () {
    $calibration = presenterModel(Calibration::class, ['calibration_method' => Calibration::METHOD_INTERNAL]);
    $scope = presenterModel(CalibrationScope::class, [
        'calibration_scope_id' => 8,
        'test_profile_version_id' => 20,
        'test_table_snapshot' => presenterDetailSnapshot(),
    ]);
    $result = presenterModel(CalibrationTestResult::class, [
        'calibration_test_result_id' => 70,
        'calibration_scope_id' => 8,
        'test_point_id' => 301,
    ]);
    $scope->setRelation('calibrationTestResults', new Collection([$result]));
    $calibration->setRelation('calibrationScopes', new Collection([$scope]));

    $grid = CalibrationSessionDetailPresenter::entryGrid($calibration, new Collection([
        (object) [
            'calibration_test_result_id' => 70,
            'column_key' => 'reading',
            'data_type' => 'numeric',
            'numeric_value' => '100.125',
            'text_value' => null,
        ],
    ]));
    $firstRow = $grid['tables'][0]['rows'][0]['cells'];

    expect($grid['tables'][0]['columns'][0]['key'])->toBe('nominal')
        ->and($firstRow[0]['editable'])->toBeFalse()
        ->and($firstRow[0]['value_source'])->toBe('template')
        ->and($firstRow[0]['display'])->toBe('100.5')
        ->and($firstRow[1]['editable'])->toBeTrue()
        ->and($firstRow[1]['value_source'])->toBe('engineer')
        ->and($firstRow[1]['input'])->toBe('100.125')
        ->and($firstRow[2]['editable'])->toBeTrue()
        ->and($firstRow[2]['value_source'])->toBe('calculated')
        ->and($grid['tables'][0]['rows'][0]['test_point_id'])->toBe(301)
        ->and($grid['tables'][0]['rows'][1]['test_point_id'])->toBe(302);
});

it('marks a legacy snapshot unavailable instead of substituting live master data', function () {
    $calibration = presenterModel(Calibration::class, ['calibration_method' => Calibration::METHOD_INTERNAL]);
    $scope = presenterModel(CalibrationScope::class, [
        'calibration_scope_id' => 9,
        'test_profile_version_id' => 20,
        'test_table_snapshot' => [
            'schema_version' => 1,
            'duration_format' => 'hh:mm:ss',
            'test_profile_id' => 2,
            'test_profile_version_id' => 20,
            'version_number' => 1,
            'profile_name' => 'Legacy',
            'columns' => [],
            'rows' => [],
        ],
    ]);
    $scope->setRelation('calibrationTestResults', new Collection);
    $calibration->setRelation('calibrationScopes', new Collection([$scope]));

    expect(CalibrationSessionDetailPresenter::testComponents($calibration, new Collection)['tables'][0]['state'])->toBe('unsupported')
        ->and(CalibrationSessionDetailPresenter::showsLegacyScopeDetails($calibration, $scope))->toBeFalse();
});

it('distinguishes frozen resolved and effective requirement modes', function () {
    $calibration = presenterModel(Calibration::class, ['calibration_method' => Calibration::METHOD_INTERNAL]);
    $calibration->setRelation('requirementSnapshots', new Collection([
        presenterModel(CalibrationRequirementSnapshot::class, [
            'requirement_category' => InstrumentTypeRequirement::CATEGORY_STANDARDS,
            'frozen_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
            'resolved_mode' => null,
        ]),
        presenterModel(CalibrationRequirementSnapshot::class, [
            'requirement_category' => InstrumentTypeRequirement::CATEGORY_TEST_PROFILE,
            'frozen_mode' => InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
            'resolved_mode' => InstrumentTypeRequirement::MODE_OPTIONAL,
        ]),
    ]));

    expect(CalibrationSessionDetailPresenter::requirementModes($calibration))->toBe([
        [
            'category' => 'standards',
            'frozen' => 'required',
            'resolved' => '—',
            'effective' => 'required',
        ],
        [
            'category' => 'test_profile',
            'frozen' => 'not_configured',
            'resolved' => 'optional',
            'effective' => 'optional',
        ],
    ]);
});

/**
 * @return array<string, mixed>
 */
function presenterDetailSnapshot(): array
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
