<?php

use App\Authorization\ApplicationRoleName;
use App\CalibrationSessions\CalibrationSessionConnection;
use App\CalibrationSessions\PinCalibrationTestTablesException;
use App\CalibrationSessions\RecordCalibrationSessionHttpConnection;
use App\CalibrationSessions\RecordCalibrationSessionHttpException;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use App\TestColumns\TestTableSnapshotBuilder;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestRefusingPostgresConnector;

function pinningUiArrange(
    string $frozenMode = InstrumentTypeRequirement::MODE_REQUIRED,
    ?string $resolvedMode = null,
    bool $seedActiveTable = true,
): void {
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();
    insertCalibrationSessionPinningUiRequirements($frozenMode, $resolvedMode);

    if ($seedActiveTable) {
        seedCalibrationSessionPinningUiActiveTable();
    }
}

it('lets an administrator see pinning controls on an in-progress internal session', function () {
    pinningUiArrange();
    actingAsRole(ApplicationRoleName::Administrator);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Pin Test Tables')
        ->assertDontSee('Requirement Modes')
        ->assertDontSee('Resolve Requirement')
        ->assertDontSee('Resolved Mode')
        ->assertDontSee('Re-Pin');
});

it('lets an engineer see pinning controls on an in-progress internal session', function () {
    pinningUiArrange();
    actingAsRole(ApplicationRoleName::Engineer);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Pin Test Tables')
        ->assertDontSee('Requirement Modes')
        ->assertDontSee('Resolve Requirement');
});

it('forbids a viewer from pinning test tables', function () {
    pinningUiArrange();
    actingAsRole(ApplicationRoleName::Viewer);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertDontSee('Pin Test Tables')
        ->assertDontSee('Resolve Requirement');

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertForbidden();

    expect(DB::table('calibration_scope')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('forbids a viewer from resolving requirement modes', function () {
    pinningUiArrange(InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    actingAsRole(ApplicationRoleName::Viewer);
    mapStartCalibrationHttpActor();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertDontSee('Resolve Requirement')
        ->assertDontSee('Pin Test Tables');

    calibrationSessionPinningUiPage()
        ->set('resolvedMode', InstrumentTypeRequirement::MODE_REQUIRED)
        ->set('resolutionReason', 'needed at the bench')
        ->call('resolveTestProfileRequirement')
        ->assertForbidden();

    $snapshot = DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_TEST_PROFILE)
        ->first();

    expect($snapshot->resolved_mode)->toBeNull()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('redirects anonymous users away from session detail without writes', function () {
    pinningUiArrange();
    Auth::logout();

    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))->assertRedirect(route('login'));

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('hides pinning controls and rejects pin for an external session', function () {
    pinningUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'calibration_method' => Calibration::METHOD_EXTERNAL,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertDontSee('Pin Test Tables')
        ->assertDontSee('Resolve Requirement');

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertHasErrors(['pinTestTables' => PinCalibrationTestTablesException::invalidMethod()->getMessage()]);

    expect(DB::table('calibration_scope')->count())->toBe(0);
});

it('hides pinning controls and rejects pin for a completed session', function () {
    pinningUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_COMPLETED,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Completed')
        ->assertDontSee('Pin Test Tables')
        ->assertDontSee('Resolve Requirement');

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertHasErrors(['pinTestTables' => PinCalibrationTestTablesException::invalidSessionStatus()->getMessage()]);

    expect(DB::table('calibration_scope')->count())->toBe(0);
});

it('hides pinning controls and rejects pin for a cancelled session', function () {
    pinningUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_CANCELLED,
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Cancelled')
        ->assertDontSee('Pin Test Tables')
        ->assertDontSee('Resolve Requirement');

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertHasErrors(['pinTestTables' => PinCalibrationTestTablesException::invalidSessionStatus()->getMessage()]);

    expect(DB::table('calibration_scope')->count())->toBe(0);
});

it('pins test tables while the test profile requirement is not configured', function () {
    pinningUiArrange(InstrumentTypeRequirement::MODE_NOT_CONFIGURED);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertDontSee('Resolve Requirement')
        ->assertSee('Pin Test Tables')
        ->assertDontSee('skip');

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertHasNoErrors();

    expect(DB::table('calibration_scope')->count())->toBe(1)
        ->and(DB::table('calibration_requirement_snapshot')
            ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_TEST_PROFILE)
            ->value('resolved_mode'))->toBeNull();
});

it('resolves an unresolved test profile to required', function () {
    pinningUiArrange(InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('resolvedMode', InstrumentTypeRequirement::MODE_REQUIRED)
        ->set('resolutionReason', 'required for this job')
        ->call('resolveTestProfileRequirement')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification')
        ->assertSee('Pin Test Tables')
        ->assertDontSee('Resolve Requirement');

    $snapshot = DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_TEST_PROFILE)
        ->first();

    expect($snapshot->frozen_mode)->toBe(InstrumentTypeRequirement::MODE_NOT_CONFIGURED)
        ->and($snapshot->resolved_mode)->toBe(InstrumentTypeRequirement::MODE_REQUIRED)
        ->and($snapshot->resolved_reason)->toBe('required for this job')
        ->and((int) $snapshot->resolved_by_user_id)->toBe(90)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('resolves an unresolved test profile to optional', function () {
    pinningUiArrange(InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('resolvedMode', InstrumentTypeRequirement::MODE_OPTIONAL)
        ->set('resolutionReason', 'optional for this job')
        ->call('resolveTestProfileRequirement')
        ->assertHasNoErrors()
        ->assertSee('Pin Test Tables');

    $snapshot = DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_TEST_PROFILE)
        ->first();

    expect($snapshot->frozen_mode)->toBe(InstrumentTypeRequirement::MODE_NOT_CONFIGURED)
        ->and($snapshot->resolved_mode)->toBe(InstrumentTypeRequirement::MODE_OPTIONAL);
});

it('rejects a missing resolution reason', function () {
    pinningUiArrange(InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    mapStartCalibrationHttpActor();

    calibrationSessionPinningUiPage()
        ->set('resolvedMode', InstrumentTypeRequirement::MODE_REQUIRED)
        ->set('resolutionReason', '')
        ->call('resolveTestProfileRequirement')
        ->assertHasErrors('resolutionReason');

    expect(DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_TEST_PROFILE)
        ->value('resolved_mode'))->toBeNull();
});

it('keeps a frozen required test profile required after pinning', function () {
    pinningUiArrange(InstrumentTypeRequirement::MODE_REQUIRED);

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertHasNoErrors();

    $snapshot = DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_TEST_PROFILE)
        ->first();

    expect($snapshot->frozen_mode)->toBe(InstrumentTypeRequirement::MODE_REQUIRED)
        ->and($snapshot->resolved_mode)->toBeNull();
});

it('keeps a frozen optional test profile optional after pinning', function () {
    pinningUiArrange(InstrumentTypeRequirement::MODE_OPTIONAL);

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertHasNoErrors();

    $snapshot = DB::table('calibration_requirement_snapshot')
        ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_TEST_PROFILE)
        ->first();

    expect($snapshot->frozen_mode)->toBe(InstrumentTypeRequirement::MODE_OPTIONAL)
        ->and($snapshot->resolved_mode)->toBeNull();
});

it('pins an eligible active profile and copies template values', function () {
    pinningUiArrange();

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification')
        ->assertSee('Table 20')
        ->assertDontSee('Pin Test Tables')
        ->assertDontSee('Re-Pin');

    $scope = DB::table('calibration_scope')->first();
    $snapshot = json_decode((string) $scope->test_table_snapshot, true);

    expect(DB::table('calibration_scope')->count())->toBe(1)
        ->and((int) $scope->test_profile_id)->toBe(20)
        ->and((int) $scope->test_profile_version_id)->toBe(200)
        ->and($snapshot['schema_version'])->toBe(TestTableSnapshotBuilder::SCHEMA_VERSION)
        ->and(DB::table('calibration_result_value')->where('value_source', 'template')->count())->toBeGreaterThan(0)
        ->and(DB::table('calibration_result_value')->where('value_source', 'calculated')->count())->toBe(0)
        ->and(DB::table('calibration_result_value')->where('column_key', 'average')->count())->toBe(0)
        ->and(DB::table('calibration_result_value')->where('column_key', 'deviation')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('never pins a draft version', function () {
    pinningUiArrange(seedActiveTable: false);
    seedCalibrationSessionPinningUiActiveTable(status: 'draft');

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertHasErrors(['pinTestTables' => PinCalibrationTestTablesException::noEligibleActiveTable()->getMessage()]);

    expect(DB::table('calibration_scope')->count())->toBe(0);
});

it('never pins a retired version', function () {
    pinningUiArrange(seedActiveTable: false);
    seedCalibrationSessionPinningUiActiveTable(status: 'retired');

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertHasErrors(['pinTestTables' => PinCalibrationTestTablesException::noEligibleActiveTable()->getMessage()]);

    expect(DB::table('calibration_scope')->count())->toBe(0);
});

it('excludes an archived profile from pinning', function () {
    pinningUiArrange(seedActiveTable: false);
    seedCalibrationSessionPinningUiActiveTable(archivedAt: '2026-10-22 08:00:00');

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertHasErrors(['pinTestTables' => PinCalibrationTestTablesException::noEligibleActiveTable()->getMessage()]);

    expect(DB::table('calibration_scope')->count())->toBe(0);
});

it('rejects ambiguous active versions', function () {
    pinningUiArrange();
    DB::table('test_profile_version')->insert([
        'test_profile_version_id' => 201,
        'test_profile_id' => 20,
        'version_number' => 2,
        'status' => 'active',
        'profile_name' => 'Second Active',
        'display_title' => 'Second Active',
        'description' => null,
        'published_at' => '2026-10-22 08:00:00',
        'created_at' => '2026-10-22 08:00:00',
    ]);

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertHasErrors(['pinTestTables' => PinCalibrationTestTablesException::ambiguousActiveVersion()->getMessage()]);

    expect(DB::table('calibration_scope')->count())->toBe(0);
});

it('explains when no eligible active table exists', function () {
    pinningUiArrange(seedActiveTable: false);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('An Active Test Table is required before this session can pin Test Components.')
        ->assertSee('Pin Test Tables');

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertHasErrors(['pinTestTables' => PinCalibrationTestTablesException::noEligibleActiveTable()->getMessage()]);

    expect(DB::table('calibration_scope')->count())->toBe(0);
});

it('keeps a frozen snapshot stable after master table changes', function () {
    pinningUiArrange();

    calibrationSessionPinningUiPage()->call('pinTestTables')->assertHasNoErrors();

    $frozen = DB::table('calibration_scope')->value('test_table_snapshot');

    DB::table('test_profile')->where('test_profile_id', 20)->update([
        'profile_name' => 'LIVE CHANGED PROFILE',
        'display_title' => 'LIVE CHANGED TITLE',
    ]);
    DB::table('test_profile_version')->where('test_profile_version_id', 200)->update([
        'profile_name' => 'LIVE CHANGED PROFILE',
        'display_title' => 'LIVE CHANGED TITLE',
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Table 20')
        ->assertDontSee('LIVE CHANGED TITLE')
        ->assertDontSee('Pin Test Tables');

    expect(DB::table('calibration_scope')->value('test_table_snapshot'))->toBe($frozen);
});

it('does not overwrite a frozen snapshot when pin is repeated', function () {
    pinningUiArrange();

    calibrationSessionPinningUiPage()->call('pinTestTables')->assertHasNoErrors();

    $before = calibrationSessionPinningUiFingerprint();

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertHasNoErrors()
        ->assertDontSee('Re-Pin');

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(DB::table('calibration_scope')->count())->toBe(1);
});

it('leaves engineer values unchanged and does not calculate formulas', function () {
    pinningUiArrange();

    calibrationSessionPinningUiPage()->call('pinTestTables')->assertHasNoErrors();

    $resultId = (int) DB::table('calibration_test_result')->orderBy('calibration_test_result_id')->value('calibration_test_result_id');

    DB::table('calibration_result_value')->insert([
        'calibration_result_value_id' => 900,
        'calibration_test_result_id' => $resultId,
        'column_key' => 'reading',
        'value_source' => 'engineer',
        'data_type' => 'numeric',
        'numeric_value' => '12.5',
        'text_value' => null,
        'unit' => 'kPa',
        'created_at' => '2026-10-22 09:00:00',
    ]);

    calibrationSessionPinningUiPage()->call('pinTestTables')->assertHasNoErrors();

    $engineer = DB::table('calibration_result_value')->where('column_key', 'reading')->first();

    expect($engineer->numeric_value)->toBe('12.5')
        ->and($engineer->value_source)->toBe('engineer')
        ->and(DB::table('calibration_result_value')->where('value_source', 'calculated')->count())->toBe(0)
        ->and(DB::table('calibration_result_value')->where('column_key', 'average')->count())->toBe(0)
        ->and(DB::table('calibration_result_value')->where('column_key', 'deviation')->count())->toBe(0);
});

it('rejects an invalid existing snapshot without rebuilding it', function () {
    pinningUiArrange();
    $legacy = json_encode([
        'schema_version' => 99,
        'duration_format' => 'hh:mm:ss',
        'test_profile_id' => 20,
        'test_profile_version_id' => 200,
        'version_number' => 1,
        'profile_name' => 'Legacy',
        'columns' => [],
        'rows' => [],
    ], JSON_THROW_ON_ERROR);

    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => 9,
        'calibration_id' => 5,
        'instrument_type_id' => 11,
        'test_profile_id' => 20,
        'test_profile_version_id' => 200,
        'test_table_snapshot' => $legacy,
        'scope_order' => 1,
        'remarks' => null,
        'created_at' => '2026-10-20 09:00:00',
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertSee('This Test Table snapshot is unavailable.')
        ->assertDontSee('Pin Test Tables');

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertHasErrors(['pinTestTables' => PinCalibrationTestTablesException::inconsistentPinnedScopes()->getMessage()]);

    expect(DB::table('calibration_scope')->count())->toBe(1)
        ->and(DB::table('calibration_scope')->value('test_table_snapshot'))->toBe($legacy);
});

it('does not write on a session detail get request', function () {
    pinningUiArrange();
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))->assertOk()->assertSee('Pin Test Tables');

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('fails closed when http record cannot use a postgresql write connection', function () {
    pinningUiArrange();

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
        ->call('pinTestTables')
        ->assertHasErrors(['pinTestTables' => 'Recording this calibration session is not available in this environment.']);

    expect(DB::table('calibration_scope')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('resolves sqlite memory for http record writes and refuses postgresql without opening it', function () {
    $connection = app(RecordCalibrationSessionHttpConnection::class)->resolve();

    expect($connection->getDriverName())->toBe('sqlite')
        ->and($connection->getDatabaseName())->toBe(':memory:')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);

    $guard = new RecordCalibrationSessionHttpConnection(
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
    );

    expect(fn () => $guard->resolve())
        ->toThrow(RecordCalibrationSessionHttpException::class, 'Recording this calibration session is not available in this environment.')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('keeps start calibration free of requirement modes after session detail pinning', function () {
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

it('keeps completed and legacy session display intact', function () {
    pinningUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_COMPLETED,
    ]);
    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => 9,
        'calibration_id' => 5,
        'instrument_type_id' => 11,
        'test_profile_id' => 20,
        'test_profile_version_id' => 200,
        'test_table_snapshot' => json_encode([
            'schema_version' => 1,
            'duration_format' => 'hh:mm:ss',
            'test_profile_id' => 20,
            'test_profile_version_id' => 200,
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
        ->assertOk()
        ->assertSee('Completed')
        ->assertSee('This Test Table snapshot is unavailable.')
        ->assertDontSee('Pin Test Tables')
        ->assertDontSee('Resolve Requirement')
        ->assertDontSee('Re-Pin');
});
