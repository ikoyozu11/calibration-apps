<?php

use App\Authorization\ApplicationRoleName;
use App\CalibrationSessions\CalibrationSessionConnection;
use App\CalibrationSessions\MaterializeCalibrationTestTemplateValuesException;
use App\CalibrationSessions\RecordCalibrationSessionHttpConnection;
use App\CalibrationSessions\RecordCalibrationSessionHttpException;
use App\Models\Calibration;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestRefusingPostgresConnector;

afterEach(function (): void {
    Model::preventLazyLoading(false);
});

function recoveryUiArrange(bool $pinSnapshot = true): void
{
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();
    insertCalibrationSessionPinningUiRequirements();

    if ($pinSnapshot) {
        insertCalibrationSessionEntryPinnedScope();
    }
}

function recoveryUiSecondSnapshot(): array
{
    $snapshot = calibrationSessionEntrySnapshot(6, 8);
    $snapshot['display_title'] = 'Frozen Sibling';
    $snapshot['rows'][0]['test_point_id'] = 21;
    $snapshot['rows'][0]['nominal_value'] = '300.0';
    $snapshot['rows'][0]['template_values']['nominal']['numeric_value'] = '300.0';
    $snapshot['rows'][1]['test_point_id'] = 22;
    $snapshot['rows'][1]['nominal_value'] = '400.0';
    $snapshot['rows'][1]['template_values']['nominal']['numeric_value'] = '400.0';

    return $snapshot;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function recoveryUiCompleteScope(
    int $scopeId = 8,
    int $firstResultId = 70,
    int $secondResultId = 71,
    int $firstPointId = 11,
    int $secondPointId = 12,
    string $firstValue = '100.5',
    string $secondValue = '200.0',
): void {
    insertCalibrationSessionResultParent($firstResultId, $scopeId, $firstPointId);
    insertCalibrationSessionResultParent($secondResultId, $scopeId, $secondPointId);
    insertCalibrationSessionResultCell([
        'calibration_result_value_id' => ($firstResultId * 10) + 1,
        'calibration_test_result_id' => $firstResultId,
        'numeric_value' => $firstValue,
    ]);
    insertCalibrationSessionResultCell([
        'calibration_result_value_id' => ($secondResultId * 10) + 1,
        'calibration_test_result_id' => $secondResultId,
        'numeric_value' => $secondValue,
    ]);
}

/**
 * @return list<object>
 */
function recoveryUiTemplateCells(int $scopeId = 8): array
{
    return DB::table('calibration_result_value')
        ->join('calibration_test_result', 'calibration_test_result.calibration_test_result_id', '=', 'calibration_result_value.calibration_test_result_id')
        ->where('calibration_test_result.calibration_scope_id', $scopeId)
        ->where('calibration_result_value.column_key', 'nominal')
        ->orderBy('calibration_test_result.test_point_id')
        ->orderBy('calibration_result_value.calibration_result_value_id')
        ->get([
            'calibration_result_value.calibration_result_value_id',
            'calibration_result_value.calibration_test_result_id',
            'calibration_result_value.column_key',
            'calibration_result_value.value_source',
            'calibration_result_value.data_type',
            'calibration_result_value.numeric_value',
            'calibration_result_value.text_value',
            'calibration_result_value.unit',
            'calibration_result_value.created_at',
            'calibration_test_result.test_point_id',
            'calibration_test_result.calibration_scope_id',
        ])
        ->all();
}

it('classifies an unpinned session without showing recovery', function () {
    recoveryUiArrange(false);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Test Components have not been pinned to this session yet.')
        ->assertDontSee('Recover Template Values')
        ->assertDontSee('Template values are missing.');

    expect(DB::table('calibration_scope')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('shows a completed materialization state without an unnecessary retry', function () {
    recoveryUiArrange();
    recoveryUiCompleteScope();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Template values are complete.')
        ->assertDontSee('Recover Template Values')
        ->assertDontSee('Template values are missing.');
});

it('shows a focused recovery action when expected template values are missing', function () {
    recoveryUiArrange();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Template values are missing.')
        ->assertSee('Recover Template Values');
});

it('asks for confirmation before recovering template values', function () {
    recoveryUiArrange();
    actingAsRole(ApplicationRoleName::Engineer);

    calibrationSessionPinningUiPage()
        ->call('requestRecoverTemplateValues', 8)
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-confirm-template-recovery');
});

it('classifies conflicting persisted values as requiring manual review', function () {
    recoveryUiArrange();
    insertCalibrationSessionResultParent(70, 8, 11);
    insertCalibrationSessionResultParent(71, 8, 12);
    insertCalibrationSessionResultCell([
        'calibration_result_value_id' => 701,
        'calibration_test_result_id' => 70,
        'numeric_value' => '999',
    ]);
    insertCalibrationSessionResultCell([
        'calibration_result_value_id' => 711,
        'calibration_test_result_id' => 71,
        'numeric_value' => '200.0',
    ]);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Template values conflict and require manual review.')
        ->assertDontSee('Recover Template Values');
});

it('classifies a malformed frozen snapshot as invalid', function () {
    recoveryUiArrange(false);
    $snapshot = calibrationSessionEntrySnapshot();
    $snapshot['schema_version'] = 1;
    insertCalibrationSessionEntryPinnedScope($snapshot);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('The frozen Test Table snapshot is invalid.')
        ->assertDontSee('Recover Template Values');
});

it('classifies multiple scopes independently', function () {
    recoveryUiArrange();
    insertCalibrationSessionEntryPinnedScope(recoveryUiSecondSnapshot(), 9, 6, 8);
    recoveryUiCompleteScope(9, 80, 81, 21, 22, '300.0', '400.0');

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Template values are missing.')
        ->assertSee('Template values are complete.')
        ->assertSee('Recover Template Values');
});

it('recovers missing expected template values from the frozen snapshot', function () {
    recoveryUiArrange();
    actingAsRole(ApplicationRoleName::Administrator);

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-notification')
        ->assertSee('Template values are complete.')
        ->assertDontSee('Recover Template Values');

    $cells = recoveryUiTemplateCells();

    expect($cells)->toHaveCount(2)
        ->and((int) $cells[0]->test_point_id)->toBe(11)
        ->and($cells[0]->numeric_value)->toBe('100.5')
        ->and($cells[0]->value_source)->toBe('template')
        ->and($cells[0]->unit)->toBe('g')
        ->and((int) $cells[1]->test_point_id)->toBe(12)
        ->and($cells[1]->numeric_value)->toBe('200.0')
        ->and($cells[1]->value_source)->toBe('template')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('leaves an existing matching template cell unchanged', function () {
    recoveryUiArrange();
    insertCalibrationSessionResultParent(70, 8, 11);
    insertCalibrationSessionResultCell([
        'calibration_result_value_id' => 701,
        'calibration_test_result_id' => 70,
        'numeric_value' => '100.5',
        'created_at' => '2026-10-21 07:00:00',
    ]);

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertHasNoErrors();

    $kept = DB::table('calibration_result_value')->where('calibration_result_value_id', 701)->first();

    expect($kept)->not->toBeNull()
        ->and($kept->numeric_value)->toBe('100.5')
        ->and($kept->value_source)->toBe('template')
        ->and($kept->created_at)->toBe('2026-10-21 07:00:00')
        ->and(recoveryUiTemplateCells())->toHaveCount(2);
});

it('leaves existing engineer values unchanged and does not calculate results', function () {
    recoveryUiArrange();
    insertCalibrationSessionResultParent(70, 8, 11);
    insertCalibrationSessionResultCell([
        'calibration_result_value_id' => 702,
        'calibration_test_result_id' => 70,
        'column_key' => 'reading',
        'value_source' => 'engineer',
        'numeric_value' => '12.25',
    ]);

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertHasNoErrors();

    $reading = DB::table('calibration_result_value')->where('calibration_result_value_id', 702)->first();

    expect($reading->numeric_value)->toBe('12.25')
        ->and($reading->value_source)->toBe('engineer')
        ->and(DB::table('calibration_result_value')->where('column_key', 'average')->count())->toBe(0)
        ->and(DB::table('calibration_result_value')->where('column_key', 'deviation')->count())->toBe(0)
        ->and(recoveryUiTemplateCells())->toHaveCount(2);
});

it('does not repin or read current master tables for recovery values', function () {
    recoveryUiArrange();
    $frozen = DB::table('calibration_scope')->where('calibration_scope_id', 8)->value('test_table_snapshot');

    DB::table('test_point')->insert([
        'test_point_id' => 11,
        'test_profile_id' => 5,
        'test_profile_version_id' => 7,
        'point_order' => 1,
        'nominal_value' => '999',
        'unit' => 'kg',
        'tolerance_plus' => null,
        'tolerance_minus' => null,
        'description' => 'Decoy',
        'created_at' => '2026-10-29 08:00:00',
    ]);
    DB::table('test_point_value')->insert([
        'test_point_value_id' => 900,
        'test_profile_id' => 5,
        'test_profile_version_id' => 7,
        'test_point_id' => 11,
        'test_column_id' => 1,
        'numeric_value' => '999',
        'text_value' => null,
        'unit' => 'kg',
        'created_at' => '2026-10-29 08:00:00',
    ]);

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertHasNoErrors();

    expect(DB::table('calibration_scope')->where('calibration_scope_id', 8)->value('test_table_snapshot'))->toBe($frozen)
        ->and(recoveryUiTemplateCells()[0]->numeric_value)->toBe('100.5')
        ->and(recoveryUiTemplateCells()[0]->unit)->toBe('g')
        ->and(DB::table('test_point_value')->where('test_point_value_id', 900)->value('numeric_value'))->toBe('999');
});

it('treats a repeated recovery as a no-write complete denial', function () {
    recoveryUiArrange();

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertHasNoErrors();

    $afterFirst = calibrationSessionPinningUiFingerprint();

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertHasErrors(['recoverTemplateValues' => 'Template values for this Test Table are already complete.']);

    expect(calibrationSessionPinningUiFingerprint())->toBe($afterFirst)
        ->and(recoveryUiTemplateCells())->toHaveCount(2)
        ->and(DB::table('calibration_result_value')->where('column_key', 'nominal')->count())->toBe(2);
});

it('does not change a completed sibling scope when recovering another missing scope', function () {
    recoveryUiArrange();
    insertCalibrationSessionEntryPinnedScope(recoveryUiSecondSnapshot(), 9, 6, 8);
    recoveryUiCompleteScope(9, 80, 81, 21, 22, '300.0', '400.0');
    $sibling = recoveryUiTemplateCells(9);

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertHasNoErrors();

    expect(recoveryUiTemplateCells(9))->toEqual($sibling)
        ->and(recoveryUiTemplateCells(8))->toHaveCount(2)
        ->and(DB::table('calibration_scope')->where('calibration_scope_id', 9)->value('test_profile_id'))->toBe(6);
});

it('lets an engineer recover missing template values', function () {
    recoveryUiArrange();
    actingAsRole(ApplicationRoleName::Engineer);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Recover Template Values');

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertHasNoErrors();

    expect(recoveryUiTemplateCells())->toHaveCount(2);
});

it('forbids a viewer from recovering template values while still showing status', function () {
    recoveryUiArrange();
    actingAsRole(ApplicationRoleName::Viewer);
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Template values are missing.')
        ->assertDontSee('Recover Template Values');

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertForbidden();

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('redirects anonymous users away from recovery without writes', function () {
    recoveryUiArrange();
    Auth::logout();
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))->assertRedirect(route('login'));

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('hides recovery and rejects it for an external session', function () {
    recoveryUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'calibration_method' => Calibration::METHOD_EXTERNAL,
    ]);
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertDontSee('Recover Template Values')
        ->assertDontSee('Template values are missing.');

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertHasErrors(['recoverTemplateValues' => MaterializeCalibrationTestTemplateValuesException::unsupportedCalibrationMethod()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('hides recovery and rejects it for a completed session', function () {
    recoveryUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_COMPLETED,
    ]);
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Completed')
        ->assertSee('Template values are missing.')
        ->assertDontSee('Recover Template Values');

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertHasErrors(['recoverTemplateValues' => MaterializeCalibrationTestTemplateValuesException::invalidSessionStatus()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('hides recovery and rejects it for a cancelled session', function () {
    recoveryUiArrange();
    DB::table('calibration')->where('calibration_id', 5)->update([
        'session_status' => Calibration::STATUS_CANCELLED,
    ]);
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Cancelled')
        ->assertDontSee('Recover Template Values');

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertHasErrors(['recoverTemplateValues' => MaterializeCalibrationTestTemplateValuesException::invalidSessionStatus()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('denies recovery when the requested scope is unpinned', function () {
    recoveryUiArrange(false);
    $before = calibrationSessionPinningUiFingerprint();

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertHasErrors(['recoverTemplateValues' => 'This Test Table is not pinned.']);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('denies an unnecessary retry when the scope is already complete', function () {
    recoveryUiArrange();
    recoveryUiCompleteScope();
    $before = calibrationSessionPinningUiFingerprint();

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertHasErrors(['recoverTemplateValues' => 'Template values for this Test Table are already complete.']);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('fails closed when persisted values conflict with the frozen template', function () {
    recoveryUiArrange();
    insertCalibrationSessionResultParent(70, 8, 11);
    insertCalibrationSessionResultParent(71, 8, 12);
    insertCalibrationSessionResultCell([
        'calibration_result_value_id' => 701,
        'calibration_test_result_id' => 70,
        'value_source' => 'engineer',
        'numeric_value' => '100.5',
    ]);
    insertCalibrationSessionResultCell([
        'calibration_result_value_id' => 711,
        'calibration_test_result_id' => 71,
        'numeric_value' => '200.0',
    ]);
    $before = calibrationSessionPinningUiFingerprint();

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertHasErrors(['recoverTemplateValues' => MaterializeCalibrationTestTemplateValuesException::conflictingResultCell()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('fails closed when the frozen snapshot is invalid', function () {
    recoveryUiArrange(false);
    $snapshot = calibrationSessionEntrySnapshot();
    $snapshot['schema_version'] = 1;
    insertCalibrationSessionEntryPinnedScope($snapshot);
    $before = calibrationSessionPinningUiFingerprint();

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertHasErrors(['recoverTemplateValues' => MaterializeCalibrationTestTemplateValuesException::malformedFrozenSnapshot()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('fails closed when another scope has a conflicting persisted value', function () {
    recoveryUiArrange();
    insertCalibrationSessionEntryPinnedScope(recoveryUiSecondSnapshot(), 9, 6, 8);
    insertCalibrationSessionResultParent(80, 9, 21);
    insertCalibrationSessionResultCell([
        'calibration_result_value_id' => 801,
        'calibration_test_result_id' => 80,
        'numeric_value' => '111',
    ]);
    $before = calibrationSessionPinningUiFingerprint();

    calibrationSessionPinningUiPage()
        ->call('recoverTemplateValues', 8)
        ->assertHasErrors(['recoverTemplateValues' => MaterializeCalibrationTestTemplateValuesException::conflictingResultCell()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(recoveryUiTemplateCells(8))->toHaveCount(0);
});

it('does not write on a session detail get request', function () {
    recoveryUiArrange();
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Recover Template Values');

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('fails closed when http recovery cannot use a postgresql write connection', function () {
    recoveryUiArrange();

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
        ->call('recoverTemplateValues', 8)
        ->assertHasErrors(['recoverTemplateValues' => 'Recording this calibration session is not available in this environment.']);

    expect(DB::table('calibration_result_value')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('resolves sqlite memory for http recovery writes and refuses postgresql without opening it', function () {
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

it('keeps pinning, identity, and start-calibration surfaces unchanged beside recovery', function () {
    recoveryUiArrange(false);
    seedCalibrationSessionPinningUiActiveTable();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Frozen Gauge')
        ->assertSee('Pin Test Tables')
        ->assertDontSee('Recover Template Values')
        ->assertDontSee('Resolve Requirement');

    calibrationSessionPinningUiPage()
        ->call('pinTestTables')
        ->assertHasNoErrors()
        ->assertSee('Template values are complete.')
        ->assertDontSee('Recover Template Values');
});

it('keeps start calibration free of requirement modes after session detail recovery', function () {
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
