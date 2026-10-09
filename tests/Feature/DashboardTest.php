<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

function createDashboardTestTables(): void
{
    Schema::create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name');
        $table->unsignedBigInteger('detail_location_id')->nullable();
        $table->text('description')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('instrument_type', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name');
        $table->string('serial_number')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('calibration', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->date('calibration_date')->nullable();
        $table->date('calibration_due')->nullable();
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertDashboardInstrument(array $attributes): void
{
    DB::table('instrument')->insert(array_merge([
        'detail_location_id' => null,
        'description' => null,
        'created_at' => '2026-01-01 00:00:00',
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertDashboardCalibration(array $attributes): void
{
    DB::table('calibration')->insert(array_merge([
        'calibration_date' => '2026-08-01',
        'calibration_due' => today()->toDateString(),
    ], $attributes));
}

beforeEach(function () {
    Model::preventLazyLoading();
});

it('shows the dashboard at the root without authentication', function () {
    createDashboardTestTables();

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('Dashboard');
    $response->assertSee('Overview of instrument calibration');
    $response->assertSeeInOrder(['Recent Instruments', 'View All']);
    $response->assertSee('href="'.route('instruments.index').'"', false);
});

it('keeps the instrument and calibration routes available', function () {
    createDashboardTestTables();

    $this->get(route('instruments.index'))->assertOk();
    $this->get(route('instruments.show', 999))->assertNotFound();
    $this->get(route('calibrations.show', 999))->assertNotFound();
});

it('classifies each instrument once from its latest calibration', function () {
    createDashboardTestTables();

    insertDashboardInstrument(['instrument_id' => 1, 'instrument_name' => 'Tie Gauge']);
    insertDashboardInstrument(['instrument_id' => 2, 'instrument_name' => 'Due Today']);
    insertDashboardInstrument(['instrument_id' => 3, 'instrument_name' => 'Due In 60']);
    insertDashboardInstrument(['instrument_id' => 4, 'instrument_name' => 'Due In 61']);
    insertDashboardInstrument(['instrument_id' => 5, 'instrument_name' => 'No History']);

    DB::table('instrument_type')->insert([
        [
            'instrument_type_id' => 1,
            'instrument_id' => 1,
            'type_name' => 'Weight',
            'serial_number' => 'ONLY-SERIAL',
            'created_at' => '2026-01-01 00:00:00',
        ],
        [
            'instrument_type_id' => 2,
            'instrument_id' => 1,
            'type_name' => 'Diameter',
            'serial_number' => null,
            'created_at' => '2026-01-01 00:00:00',
        ],
    ]);

    insertDashboardCalibration([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'calibration_date' => '2026-08-01',
        'calibration_due' => today()->addDays(61)->toDateString(),
    ]);
    insertDashboardCalibration([
        'calibration_id' => 2,
        'instrument_id' => 1,
        'calibration_date' => '2026-08-01',
        'calibration_due' => today()->subDay()->toDateString(),
    ]);
    insertDashboardCalibration([
        'calibration_id' => 3,
        'instrument_id' => 2,
        'calibration_due' => today()->toDateString(),
    ]);
    insertDashboardCalibration([
        'calibration_id' => 4,
        'instrument_id' => 3,
        'calibration_due' => today()->addDays(60)->toDateString(),
    ]);
    insertDashboardCalibration([
        'calibration_id' => 5,
        'instrument_id' => 4,
        'calibration_due' => today()->addDays(61)->toDateString(),
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSeeInOrder(['Total Instruments', '5']);
    $response->assertSeeInOrder(['On Track', '1']);
    $response->assertSeeInOrder(['Overdue', '1']);
    $response->assertSeeInOrder(['Due Soon', '2']);
    $response->assertSeeInOrder(['Not Calibrated', '1']);
    $response->assertSee('ONLY-SERIAL');
    $response->assertSeeInOrder(['Tie Gauge', 'Overdue']);

    expect(substr_count($response->getContent(), 'Tie Gauge'))->toBe(1);
});

it('limits recent instruments to ten distinct identities', function () {
    createDashboardTestTables();

    foreach (range(1, 12) as $id) {
        insertDashboardInstrument([
            'instrument_id' => $id,
            'instrument_name' => sprintf('Gauge %02d', $id),
            'created_at' => '2026-02-01 00:00:00',
        ]);
    }

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('Gauge 12');
    $response->assertSee('Gauge 03');
    $response->assertDontSee('Gauge 02');
    $response->assertDontSee('Gauge 01');
    $response->assertSee('Not Calibrated');
    $response->assertSee('href="'.route('instruments.index').'"', false);
});

it('filters the recent table by instrument name without changing the totals', function () {
    createDashboardTestTables();

    foreach (range(1, 12) as $id) {
        insertDashboardInstrument([
            'instrument_id' => $id,
            'instrument_name' => sprintf('Gauge %02d', $id),
            'created_at' => '2026-02-01 00:00:00',
        ]);
    }

    $component = Livewire::test('pages::dashboard')
        ->set('search', 'gauge 0');

    $component->assertSee('Search by name');
    $component->assertSee('ui-button-primary', false);
    $component->assertSeeInOrder(['Total Instruments', '12']);
    $component->assertSee('Gauge 09');
    $component->assertSee('Gauge 01');
    $component->assertDontSee('Gauge 12');
    $component->assertDontSee('Gauge 10');

    Livewire::test('pages::dashboard')
        ->set('search', 'missing-name')
        ->assertSee('No instruments found.')
        ->assertDontSee('Gauge 12')
        ->assertSeeInOrder(['Total Instruments', '12']);
});
