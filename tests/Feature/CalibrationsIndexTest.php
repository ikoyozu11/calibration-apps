<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

function createCalibrationIndexTables(): void
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
        $table->date('calibration_date')->nullable();
        $table->date('calibration_due')->nullable();
        $table->string('calibration_result', 20)->nullable();
        $table->string('certificate_number', 100)->nullable();
        $table->timestamp('created_at')->nullable();
        addCalibrationSessionColumns($table);
    });

    Schema::create('calibration_scope', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_scope_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('instrument_type_id');
        $table->integer('scope_order');
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertCalibrationIndexInstrument(array $attributes): void
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
function insertCalibrationIndexType(array $attributes): void
{
    DB::table('instrument_type')->insert([
        'instrument_type_id' => $attributes['instrument_type_id'],
        'instrument_id' => $attributes['instrument_id'],
        'type_name' => $attributes['type_name'],
        'asset_number' => $attributes['asset_number'] ?? null,
        'brand' => $attributes['brand'] ?? null,
        'model' => $attributes['model'] ?? null,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertCalibrationIndexScope(array $attributes): void
{
    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => $attributes['calibration_scope_id'],
        'calibration_id' => $attributes['calibration_id'],
        'instrument_type_id' => $attributes['instrument_type_id'],
        'scope_order' => $attributes['scope_order'] ?? 1,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertCalibrationIndexRecord(array $attributes): void
{
    DB::table('calibration')->insert([
        'calibration_id' => $attributes['calibration_id'],
        'instrument_id' => $attributes['instrument_id'],
        'instrument_type_id' => $attributes['instrument_type_id'] ?? null,
        'session_status' => $attributes['session_status'] ?? 'completed',
        'calibration_date' => $attributes['calibration_date'] ?? null,
        'calibration_due' => $attributes['calibration_due'] ?? null,
        'calibration_result' => $attributes['calibration_result'] ?? null,
        'certificate_number' => $attributes['certificate_number'] ?? null,
        'created_at' => $attributes['created_at'] ?? (($attributes['calibration_date'] ?? null) === null
            ? '2026-01-01 00:00:00'
            : $attributes['calibration_date'].' 00:00:00'),
    ]);
}

beforeEach(function () {
    Model::preventLazyLoading();
});

it('shows each instrument type with the latest calibration standing', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline', 'serial_number' => 'WEIGHT-001']);
    insertCalibrationIndexInstrument(['instrument_id' => 4, 'instrument_name' => 'Due Today']);
    insertCalibrationIndexInstrument(['instrument_id' => 5, 'instrument_name' => 'Late Gauge']);
    insertCalibrationIndexInstrument(['instrument_id' => 6, 'instrument_name' => 'No History']);
    insertCalibrationIndexInstrument(['instrument_id' => 7, 'instrument_name' => 'Open Due']);
    insertCalibrationIndexType([
        'instrument_type_id' => 1,
        'instrument_id' => 3,
        'type_name' => 'Weight',
        'brand' => 'Sodim',
        'model' => 'PDV - PD',
        'asset_number' => 'ASSET-9',
    ]);
    insertCalibrationIndexType([
        'instrument_type_id' => 2,
        'instrument_id' => 3,
        'type_name' => 'Diameter',
    ]);
    insertCalibrationIndexType(['instrument_type_id' => 3, 'instrument_id' => 4, 'type_name' => 'Gauge']);
    insertCalibrationIndexType(['instrument_type_id' => 4, 'instrument_id' => 5, 'type_name' => 'Gauge']);
    insertCalibrationIndexType(['instrument_type_id' => 5, 'instrument_id' => 6, 'type_name' => 'Gauge']);
    insertCalibrationIndexType(['instrument_type_id' => 6, 'instrument_id' => 7, 'type_name' => 'Gauge']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-09-01',
        'calibration_due' => '2026-10-11',
        'calibration_result' => 'PASS',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-10-01',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'FAIL',
        'certificate_number' => 'CERT-1',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 6,
        'instrument_id' => 3,
        'instrument_type_id' => 2,
        'calibration_date' => '2026-10-01',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'FAIL',
        'certificate_number' => 'CERT-DIA',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 3,
        'instrument_id' => 4,
        'instrument_type_id' => 3,
        'calibration_date' => '2026-10-12',
        'calibration_due' => '2026-10-12',
        'calibration_result' => 'PASS',
        'certificate_number' => '   ',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 4,
        'instrument_id' => 5,
        'instrument_type_id' => 4,
        'calibration_date' => '2026-09-15',
        'calibration_due' => '2026-10-11',
        'certificate_number' => 'CERT-2',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 5,
        'instrument_id' => 7,
        'instrument_type_id' => 6,
        'calibration_date' => '2026-10-02',
        'calibration_result' => 'PASS',
    ]);
    insertCalibrationIndexScope(['calibration_scope_id' => 1, 'calibration_id' => 1, 'instrument_type_id' => 1]);
    insertCalibrationIndexScope(['calibration_scope_id' => 2, 'calibration_id' => 1, 'instrument_type_id' => 2]);
    insertCalibrationIndexScope(['calibration_scope_id' => 3, 'calibration_id' => 2, 'instrument_type_id' => 1]);
    insertCalibrationIndexScope(['calibration_scope_id' => 4, 'calibration_id' => 2, 'instrument_type_id' => 2]);
    insertCalibrationIndexScope(['calibration_scope_id' => 5, 'calibration_id' => 3, 'instrument_type_id' => 3]);
    insertCalibrationIndexScope(['calibration_scope_id' => 6, 'calibration_id' => 4, 'instrument_type_id' => 4]);
    insertCalibrationIndexScope(['calibration_scope_id' => 7, 'calibration_id' => 5, 'instrument_type_id' => 6]);

    $response = $this->get(route('calibrations.index'));

    $response
        ->assertSeeInOrder([
            'Instrument Name',
            'Brand',
            'Type',
            'Model',
            'Serial Number',
            'Asset Number',
            'Calibration Date',
            'Calibration Due',
            'Annual Progress',
            'Current Due Status',
        ])
        ->assertSee('Annual Overview')
        ->assertSeeInOrder(['Completed', '5', 'In Progress', '0', 'Not Calibrated', '1'])
        ->assertSeeInOrder(['Sodiline', 'Sodim', 'Weight', 'PDV - PD', 'WEIGHT-001', 'ASSET-9', '2026-10-01', '2026-12-12', 'Completed', 'On Track'])
        ->assertSeeInOrder(['Sodiline', 'Diameter', '2026-10-01', '2026-12-12', 'Completed', 'On Track'])
        ->assertSeeInOrder(['Due Today', '2026-10-12', '2026-10-12', 'Completed', 'Due Soon'])
        ->assertSeeInOrder(['Late Gauge', '2026-09-15', '2026-10-11', 'Completed', 'Overdue'])
        ->assertSeeInOrder(['No History', 'Not Calibrated', 'Not Calibrated'])
        ->assertSeeInOrder(['Open Due', '2026-10-02', 'Completed', 'Not Calibrated'])
        ->assertDontSee('2026-09-01')
        ->assertDontSee('This month')
        ->assertDontSee('Pass / Fail')
        ->assertDontSee('No certificate')
        ->assertDontSee('Calibration Number')
        ->assertDontSee('Remarks')
        ->assertSee('Start Calibration')
        ->assertSee('href="'.e(route('instruments.types.calibrations.start', [
            'instrument' => 6,
            'instrumentType' => 5,
        ])).'"', false)
        ->assertDontSee('Total Instruments')
        ->assertSee('href="'.route('calibrations.show', 2).'"', false)
        ->assertDontSee('href="'.route('calibrations.show', 1).'"', false);
});

it('opens the latest calibration that covers each instrument type', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertCalibrationIndexType(['instrument_type_id' => 2, 'instrument_id' => 3, 'type_name' => 'Diameter']);
    insertCalibrationIndexType(['instrument_type_id' => 3, 'instrument_id' => 3, 'type_name' => 'PD']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-08-01',
        'calibration_due' => '2026-09-01',
        'calibration_result' => 'PASS',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-10-01',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-WEIGHT',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 3,
        'instrument_id' => 3,
        'instrument_type_id' => 2,
        'calibration_date' => '2026-09-15',
        'calibration_due' => '2026-10-11',
        'calibration_result' => 'FAIL',
        'certificate_number' => 'CERT-DIAMETER',
    ]);
    insertCalibrationIndexScope(['calibration_scope_id' => 1, 'calibration_id' => 1, 'instrument_type_id' => 1]);
    insertCalibrationIndexScope(['calibration_scope_id' => 2, 'calibration_id' => 2, 'instrument_type_id' => 1]);
    insertCalibrationIndexScope(['calibration_scope_id' => 3, 'calibration_id' => 3, 'instrument_type_id' => 2]);

    $response = $this->get(route('calibrations.index'));

    $response
        ->assertSeeInOrder(['Sodiline', 'Weight', '2026-10-01', '2026-12-12', 'Completed', 'On Track'])
        ->assertSeeInOrder(['Sodiline', 'Diameter', '2026-09-15', '2026-10-11', 'Completed', 'Overdue'])
        ->assertSeeInOrder(['Sodiline', 'PD', 'Not Calibrated', 'Not Calibrated'])
        ->assertDontSee('2026-08-01')
        ->assertSee('href="'.route('calibrations.show', 2).'"', false)
        ->assertSee('href="'.route('calibrations.show', 3).'"', false)
        ->assertDontSee('href="'.route('calibrations.show', 1).'"', false);
});

it('keeps the calibration totals when the type list moves to the next page', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    foreach (range(1, 11) as $id) {
        insertCalibrationIndexInstrument([
            'instrument_id' => $id,
            'instrument_name' => sprintf('Gauge %02d', $id),
        ]);
        insertCalibrationIndexType([
            'instrument_type_id' => $id,
            'instrument_id' => $id,
            'type_name' => 'Weight',
        ]);
    }

    insertCalibrationIndexRecord([
        'calibration_id' => 11,
        'instrument_id' => 11,
        'instrument_type_id' => 11,
        'calibration_date' => '2026-10-03',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-11',
    ]);

    $firstPage = $this->get(route('calibrations.index'));
    $secondPage = $this->get(route('calibrations.index', ['page' => 2]));

    $firstPage
        ->assertSeeInOrder(['Completed', '1', 'In Progress', '0', 'Not Calibrated', '10'])
        ->assertSee('Gauge 01')
        ->assertDontSee('Gauge 11');
    $secondPage
        ->assertSeeInOrder(['Completed', '1', 'In Progress', '0', 'Not Calibrated', '10'])
        ->assertSee('Gauge 11')
        ->assertDontSee('Gauge 01');
});

it('filters calibration rows by name brand type or model without changing the totals', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 1, 'instrument_name' => 'Sodiline', 'serial_number' => 'WEIGHT-001']);
    insertCalibrationIndexInstrument(['instrument_id' => 2, 'instrument_name' => 'Digital Caliper']);
    insertCalibrationIndexType([
        'instrument_type_id' => 1,
        'instrument_id' => 1,
        'type_name' => 'Weight',
        'brand' => 'Sodim',
        'model' => 'PDV - PD',
    ]);
    insertCalibrationIndexType([
        'instrument_type_id' => 2,
        'instrument_id' => 1,
        'type_name' => 'Diameter',
    ]);
    insertCalibrationIndexType([
        'instrument_type_id' => 3,
        'instrument_id' => 2,
        'type_name' => 'Mitutoyo',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 2,
        'instrument_type_id' => 3,
        'calibration_date' => '2026-10-12',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-1',
    ]);

    Livewire::test('pages::calibrations')
        ->set('search', 'diameter')
        ->assertSee('Diameter')
        ->assertDontSee('Weight')
        ->assertDontSee('Digital Caliper')
        ->assertSeeInOrder(['Completed', '1', 'In Progress', '0', 'Not Calibrated', '2'])
        ->set('search', 'sodim')
        ->assertSee('Weight')
        ->assertDontSee('Diameter')
        ->assertSeeInOrder(['Completed', '1', 'In Progress', '0', 'Not Calibrated', '2'])
        ->set('search', 'pdv')
        ->assertSee('PDV - PD')
        ->assertDontSee('Diameter')
        ->set('search', 'WEIGHT-001')
        ->assertDontSee('Sodiline')
        ->assertSeeInOrder(['Completed', '1', 'In Progress', '0', 'Not Calibrated', '2']);
});

it('rejects a page size outside the allowed choices', function () {
    createCalibrationIndexTables();

    foreach (range(1, 11) as $id) {
        insertCalibrationIndexInstrument([
            'instrument_id' => $id,
            'instrument_name' => sprintf('Gauge %02d', $id),
        ]);
        insertCalibrationIndexType([
            'instrument_type_id' => $id,
            'instrument_id' => $id,
            'type_name' => 'Weight',
        ]);
    }

    Livewire::test('pages::calibrations')
        ->set('perPage', 100)
        ->assertSet('perPage', 10)
        ->assertDontSee('Gauge 11');
});

it('shows zero totals when no instruments exist', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    $response = $this->get(route('calibrations.index'));

    $response
        ->assertSeeInOrder(['Completed', '0', 'In Progress', '0', 'Not Calibrated', '0'])
        ->assertSee('No instruments found.');
});

it('prefers an in-progress session for the year and ignores cancelled sessions', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-09-01',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-DONE',
        'session_status' => 'completed',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'session_status' => 'in_progress',
        'created_at' => '2026-10-12 08:00:00',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 3,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-10-11',
        'calibration_due' => '2026-10-11',
        'session_status' => 'cancelled',
        'created_at' => '2026-10-11 08:00:00',
    ]);

    $response = $this->get(route('calibrations.index'));

    $response
        ->assertSeeInOrder(['Completed', '0', 'In Progress', '1', 'Not Calibrated', '0'])
        ->assertSeeInOrder(['Sodiline', 'Weight', '2026-09-01', '2026-12-12', 'In Progress', 'On Track'])
        ->assertDontSee('2026-10-11')
        ->assertDontSee('Active session')
        ->assertSee('href="'.route('calibrations.show', 2).'"', false)
        ->assertDontSee('href="'.route('calibrations.show', 1).'"', false)
        ->assertDontSee('href="'.route('calibrations.show', 3).'"', false);
});

it('does not treat a completed session from another year as the annual cell', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2025-06-01',
        'calibration_due' => '2025-12-01',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-2025',
    ]);

    $response = $this->get(route('calibrations.index'));

    $response
        ->assertSeeInOrder(['Completed', '0', 'In Progress', '0', 'Not Calibrated', '1'])
        ->assertSeeInOrder(['Sodiline', 'Weight', 'Not Calibrated', 'Overdue'])
        ->assertDontSee('2025-06-01')
        ->assertDontSee('href="'.route('calibrations.show', 1).'"', false);
});

it('switches the annual cell when the selected year changes', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-03-01',
        'calibration_due' => '2026-09-01',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-2026',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2027-04-01',
        'calibration_due' => '2027-10-01',
        'calibration_result' => 'FAIL',
        'certificate_number' => 'CERT-2027',
    ]);

    Livewire::test('pages::calibrations')
        ->assertSee('option value="2027"', false)
        ->assertSeeInOrder(['Completed', '1', 'In Progress', '0', 'Not Calibrated', '0'])
        ->assertSeeInOrder(['Sodiline', 'Weight', '2026-03-01', '2026-09-01', 'Completed', 'On Track'])
        ->assertDontSee('2027-04-01')
        ->set('year', 2027)
        ->assertSeeInOrder(['Completed', '1', 'In Progress', '0', 'Not Calibrated', '0'])
        ->assertSeeInOrder(['Sodiline', 'Weight', '2027-04-01', '2027-10-01', 'Completed', 'On Track'])
        ->assertDontSee('2026-03-01');
});

it('clamps an unsupported year back to the default', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    Livewire::test('pages::calibrations')
        ->set('year', 2019)
        ->assertSet('year', 2026)
        ->assertSee('Annual Overview');
});

it('keeps annual overview as the default tab', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);

    Livewire::test('pages::calibrations')
        ->assertSee('Annual Overview')
        ->assertSee('Session History')
        ->assertSet('tab', 'overview')
        ->assertSee('Sodiline')
        ->assertDontSee('No Calibration Sessions Yet')
        ->set('tab', 'unknown')
        ->assertSet('tab', 'overview')
        ->assertSee('Sodiline');
});

it('escapes instrument identity values in the annual overview', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => '<script>alert(1)</script>']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);

    $response = $this->get(route('calibrations.index'));

    $response
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('treats a cancelled-only type as not calibrated for the selected year', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-10-11',
        'calibration_due' => '2026-12-12',
        'session_status' => 'cancelled',
        'created_at' => '2026-10-11 08:00:00',
    ]);

    $response = $this->get(route('calibrations.index'));

    $response
        ->assertSeeInOrder(['Completed', '0', 'In Progress', '0', 'Not Calibrated', '1'])
        ->assertSeeInOrder(['Sodiline', 'Weight', 'Not Calibrated', 'Not Calibrated'])
        ->assertDontSee('2026-10-11')
        ->assertDontSee('href="'.route('calibrations.show', 1).'"', false);
});

it('keeps a cross-year in-progress session off the selected year progress', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-03-01',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-2026',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'session_status' => 'in_progress',
        'created_at' => '2027-02-01 08:00:00',
    ]);

    Livewire::test('pages::calibrations')
        ->assertSeeInOrder(['Completed', '1', 'In Progress', '0', 'Not Calibrated', '0'])
        ->assertSeeInOrder(['Sodiline', 'Weight', '2026-03-01', '2026-12-12', 'Completed', 'On Track'])
        ->assertSee('Active session (2027)')
        ->assertSee('href="'.route('calibrations.show', 2).'"', false)
        ->set('year', 2027)
        ->assertSeeInOrder(['Completed', '0', 'In Progress', '1', 'Not Calibrated', '0'])
        ->assertSeeInOrder(['Sodiline', 'Weight', 'In Progress', 'On Track'])
        ->assertDontSee('Active session (2027)')
        ->assertDontSee('2026-03-01');
});

it('keeps current due status independent of the selected annual year', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-03-01',
        'calibration_due' => '2026-09-01',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-2026',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2027-01-15',
        'calibration_due' => '2027-12-12',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-2027',
    ]);

    Livewire::test('pages::calibrations')
        ->assertSeeInOrder(['Sodiline', 'Weight', '2026-03-01', '2026-09-01', 'Completed', 'On Track'])
        ->set('year', 2027)
        ->assertSeeInOrder(['Sodiline', 'Weight', '2027-01-15', '2027-12-12', 'Completed', 'On Track']);
});

it('reuses home due standing thresholds for current due status', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 1, 'instrument_name' => 'On Track Type']);
    insertCalibrationIndexInstrument(['instrument_id' => 2, 'instrument_name' => 'Due Soon Type']);
    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Overdue Type']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 1, 'type_name' => 'Gauge']);
    insertCalibrationIndexType(['instrument_type_id' => 2, 'instrument_id' => 2, 'type_name' => 'Gauge']);
    insertCalibrationIndexType(['instrument_type_id' => 3, 'instrument_id' => 3, 'type_name' => 'Gauge']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => 1,
        'calibration_date' => '2025-10-01',
        'calibration_due' => '2026-12-12',
        'certificate_number' => 'CERT-TRACK',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 2,
        'instrument_id' => 2,
        'instrument_type_id' => 2,
        'calibration_date' => '2025-10-01',
        'calibration_due' => '2026-10-12',
        'certificate_number' => 'CERT-SOON',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 3,
        'instrument_id' => 3,
        'instrument_type_id' => 3,
        'calibration_date' => '2025-10-01',
        'calibration_due' => '2026-10-11',
        'certificate_number' => 'CERT-LATE',
    ]);

    $response = $this->get(route('calibrations.index'));

    $response
        ->assertSeeInOrder(['Completed', '0', 'In Progress', '0', 'Not Calibrated', '3'])
        ->assertSeeInOrder(['On Track Type', 'Not Calibrated', 'On Track'])
        ->assertSeeInOrder(['Due Soon Type', 'Not Calibrated', 'Due Soon'])
        ->assertSeeInOrder(['Overdue Type', 'Not Calibrated', 'Overdue'])
        ->assertDontSee('2025-10-01');
});
