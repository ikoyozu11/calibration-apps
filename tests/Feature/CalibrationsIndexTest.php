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
    });

    Schema::create('calibration', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->date('calibration_date')->nullable();
        $table->date('calibration_due')->nullable();
        $table->string('calibration_result', 20)->nullable();
        $table->string('certificate_number', 100)->nullable();
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
        'serial_number' => $attributes['serial_number'] ?? null,
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
        'calibration_date' => $attributes['calibration_date'] ?? null,
        'calibration_due' => $attributes['calibration_due'] ?? null,
        'calibration_result' => $attributes['calibration_result'] ?? null,
        'certificate_number' => $attributes['certificate_number'] ?? null,
    ]);
}

beforeEach(function () {
    Model::preventLazyLoading();
});

it('shows each instrument type with the latest calibration standing', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
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
        'serial_number' => 'WEIGHT-001',
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
        'calibration_date' => '2026-09-01',
        'calibration_due' => '2026-10-11',
        'calibration_result' => 'PASS',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'calibration_date' => '2026-10-01',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'FAIL',
        'certificate_number' => 'CERT-1',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 3,
        'instrument_id' => 4,
        'calibration_date' => '2026-10-12',
        'calibration_due' => '2026-10-12',
        'calibration_result' => 'PASS',
        'certificate_number' => '   ',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 4,
        'instrument_id' => 5,
        'calibration_date' => '2026-09-15',
        'calibration_due' => '2026-10-11',
        'certificate_number' => 'CERT-2',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 5,
        'instrument_id' => 7,
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
            'Calibration Status',
        ])
        ->assertSeeInOrder(['This month', '3', 'Pass / Fail', '3', '1', 'No certificate', '3'])
        ->assertSeeInOrder(['Sodiline', 'Sodim', 'Weight', 'PDV - PD', 'WEIGHT-001', 'ASSET-9', '2026-10-01', '2026-12-12', 'On Track'])
        ->assertSeeInOrder(['Sodiline', 'Diameter', '2026-10-01', '2026-12-12', 'On Track'])
        ->assertSeeInOrder(['Due Today', '2026-10-12', '2026-10-12', 'Due Soon'])
        ->assertSeeInOrder(['Late Gauge', '2026-09-15', '2026-10-11', 'Overdue'])
        ->assertSeeInOrder(['No History', 'Not Calibrated'])
        ->assertSeeInOrder(['Open Due', '2026-10-02', 'Not Calibrated'])
        ->assertDontSee('2026-09-01')
        ->assertDontSee('Calibration Number')
        ->assertDontSee('Remarks')
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
        'calibration_date' => '2026-08-01',
        'calibration_due' => '2026-09-01',
        'calibration_result' => 'PASS',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'calibration_date' => '2026-10-01',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-WEIGHT',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 3,
        'instrument_id' => 3,
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
        ->assertSeeInOrder(['Sodiline', 'Weight', '2026-10-01', '2026-12-12', 'On Track'])
        ->assertSeeInOrder(['Sodiline', 'Diameter', '2026-09-15', '2026-10-11', 'Overdue'])
        ->assertSeeInOrder(['Sodiline', 'PD', 'Not Calibrated'])
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
        'calibration_date' => '2026-10-03',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-11',
    ]);

    $firstPage = $this->get(route('calibrations.index'));
    $secondPage = $this->get(route('calibrations.index', ['page' => 2]));

    $firstPage
        ->assertSeeInOrder(['This month', '1', 'Pass / Fail', '1', '0', 'No certificate', '0'])
        ->assertSee('Gauge 01')
        ->assertDontSee('Gauge 11');
    $secondPage
        ->assertSeeInOrder(['This month', '1'])
        ->assertSee('Gauge 11')
        ->assertDontSee('Gauge 01');
});

it('filters calibration rows by name brand type or model without changing the totals', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 1, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexInstrument(['instrument_id' => 2, 'instrument_name' => 'Digital Caliper']);
    insertCalibrationIndexType([
        'instrument_type_id' => 1,
        'instrument_id' => 1,
        'type_name' => 'Weight',
        'brand' => 'Sodim',
        'model' => 'PDV - PD',
        'serial_number' => 'WEIGHT-001',
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
        ->assertSee('This month')
        ->assertSee('Pass / Fail')
        ->assertSee('>1</p>', false)
        ->assertSee('>/</span>0', false)
        ->assertSee('No certificate')
        ->assertSee('>0</p>', false)
        ->set('search', 'sodim')
        ->assertSee('Weight')
        ->assertDontSee('Diameter')
        ->set('search', 'pdv')
        ->assertSee('PDV - PD')
        ->assertDontSee('Diameter')
        ->set('search', 'WEIGHT-001')
        ->assertDontSee('Sodiline')
        ->assertSee('>1</p>', false)
        ->assertSee('>/</span>0', false)
        ->assertSee('>0</p>', false);
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

    $response = $this->get(route('calibrations.index'));

    $response
        ->assertSeeInOrder(['This month', '0', 'Pass / Fail', '0', '0', 'No certificate', '0'])
        ->assertSee('No instruments found.');
});
