<?php

use App\Authorization\ApplicationCapability;
use App\Authorization\ApplicationRoleName;
use App\CalibrationSessions\CalibrationSessionConnection;
use App\CalibrationSessions\CompleteCalibrationSessionException;
use App\CalibrationSessions\RecordCalibrationSessionHttpConnection;
use App\CalibrationSessions\RecordCalibrationSessionHttpException;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestRefusingPostgresConnector;

afterEach(function (): void {
    Model::preventLazyLoading(false);
});

function completeUiArrange(bool $ready = false): void
{
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();
    insertCalibrationSessionPinningUiRequirements();
    insertCalibrationSessionInternalProvider();

    if ($ready) {
        completeUiMakeReady();
    }
}

function completeUiArrangeOptional(): void
{
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();
    insertCalibrationSessionPinningUiRequirements(
        InstrumentTypeRequirement::MODE_OPTIONAL,
        null,
        InstrumentTypeRequirement::MODE_OPTIONAL,
        null,
        InstrumentTypeRequirement::MODE_OPTIONAL,
        null,
        InstrumentTypeRequirement::MODE_OPTIONAL,
    );
    insertCalibrationSessionInternalProvider();
    applyCalibrationSessionFrozenLocation();
    seedCalibrationSessionEnvironmentCatalog();
    confirmCalibrationSessionEnvironment();
    insertCalibrationSessionEnvironmentValue();
    insertCalibrationSessionEnvironmentValue([
        'parameter_id' => 2,
        'numeric_value' => '55',
    ]);
}

function completeUiMapCurrentActor(int $appUserId = 501): void
{
    $laravelUserId = (int) Auth::id();

    DB::table('app_user')->insert([
        'app_user_id' => $appUserId,
        'is_active' => true,
    ]);
    DB::table('user_app_user_map')->insert([
        'user_app_user_map_id' => $appUserId,
        'laravel_user_id' => $laravelUserId,
        'app_user_id' => $appUserId,
        'status' => 'active',
        'created_at' => '2026-10-22 08:00:00',
        'inactivated_at' => null,
    ]);
    DB::table('calibration')->where('calibration_id', 5)->update([
        'started_by_app_user_id' => $appUserId,
    ]);
}

function completeUiMakeReady(): void
{
    applyCalibrationSessionFrozenLocation();
    insertCalibrationSessionStandardUsage();
    insertCalibrationSessionActivity();
    seedCalibrationSessionEnvironmentCatalog();
    confirmCalibrationSessionEnvironment();
    insertCalibrationSessionEnvironmentValue();
    insertCalibrationSessionEnvironmentValue([
        'parameter_id' => 2,
        'numeric_value' => '55',
    ]);
    insertCalibrationSessionEntryPinnedScope();
    insertCalibrationSessionResultParent(70, 8, 11);
    insertCalibrationSessionResultParent(71, 8, 12);
    insertCalibrationSessionResultCell([
        'calibration_result_value_id' => 701,
        'calibration_test_result_id' => 70,
        'numeric_value' => '100.5',
    ]);
    insertCalibrationSessionResultCell([
        'calibration_result_value_id' => 711,
        'calibration_test_result_id' => 71,
        'numeric_value' => '200.0',
    ]);
    insertCalibrationSessionResultCell([
        'calibration_result_value_id' => 702,
        'calibration_test_result_id' => 70,
        'column_key' => 'reading',
        'value_source' => 'engineer',
        'numeric_value' => '12.25',
    ]);
    insertCalibrationSessionResultCell([
        'calibration_result_value_id' => 712,
        'calibration_test_result_id' => 71,
        'column_key' => 'reading',
        'value_source' => 'engineer',
        'numeric_value' => '22.50',
    ]);
}

function completeUiSubmit(string $date = '2026-10-22', string $due = '2027-10-22'): mixed
{
    return calibrationSessionPinningUiPage()
        ->set('completionDate', $date)
        ->set('completionDue', $due)
        ->call('completeSession');
}

function completeUiRow(): object
{
    return DB::table('calibration')->where('calibration_id', 5)->first();
}

it('shows the internal in-progress complete form without default dates or a green checklist', function () {
    completeUiArrange();
    actingAsRole(ApplicationRoleName::Administrator);

    expect(Gate::allows(ApplicationCapability::SessionComplete->value))->toBeTrue();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Complete Calibration')
        ->assertSee('Calibration Number')
        ->assertSee('Instrument Type')
        ->assertSee('Calibration Method')
        ->assertSee('Session Status')
        ->assertDontSee('Calibration Information')
        ->assertDontSee('Calibrated Types')
        ->assertSee('Calibration Date')
        ->assertSee('Calibration Due')
        ->assertSee('Save Dates')
        ->assertSee('Save Resume')
        ->assertDontSee('ready to complete')
        ->assertDontSee('All requirements met')
        ->assertDontSee('Resolve Requirement')
        ->assertDontSee('External Calibration Vendor (Optional)')
        ->assertDontSee('External Certificate PDF')
        ->assertDontSee('Complete External Calibration')
        ->assertDontSee('confirmed environment configuration')
        ->assertDontSee('Confirmation Reason')
        ->assertDontSee('Confirm Environment Configuration');

    $html = calibrationSessionPinningUiPage()->html();

    expect($html)->toContain('wire:submit="requestCompleteSession"')
        ->and($html)->toContain('id="session-calibration-date"')
        ->and($html)->toContain('id="session-calibration-due"')
        ->and($html)->not->toContain('value="2026-')
        ->and($html)->not->toContain('value="2027-')
        ->and(completeUiRow()->calibration_date)->toBeNull()
        ->and(completeUiRow()->calibration_due)->toBeNull()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('asks for confirmation before completing an internal session', function () {
    completeUiArrange();
    actingAsRole(ApplicationRoleName::Administrator);

    calibrationSessionPinningUiPage()
        ->call('requestCompleteSession')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-confirm-completion');
});

it('cancels an in-progress session from the detail page and preserves its records', function () {
    completeUiArrange(true);
    completeUiMapCurrentActor();
    $before = calibrationSessionPinningUiFingerprint();

    $page = calibrationSessionPinningUiPage()
        ->assertSee('Cancel Calibration')
        ->call('openCancellationModal')
        ->assertDispatched('instruments-desk-confirm-cancellation')
        ->set('cancellationReason', 'Instrument withdrawn from service')
        ->call('cancelSession')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-terminal-success')
        ->assertDontSee('The calibration session has been cancelled.')
        ->assertSee('Cancelled')
        ->assertDontSee('Confirm Cancellation');

    $after = calibrationSessionPinningUiFingerprint();
    expect($after['scopes'])->toBe($before['scopes'])
        ->and($after['results'])->toBe($before['results'])
        ->and($after['values'])->toBe($before['values'])
        ->and(DB::table('calibration')->where('calibration_id', 5)->value('session_status'))->toBe(Calibration::STATUS_CANCELLED)
        ->and(DB::table('calibration')->where('calibration_id', 5)->value('cancelled_reason'))->toBe('Instrument withdrawn from service')
        ->and(DB::table('calibration')->where('calibration_id', 5)->value('cancelled_by_user_id'))->toBe(501)
        ->and($page->instance()->canShowCancelSection())->toBeFalse();
});

it('requires a reason before cancelling and keeps the session in progress', function () {
    completeUiArrange();
    completeUiMapCurrentActor();

    calibrationSessionPinningUiPage()
        ->call('openCancellationModal')
        ->assertDispatched('instruments-desk-confirm-cancellation')
        ->call('cancelSession')
        ->assertHasErrors(['cancellationReason' => 'The cancellation reason field is required.']);

    expect(DB::table('calibration')->where('calibration_id', 5)->value('session_status'))
        ->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('keeps owner-aware cancellation authorization in the browser action', function () {
    completeUiArrange();
    actingAsRole(ApplicationRoleName::Engineer);
    completeUiMapCurrentActor(501);
    DB::table('calibration')->where('calibration_id', 5)->update([
        'started_by_app_user_id' => 999,
    ]);
    calibrationSessionPinningUiPage()
        ->call('openCancellationModal')
        ->assertDispatched('instruments-desk-confirm-cancellation')
        ->set('cancellationReason', 'not my session')
        ->call('cancelSession')
        ->assertHasErrors(['cancelSession' => 'You are not allowed to cancel this calibration session.']);

    expect(DB::table('calibration')->where('calibration_id', 5)->value('session_status'))
        ->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('keeps the complete section read-only for a viewer', function () {
    completeUiArrange();
    actingAsRole(ApplicationRoleName::Viewer);
    $before = calibrationSessionPinningUiFingerprint();

    expect(Gate::allows(ApplicationCapability::SessionComplete->value))->toBeFalse()
        ->and(Gate::allows(ApplicationCapability::HistoryView->value))->toBeTrue();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertDontSee('Complete Calibration');

    expect(calibrationSessionPinningUiPage()->instance()->canCompleteSession())->toBeFalse()
        ->and(calibrationSessionPinningUiPage()->html())->not->toContain('wire:submit="completeSession"');

    calibrationSessionPinningUiPage()
        ->set('completionDate', '2026-10-22')
        ->set('completionDue', '2027-10-22')
        ->call('completeSession')
        ->assertForbidden();

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(completeUiRow()->session_status)->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('hides complete UI for an external session and rejects the write', function () {
    completeUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'calibration_method' => Calibration::METHOD_EXTERNAL,
    ]);
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Complete External Calibration')
        ->assertDontSee('Complete requires a frozen instrument identity and location, Internal Calibration Team')
        ->assertDontSee('>Complete Calibration</button>', false);

    completeUiSubmit()
        ->assertHasErrors(['completeSession' => 'This Complete action is available only for an internal calibration session.']);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('shows a completed internal session as read-only', function () {
    completeUiArrange(true);
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_COMPLETED,
        'calibration_date' => '2026-10-22',
        'calibration_due' => '2027-10-22',
        'action_date' => '2019-01-01',
        'calibration_result' => 'PASS',
        'completed_at' => '2026-10-22 10:00:00',
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Completed')
        ->assertSee('Calibration Information')
        ->assertDontSee('Calibrated Types')
        ->assertSee('2026-10-22')
        ->assertSee('2027-10-22')
        ->assertSee('Action Date')
        ->assertDontSee('2019-01-01')
        ->assertDontSee('Calibration Result')
        ->assertDontSee('Complete Calibration')
        ->assertDontSee('Complete requires a frozen instrument identity and location');
});

it('shows a cancelled internal session as read-only', function () {
    completeUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_CANCELLED,
        'calibration_date' => '2026-10-22',
        'action_date' => '2019-01-01',
        'calibration_result' => 'FAIL',
        'cancelled_reason' => 'Instrument withdrawn from service',
        'cancelled_by_user_id' => 501,
        'cancelled_at' => '2026-10-22 11:30:00',
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Cancelled')
        ->assertSee('Calibration Information')
        ->assertDontSee('Calibrated Types')
        ->assertSee('Cancellation Details')
        ->assertSee('Instrument withdrawn from service')
        ->assertSee('App User #501')
        ->assertSee('2026-10-22 11:30:00')
        ->assertSee('Action Date')
        ->assertDontSee('2019-01-01')
        ->assertDontSee('Calibration Result')
        ->assertDontSee('Complete Calibration')
        ->assertDontSee('Complete requires a frozen instrument identity and location');
});

it('does not write when the complete section is displayed', function () {
    completeUiArrange();
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Complete Calibration');

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('requires an explicit calibration date', function () {
    completeUiArrange(true);
    $before = calibrationSessionPinningUiFingerprint();

    calibrationSessionPinningUiPage()
        ->set('completionDue', '2027-10-22')
        ->call('completeSession')
        ->assertHasErrors(['completionDate' => 'Calibration Date is required.']);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('requires an explicit due date', function () {
    completeUiArrange(true);
    $before = calibrationSessionPinningUiFingerprint();

    calibrationSessionPinningUiPage()
        ->set('completionDate', '2026-10-22')
        ->call('completeSession')
        ->assertHasErrors(['completionDue' => 'Calibration Due is required.']);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('rejects an invalid date format', function () {
    completeUiArrange(true);
    $before = calibrationSessionPinningUiFingerprint();

    completeUiSubmit('22-10-2026', '2027-10-22')
        ->assertHasErrors(['completeSession' => CompleteCalibrationSessionException::invalidDates()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('rejects an impossible calendar date', function () {
    completeUiArrange(true);
    $before = calibrationSessionPinningUiFingerprint();

    completeUiSubmit('2026-02-30', '2027-10-22')
        ->assertHasErrors(['completeSession' => CompleteCalibrationSessionException::invalidDates()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('rejects a due date before the calibration date', function () {
    completeUiArrange(true);
    $before = calibrationSessionPinningUiFingerprint();

    completeUiSubmit('2026-10-22', '2026-10-21')
        ->assertHasErrors(['completeSession' => CompleteCalibrationSessionException::dueBeforeCalibrationDate()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('allows equal calibration and due dates', function () {
    completeUiArrange(true);

    completeUiSubmit('2026-10-22', '2026-10-22')->assertHasNoErrors();

    expect(completeUiRow()->calibration_date)->toBe('2026-10-22')
        ->and(completeUiRow()->calibration_due)->toBe('2026-10-22')
        ->and(completeUiRow()->session_status)->toBe(Calibration::STATUS_COMPLETED);
});

it('persists a later due date without interval math', function () {
    completeUiArrange(true);

    completeUiSubmit('2026-01-15', '2028-03-01')->assertHasNoErrors();

    expect(completeUiRow()->calibration_date)->toBe('2026-01-15')
        ->and(completeUiRow()->calibration_due)->toBe('2028-03-01');
});

it('completes a valid internal session and leaves recorded data unchanged', function () {
    completeUiArrange(true);
    actingAsRole(ApplicationRoleName::Administrator);
    $before = calibrationSessionPinningUiFingerprint();

    completeUiSubmit()
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-terminal-success')
        ->assertDontSee('The calibration session has been completed.');

    $row = completeUiRow();
    $after = calibrationSessionPinningUiFingerprint();

    expect($row->session_status)->toBe(Calibration::STATUS_COMPLETED)
        ->and($row->completed_at)->not->toBeNull()
        ->and($row->calibration_date)->toBe('2026-10-22')
        ->and($row->calibration_due)->toBe('2027-10-22')
        ->and($row->certificate_number)->toBeNull()
        ->and($after['scopes'])->toBe($before['scopes'])
        ->and($after['results'])->toBe($before['results'])
        ->and($after['values'])->toBe($before['values'])
        ->and($after['usages'])->toBe($before['usages'])
        ->and($after['activities'])->toBe($before['activities'])
        ->and($after['environment_snapshots'])->toBe($before['environment_snapshots'])
        ->and($after['environment_values'])->toBe($before['environment_values'])
        ->and(DB::table('calibration_result_value')->where('column_key', 'average')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('lets an engineer complete an eligible internal session', function () {
    completeUiArrange(true);
    actingAsRole(ApplicationRoleName::Engineer);

    expect(Gate::allows(ApplicationCapability::SessionComplete->value))->toBeTrue();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Complete Calibration');

    expect(calibrationSessionPinningUiPage()->instance()->canCompleteSession())->toBeTrue()
        ->and(calibrationSessionPinningUiPage()->html())->toContain('wire:submit="requestCompleteSession"');

    completeUiSubmit()->assertHasNoErrors();

    expect(completeUiRow()->session_status)->toBe(Calibration::STATUS_COMPLETED)
        ->and(completeUiRow()->certificate_number)->toBeNull();
});

it('rejects complete when frozen identity is missing', function () {
    completeUiArrange(true);
    DB::table('calibration')->where('calibration_id', 5)->update([
        'snapshot_instrument_name' => null,
    ]);
    $before = calibrationSessionPinningUiFingerprint();

    completeUiSubmit()
        ->assertHasErrors(['completeSession' => CompleteCalibrationSessionException::missingFrozenIdentity()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('rejects complete when frozen location is missing', function () {
    completeUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'calibration_provider_id' => 77,
    ]);
    $before = calibrationSessionPinningUiFingerprint();

    completeUiSubmit()
        ->assertHasErrors(['completeSession' => CompleteCalibrationSessionException::missingFrozenIdentity()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('rejects complete when the provider is not the internal team', function () {
    completeUiArrange(true);
    DB::table('calibration')->where('calibration_id', 5)->update([
        'calibration_provider_id' => 3,
    ]);
    $before = calibrationSessionPinningUiFingerprint();

    completeUiSubmit()
        ->assertHasErrors(['completeSession' => CompleteCalibrationSessionException::missingInternalProvider()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('does not reject completion solely for a not-configured requirement mode', function (string $category) {
    $modes = [
        InstrumentTypeRequirement::CATEGORY_TEST_PROFILE => [InstrumentTypeRequirement::MODE_REQUIRED, null],
        InstrumentTypeRequirement::CATEGORY_STANDARDS => [InstrumentTypeRequirement::MODE_REQUIRED, null],
        InstrumentTypeRequirement::CATEGORY_ACTIVITY => [InstrumentTypeRequirement::MODE_REQUIRED, null],
        InstrumentTypeRequirement::CATEGORY_ENVIRONMENT => [InstrumentTypeRequirement::MODE_REQUIRED, null],
    ];
    $modes[$category] = [InstrumentTypeRequirement::MODE_NOT_CONFIGURED, null];

    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();
    insertCalibrationSessionPinningUiRequirements(
        $modes[InstrumentTypeRequirement::CATEGORY_TEST_PROFILE][0],
        $modes[InstrumentTypeRequirement::CATEGORY_TEST_PROFILE][1],
        $modes[InstrumentTypeRequirement::CATEGORY_STANDARDS][0],
        $modes[InstrumentTypeRequirement::CATEGORY_STANDARDS][1],
        $modes[InstrumentTypeRequirement::CATEGORY_ACTIVITY][0],
        $modes[InstrumentTypeRequirement::CATEGORY_ACTIVITY][1],
        $modes[InstrumentTypeRequirement::CATEGORY_ENVIRONMENT][0],
        $modes[InstrumentTypeRequirement::CATEGORY_ENVIRONMENT][1],
    );
    insertCalibrationSessionInternalProvider();
    applyCalibrationSessionFrozenLocation();
    $before = calibrationSessionPinningUiFingerprint();

    $component = completeUiSubmit();

    expect($component->errors()->get('completeSession'))
        ->not->toContain(CompleteCalibrationSessionException::unresolvedRequirementMode($category)->getMessage())
        ->and(calibrationSessionPinningUiFingerprint())->toBe($before);
})->with([
    'standards' => InstrumentTypeRequirement::CATEGORY_STANDARDS,
    'environment' => InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
    'test_profile' => InstrumentTypeRequirement::CATEGORY_TEST_PROFILE,
]);

it('rejects required standards without a usage row', function () {
    completeUiArrange();
    applyCalibrationSessionFrozenLocation();
    insertCalibrationSessionActivity();
    seedCalibrationSessionEnvironmentCatalog();
    confirmCalibrationSessionEnvironment();
    insertCalibrationSessionEnvironmentValue();
    insertCalibrationSessionEnvironmentValue(['parameter_id' => 2, 'numeric_value' => '55']);
    insertCalibrationSessionEntryPinnedScope();
    $before = calibrationSessionPinningUiFingerprint();

    completeUiSubmit()
        ->assertHasErrors(['completeSession' => CompleteCalibrationSessionException::missingRequiredStandards()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('completes a ready session without any activity rows', function () {
    completeUiArrange(true);
    DB::table('calibration_activity')->delete();

    completeUiSubmit()
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-terminal-success')
        ->assertDontSee('The calibration session has been completed.');

    expect(completeUiRow()->session_status)->toBe(Calibration::STATUS_COMPLETED)
        ->and(DB::table('calibration_activity')->count())->toBe(0);
});

it('completes a ready session without environment configuration confirmation', function () {
    completeUiArrange(true);
    DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
        ->update([
            'configuration_confirmed_at' => null,
            'configuration_confirmed_reason' => null,
            'configuration_confirmed_by_user_id' => null,
        ]);

    completeUiSubmit()
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-terminal-success')
        ->assertDontSee('The calibration session has been completed.');

    expect(completeUiRow()->session_status)->toBe(Calibration::STATUS_COMPLETED)
        ->and(DB::table('calibration_requirement_snapshot')
            ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
            ->value('configuration_confirmed_at'))->toBeNull()
        ->and(DB::table('calibration_environment_value')->count())->toBe(2);
});

it('rejects complete when a required environment measurement is missing', function () {
    completeUiArrange(true);
    DB::table('calibration_environment_value')->delete();
    $before = calibrationSessionPinningUiFingerprint();

    completeUiSubmit()
        ->assertHasErrors(['completeSession' => CompleteCalibrationSessionException::incompleteEnvironment()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('rejects complete when a required test table is not pinned', function () {
    completeUiArrange();
    applyCalibrationSessionFrozenLocation();
    insertCalibrationSessionStandardUsage();
    insertCalibrationSessionActivity();
    seedCalibrationSessionEnvironmentCatalog();
    confirmCalibrationSessionEnvironment();
    insertCalibrationSessionEnvironmentValue();
    insertCalibrationSessionEnvironmentValue(['parameter_id' => 2, 'numeric_value' => '55']);
    $before = calibrationSessionPinningUiFingerprint();

    completeUiSubmit()
        ->assertHasErrors(['completeSession' => CompleteCalibrationSessionException::missingRequiredPin()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('rejects complete when a required test cell is missing', function () {
    completeUiArrange();
    applyCalibrationSessionFrozenLocation();
    insertCalibrationSessionStandardUsage();
    insertCalibrationSessionActivity();
    seedCalibrationSessionEnvironmentCatalog();
    confirmCalibrationSessionEnvironment();
    insertCalibrationSessionEnvironmentValue();
    insertCalibrationSessionEnvironmentValue(['parameter_id' => 2, 'numeric_value' => '55']);
    insertCalibrationSessionEntryPinnedScope();
    insertCalibrationSessionResultParent(70, 8, 11);
    insertCalibrationSessionResultParent(71, 8, 12);
    insertCalibrationSessionResultCell([
        'calibration_result_value_id' => 701,
        'calibration_test_result_id' => 70,
        'numeric_value' => '100.5',
    ]);
    insertCalibrationSessionResultCell([
        'calibration_result_value_id' => 711,
        'calibration_test_result_id' => 71,
        'numeric_value' => '200.0',
    ]);
    $before = calibrationSessionPinningUiFingerprint();

    completeUiSubmit()
        ->assertHasErrors(['completeSession' => 'Required test component reading is missing an engineer-entered value.']);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(DB::table('calibration_result_value')->where('column_key', 'reading')->count())->toBe(0);
});

it('does not silently materialize missing template values during complete', function () {
    completeUiArrange();
    applyCalibrationSessionFrozenLocation();
    insertCalibrationSessionStandardUsage();
    insertCalibrationSessionActivity();
    seedCalibrationSessionEnvironmentCatalog();
    confirmCalibrationSessionEnvironment();
    insertCalibrationSessionEnvironmentValue();
    insertCalibrationSessionEnvironmentValue(['parameter_id' => 2, 'numeric_value' => '55']);
    insertCalibrationSessionEntryPinnedScope();
    $before = calibrationSessionPinningUiFingerprint();

    completeUiSubmit()->assertHasErrors('completeSession');

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(DB::table('calibration_result_value')->count())->toBe(0);
});

it('follows optional category rules without creating missing records', function () {
    completeUiArrangeOptional();
    $before = calibrationSessionPinningUiFingerprint();

    completeUiSubmit()->assertHasNoErrors();

    $after = calibrationSessionPinningUiFingerprint();

    expect(completeUiRow()->session_status)->toBe(Calibration::STATUS_COMPLETED)
        ->and($after['usages'])->toBe($before['usages'])
        ->and($after['activities'])->toBe($before['activities'])
        ->and($after['scopes'])->toBe($before['scopes'])
        ->and($after['values'])->toBe($before['values']);
});

it('redirects anonymous users away from complete without writes', function () {
    completeUiArrange(true);
    Auth::logout();
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))->assertRedirect(route('login'));

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(completeUiRow()->session_status)->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('rejects complete for a completed session', function () {
    completeUiArrange(true);
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_COMPLETED,
        'calibration_date' => '2026-10-01',
        'calibration_due' => '2027-10-01',
        'completed_at' => '2026-10-01 10:00:00',
    ]);
    $before = calibrationSessionPinningUiFingerprint();

    completeUiSubmit()
        ->assertHasErrors(['completeSession' => CompleteCalibrationSessionException::invalidSessionStatus()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('rejects complete for a cancelled session', function () {
    completeUiArrange(true);
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_CANCELLED,
    ]);
    $before = calibrationSessionPinningUiFingerprint();

    completeUiSubmit()
        ->assertHasErrors(['completeSession' => CompleteCalibrationSessionException::invalidSessionStatus()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('rejects a repeated complete without extra writes', function () {
    completeUiArrange(true);

    completeUiSubmit()->assertHasNoErrors();
    $afterFirst = calibrationSessionPinningUiFingerprint();

    completeUiSubmit()
        ->assertHasErrors(['completeSession' => CompleteCalibrationSessionException::invalidSessionStatus()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($afterFirst);
});

it('denies internal complete when session.complete is denied even if session.record is allowed', function () {
    completeUiArrange(true);
    actingAsRole(ApplicationRoleName::Administrator);
    denySessionCompleteWhileAllowingSessionRecord();

    try {
        expect(Gate::allows(ApplicationCapability::SessionRecord->value))->toBeTrue()
            ->and(Gate::allows(ApplicationCapability::SessionComplete->value))->toBeFalse();

        $before = calibrationSessionPinningUiFingerprint();

        $this->get(route('calibrations.show', 5))
            ->assertOk()
            ->assertDontSee('Complete Calibration');

        expect(calibrationSessionPinningUiPage()->instance()->canCompleteSession())->toBeFalse()
            ->and(calibrationSessionPinningUiPage()->html())->not->toContain('wire:submit="completeSession"');

        calibrationSessionPinningUiPage()
            ->set('completionDate', '2026-10-22')
            ->set('completionDue', '2027-10-22')
            ->call('completeSession')
            ->assertForbidden();

        expect(calibrationSessionPinningUiFingerprint())->toBe($before)
            ->and(completeUiRow()->session_status)->toBe(Calibration::STATUS_IN_PROGRESS);

        calibrationSessionPinningUiPage()
            ->set('environmentInputs.1', '24.00')
            ->call('saveEnvironmentMeasurement', 1)
            ->assertHasNoErrors();

        expect(DB::table('calibration_environment_value')->where('parameter_id', 1)->value('numeric_value'))
            ->toBe('24.00')
            ->and(completeUiRow()->session_status)->toBe(Calibration::STATUS_IN_PROGRESS)
            ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
    } finally {
        restoreApplicationCapabilityGates();
    }
});

it('fails closed when http complete cannot use a postgresql write connection', function () {
    completeUiArrange(true);

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
        ->set('completionDate', '2026-10-22')
        ->set('completionDue', '2027-10-22')
        ->call('completeSession')
        ->assertHasErrors(['completeSession' => RecordCalibrationSessionHttpException::writesUnavailable()->getMessage()]);

    expect(completeUiRow()->session_status)->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('keeps start calibration free of requirement modes after session detail complete', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();

    Livewire::test('pages::instruments.start-calibration', [
        'instrument' => 1,
        'instrumentType' => 11,
    ])
        ->assertSee('Start Calibration')
        ->assertDontSee('Requirement Modes')
        ->assertDontSee('>test_profile</dt>', false);
});
