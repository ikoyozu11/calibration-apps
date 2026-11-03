<?php

use App\Authorization\ApplicationRoleName;
use App\CalibrationSessions\CalibrationSessionConnection;
use App\CalibrationSessions\CalibrationSessionDetailPresenter;
use App\CalibrationSessions\ConfirmEnvironmentConfigurationException;
use App\CalibrationSessions\RecordCalibrationEnvironmentMeasurementException;
use App\CalibrationSessions\RecordCalibrationSessionHttpConnection;
use App\CalibrationSessions\ResolveCalibrationRequirementException;
use App\CalibrationSessions\ResolveDomainActorException;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestRefusingPostgresConnector;

afterEach(function (): void {
    Model::preventLazyLoading(false);
});

function environmentUiArrange(
    string $frozenMode = InstrumentTypeRequirement::MODE_REQUIRED,
    ?string $resolvedMode = null,
    string $standardsFrozenMode = InstrumentTypeRequirement::MODE_REQUIRED,
    ?string $standardsResolvedMode = null,
    string $activityFrozenMode = InstrumentTypeRequirement::MODE_REQUIRED,
    ?string $activityResolvedMode = null,
    string $environmentFrozenMode = InstrumentTypeRequirement::MODE_REQUIRED,
    ?string $environmentResolvedMode = null,
    bool $seedCatalog = true,
): void {
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();
    insertCalibrationSessionPinningUiRequirements(
        $frozenMode,
        $resolvedMode,
        $standardsFrozenMode,
        $standardsResolvedMode,
        $activityFrozenMode,
        $activityResolvedMode,
        $environmentFrozenMode,
        $environmentResolvedMode,
    );

    if ($seedCatalog) {
        seedCalibrationSessionEnvironmentCatalog();
    }
}

function environmentUiSave(int $parameterId, string $value): mixed
{
    return calibrationSessionPinningUiPage()
        ->set('environmentInputs.'.$parameterId, $value)
        ->call('saveEnvironmentMeasurement', $parameterId);
}

function environmentUiValue(int $parameterId): ?object
{
    return DB::table('calibration_environment_value')
        ->where('parameter_id', $parameterId)
        ->first();
}

it('renders frozen parameter identity, units, types, and required indicators', function () {
    environmentUiArrange();
    insertCalibrationSessionEnvironmentValue();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Temperature')
        ->assertSee('temperature')
        ->assertSee('°C')
        ->assertSee('numeric')
        ->assertSee('required')
        ->assertSee('Relative Humidity')
        ->assertSee('relative_humidity')
        ->assertSee('%RH')
        ->assertSee('Condition')
        ->assertSee('optional')
        ->assertSee('23.5')
        ->assertSee('Required value missing')
        ->assertSee('Save Measurement')
        ->assertDontSee('Confirmation Reason')
        ->assertDontSee('Confirm Environment Configuration')
        ->assertDontSee('Environment configuration confirmed')
        ->assertDontSee('No environment data found.');
});

it('shows persisted measurements to a viewer without write controls', function () {
    environmentUiArrange();
    insertCalibrationSessionEnvironmentValue();
    actingAsRole(ApplicationRoleName::Viewer);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Temperature')
        ->assertSee('23.5')
        ->assertDontSee('Save Measurement')
        ->assertDontSee('Apply Environment Mode')
        ->assertDontSee('Confirm Environment Configuration')
        ->assertDontSee('saveEnvironmentMeasurement')
        ->assertDontSee('confirmEnvironmentConfiguration');
});

it('keeps the legacy environment fallback when snapshots are unavailable', function () {
    environmentUiArrange(seedCatalog: false);
    Schema::dropIfExists('calibration_environment_parameter_snapshot');
    Schema::dropIfExists('calibration_environment_value');
    DB::table('calibration_environment')->insert([
        'calibration_environment_id' => 1,
        'calibration_id' => 5,
        'created_at' => '2026-10-20 09:00:00',
        'temperature_value' => 21.25,
        'temperature_unit' => '°C',
        'humidity_value' => 55,
        'humidity_unit' => '%RH',
        'pressure_value' => null,
        'pressure_unit' => null,
        'remarks' => 'Legacy room note',
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Environment')
        ->assertSee('21.25')
        ->assertSee('Legacy room note')
        ->assertDontSee('Save Measurement')
        ->assertDontSee('Required value missing')
        ->assertDontSee('No environment parameters frozen for this session.');
});

it('does not render a second hardcoded environment form beside frozen snapshots', function () {
    environmentUiArrange();
    DB::table('calibration_environment')->insert([
        'calibration_environment_id' => 1,
        'calibration_id' => 5,
        'created_at' => '2026-10-20 09:00:00',
        'temperature_value' => 99,
        'temperature_unit' => 'F',
        'humidity_value' => null,
        'humidity_unit' => null,
        'pressure_value' => null,
        'pressure_unit' => null,
        'remarks' => 'Legacy leftover',
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Temperature')
        ->assertDontSee('Legacy leftover')
        ->assertDontSee('No environment data found.');
});

it('lets an administrator record a numeric measurement', function () {
    environmentUiArrange();
    actingAsRole(ApplicationRoleName::Administrator);

    environmentUiSave(1, '23.50')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification');

    expect(environmentUiValue(1))->toMatchArray([
        'calibration_id' => 5,
        'parameter_id' => 1,
        'numeric_value' => '23.50',
        'text_value' => null,
    ])
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('lets an engineer record a numeric measurement', function () {
    environmentUiArrange();
    actingAsRole(ApplicationRoleName::Engineer);

    environmentUiSave(2, '48.25')->assertHasNoErrors();

    expect(environmentUiValue(2)?->numeric_value)->toBe('48.25');
});

it('forbids a viewer from recording, resolving, or confirming environment', function () {
    environmentUiArrange();
    actingAsRole(ApplicationRoleName::Viewer);

    calibrationSessionPinningUiPage()->call('saveEnvironmentMeasurement', 1)->assertForbidden();
    calibrationSessionPinningUiPage()->call('resolveEnvironmentRequirement')->assertForbidden();
    calibrationSessionPinningUiPage()->call('confirmEnvironmentConfiguration')->assertForbidden();

    expect(DB::table('calibration_environment_value')->count())->toBe(0)
        ->and(DB::table('calibration_requirement_snapshot')
            ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
            ->value('configuration_confirmed_at'))->toBeNull();
});

it('redirects anonymous users away from session detail without writes', function () {
    environmentUiArrange();
    Auth::logout();
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))->assertRedirect(route('login'));

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('rejects environment writes on an external session', function () {
    environmentUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'calibration_method' => Calibration::METHOD_EXTERNAL,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertDontSee('Save Measurement')
        ->assertDontSee('Confirm Environment Configuration');

    environmentUiSave(1, '23.5')
        ->assertHasErrors(['environmentInputs.1' => RecordCalibrationEnvironmentMeasurementException::unsupportedCalibrationMethod()->getMessage()]);

    expect(DB::table('calibration_environment_value')->count())->toBe(0);
});

it('keeps completed sessions read-only for environment measurements', function () {
    environmentUiArrange();
    insertCalibrationSessionEnvironmentValue();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_COMPLETED,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Completed')
        ->assertDontSee('Save Measurement');

    environmentUiSave(1, '30')
        ->assertHasErrors(['environmentInputs.1' => RecordCalibrationEnvironmentMeasurementException::invalidSessionStatus()->getMessage()]);

    expect(environmentUiValue(1)?->numeric_value)->toBe('23.5');
});

it('keeps cancelled sessions read-only for environment measurements', function () {
    environmentUiArrange();
    insertCalibrationSessionEnvironmentValue();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_CANCELLED,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Cancelled')
        ->assertDontSee('Save Measurement');

    environmentUiSave(2, '40')
        ->assertHasErrors(['environmentInputs.2' => RecordCalibrationEnvironmentMeasurementException::invalidSessionStatus()->getMessage()]);

    expect(DB::table('calibration_environment_value')->count())->toBe(1);
});

it('rejects an unknown parameter and a parameter outside the frozen snapshot', function () {
    environmentUiArrange();

    environmentUiSave(99, '23')
        ->assertHasErrors(['environmentInputs.99' => RecordCalibrationEnvironmentMeasurementException::unknownSessionParameter()->getMessage()]);
    environmentUiSave(4, '101')
        ->assertHasErrors(['environmentInputs.4' => RecordCalibrationEnvironmentMeasurementException::unknownSessionParameter()->getMessage()]);

    expect(DB::table('calibration_environment_value')->count())->toBe(0)
        ->and(DB::table('environment_parameter')->where('parameter_id', 4)->value('parameter_code'))->toBe('pressure');
});

it('accepts a numeric decimal and retains its precision', function () {
    environmentUiArrange();

    environmentUiSave(1, '0.00010')->assertHasNoErrors();

    expect(environmentUiValue(1)?->numeric_value)->toBe('0.00010');
});

it('rejects an invalid numeric measurement', function () {
    environmentUiArrange();

    environmentUiSave(1, '25,5')
        ->assertHasErrors(['environmentInputs.1' => RecordCalibrationEnvironmentMeasurementException::invalidNumericValue()->getMessage()])
        ->assertDispatched('instruments-desk-notification');

    expect(DB::table('calibration_environment_value')->count())->toBe(0);
});

it('accepts a text measurement and updates it later', function () {
    environmentUiArrange();

    environmentUiSave(3, 'Stable')
        ->assertHasNoErrors();
    environmentUiSave(3, '  Lab note  ')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification');

    expect(environmentUiValue(3))->toMatchArray([
        'parameter_id' => 3,
        'numeric_value' => null,
        'text_value' => '  Lab note  ',
    ])
        ->and(DB::table('calibration_environment_value')->count())->toBe(1);
});

it('clears a measurement when the value is empty', function () {
    environmentUiArrange();
    insertCalibrationSessionEnvironmentValue();

    environmentUiSave(1, '')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification');

    expect(environmentUiValue(1))->toBeNull()
        ->and(DB::table('calibration')->where('calibration_id', 5)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('does not invent measurements or calculate sibling values', function () {
    environmentUiArrange();
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))->assertOk()->assertSee('Save Measurement');

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(DB::table('calibration_environment_value')->count())->toBe(0);

    environmentUiSave(1, '23.5')->assertHasNoErrors();

    expect(environmentUiValue(2))->toBeNull()
        ->and(environmentUiValue(3))->toBeNull()
        ->and(DB::table('calibration_environment_parameter_snapshot')->count())->toBe(3);
});

it('allows measurements while the environment requirement is not configured', function () {
    environmentUiArrange(environmentFrozenMode: InstrumentTypeRequirement::MODE_NOT_CONFIGURED);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertDontSee('Apply Environment Mode')
        ->assertSee('Save Measurement')
        ->assertDontSee('skip');

    environmentUiSave(1, '23.5')->assertHasNoErrors();

    expect(DB::table('calibration_environment_value')->count())->toBe(1)
        ->and(DB::table('calibration_requirement_snapshot')
            ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
            ->value('resolved_mode'))->toBeNull();
});

it('resolves an unresolved environment requirement to required', function () {
    environmentUiArrange(environmentFrozenMode: InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('environmentResolvedMode', InstrumentTypeRequirement::MODE_REQUIRED)
        ->set('environmentResolutionReason', 'required for this job')
        ->call('resolveEnvironmentRequirement')
        ->assertHasNoErrors()
        ->assertSee('The environment requirement was resolved.')
        ->assertSee('Save Measurement')
        ->assertDontSee('Apply Environment Mode');

    $snapshot = DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
        ->first();

    expect($snapshot->frozen_mode)->toBe(InstrumentTypeRequirement::MODE_NOT_CONFIGURED)
        ->and($snapshot->resolved_mode)->toBe(InstrumentTypeRequirement::MODE_REQUIRED)
        ->and($snapshot->resolved_reason)->toBe('required for this job')
        ->and(DB::table('instrument_type_environment_parameter')->where('parameter_id', 1)->value('requirement_mode'))->toBe(InstrumentTypeRequirement::MODE_REQUIRED);
});

it('resolves an unresolved environment requirement to optional', function () {
    environmentUiArrange(environmentFrozenMode: InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('environmentResolvedMode', InstrumentTypeRequirement::MODE_OPTIONAL)
        ->set('environmentResolutionReason', 'optional for this job')
        ->call('resolveEnvironmentRequirement')
        ->assertHasNoErrors();

    expect(DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
        ->value('resolved_mode'))->toBe(InstrumentTypeRequirement::MODE_OPTIONAL);
});

it('rejects environment resolution without a reason', function () {
    environmentUiArrange(environmentFrozenMode: InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('environmentResolvedMode', InstrumentTypeRequirement::MODE_REQUIRED)
        ->set('environmentResolutionReason', '')
        ->call('resolveEnvironmentRequirement')
        ->assertHasErrors('environmentResolutionReason');

    expect(DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
        ->value('resolved_mode'))->toBeNull();
});

it('does not auto-resolve an unresolved environment requirement', function () {
    environmentUiArrange(environmentFrozenMode: InstrumentTypeRequirement::MODE_NOT_CONFIGURED);

    $this->get(route('calibrations.show', 5))->assertOk()->assertDontSee('Apply Environment Mode');

    expect(DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
        ->value('resolved_mode'))->toBeNull();
});

it('rejects resolving environment to required without a required frozen parameter', function () {
    environmentUiArrange(environmentFrozenMode: InstrumentTypeRequirement::MODE_NOT_CONFIGURED, seedCatalog: false);
    insertCalibrationSessionEnvironmentParameter(3, 'condition', 'Condition', 'note', 'text');
    insertCalibrationSessionEnvironmentSnapshot([
        'parameter_id' => 3,
        'parameter_code' => 'condition',
        'parameter_name' => 'Condition',
        'unit' => 'note',
        'data_type' => 'text',
        'requirement_mode' => InstrumentTypeRequirement::MODE_OPTIONAL,
        'display_order' => 1,
    ]);
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('environmentResolvedMode', InstrumentTypeRequirement::MODE_REQUIRED)
        ->set('environmentResolutionReason', 'need required')
        ->call('resolveEnvironmentRequirement')
        ->assertHasErrors(['environmentResolvedMode' => ResolveCalibrationRequirementException::missingRequiredEnvironmentParameter()->getMessage()]);

    expect(DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
        ->value('resolved_mode'))->toBeNull();
});

it('confirms environment configuration explicitly with an actor and reason', function () {
    environmentUiArrange();
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('environmentConfirmationReason', 'bench inspected')
        ->call('confirmEnvironmentConfiguration')
        ->assertHasNoErrors()
        ->assertSee('Environment configuration was confirmed.')
        ->assertDontSee('Confirmation Reason')
        ->assertDontSee('Confirm Environment Configuration');

    $snapshot = DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
        ->first();

    expect($snapshot->configuration_confirmed_reason)->toBe('bench inspected')
        ->and($snapshot->configuration_confirmed_by_user_id)->toBe(90)
        ->and($snapshot->configuration_confirmed_at)->not->toBeNull();
});

it('rejects confirmation without a reason or actor mapping', function () {
    environmentUiArrange();

    calibrationSessionPinningUiPage()
        ->set('environmentConfirmationReason', '')
        ->call('confirmEnvironmentConfiguration')
        ->assertHasErrors('environmentConfirmationReason');

    mapStartCalibrationHttpActor();
    calibrationSessionPinningUiPage()
        ->set('environmentConfirmationReason', '')
        ->call('confirmEnvironmentConfiguration')
        ->assertHasErrors('environmentConfirmationReason');

    expect(DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
        ->value('configuration_confirmed_at'))->toBeNull();

    Auth::logout();
    actingAsRole(ApplicationRoleName::Administrator);

    calibrationSessionPinningUiPage()
        ->set('environmentConfirmationReason', 'no mapping')
        ->call('confirmEnvironmentConfiguration')
        ->assertHasErrors(['confirmEnvironmentConfiguration' => ResolveDomainActorException::unmapped()->getMessage()]);
});

it('rejects a second confirmation and does not auto-confirm on measurement', function () {
    environmentUiArrange();
    mapStartCalibrationHttpActor();

    environmentUiSave(1, '23.5')->assertHasNoErrors();

    expect(DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
        ->value('configuration_confirmed_at'))->toBeNull();

    calibrationSessionPinningUiPage()
        ->set('environmentConfirmationReason', 'first confirm')
        ->call('confirmEnvironmentConfiguration')
        ->assertHasNoErrors();

    calibrationSessionPinningUiPage()
        ->set('environmentConfirmationReason', 'second confirm')
        ->call('confirmEnvironmentConfiguration')
        ->assertHasErrors(['confirmEnvironmentConfiguration' => ConfirmEnvironmentConfigurationException::alreadyConfirmed()->getMessage()]);

    expect(DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
        ->value('configuration_confirmed_reason'))->toBe('first confirm');
});

it('fails closed when http environment writes cannot use a postgresql write connection', function () {
    environmentUiArrange();

    app()->instance(RecordCalibrationSessionHttpConnection::class, new RecordCalibrationSessionHttpConnection(
        app(CalibrationSessionConnection::class),
        new Repository([
            'database' => [
                'default' => 'pgsql',
                'connections' => [
                    'pgsql' => [
                        'driver' => 'pgsql',
                        'database' => 'calibration_db',
                        'url' => null,
                    ],
                ],
            ],
        ]),
    ));

    Livewire::test('pages::calibrations.show', [
        'calibration' => 5,
    ])
        ->set('environmentInputs.1', '23.5')
        ->call('saveEnvironmentMeasurement', 1)
        ->assertHasErrors(['environmentInputs.1' => 'Recording this calibration session is not available in this environment.']);

    expect(DB::table('calibration_environment_value')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('keeps pinning, result entry, standards, and activity available', function () {
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();
    insertCalibrationSessionPinningUiRequirements(InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    seedCalibrationSessionPinningUiActiveTable();
    seedCalibrationSessionStandardUsageCatalog();
    seedCalibrationSessionActivityCatalog();
    seedCalibrationSessionEnvironmentCatalog();
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('resolvedMode', InstrumentTypeRequirement::MODE_REQUIRED)
        ->set('resolutionReason', 'needed at the bench')
        ->call('resolveTestProfileRequirement')
        ->assertHasNoErrors();

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertHasNoErrors()
        ->assertSee('Table 20');

    insertCalibrationSessionEntryPinnedScope();
    $cellKey = CalibrationSessionDetailPresenter::resultCellKey(8, 11, 'reading');
    calibrationSessionPinningUiPage()
        ->set('resultInputs.'.$cellKey, '12.25')
        ->call('saveTestResult', 8, 11, 'reading')
        ->assertHasNoErrors();

    calibrationSessionPinningUiPage()
        ->set('usageStandardId', '5')
        ->call('addStandardUsage')
        ->assertHasNoErrors();

    calibrationSessionPinningUiPage()
        ->set('selectedActivityIds', ['10'])
        ->call('saveActivities')
        ->assertHasNoErrors();

    environmentUiSave(1, '23.5')->assertHasNoErrors();

    expect(DB::table('calibration_standard_usage')->count())->toBe(1)
        ->and(DB::table('calibration_activity')->count())->toBe(1)
        ->and(environmentUiValue(1)?->numeric_value)->toBe('23.5')
        ->and(DB::table('calibration_result_value')->where('column_key', 'reading')->value('numeric_value'))->toBe('12.25');
});

it('keeps start calibration free of requirement modes', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();

    Livewire::test('pages::instruments.start-calibration', [
        'instrument' => 1,
        'instrumentType' => 11,
    ])
        ->assertSee('Start Calibration')
        ->assertDontSee('Requirement Modes');
});
