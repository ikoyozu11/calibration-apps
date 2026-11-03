<?php

use App\Authorization\ApplicationRoleName;
use App\CalibrationSessions\CalibrationSessionConnection;
use App\CalibrationSessions\RecordCalibrationSessionHttpConnection;
use App\CalibrationSessions\RecordCalibrationStandardUsageException;
use App\CalibrationSessions\ResolveCalibrationRequirementException;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestRefusingPostgresConnector;

afterEach(function (): void {
    Model::preventLazyLoading(false);
});

function usageUiArrange(
    string $frozenMode = InstrumentTypeRequirement::MODE_REQUIRED,
    ?string $resolvedMode = null,
    string $standardsFrozenMode = InstrumentTypeRequirement::MODE_REQUIRED,
    ?string $standardsResolvedMode = null,
    bool $seedCatalog = true,
): void {
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();
    insertCalibrationSessionPinningUiRequirements(
        $frozenMode,
        $resolvedMode,
        $standardsFrozenMode,
        $standardsResolvedMode,
    );

    if ($seedCatalog) {
        seedCalibrationSessionStandardUsageCatalog();
    }
}

function usageUiAdd(int $standardId, string $range = '', string $unit = '', string $purpose = '', string $remarks = ''): mixed
{
    return calibrationSessionPinningUiPage()
        ->set('usageStandardId', (string) $standardId)
        ->set('newUsageRange', $range)
        ->set('newUsageUnit', $unit)
        ->set('newUsagePurpose', $purpose)
        ->set('newUsageRemarks', $remarks)
        ->call('addStandardUsage');
}

/**
 * @param  list<int>  $standardIds
 */
function usageUiSave(array $standardIds): mixed
{
    return calibrationSessionPinningUiPage()
        ->set('draftStandardIds', array_map(static fn (int $standardId): string => (string) $standardId, $standardIds))
        ->call('saveStandards');
}

function usageUiRow(int $usageId): ?object
{
    return DB::table('calibration_standard_usage')
        ->where('calibration_standard_usage_id', $usageId)
        ->first();
}

it('lets an administrator add an assigned standard', function () {
    usageUiArrange();
    actingAsRole(ApplicationRoleName::Administrator);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Add Standard')
        ->assertSee('Pressure Gauge')
        ->assertSee('STD-5');

    usageUiAdd(5, '', '', 'Working standard', 'Bench A')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification');

    expect(usageUiRow(1))->toMatchArray([
        'calibration_id' => 5,
        'standard_id' => 5,
        'usage_order' => 1,
        'standard_range' => '0-100',
        'standard_unit' => 'kPa',
        'usage_purpose' => 'Working standard',
        'remarks' => 'Bench A',
        'snapshot_standard_code' => 'STD-5',
        'snapshot_instrument_name' => 'Pressure Gauge',
        'snapshot_type_specification' => '0.1 class',
        'snapshot_serial_number' => 'SN-5',
        'standard_instrument_id' => null,
    ])
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('provides a close button for the add standard modal', function () {
    usageUiArrange();
    actingAsRole(ApplicationRoleName::Administrator);

    calibrationSessionPinningUiPage()
        ->call('openAddStandardModal')
        ->assertSee('Add Standard')
        ->assertSee('aria-label="Close Add Standard"', false)
        ->call('closeAddStandardModal')
        ->assertDontSee('aria-label="Close Add Standard"', false);
});

it('lets an engineer add an assigned standard', function () {
    usageUiArrange();
    actingAsRole(ApplicationRoleName::Engineer);

    usageUiAdd(5)->assertHasNoErrors();

    expect(usageUiRow(1)?->standard_id)->toBe(5)
        ->and(usageUiRow(1)?->snapshot_standard_code)->toBe('STD-5');
});

it('forbids a viewer from adding, editing, or removing standard usage', function () {
    usageUiArrange();
    insertCalibrationSessionStandardUsage();
    actingAsRole(ApplicationRoleName::Viewer);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Pressure Gauge')
        ->assertDontSee('Save Standards')
        ->assertDontSee('Add Standard')
        ->assertDontSee('Record Standard Usage')
        ->assertDontSee('saveStandards')
        ->assertDontSee('addStandardUsage')
        ->assertDontSee('updateStandardUsage')
        ->assertDontSee('removeStandardUsage');

    calibrationSessionPinningUiPage()->call('saveStandards')->assertForbidden();
    calibrationSessionPinningUiPage()->call('openAddStandardModal')->assertForbidden();
    calibrationSessionPinningUiPage()->call('addStandardUsage')->assertForbidden();
    calibrationSessionPinningUiPage()->call('updateStandardUsage', 1)->assertForbidden();
    calibrationSessionPinningUiPage()->call('askToRemoveStandardUsage', 1)->assertForbidden();
    calibrationSessionPinningUiPage()->call('removeStandardUsage')->assertForbidden();
    calibrationSessionPinningUiPage()->call('removeDraftStandard', 5)->assertForbidden();

    expect(DB::table('calibration_standard_usage')->count())->toBe(1)
        ->and(usageUiRow(1)?->standard_range)->toBe('0-100');
});

it('redirects anonymous users away from session detail without writes', function () {
    usageUiArrange();
    Auth::logout();
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))->assertRedirect(route('login'));

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('rejects standard usage changes on an external session', function () {
    usageUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'calibration_method' => Calibration::METHOD_EXTERNAL,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertDontSee('Save Standards')
        ->assertDontSee('Add Standard');

    usageUiAdd(5)
        ->assertHasErrors(['addStandardUsage' => RecordCalibrationStandardUsageException::invalidCalibrationMethod()->getMessage()]);

    expect(DB::table('calibration_standard_usage')->count())->toBe(0);
});

it('keeps completed sessions read-only for standard usage', function () {
    usageUiArrange();
    insertCalibrationSessionStandardUsage();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_COMPLETED,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Completed')
        ->assertDontSee('Save Standards')
        ->assertDontSee('Add Standard')
        ->assertDontSee('Record Standard Usage');

    usageUiAdd(6)
        ->assertHasErrors(['addStandardUsage' => RecordCalibrationStandardUsageException::invalidCalibrationStatus()->getMessage()]);

    expect(DB::table('calibration_standard_usage')->count())->toBe(1);
});

it('keeps cancelled sessions read-only for standard usage', function () {
    usageUiArrange();
    insertCalibrationSessionStandardUsage();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_CANCELLED,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Cancelled')
        ->assertDontSee('Save Standards')
        ->assertDontSee('Add Standard');

    calibrationSessionPinningUiPage()
        ->set('removingUsageId', 1)
        ->call('removeStandardUsage')
        ->assertHasErrors(['removeStandardUsage' => RecordCalibrationStandardUsageException::invalidCalibrationStatus()->getMessage()]);

    expect(DB::table('calibration_standard_usage')->count())->toBe(1);
});

it('saves standards on a not-configured snapshot without resolving it', function () {
    usageUiArrange(
        standardsFrozenMode: InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
    );

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Add Standard')
        ->assertDontSee('Apply Standards Mode')
        ->assertDontSee(RecordCalibrationStandardUsageException::unresolvedStandardsMode()->getMessage());

    usageUiSave([5])->assertHasNoErrors();

    expect(usageUiRow(1)?->standard_id)->toBe(5)
        ->and(DB::table('calibration_requirement_snapshot')
            ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_STANDARDS)
            ->value('frozen_mode'))->toBe(InstrumentTypeRequirement::MODE_NOT_CONFIGURED)
        ->and(DB::table('calibration_requirement_snapshot')
            ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_STANDARDS)
            ->value('resolved_mode'))->toBeNull();
});

it('resolves an unresolved standards requirement to required', function () {
    usageUiArrange(standardsFrozenMode: InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('standardsResolvedMode', InstrumentTypeRequirement::MODE_REQUIRED)
        ->set('standardsResolutionReason', 'required for this job')
        ->call('resolveStandardsRequirement')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification')
        ->assertDontSee('Apply Standards Mode');

    $snapshot = DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_STANDARDS)
        ->first();

    expect($snapshot->frozen_mode)->toBe(InstrumentTypeRequirement::MODE_NOT_CONFIGURED)
        ->and($snapshot->resolved_mode)->toBe(InstrumentTypeRequirement::MODE_REQUIRED)
        ->and($snapshot->resolved_reason)->toBe('required for this job');
});

it('resolves an unresolved standards requirement to optional', function () {
    usageUiArrange(standardsFrozenMode: InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('standardsResolvedMode', InstrumentTypeRequirement::MODE_OPTIONAL)
        ->set('standardsResolutionReason', 'optional for this job')
        ->call('resolveStandardsRequirement')
        ->assertHasNoErrors();

    expect(DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_STANDARDS)
        ->value('resolved_mode'))->toBe(InstrumentTypeRequirement::MODE_OPTIONAL);
});

it('rejects standards resolution without a reason', function () {
    usageUiArrange(standardsFrozenMode: InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('standardsResolvedMode', InstrumentTypeRequirement::MODE_REQUIRED)
        ->set('standardsResolutionReason', '')
        ->call('resolveStandardsRequirement')
        ->assertHasErrors('standardsResolutionReason');

    expect(DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_STANDARDS)
        ->value('resolved_mode'))->toBeNull();
});

it('keeps a frozen required standards mode unchanged', function () {
    usageUiArrange();
    mapStartCalibrationHttpActor();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertDontSee('Apply Standards Mode');

    calibrationSessionPinningUiPage()
        ->set('standardsResolvedMode', InstrumentTypeRequirement::MODE_OPTIONAL)
        ->set('standardsResolutionReason', 'should not apply')
        ->call('resolveStandardsRequirement')
        ->assertHasErrors(['standardsResolvedMode' => ResolveCalibrationRequirementException::frozenModeNotEligible()->getMessage()]);

    $snapshot = DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_STANDARDS)
        ->first();

    expect($snapshot->frozen_mode)->toBe(InstrumentTypeRequirement::MODE_REQUIRED)
        ->and($snapshot->resolved_mode)->toBeNull();
});

it('keeps a frozen optional standards mode unchanged', function () {
    usageUiArrange(standardsFrozenMode: InstrumentTypeRequirement::MODE_OPTIONAL);
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('standardsResolvedMode', InstrumentTypeRequirement::MODE_REQUIRED)
        ->set('standardsResolutionReason', 'should not apply')
        ->call('resolveStandardsRequirement')
        ->assertHasErrors(['standardsResolvedMode' => ResolveCalibrationRequirementException::frozenModeNotEligible()->getMessage()]);

    expect(DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_STANDARDS)
        ->value('frozen_mode'))->toBe(InstrumentTypeRequirement::MODE_OPTIONAL)
        ->and(DB::table('calibration_requirement_snapshot')
            ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_STANDARDS)
            ->value('resolved_mode'))->toBeNull();
});

it('does not auto-resolve an unresolved standards requirement', function () {
    usageUiArrange(standardsFrozenMode: InstrumentTypeRequirement::MODE_NOT_CONFIGURED);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Add Standard')
        ->assertDontSee('Apply Standards Mode');

    expect(DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_STANDARDS)
        ->value('resolved_mode'))->toBeNull();
});

it('lists assigned standards for the session instrument type and excludes others', function () {
    usageUiArrange();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Pressure Gauge')
        ->assertSee('STD-5')
        ->assertSee('Temperature Probe')
        ->assertSee('STD-6')
        ->assertDontSee('Unassigned Calibrator')
        ->assertDontSee('STD-7')
        ->assertDontSee('Other Type Standard')
        ->assertDontSee('STD-8');

    $ids = collect(calibrationSessionPinningUiPage()->instance()->displayedStandards())->pluck('standard_id')->all();

    expect($ids)->toBe([5, 6]);
});

it('excludes standards already recorded on the session', function () {
    usageUiArrange();
    usageUiSave([5])->assertHasNoErrors();

    $page = calibrationSessionPinningUiPage();
    $page->call('openAddStandardModal');
    $ids = collect($page->instance()->searchableStandards())->pluck('standard_id')->all();

    expect(collect($page->instance()->displayedStandards())->pluck('standard_id')->all())->toBe([5])
        ->and($ids)->not->toContain(5);
});

it('keeps global master standards available after assigned rows are saved', function () {
    usageUiArrange();
    usageUiSave([5, 6])->assertHasNoErrors();

    $page = calibrationSessionPinningUiPage();
    $page->call('openAddStandardModal');

    expect(collect($page->instance()->searchableStandards())->pluck('standard_id')->all())->toBe([7, 8]);
});

it('applies assignment default range and unit when those fields are left empty', function () {
    usageUiArrange();

    usageUiAdd(5)->assertHasNoErrors();

    expect(usageUiRow(1)?->standard_range)->toBe('0-100')
        ->and(usageUiRow(1)?->standard_unit)->toBe('kPa')
        ->and(DB::table('instrument_type_standard_assignment')->where('standard_id', 5)->value('default_range'))->toBe('0-100');
});

it('stores an explicit range and unit instead of the assignment defaults', function () {
    usageUiArrange();

    usageUiAdd(5, '1-5', 'psi')->assertHasNoErrors();

    expect(usageUiRow(1)?->standard_range)->toBe('1-5')
        ->and(usageUiRow(1)?->standard_unit)->toBe('psi')
        ->and(DB::table('instrument_type_standard_assignment')->where('standard_id', 5)->value('default_range'))->toBe('0-100');
});

it('rejects a duplicate standard usage without modifying the master catalog', function () {
    usageUiArrange();
    $master = (array) DB::table('standard_master')->where('standard_id', 5)->first();
    $assignment = (array) DB::table('instrument_type_standard_assignment')->where('instrument_type_standard_assignment_id', 1)->first();

    usageUiAdd(5)->assertHasNoErrors();
    usageUiAdd(5)
        ->assertHasErrors(['addStandardUsage' => RecordCalibrationStandardUsageException::duplicateStandardUsage()->getMessage()]);

    expect(DB::table('calibration_standard_usage')->count())->toBe(1)
        ->and((array) DB::table('standard_master')->where('standard_id', 5)->first())->toBe($master)
        ->and((array) DB::table('instrument_type_standard_assignment')->where('instrument_type_standard_assignment_id', 1)->first())->toBe($assignment);
});

it('rejects an unassigned standard', function () {
    usageUiArrange();

    usageUiAdd(7)
        ->assertHasErrors(['addStandardUsage' => RecordCalibrationStandardUsageException::missingAssignment()->getMessage()]);

    expect(DB::table('calibration_standard_usage')->count())->toBe(0);
});

it('rejects add without a standard selection', function () {
    usageUiArrange();

    calibrationSessionPinningUiPage()
        ->set('usageStandardId', '')
        ->call('addStandardUsage')
        ->assertHasErrors('usageStandardId');

    expect(DB::table('calibration_standard_usage')->count())->toBe(0);
});

it('updates range, unit, purpose, and remarks without changing identity', function () {
    usageUiArrange();
    usageUiAdd(5, '1-5', 'psi', 'Initial', 'First')->assertHasNoErrors();
    usageUiAdd(6)->assertHasNoErrors();
    $other = (array) usageUiRow(2);

    calibrationSessionPinningUiPage()
        ->set('usageDetails.1.range', '2-8')
        ->set('usageDetails.1.unit', 'mbar')
        ->set('usageDetails.1.purpose', 'Adjusted')
        ->set('usageDetails.1.remarks', 'Second')
        ->call('updateStandardUsage', 1)
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification');

    expect(usageUiRow(1))->toMatchArray([
        'standard_range' => '2-8',
        'standard_unit' => 'mbar',
        'usage_purpose' => 'Adjusted',
        'remarks' => 'Second',
        'standard_id' => 5,
        'snapshot_standard_code' => 'STD-5',
        'snapshot_instrument_name' => 'Pressure Gauge',
        'snapshot_type_specification' => '0.1 class',
        'snapshot_serial_number' => 'SN-5',
    ])
        ->and((array) usageUiRow(2))->toBe($other);
});

it('clears nullable usage details when update values are empty', function () {
    usageUiArrange();
    usageUiAdd(5, '1-5', 'psi', 'Initial', 'First')->assertHasNoErrors();

    calibrationSessionPinningUiPage()
        ->set('usageDetails.1.range', '')
        ->set('usageDetails.1.unit', '')
        ->set('usageDetails.1.purpose', '')
        ->set('usageDetails.1.remarks', '')
        ->call('updateStandardUsage', 1)
        ->assertHasNoErrors();

    expect(usageUiRow(1))->toMatchArray([
        'standard_range' => null,
        'standard_unit' => null,
        'usage_purpose' => null,
        'remarks' => null,
        'standard_id' => 5,
        'snapshot_standard_code' => 'STD-5',
    ]);
});

it('removes a usage, the final required usage, and compacts remaining order', function () {
    usageUiArrange();
    insertCalibrationSessionStandardAssignment(4, 11, 7, '1-2', 'L/s', 3);
    usageUiAdd(5)->assertHasNoErrors();
    usageUiAdd(6)->assertHasNoErrors();
    usageUiAdd(7)->assertHasNoErrors();
    $masterCount = DB::table('standard_master')->count();
    $assignmentCount = DB::table('instrument_type_standard_assignment')->count();

    calibrationSessionPinningUiPage()
        ->set('removingUsageId', 2)
        ->call('removeStandardUsage')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification');

    expect(DB::table('calibration_standard_usage')->orderBy('usage_order')->pluck('calibration_standard_usage_id')->all())->toBe([1, 3])
        ->and(DB::table('calibration_standard_usage')->orderBy('usage_order')->pluck('usage_order')->all())->toBe([1, 2])
        ->and(usageUiRow(3)?->standard_id)->toBe(7)
        ->and(DB::table('standard_master')->count())->toBe($masterCount)
        ->and(DB::table('instrument_type_standard_assignment')->count())->toBe($assignmentCount);

    calibrationSessionPinningUiPage()
        ->set('removingUsageId', 1)
        ->call('removeStandardUsage')
        ->assertHasNoErrors();
    calibrationSessionPinningUiPage()
        ->set('removingUsageId', 3)
        ->call('removeStandardUsage')
        ->assertHasNoErrors();

    expect(DB::table('calibration_standard_usage')->count())->toBe(0)
        ->and(DB::table('calibration')->where('calibration_id', 5)->value('session_status'))->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and(DB::table('standard_master')->count())->toBe($masterCount)
        ->and(DB::table('instrument_type_standard_assignment')->count())->toBe($assignmentCount);
});

it('protects standard usage belonging to another session', function () {
    usageUiArrange();
    usageUiAdd(5)->assertHasNoErrors();
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
    insertCalibrationSessionStandardUsage([
        'calibration_standard_usage_id' => 90,
        'calibration_id' => 41,
        'standard_id' => 6,
        'usage_order' => 1,
        'snapshot_standard_code' => 'STD-6',
        'snapshot_instrument_name' => 'Temperature Probe',
    ]);

    calibrationSessionPinningUiPage()
        ->set('removingUsageId', 90)
        ->call('removeStandardUsage')
        ->assertHasErrors(['removeStandardUsage' => RecordCalibrationStandardUsageException::foreignSessionUsage()->getMessage()]);

    expect(usageUiRow(90)?->calibration_id)->toBe(41)
        ->and(DB::table('calibration_standard_usage')->where('calibration_id', 5)->count())->toBe(1);
});

it('displays frozen snapshot identity after the master standard changes', function () {
    usageUiArrange();
    usageUiAdd(5)->assertHasNoErrors();

    DB::table('standard_master')->where('standard_id', 5)->update([
        'standard_code' => 'STD-X',
        'instrument_name' => 'Renamed Gauge',
        'type_specification' => 'Changed',
        'serial_number' => 'SN-X',
        'updated_at' => '2026-10-22 09:00:00',
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Pressure Gauge')
        ->assertSee('STD-5')
        ->assertSee('0.1 class')
        ->assertSee('SN-5')
        ->assertDontSee('Renamed Gauge')
        ->assertDontSee('STD-X');
});

it('supports a recorded usage whose standard_instrument_id is null', function () {
    usageUiArrange();
    insertCalibrationSessionStandardUsage([
        'standard_instrument_id' => null,
        'snapshot_instrument_name' => 'Frozen Standard',
        'snapshot_standard_code' => 'STD-9',
        'snapshot_type_specification' => 'Class E2',
        'snapshot_serial_number' => 'SN-STD',
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Frozen Standard')
        ->assertSee('STD-9')
        ->assertSee('Class E2')
        ->assertSee('SN-STD');
});

it('shows the no usage empty state for viewers', function () {
    usageUiArrange();
    actingAsRole(ApplicationRoleName::Viewer);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('No standards used.')
        ->assertDontSee('Save Standards')
        ->assertDontSee('Add Standard');
});

it('does not write on a session detail get request', function () {
    usageUiArrange();
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))->assertOk()->assertSee('Add Standard');

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('fails closed when http standard usage cannot use a postgresql write connection', function () {
    usageUiArrange();

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
        ->set('draftStandardIds', ['5'])
        ->call('saveStandards')
        ->assertHasErrors(['saveStandards' => 'Recording this calibration session is not available in this environment.']);

    expect(DB::table('calibration_standard_usage')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('keeps pinning, requirement resolution, and manual result entry available', function () {
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();
    insertCalibrationSessionPinningUiRequirements(InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    seedCalibrationSessionPinningUiActiveTable();
    seedCalibrationSessionStandardUsageCatalog();
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

    usageUiAdd(5)->assertHasNoErrors();

    expect(usageUiRow(1)?->standard_id)->toBe(5);
});

it('preloads assigned standards on a new session without writing usage rows', function () {
    usageUiArrange();

    $page = calibrationSessionPinningUiPage();

    expect(collect($page->instance()->displayedStandards())->pluck('standard_id')->all())->toBe([5, 6])
        ->and(DB::table('calibration_standard_usage')->count())->toBe(0);
});

it('saves preloaded assigned standards to the session', function () {
    usageUiArrange();

    usageUiSave([5, 6])
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification');

    expect(DB::table('calibration_standard_usage')->orderBy('usage_order')->pluck('standard_id')->all())->toBe([5, 6])
        ->and(usageUiRow(1)?->snapshot_instrument_name)->toBe('Pressure Gauge')
        ->and(usageUiRow(2)?->snapshot_instrument_name)->toBe('Temperature Probe');
});

it('filters the add-standard catalog by name, code, or serial number', function () {
    usageUiArrange();

    $page = calibrationSessionPinningUiPage()
        ->call('openAddStandardModal')
        ->assertSee('Add Selected Standards')
        ->set('standardSearch', 'Unassigned');

    expect(collect($page->instance()->searchableStandards())->pluck('standard_id')->all())->toBe([7]);

    $page->set('standardSearch', 'SN-8');

    expect(collect($page->instance()->searchableStandards())->pluck('standard_id')->all())->toBe([8]);
});

it('adds selected master standards and saves them immediately', function () {
    usageUiArrange();

    calibrationSessionPinningUiPage()
        ->call('openAddStandardModal')
        ->set('modalStandardIds', ['7'])
        ->call('addSelectedStandards')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification');

    expect(DB::table('calibration_standard_usage')->orderBy('usage_order')->pluck('standard_id')->all())->toBe([5, 6, 7])
        ->and(DB::table('instrument_type_standard_assignment')->where('standard_id', 7)->count())->toBe(0);
});

it('ignores duplicate draft selections when saving', function () {
    usageUiArrange();

    usageUiSave([5, 5, 6])->assertHasNoErrors();

    expect(DB::table('calibration_standard_usage')->count())->toBe(2)
        ->and(DB::table('calibration_standard_usage')->orderBy('usage_order')->pluck('standard_id')->all())->toBe([5, 6]);
});

it('removes a standard from the current session immediately', function () {
    usageUiArrange();
    usageUiSave([5, 6])->assertHasNoErrors();
    $masterCount = DB::table('standard_master')->count();
    $assignmentCount = DB::table('instrument_type_standard_assignment')->count();

    calibrationSessionPinningUiPage()
        ->call('askToRemoveDraftStandard', 5)
        ->assertDispatched('instruments-desk-confirm-standard-removal')
        ->call('removeDraftStandard', 5)
        ->assertHasNoErrors()
        ->assertDontSee('Pressure Gauge')
        ->assertDispatched('instruments-desk-notification');

    expect(DB::table('calibration_standard_usage')->pluck('standard_id')->all())->toBe([6])
        ->and(DB::table('standard_master')->count())->toBe($masterCount)
        ->and(DB::table('instrument_type_standard_assignment')->count())->toBe($assignmentCount);
});

it('does not duplicate rows when the same standards are saved again', function () {
    usageUiArrange();

    usageUiSave([5, 6])->assertHasNoErrors();
    $first = (array) usageUiRow(1);

    usageUiSave([5, 6])->assertHasNoErrors();

    expect(DB::table('calibration_standard_usage')->count())->toBe(2)
        ->and((array) usageUiRow(1))->toBe($first);
});

it('rejects saveStandards on a completed session', function () {
    usageUiArrange();
    insertCalibrationSessionStandardUsage();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_COMPLETED,
    ]);

    usageUiSave([6])
        ->assertHasErrors(['saveStandards' => RecordCalibrationStandardUsageException::invalidCalibrationStatus()->getMessage()]);

    expect(DB::table('calibration_standard_usage')->count())->toBe(1)
        ->and(usageUiRow(1)?->standard_id)->toBe(5);
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
