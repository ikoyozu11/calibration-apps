<?php

use App\Authorization\ApplicationRoleName;
use App\Dashboard\CalibrationAnnualOverview;
use App\Dashboard\CalibrationSessionHistory;
use App\Models\Calibration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

function createSessionHistoryTables(): void
{
    Schema::create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name');
        $table->string('serial_number')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('instrument_type', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name');
        $table->string('serial_number')->nullable();
        $table->string('asset_number')->nullable();
        $table->string('brand')->nullable();
        $table->string('model')->nullable();
        addInstrumentTypeIntervalColumn($table);
    });

    Schema::create('calibration', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->string('calibration_number', 100)->nullable();
        $table->date('calibration_date')->nullable();
        $table->date('calibration_due')->nullable();
        $table->string('calibration_method', 20)->nullable();
        $table->string('calibration_result', 20)->nullable();
        $table->string('certificate_number', 100)->nullable();
        $table->timestamp('created_at')->nullable();
        addCalibrationSessionColumns($table);
    });

    Schema::create('app_user', function (Blueprint $table): void {
        $table->unsignedBigInteger('app_user_id')->primary();
        $table->string('username', 100);
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertSessionHistoryInstrument(array $attributes): void
{
    DB::table('instrument')->insert([
        'instrument_id' => $attributes['instrument_id'],
        'instrument_name' => $attributes['instrument_name'],
        'serial_number' => $attributes['serial_number'] ?? null,
        'created_at' => '2026-01-01 00:00:00',
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertSessionHistoryType(array $attributes): void
{
    DB::table('instrument_type')->insert([
        'instrument_type_id' => $attributes['instrument_type_id'],
        'instrument_id' => $attributes['instrument_id'],
        'type_name' => $attributes['type_name'],
        'brand' => $attributes['brand'] ?? null,
        'model' => $attributes['model'] ?? null,
        'serial_number' => $attributes['serial_number'] ?? null,
        'asset_number' => $attributes['asset_number'] ?? null,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertSessionHistoryRecord(array $attributes): void
{
    DB::table('calibration')->insert([
        'calibration_id' => $attributes['calibration_id'],
        'instrument_id' => $attributes['instrument_id'],
        'instrument_type_id' => $attributes['instrument_type_id'] ?? null,
        'session_status' => $attributes['session_status'] ?? Calibration::STATUS_COMPLETED,
        'calibration_number' => $attributes['calibration_number'] ?? null,
        'calibration_method' => $attributes['calibration_method'] ?? null,
        'calibration_date' => $attributes['calibration_date'] ?? null,
        'calibration_due' => $attributes['calibration_due'] ?? null,
        'started_by_app_user_id' => $attributes['started_by_app_user_id'] ?? null,
        'created_at' => $attributes['created_at'] ?? (($attributes['calibration_date'] ?? null) === null
            ? '2026-01-01 00:00:00'
            : $attributes['calibration_date'].' 00:00:00'),
    ]);
}

beforeEach(function () {
    Model::preventLazyLoading();
    $this->travelTo('2026-10-12');
});

afterEach(function (): void {
    Model::preventLazyLoading(false);
});

it('loads session history with one row per persisted session', function () {
    createSessionHistoryTables();
    insertSessionHistoryInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline', 'serial_number' => 'WEIGHT-001']);
    insertSessionHistoryType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertSessionHistoryRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_number' => 'CAL.VT0001/03/26',
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'calibration_date' => '2026-03-01',
        'session_status' => Calibration::STATUS_COMPLETED,
    ]);
    insertSessionHistoryRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_number' => 'CAL.VT0002/10/26',
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'calibration_date' => '2026-10-01',
        'session_status' => Calibration::STATUS_COMPLETED,
    ]);

    Livewire::test('pages::calibrations')
        ->set('tab', CalibrationAnnualOverview::TAB_HISTORY)
        ->assertSee('Session History')
        ->assertSee('CAL.VT0001/03/26')
        ->assertSee('CAL.VT0002/10/26')
        ->assertSee('href="'.route('calibrations.show', 1).'"', false)
        ->assertSee('href="'.route('calibrations.show', 2).'"', false)
        ->assertDontSee('Start Calibration')
        ->assertDontSee('No Calibration Sessions Yet');
});

it('shows the empty session state when no sessions exist', function () {
    createSessionHistoryTables();

    Livewire::test('pages::calibrations')
        ->set('tab', CalibrationAnnualOverview::TAB_HISTORY)
        ->assertSee('No Calibration Sessions Yet')
        ->assertSee('Sessions appear after a calibration has been started.')
        ->assertDontSee('No Matching Calibration Sessions');
});

it('includes in-progress and cancelled sessions for the selected year', function () {
    createSessionHistoryTables();
    insertSessionHistoryInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertSessionHistoryType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertSessionHistoryRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_number' => 'CAL.OPEN',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'created_at' => '2026-04-01 08:00:00',
    ]);
    insertSessionHistoryRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_number' => 'CAL.CANCEL',
        'session_status' => Calibration::STATUS_CANCELLED,
        'created_at' => '2026-05-01 08:00:00',
    ]);

    Livewire::test('pages::calibrations')
        ->set('tab', CalibrationAnnualOverview::TAB_HISTORY)
        ->assertSee('CAL.OPEN')
        ->assertSee('CAL.CANCEL')
        ->assertSee('In Progress')
        ->assertSee('Cancelled');
});

it('keeps a cross-year session in only the classified year', function () {
    createSessionHistoryTables();
    insertSessionHistoryInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertSessionHistoryType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertSessionHistoryRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_number' => 'CAL.2026',
        'calibration_date' => '2026-03-01',
        'session_status' => Calibration::STATUS_COMPLETED,
    ]);
    insertSessionHistoryRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_number' => 'CAL.2027',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'created_at' => '2027-02-01 08:00:00',
    ]);

    Livewire::test('pages::calibrations')
        ->set('tab', CalibrationAnnualOverview::TAB_HISTORY)
        ->assertSee('option value="2027"', false)
        ->assertSee('CAL.2026')
        ->assertDontSee('CAL.2027')
        ->set('year', 2027)
        ->assertSee('CAL.2027')
        ->assertDontSee('CAL.2026');
});

it('filters sessions by persisted status and normalizes invalid values', function () {
    createSessionHistoryTables();
    insertSessionHistoryInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertSessionHistoryType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertSessionHistoryRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_number' => 'CAL.DONE',
        'calibration_date' => '2026-03-01',
        'session_status' => Calibration::STATUS_COMPLETED,
    ]);
    insertSessionHistoryRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_number' => 'CAL.OPEN',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'created_at' => '2026-04-01 08:00:00',
    ]);

    Livewire::test('pages::calibrations')
        ->set('tab', CalibrationAnnualOverview::TAB_HISTORY)
        ->set('sessionStatus', Calibration::STATUS_COMPLETED)
        ->assertSee('CAL.DONE')
        ->assertDontSee('CAL.OPEN')
        ->set('sessionStatus', 'bogus')
        ->assertSet('sessionStatus', 'all')
        ->assertSee('CAL.DONE')
        ->assertSee('CAL.OPEN');
});

it('searches session history by identity and calibration number', function () {
    createSessionHistoryTables();
    insertSessionHistoryInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline', 'serial_number' => 'WEIGHT-001']);
    insertSessionHistoryInstrument(['instrument_id' => 4, 'instrument_name' => 'Caliper', 'serial_number' => 'LEN-002']);
    insertSessionHistoryType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertSessionHistoryType(['instrument_type_id' => 2, 'instrument_id' => 4, 'type_name' => 'Length']);
    insertSessionHistoryRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_number' => 'CAL.WEIGHT',
        'calibration_date' => '2026-03-01',
    ]);
    insertSessionHistoryRecord([
        'calibration_id' => 2,
        'instrument_id' => 4,
        'instrument_type_id' => 2,
        'calibration_number' => 'CAL.LENGTH',
        'calibration_date' => '2026-03-02',
    ]);

    Livewire::test('pages::calibrations')
        ->set('tab', CalibrationAnnualOverview::TAB_HISTORY)
        ->set('search', 'weight')
        ->assertSee('CAL.WEIGHT')
        ->assertDontSee('CAL.LENGTH')
        ->set('search', 'LEN-002')
        ->assertSee('CAL.LENGTH')
        ->assertDontSee('CAL.WEIGHT')
        ->set('search', 'CAL.LENGTH')
        ->assertSee('CAL.LENGTH')
        ->assertDontSee('CAL.WEIGHT')
        ->set('sessionStatus', Calibration::STATUS_CANCELLED)
        ->assertSee('No Matching Calibration Sessions')
        ->assertDontSee('Sessions were deleted');
});

it('paginates session history in deterministic calibration id order', function () {
    createSessionHistoryTables();

    foreach (range(1, 11) as $id) {
        insertSessionHistoryInstrument([
            'instrument_id' => $id,
            'instrument_name' => sprintf('Gauge %02d', $id),
        ]);
        insertSessionHistoryType([
            'instrument_type_id' => $id,
            'instrument_id' => $id,
            'type_name' => 'Weight',
        ]);
        insertSessionHistoryRecord([
            'calibration_id' => $id,
            'instrument_id' => $id,
            'instrument_type_id' => $id,
            'calibration_number' => sprintf('CAL.%02d', $id),
            'calibration_date' => '2026-03-01',
        ]);
    }

    Livewire::test('pages::calibrations')
        ->set('tab', CalibrationAnnualOverview::TAB_HISTORY)
        ->set('search', 'Gauge')
        ->set('sessionStatus', Calibration::STATUS_COMPLETED)
        ->assertSee('CAL.11')
        ->assertDontSee('CAL.01')
        ->call('gotoPage', 2)
        ->assertSee('CAL.01')
        ->assertDontSee('CAL.11')
        ->assertSet('search', 'Gauge')
        ->assertSet('sessionStatus', Calibration::STATUS_COMPLETED)
        ->assertSet('year', 2026)
        ->set('sessionStatus', Calibration::STATUS_CANCELLED)
        ->assertSee('No Matching Calibration Sessions')
        ->assertDontSee('CAL.01')
        ->assertDontSee('CAL.11');
});

it('does not duplicate a persisted session across joined identity rows', function () {
    createSessionHistoryTables();
    insertSessionHistoryInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertSessionHistoryType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertSessionHistoryType(['instrument_type_id' => 2, 'instrument_id' => 3, 'type_name' => 'Diameter']);
    insertSessionHistoryRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_number' => 'CAL.ONCE',
        'calibration_date' => '2026-03-01',
    ]);

    $sessions = CalibrationSessionHistory::paginate(
        2026,
        CalibrationSessionHistory::STATUS_ALL,
        '',
        null,
        null,
        10,
        DB::connection(),
    );

    expect($sessions->pluck('calibration_id')->all())->toBe([1])
        ->and($sessions->pluck('calibration_number')->all())->toBe(['CAL.ONCE']);
});

it('clamps an unsupported history year back to 2026', function () {
    createSessionHistoryTables();

    Livewire::test('pages::calibrations')
        ->set('tab', CalibrationAnnualOverview::TAB_HISTORY)
        ->set('year', 2019)
        ->assertSet('year', 2026);
});

it('filters view-all history to the requested instrument type', function () {
    createSessionHistoryTables();
    insertSessionHistoryInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertSessionHistoryInstrument(['instrument_id' => 4, 'instrument_name' => 'Other']);
    insertSessionHistoryType(['instrument_type_id' => 31, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertSessionHistoryType(['instrument_type_id' => 32, 'instrument_id' => 3, 'type_name' => 'Diameter']);
    insertSessionHistoryType(['instrument_type_id' => 41, 'instrument_id' => 4, 'type_name' => 'Gauge']);
    insertSessionHistoryRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 31,
        'calibration_number' => 'CAL.WEIGHT',
        'calibration_date' => '2026-03-01',
    ]);
    insertSessionHistoryRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 32,
        'calibration_number' => 'CAL.DIAMETER',
        'calibration_date' => '2026-03-02',
    ]);
    insertSessionHistoryRecord([
        'calibration_id' => 3,
        'instrument_id' => 4,
        'instrument_type_id' => 41,
        'calibration_number' => 'CAL.OTHER',
        'calibration_date' => '2026-03-03',
    ]);

    Livewire::test('pages::calibrations', [
        'tab' => CalibrationAnnualOverview::TAB_HISTORY,
        'instrument_id' => 3,
        'instrument_type_id' => 31,
    ])
        ->assertSee('CAL.WEIGHT')
        ->assertDontSee('CAL.DIAMETER')
        ->assertDontSee('CAL.OTHER');
});

it('shows a filtered empty state for view all without sessions', function () {
    createSessionHistoryTables();
    insertSessionHistoryInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertSessionHistoryType(['instrument_type_id' => 31, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertSessionHistoryRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 31,
        'calibration_number' => 'CAL.OTHER-YEAR',
        'calibration_date' => '2027-01-15',
    ]);

    Livewire::test('pages::calibrations', [
        'tab' => CalibrationAnnualOverview::TAB_HISTORY,
        'instrument_id' => 3,
        'instrument_type_id' => 31,
        'year' => 2026,
    ])
        ->assertSee('No Matching Calibration Sessions')
        ->assertDontSee('CAL.OTHER-YEAR')
        ->assertDontSee('No Calibration Sessions Yet');
});

it('renders the instrument detail view all link with identity ids', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    $this->get(route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 31]))
        ->assertSee('Calibration History')
        ->assertSee('View All')
        ->assertSee('href="'.e(route('calibrations.index', [
            'tab' => 'history',
            'instrument_id' => 3,
            'instrument_type_id' => 31,
        ])).'"', false)
        ->assertDontSee('href="'.e(route('calibrations.index', [
            'tab' => 'history',
            'instrument_id' => 3,
            'instrument_type_id' => 32,
        ])).'"', false);
});

it('does not add workflow mutation actions on session history', function () {
    createSessionHistoryTables();
    actingAsRole(ApplicationRoleName::Viewer);

    Livewire::test('pages::calibrations')
        ->set('tab', CalibrationAnnualOverview::TAB_HISTORY)
        ->assertSee('No Calibration Sessions Yet')
        ->assertDontSee('Start Calibration')
        ->assertDontSee('Complete Calibration')
        ->assertDontSee('Cancel Calibration');
});

it('keeps annual overview cards unchanged while session history is available', function () {
    createSessionHistoryTables();
    insertSessionHistoryInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertSessionHistoryType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertSessionHistoryRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-03-01',
        'calibration_due' => '2026-12-12',
        'session_status' => Calibration::STATUS_COMPLETED,
    ]);
    insertSessionHistoryRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'created_at' => '2026-10-01 08:00:00',
    ]);

    $this->get(route('calibrations.index'))
        ->assertSeeInOrder(['Completed', '0', 'In Progress', '1', 'Not Calibrated', '0'])
        ->assertSee('Annual Progress')
        ->assertDontSee('CAL.VT');
});
