<?php

use App\Authorization\ApplicationRoleName;
use App\CalibrationSessions\CalibrationSessionConnection;
use App\CalibrationSessions\CalibrationSessionDetailPresenter;
use App\CalibrationSessions\RecordCalibrationActivityException;
use App\CalibrationSessions\RecordCalibrationSessionHttpConnection;
use App\CalibrationSessions\ResolveCalibrationRequirementException;
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

function activityUiArrange(
    string $frozenMode = InstrumentTypeRequirement::MODE_REQUIRED,
    ?string $resolvedMode = null,
    string $standardsFrozenMode = InstrumentTypeRequirement::MODE_REQUIRED,
    ?string $standardsResolvedMode = null,
    string $activityFrozenMode = InstrumentTypeRequirement::MODE_REQUIRED,
    ?string $activityResolvedMode = null,
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
    );

    if ($seedCatalog) {
        seedCalibrationSessionActivityCatalog();
    }
}

function activityUiAdd(int $activityId, string $description = '', string $result = ''): mixed
{
    return calibrationSessionPinningUiPage()
        ->set('selectedActivityId', (string) $activityId)
        ->set('newActivityDescription', $description)
        ->set('newActivityResult', $result)
        ->call('addActivity');
}

/**
 * @param  list<int>  $activityIds
 */
function activityUiSave(array $activityIds): mixed
{
    return calibrationSessionPinningUiPage()
        ->set('selectedActivityIds', array_map(static fn (int $activityId): string => (string) $activityId, $activityIds))
        ->call('saveActivities');
}

function activityUiRow(int $activityRowId): ?object
{
    return DB::table('calibration_activity')
        ->where('calibration_activity_id', $activityRowId)
        ->first();
}

it('lets an administrator add an activity from the global catalog', function () {
    activityUiArrange();
    actingAsRole(ApplicationRoleName::Administrator);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Add Activity')
        ->assertSee('No activities recorded.')
        ->assertDontSee('Apply Activity Mode')
        ->assertDontSee('Record Activity');

    calibrationSessionPinningUiPage()
        ->call('openAddActivityModal')
        ->assertSee('Cleaning')
        ->assertSee('Adjustment')
        ->assertSee('Add Selected Activities')
        ->set('modalActivityIds', ['10'])
        ->call('addSelectedActivities')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification');

    expect(activityUiRow(1))->toMatchArray([
        'calibration_id' => 5,
        'activity_id' => 10,
        'activity_name' => 'Cleaning',
        'activity_type' => 'Cleaning',
        'activity_description' => null,
        'activity_result' => null,
        'activity_order' => 1,
    ])
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('lets an engineer add an activity from the global catalog', function () {
    activityUiArrange();
    actingAsRole(ApplicationRoleName::Engineer);

    activityUiSave([10])->assertHasNoErrors();

    expect(activityUiRow(1)?->activity_id)->toBe(10)
        ->and(activityUiRow(1)?->activity_name)->toBe('Cleaning')
        ->and(activityUiRow(1)?->activity_type)->toBe('Cleaning');
});

it('forbids a viewer from adding, editing, or removing activities', function () {
    activityUiArrange();
    insertCalibrationSessionActivity();
    actingAsRole(ApplicationRoleName::Viewer);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Cleaning')
        ->assertDontSee('Add Activity')
        ->assertDontSee('saveActivities')
        ->assertDontSee('Record Activity')
        ->assertDontSee('Apply Activity Mode');

    calibrationSessionPinningUiPage()->call('saveActivities')->assertForbidden();
    calibrationSessionPinningUiPage()->call('addActivity')->assertForbidden();
    calibrationSessionPinningUiPage()->call('updateActivity', 1)->assertForbidden();
    calibrationSessionPinningUiPage()->call('askToRemoveActivity', 1)->assertForbidden();
    calibrationSessionPinningUiPage()->call('removeActivity')->assertForbidden();

    expect(DB::table('calibration_activity')->count())->toBe(1)
        ->and(activityUiRow(1)?->activity_description)->toBe('Wiped the bench');
});

it('redirects anonymous users away from session detail without writes', function () {
    activityUiArrange();
    Auth::logout();
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))->assertRedirect(route('login'));

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('rejects activity changes on an external session', function () {
    activityUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'calibration_method' => Calibration::METHOD_EXTERNAL,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertDontSee('Save Activities')
        ->assertDontSee('Record Activity');

    activityUiSave([10])
        ->assertHasErrors(['saveActivities' => RecordCalibrationActivityException::unsupportedCalibrationMethod()->getMessage()]);

    expect(DB::table('calibration_activity')->count())->toBe(0);
});

it('keeps completed sessions read-only for activities', function () {
    activityUiArrange();
    insertCalibrationSessionActivity();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_COMPLETED,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Completed')
        ->assertDontSee('Save Activities')
        ->assertDontSee('Record Activity');

    activityUiSave([11])
        ->assertHasErrors(['saveActivities' => RecordCalibrationActivityException::invalidSessionStatus()->getMessage()]);

    expect(DB::table('calibration_activity')->count())->toBe(1);
});

it('keeps cancelled sessions read-only for activities', function () {
    activityUiArrange();
    insertCalibrationSessionActivity();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_CANCELLED,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Cancelled')
        ->assertDontSee('Save Activities')
        ->assertDontSee('Record Activity');

    activityUiSave([])
        ->assertHasErrors(['saveActivities' => RecordCalibrationActivityException::invalidSessionStatus()->getMessage()]);

    expect(DB::table('calibration_activity')->count())->toBe(1);
});

it('saves activities on a not-configured snapshot without resolving it', function () {
    activityUiArrange(activityFrozenMode: InstrumentTypeRequirement::MODE_NOT_CONFIGURED);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Add Activity')
        ->assertDontSee('Apply Activity Mode')
        ->assertDontSee('Record Activity');

    activityUiSave([10])->assertHasNoErrors();

    expect(activityUiRow(1)?->activity_id)->toBe(10)
        ->and(DB::table('calibration_requirement_snapshot')
            ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ACTIVITY)
            ->value('frozen_mode'))->toBe(InstrumentTypeRequirement::MODE_NOT_CONFIGURED)
        ->and(DB::table('calibration_requirement_snapshot')
            ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ACTIVITY)
            ->value('resolved_mode'))->toBeNull();
});

it('resolves an unresolved activity requirement to required', function () {
    activityUiArrange(activityFrozenMode: InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('activityResolvedMode', InstrumentTypeRequirement::MODE_REQUIRED)
        ->set('activityResolutionReason', 'required for this job')
        ->call('resolveActivityRequirement')
        ->assertHasNoErrors()
        ->assertSee('Add Activity')
        ->assertDontSee('Apply Activity Mode');

    $snapshot = DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ACTIVITY)
        ->first();

    expect($snapshot->frozen_mode)->toBe(InstrumentTypeRequirement::MODE_NOT_CONFIGURED)
        ->and($snapshot->resolved_mode)->toBe(InstrumentTypeRequirement::MODE_REQUIRED)
        ->and($snapshot->resolved_reason)->toBe('required for this job');
});

it('resolves an unresolved activity requirement to optional', function () {
    activityUiArrange(activityFrozenMode: InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('activityResolvedMode', InstrumentTypeRequirement::MODE_OPTIONAL)
        ->set('activityResolutionReason', 'optional for this job')
        ->call('resolveActivityRequirement')
        ->assertHasNoErrors();

    expect(DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ACTIVITY)
        ->value('resolved_mode'))->toBe(InstrumentTypeRequirement::MODE_OPTIONAL);
});

it('rejects activity resolution without a reason', function () {
    activityUiArrange(activityFrozenMode: InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('activityResolvedMode', InstrumentTypeRequirement::MODE_REQUIRED)
        ->set('activityResolutionReason', '')
        ->call('resolveActivityRequirement')
        ->assertHasErrors('activityResolutionReason');

    expect(DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ACTIVITY)
        ->value('resolved_mode'))->toBeNull();
});

it('keeps a frozen required activity mode unchanged', function () {
    activityUiArrange();
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->call('openAddActivityModal')
        ->assertDontSee('Apply Activity Mode');

    calibrationSessionPinningUiPage()
        ->set('activityResolvedMode', InstrumentTypeRequirement::MODE_OPTIONAL)
        ->set('activityResolutionReason', 'should not apply')
        ->call('resolveActivityRequirement')
        ->assertHasErrors(['activityResolvedMode' => ResolveCalibrationRequirementException::frozenModeNotEligible()->getMessage()]);

    $snapshot = DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ACTIVITY)
        ->first();

    expect($snapshot->frozen_mode)->toBe(InstrumentTypeRequirement::MODE_REQUIRED)
        ->and($snapshot->resolved_mode)->toBeNull();
});

it('keeps a frozen optional activity mode unchanged', function () {
    activityUiArrange(activityFrozenMode: InstrumentTypeRequirement::MODE_OPTIONAL);
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('activityResolvedMode', InstrumentTypeRequirement::MODE_REQUIRED)
        ->set('activityResolutionReason', 'should not apply')
        ->call('resolveActivityRequirement')
        ->assertHasErrors(['activityResolvedMode' => ResolveCalibrationRequirementException::frozenModeNotEligible()->getMessage()]);

    expect(DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ACTIVITY)
        ->value('frozen_mode'))->toBe(InstrumentTypeRequirement::MODE_OPTIONAL)
        ->and(DB::table('calibration_requirement_snapshot')
            ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ACTIVITY)
            ->value('resolved_mode'))->toBeNull();
});

it('does not auto-resolve an unresolved activity requirement', function () {
    activityUiArrange(activityFrozenMode: InstrumentTypeRequirement::MODE_NOT_CONFIGURED);

    $this->get(route('calibrations.show', 5))->assertOk()->assertDontSee('Apply Activity Mode');

    expect(DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ACTIVITY)
        ->value('resolved_mode'))->toBeNull()
        ->and(DB::table('calibration_activity')->count())->toBe(0);
});

it('lists active global activities and hides inactive ones', function () {
    activityUiArrange();

    calibrationSessionPinningUiPage()
        ->call('openAddActivityModal')
        ->assertSee('Cleaning')
        ->assertSee('Adjustment')
        ->assertDontSee('ACT-10')
        ->assertDontSee('ACT-11')
        ->assertDontSee('Retired Check')
        ->assertDontSee('ACT-12');

    $ids = collect(calibrationSessionPinningUiPage()->instance()->selectableActivities())->pluck('activity_id')->all();

    expect($ids)->toBe([10, 11]);
});

it('filters the add activity modal and excludes recorded activities', function () {
    activityUiArrange();
    activityUiSave([10])->assertHasNoErrors();

    calibrationSessionPinningUiPage()
        ->call('openAddActivityModal')
        ->assertDontSee('activity-option-10')
        ->assertSee('Adjustment')
        ->set('activitySearch', 'Adjust')
        ->assertSee('Adjustment')
        ->assertDontSee('activity-option-10')
        ->set('activitySearch', 'does-not-exist')
        ->assertSee('No additional active activities are available.');
});

it('removes a recorded activity through the compact activity list', function () {
    activityUiArrange();
    activityUiSave([10])->assertHasNoErrors();

    calibrationSessionPinningUiPage()
        ->call('askToRemoveActivity', 1)
        ->assertDispatched('instruments-desk-confirm-activity-removal')
        ->set('removingActivityId', 1)
        ->call('removeActivity')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification');

    expect(DB::table('calibration_activity')->count())->toBe(0);
});

it('keeps already recorded activities available for removal', function () {
    activityUiArrange();
    activityUiSave([10])->assertHasNoErrors();

    $ids = collect(calibrationSessionPinningUiPage()->instance()->selectableActivities())->pluck('activity_id')->all();

    expect($ids)->toBe([10, 11])
        ->and(calibrationSessionPinningUiPage()->get('selectedActivityIds'))->toBe(['10']);
});

it('saves multiple selections and then removes one', function () {
    activityUiArrange();

    activityUiSave([10, 11])
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification');

    expect(DB::table('calibration_activity')->orderBy('activity_order')->pluck('activity_id')->all())->toBe([10, 11]);

    activityUiSave([11])->assertHasNoErrors();

    expect(DB::table('calibration_activity')->pluck('activity_id')->all())->toBe([11])
        ->and(DB::table('calibration_activity')->value('activity_order'))->toBe(1);
});

it('does not depend on instrument type assignment for activity candidates', function () {
    activityUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'instrument_type_id' => 99,
    ]);

    $ids = collect(calibrationSessionPinningUiPage()->instance()->candidateActivities())->pluck('activity_id')->all();

    expect($ids)->toBe([10, 11])
        ->and(Schema::hasTable('instrument_type_activity_assignment'))->toBeFalse();
});

it('shows an empty catalog state without inserting activities', function () {
    activityUiArrange(seedCatalog: false);

    calibrationSessionPinningUiPage()
        ->call('openAddActivityModal')
        ->assertSee('No additional active activities are available.');

    expect(DB::table('calibration_activity')->count())->toBe(0)
        ->and(DB::table('calibration_activity_master')->count())->toBe(0);
});

it('rejects a duplicate activity without modifying the master catalog', function () {
    activityUiArrange();
    $master = (array) DB::table('calibration_activity_master')->where('activity_id', 10)->first();

    activityUiSave([10, 10])->assertHasNoErrors();

    expect(DB::table('calibration_activity')->count())->toBe(1)
        ->and(activityUiRow(1)?->activity_id)->toBe(10)
        ->and((array) DB::table('calibration_activity_master')->where('activity_id', 10)->first())->toBe($master);
});

it('rejects an inactive activity master', function () {
    activityUiArrange();

    activityUiSave([12])
        ->assertHasErrors(['saveActivities' => RecordCalibrationActivityException::inactiveActivityMaster()->getMessage()]);

    expect(DB::table('calibration_activity')->count())->toBe(0);
});

it('rejects an unknown activity master', function () {
    activityUiArrange();

    activityUiSave([99])
        ->assertHasErrors(['saveActivities' => RecordCalibrationActivityException::missingActivityMaster()->getMessage()]);

    expect(DB::table('calibration_activity')->count())->toBe(0);
});

it('saves an empty activity selection', function () {
    activityUiArrange();

    activityUiSave([])->assertHasNoErrors();

    expect(DB::table('calibration_activity')->count())->toBe(0);
});

it('updates description and result without changing frozen identity', function () {
    activityUiArrange();
    activityUiAdd(10, 'Initial', 'First')->assertHasNoErrors();
    activityUiAdd(11, 'Keep this text', 'Keep')->assertHasNoErrors();
    $other = (array) activityUiRow(2);

    calibrationSessionPinningUiPage()
        ->set('activityDetails.1.description', 'Adjusted')
        ->set('activityDetails.1.result', 'Second')
        ->call('updateActivity', 1)
        ->assertHasNoErrors();

    expect(activityUiRow(1))->toMatchArray([
        'activity_description' => 'Adjusted',
        'activity_result' => 'Second',
        'activity_id' => 10,
        'activity_name' => 'Cleaning',
        'activity_type' => 'Cleaning',
    ])
        ->and((array) activityUiRow(2))->toBe($other);
});

it('clears nullable activity details when update values are empty', function () {
    activityUiArrange();
    activityUiAdd(10, 'Initial', 'First')->assertHasNoErrors();

    calibrationSessionPinningUiPage()
        ->set('activityDetails.1.description', '')
        ->set('activityDetails.1.result', '')
        ->call('updateActivity', 1)
        ->assertHasNoErrors();

    expect(activityUiRow(1))->toMatchArray([
        'activity_description' => null,
        'activity_result' => null,
        'activity_id' => 10,
        'activity_name' => 'Cleaning',
    ]);
});

it('removes a usage, the final required usage, and compacts remaining order', function () {
    activityUiArrange();
    activityUiAdd(10)->assertHasNoErrors();
    activityUiAdd(11)->assertHasNoErrors();
    $masterCount = DB::table('calibration_activity_master')->count();

    calibrationSessionPinningUiPage()
        ->set('removingActivityId', 1)
        ->call('removeActivity')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification');

    expect(DB::table('calibration_activity')->orderBy('activity_order')->pluck('calibration_activity_id')->all())->toBe([2])
        ->and(DB::table('calibration_activity')->orderBy('activity_order')->pluck('activity_order')->all())->toBe([1])
        ->and(activityUiRow(2)?->activity_id)->toBe(11)
        ->and(DB::table('calibration_activity_master')->count())->toBe($masterCount);

    calibrationSessionPinningUiPage()
        ->set('removingActivityId', 2)
        ->call('removeActivity')
        ->assertHasNoErrors();

    expect(DB::table('calibration_activity')->count())->toBe(0)
        ->and(DB::table('calibration')->where('calibration_id', 5)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and(DB::table('calibration_activity_master')->count())->toBe($masterCount);
});

it('renumbers remaining rows including legacy free-text activities', function () {
    activityUiArrange();
    insertCalibrationSessionActivity([
        'calibration_activity_id' => 1,
        'activity_id' => 10,
        'activity_name' => 'Cleaning',
        'activity_type' => 'Cleaning',
        'activity_order' => 1,
        'activity_description' => 'Catalog',
        'activity_result' => 'Pass',
    ]);
    insertCalibrationSessionActivity([
        'calibration_activity_id' => 2,
        'activity_id' => null,
        'activity_name' => null,
        'activity_type' => 'Legacy Free Text',
        'activity_order' => 2,
        'activity_description' => 'Keep this text',
        'activity_result' => 'Keep',
    ]);

    calibrationSessionPinningUiPage()
        ->set('removingActivityId', 1)
        ->call('removeActivity')
        ->assertHasNoErrors();

    expect(activityUiRow(2))->toMatchArray([
        'activity_id' => null,
        'activity_name' => null,
        'activity_type' => 'Legacy Free Text',
        'activity_description' => 'Keep this text',
        'activity_result' => 'Keep',
        'activity_order' => 1,
    ])
        ->and(DB::table('calibration_activity_master')->where('activity_id', 10)->value('activity_name'))->toBe('Cleaning');
});

it('protects activity belonging to another session', function () {
    activityUiArrange();
    activityUiAdd(10)->assertHasNoErrors();
    DB::table('calibration')->insert([
        'calibration_id' => 41,
        'instrument_id' => 1,
        'instrument_type_id' => 11,
        'calibration_number' => 'CAL.SESS.41',
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'created_at' => '2026-10-20 09:00:00',
        'updated_at' => '2026-10-20 09:00:00',
    ]);
    insertCalibrationSessionActivity([
        'calibration_activity_id' => 90,
        'calibration_id' => 41,
        'activity_id' => 11,
        'activity_name' => 'Adjustment',
        'activity_type' => 'Adjustment',
        'activity_order' => 1,
    ]);

    calibrationSessionPinningUiPage()
        ->set('removingActivityId', 90)
        ->call('removeActivity')
        ->assertHasErrors(['removeActivity' => RecordCalibrationActivityException::foreignSessionActivity()->getMessage()]);

    expect(activityUiRow(90)?->calibration_id)->toBe(41)
        ->and(DB::table('calibration_activity')->where('calibration_id', 5)->count())->toBe(1);
});

it('displays frozen activity identity after the master name changes', function () {
    activityUiArrange();
    activityUiAdd(10)->assertHasNoErrors();

    DB::table('calibration_activity_master')->where('activity_id', 10)->update([
        'activity_code' => 'ACT-X',
        'activity_name' => 'Renamed Cleaning',
        'updated_at' => '2026-10-22 09:00:00',
    ]);

    expect(activityUiRow(1))->toMatchArray([
        'activity_id' => 10,
        'activity_name' => 'Cleaning',
        'activity_type' => 'Cleaning',
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Cleaning')
        ->assertDontSee('Renamed Cleaning')
        ->assertDontSee('ACT-X');
});

it('shows the no activity empty state for viewers', function () {
    activityUiArrange();
    actingAsRole(ApplicationRoleName::Viewer);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('No activities recorded.')
        ->assertDontSee('Save Activities')
        ->assertDontSee('Record Activity');
});

it('does not write on a session detail get request', function () {
    activityUiArrange();
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))->assertOk()->assertSee('Add Activity');

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('fails closed when http activity recording cannot use a postgresql write connection', function () {
    activityUiArrange();

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
        ->set('selectedActivityIds', ['10'])
        ->call('saveActivities')
        ->assertHasErrors(['saveActivities' => 'Recording this calibration session is not available in this environment.']);

    expect(DB::table('calibration_activity')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('keeps pinning, result entry, and standard usage available', function () {
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();
    insertCalibrationSessionPinningUiRequirements(InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    seedCalibrationSessionPinningUiActiveTable();
    seedCalibrationSessionStandardUsageCatalog();
    seedCalibrationSessionActivityCatalog();
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

    activityUiAdd(10, 'Wiped', 'Pass')->assertHasNoErrors();

    expect(DB::table('calibration_standard_usage')->count())->toBe(1)
        ->and(activityUiRow(1)?->activity_id)->toBe(10)
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
