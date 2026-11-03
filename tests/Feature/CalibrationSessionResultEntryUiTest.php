<?php

use App\Authorization\ApplicationRoleName;
use App\CalibrationSessions\CalibrationSessionConnection;
use App\CalibrationSessions\CalibrationSessionDetailPresenter;
use App\CalibrationSessions\RecordCalibrationSessionHttpConnection;
use App\CalibrationSessions\RecordCalibrationTestResultException;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestRefusingPostgresConnector;

function entryUiArrange(
    string $frozenMode = InstrumentTypeRequirement::MODE_REQUIRED,
    ?string $resolvedMode = null,
    bool $pinSnapshot = true,
): void {
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();
    insertCalibrationSessionPinningUiRequirements($frozenMode, $resolvedMode);

    if ($pinSnapshot) {
        insertCalibrationSessionEntryPinnedScope();
    }
}

function entryUiSave(string $columnKey, string $value, int $testPointId = 11, int $scopeId = 8): mixed
{
    $key = CalibrationSessionDetailPresenter::resultCellKey($scopeId, $testPointId, $columnKey);

    return calibrationSessionPinningUiPage()
        ->set('resultInputs', [$key => $value])
        ->call('saveTestResult', $scopeId, $testPointId, $columnKey);
}

function entryUiCell(string $columnKey, int $testPointId = 11): ?object
{
    $resultId = DB::table('calibration_test_result')
        ->where('test_point_id', $testPointId)
        ->value('calibration_test_result_id');

    if ($resultId === null) {
        return null;
    }

    return DB::table('calibration_result_value')
        ->where('calibration_test_result_id', $resultId)
        ->where('column_key', $columnKey)
        ->first();
}

it('lets an administrator edit pinned test components', function () {
    entryUiArrange();
    actingAsRole(ApplicationRoleName::Administrator);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Frozen Entry')
        ->assertSee('Save')
        ->assertSee('Reading');

    entryUiSave('reading', '12.25')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification');

    expect(entryUiCell('reading')?->numeric_value)->toBe('12.25')
        ->and(entryUiCell('reading')?->value_source)->toBe('engineer')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('asks for confirmation before clearing an existing test result', function () {
    entryUiArrange();
    actingAsRole(ApplicationRoleName::Engineer);

    $key = CalibrationSessionDetailPresenter::resultCellKey(8, 11, 'reading');

    entryUiSave('reading', '12.25')
        ->assertHasNoErrors()
        ->set('resultInputs.'.$key, '')
        ->call('requestSaveTestResult', 8, 11, 'reading')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-confirm-test-result-clear');

    expect(entryUiCell('reading')?->numeric_value)->toBe('12.25');
});

it('lets an engineer edit pinned test components', function () {
    entryUiArrange();
    actingAsRole(ApplicationRoleName::Engineer);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Save');

    entryUiSave('reading', '8.5')->assertHasNoErrors();

    expect(entryUiCell('reading')?->numeric_value)->toBe('8.5');
});

it('forbids a viewer from editing test results', function () {
    entryUiArrange();
    actingAsRole(ApplicationRoleName::Viewer);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('100.5')
        ->assertDontSee('requestSaveTestResult');

    calibrationSessionPinningUiPage()
        ->call('saveTestResult', 8, 11, 'reading')
        ->assertForbidden();

    expect(DB::table('calibration_result_value')->count())->toBe(0);
});

it('redirects anonymous users away from session detail without writes', function () {
    entryUiArrange();
    Auth::logout();
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))->assertRedirect(route('login'));

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('rejects result editing on an external session', function () {
    entryUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'calibration_method' => Calibration::METHOD_EXTERNAL,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertDontSee('requestSaveTestResult');

    entryUiSave('reading', '1')
        ->assertHasErrors(['resultInputs.'.CalibrationSessionDetailPresenter::resultCellKey(8, 11, 'reading') => RecordCalibrationTestResultException::unsupportedCalibrationMethod()->getMessage()]);

    expect(DB::table('calibration_result_value')->count())->toBe(0);
});

it('keeps completed sessions read-only', function () {
    entryUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_COMPLETED,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Completed')
        ->assertDontSee('requestSaveTestResult');

    entryUiSave('reading', '1')
        ->assertHasErrors(['resultInputs.'.CalibrationSessionDetailPresenter::resultCellKey(8, 11, 'reading') => RecordCalibrationTestResultException::invalidSessionStatus()->getMessage()]);

    expect(DB::table('calibration_result_value')->count())->toBe(0);
});

it('keeps cancelled sessions read-only', function () {
    entryUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_CANCELLED,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Cancelled')
        ->assertDontSee('requestSaveTestResult');

    entryUiSave('reading', '1')
        ->assertHasErrors(['resultInputs.'.CalibrationSessionDetailPresenter::resultCellKey(8, 11, 'reading') => RecordCalibrationTestResultException::invalidSessionStatus()->getMessage()]);

    expect(DB::table('calibration_result_value')->count())->toBe(0);
});

it('allows result editing when the test profile requirement is not configured', function () {
    entryUiArrange(InstrumentTypeRequirement::MODE_NOT_CONFIGURED);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertDontSee('Resolve Requirement')
        ->assertSee('requestSaveTestResult');

    entryUiSave('reading', '1')
        ->assertHasNoErrors();

    expect(DB::table('calibration_result_value')->where('column_key', 'reading')->count())->toBe(1);
});

it('renders the schema v2 grid in frozen column and point order with labels and units', function () {
    entryUiArrange();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSeeInOrder(['Nominal', 'Reading', 'Correction', 'Average', 'Note', 'Elapsed'])
        ->assertSeeInOrder(['1', '2'])
        ->assertSee('(g)')
        ->assertSee('100.5')
        ->assertSee('200.0')
        ->assertSee('Frozen Entry');
});

it('supports multiple pinned scopes', function () {
    entryUiArrange();
    $second = calibrationSessionEntrySnapshot(6, 8);
    $second['display_title'] = 'Second Frozen';
    insertCalibrationSessionEntryPinnedScope($second, 9, 6, 8);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Frozen Entry')
        ->assertSee('Second Frozen');

    entryUiSave('reading', '3.5', 11, 9)->assertHasNoErrors();

    expect(entryUiCell('reading')?->numeric_value)->toBe('3.5')
        ->and((int) DB::table('calibration_test_result')->where('calibration_scope_id', 9)->count())->toBe(1)
        ->and((int) DB::table('calibration_test_result')->where('calibration_scope_id', 8)->count())->toBe(0);
});

it('does not follow master table changes after the snapshot is frozen', function () {
    entryUiArrange();
    DB::table('test_profile')->insert([
        'test_profile_id' => 5,
        'instrument_type_id' => 11,
        'profile_name' => 'LIVE MASTER',
        'display_title' => 'LIVE TITLE',
        'created_at' => '2026-10-22 07:00:00',
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Frozen Entry')
        ->assertDontSee('LIVE TITLE')
        ->assertDontSee('LIVE MASTER');
});

it('keeps an invalid snapshot unavailable and rejects writes', function () {
    entryUiArrange(pinSnapshot: false);
    insertCalibrationSessionEntryPinnedScope([
        'schema_version' => 99,
        'duration_format' => 'hh:mm:ss',
        'test_profile_id' => 5,
        'test_profile_version_id' => 7,
        'version_number' => 1,
        'profile_name' => 'Broken',
        'columns' => [],
        'rows' => [],
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('This Test Table snapshot is unavailable.')
        ->assertDontSee('requestSaveTestResult');

    entryUiSave('reading', '1')
        ->assertHasErrors(['resultInputs.'.CalibrationSessionDetailPresenter::resultCellKey(8, 11, 'reading') => RecordCalibrationTestResultException::unsupportedSchemaVersion()->getMessage()]);
});

it('keeps a legacy snapshot read-only', function () {
    entryUiArrange(pinSnapshot: false);
    insertCalibrationSessionEntryPinnedScope([
        'schema_version' => 1,
        'duration_format' => 'hh:mm:ss',
        'test_profile_id' => 5,
        'test_profile_version_id' => 7,
        'version_number' => 1,
        'profile_name' => 'Legacy',
        'columns' => [],
        'rows' => [],
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('This Test Table snapshot is unavailable.')
        ->assertDontSee('requestSaveTestResult');
});

it('keeps template values read-only and rejects template writes', function () {
    entryUiArrange();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('100.5');

    entryUiSave('nominal', '1')
        ->assertHasErrors(['resultInputs.'.CalibrationSessionDetailPresenter::resultCellKey(8, 11, 'nominal') => RecordCalibrationTestResultException::templateColumnNotEditable()->getMessage()]);

    expect(DB::table('calibration_result_value')->where('column_key', 'nominal')->count())->toBe(0);
});

it('saves engineer and calculated columns without evaluating formulas', function () {
    entryUiArrange();

    entryUiSave('reading', '10')->assertHasNoErrors();
    entryUiSave('average', '4.5')->assertHasNoErrors();

    $parent = DB::table('calibration_test_result')->first();

    expect(entryUiCell('reading')?->numeric_value)->toBe('10')
        ->and(entryUiCell('average')?->numeric_value)->toBe('4.5')
        ->and(entryUiCell('average')?->value_source)->toBe('engineer')
        ->and($parent->average_value)->toBeNull()
        ->and((float) $parent->correction_value)->toBe(90.5)
        ->and($parent->standard_value)->toBeNull()
        ->and(DB::table('calibration_result_value')->where('column_key', 'deviation')->count())->toBe(0)
        ->and(DB::table('calibration_result_value')->where('value_source', 'calculated')->count())->toBe(1);
});

it('calculates and persists configured standard-minus-reading correction', function () {
    entryUiArrange();

    entryUiSave('reading', '12.345')->assertHasNoErrors();

    expect((string) entryUiCell('correction')?->numeric_value)->toBe('88.155')
        ->and(entryUiCell('correction')?->value_source)->toBe('calculated')
        ->and((string) DB::table('calibration_test_result')->value('correction_value'))->toBe('88.155');

    calibrationSessionPinningUiPage()
        ->assertSee('88.155')
        ->assertDontSee("requestSaveTestResult(8, 11, 'correction')");

    entryUiSave('reading', '13')->assertHasNoErrors();

    expect((string) entryUiCell('correction')?->numeric_value)->toBe('87.500')
        ->and((float) DB::table('calibration_test_result')->value('correction_value'))->toBe(87.5);

    entryUiSave('reading', '')->assertHasNoErrors();

    expect(entryUiCell('correction'))->toBeNull()
        ->and(DB::table('calibration_test_result')->value('correction_value'))->toBeNull();
});

it('rejects direct writes to configured standard-minus-reading correction', function () {
    entryUiArrange();

    entryUiSave('correction', '1')
        ->assertHasErrors([
            'resultInputs.'.CalibrationSessionDetailPresenter::resultCellKey(8, 11, 'correction') => RecordCalibrationTestResultException::calculatedColumnNotEditable()->getMessage(),
        ]);
});

it('saves numeric text and duration values and can update them', function () {
    entryUiArrange();

    entryUiSave('reading', '12.25')->assertHasNoErrors();
    entryUiSave('note', 'stable')->assertHasNoErrors();
    entryUiSave('elapsed', '00:01:30')->assertHasNoErrors();

    expect(entryUiCell('reading')?->numeric_value)->toBe('12.25')
        ->and(entryUiCell('note')?->text_value)->toBe('stable')
        ->and(entryUiCell('elapsed')?->numeric_value)->toBe('90');

    entryUiSave('reading', '13')->assertHasNoErrors();
    entryUiSave('elapsed', '120')->assertHasNoErrors();

    expect(entryUiCell('reading')?->numeric_value)->toBe('13')
        ->and(entryUiCell('elapsed')?->numeric_value)->toBe('120')
        ->and(DB::table('calibration_result_value')->where('column_key', 'reading')->count())->toBe(1);
});

it('clears a previously recorded required cell during in progress', function () {
    entryUiArrange();
    entryUiSave('reading', '12.25')->assertHasNoErrors();

    entryUiSave('reading', '')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification');

    expect(entryUiCell('reading'))->toBeNull()
        ->and(DB::table('calibration_test_result')->count())->toBe(0)
        ->and(DB::table('calibration_scope')->count())->toBe(1);
});

it('leaves unrelated cells unchanged when one cell is saved', function () {
    entryUiArrange();
    entryUiSave('reading', '12.25')->assertHasNoErrors();
    entryUiSave('note', 'keep')->assertHasNoErrors();
    entryUiSave('average', '3')->assertHasNoErrors();

    expect(entryUiCell('reading')?->numeric_value)->toBe('12.25')
        ->and(entryUiCell('note')?->text_value)->toBe('keep')
        ->and(entryUiCell('average')?->numeric_value)->toBe('3');
});

it('reloads the persisted value after a successful save', function () {
    entryUiArrange();
    $key = CalibrationSessionDetailPresenter::resultCellKey(8, 11, 'reading');

    calibrationSessionPinningUiPage()
        ->set('resultInputs', [$key => '12.2500'])
        ->call('saveTestResult', 8, 11, 'reading')
        ->assertSet('resultInputs.'.$key, '12.2500')
        ->assertDispatched('instruments-desk-notification');

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('12.2500');
});

it('does not alter a persisted value when a later save fails', function () {
    entryUiArrange();
    entryUiSave('reading', '12.25')->assertHasNoErrors();

    entryUiSave('reading', '12,25')
        ->assertHasErrors(['resultInputs.'.CalibrationSessionDetailPresenter::resultCellKey(8, 11, 'reading') => RecordCalibrationTestResultException::invalidNumericValue()->getMessage()]);

    expect(entryUiCell('reading')?->numeric_value)->toBe('12.25');
});

it('rejects invalid duration and blank text without writing', function () {
    entryUiArrange();

    entryUiSave('elapsed', '00:99:99')
        ->assertHasErrors(['resultInputs.'.CalibrationSessionDetailPresenter::resultCellKey(8, 11, 'elapsed') => RecordCalibrationTestResultException::invalidDurationValue()->getMessage()]);

    entryUiSave('note', '   ')
        ->assertHasErrors(['resultInputs.'.CalibrationSessionDetailPresenter::resultCellKey(8, 11, 'note') => RecordCalibrationTestResultException::invalidTextValue()->getMessage()]);

    expect(DB::table('calibration_result_value')->count())->toBe(0);
});

it('rejects an unknown column, wrong scope, and wrong test point', function () {
    entryUiArrange();

    entryUiSave('missing', '1')
        ->assertHasErrors(['resultInputs.'.CalibrationSessionDetailPresenter::resultCellKey(8, 11, 'missing') => RecordCalibrationTestResultException::unknownFrozenColumn()->getMessage()]);

    entryUiSave('reading', '1', 11, 99)
        ->assertHasErrors(['resultInputs.'.CalibrationSessionDetailPresenter::resultCellKey(99, 11, 'reading') => RecordCalibrationTestResultException::unknownScope()->getMessage()]);

    entryUiSave('reading', '1', 99)
        ->assertHasErrors(['resultInputs.'.CalibrationSessionDetailPresenter::resultCellKey(8, 99, 'reading') => RecordCalibrationTestResultException::unknownFrozenRow()->getMessage()]);

    expect(DB::table('calibration_result_value')->count())->toBe(0);
});

it('does not write on a session detail get request', function () {
    entryUiArrange();
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))->assertOk()->assertSee('Save');

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('fails closed when http record cannot use a postgresql write connection', function () {
    entryUiArrange();

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

    $key = CalibrationSessionDetailPresenter::resultCellKey(8, 11, 'reading');

    Livewire::test('pages::calibrations.show', [
        'calibration' => 5,
    ])
        ->set('resultInputs', [$key => '1'])
        ->call('saveTestResult', 8, 11, 'reading')
        ->assertHasErrors(['resultInputs.'.$key => 'Recording this calibration session is not available in this environment.']);

    expect(DB::table('calibration_result_value')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('keeps pinning and requirement resolution available beside result entry', function () {
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();
    insertCalibrationSessionPinningUiRequirements(InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    seedCalibrationSessionPinningUiActiveTable();
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

    $scopeId = (int) DB::table('calibration_scope')->value('calibration_scope_id');
    $key = CalibrationSessionDetailPresenter::resultCellKey($scopeId, 2001, 'reading');

    calibrationSessionPinningUiPage()
        ->set('resultInputs', [$key => '7.5'])
        ->call('saveTestResult', $scopeId, 2001, 'reading')
        ->assertHasNoErrors();

    expect(entryUiCell('reading', 2001)?->numeric_value)->toBe('7.5');
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
