<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createInstrumentDetailTestTables(): void
{
    Schema::create('location', function (Blueprint $table): void {
        $table->unsignedBigInteger('location_id')->primary();
        $table->string('location_name', 150);
    });

    Schema::create('detail_location', function (Blueprint $table): void {
        $table->unsignedBigInteger('detail_location_id')->primary();
        $table->unsignedBigInteger('location_id');
        $table->string('detail_location_name', 150);
    });

    Schema::create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name', 150);
        $table->unsignedBigInteger('detail_location_id')->nullable();
        $table->text('description')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('instrument_type', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name', 150);
        $table->text('description')->nullable();
        $table->timestamp('created_at');
        $table->string('asset_number')->nullable();
        $table->string('serial_number')->nullable();
        $table->decimal('max_capacity')->nullable();
        $table->string('max_capacity_unit')->nullable();
        $table->decimal('resolution')->nullable();
        $table->string('resolution_unit')->nullable();
        $table->string('brand')->nullable();
        $table->string('model')->nullable();
    });

    Schema::create('calibration', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->unsignedBigInteger('calibration_provider_id');
        $table->string('calibration_number', 100);
        $table->date('calibration_date')->nullable();
        $table->date('calibration_due')->nullable();
        $table->string('calibration_method', 20);
        $table->string('calibration_result', 20)->nullable();
        $table->string('certificate_number', 100)->nullable();
        $table->text('remarks')->nullable();
        $table->timestamps();
        $table->date('action_date')->nullable();
        $table->text('resume')->nullable();
        $table->unsignedBigInteger('customer_id')->nullable();
    });
}

it('renders one instrument type identity', function () {
    createInstrumentDetailTestTables();

    DB::table('location')->insert([
        'location_id' => 1,
        'location_name' => 'Plant A',
    ]);
    DB::table('detail_location')->insert([
        'detail_location_id' => 2,
        'location_id' => 1,
        'detail_location_name' => 'Line 2',
    ]);
    DB::table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => 2,
        'description' => 'Prototype instrument',
        'created_at' => '2026-10-07 09:17:33',
    ]);

    DB::table('instrument_type')->insert([
        [
            'instrument_type_id' => 31,
            'instrument_id' => 3,
            'type_name' => 'Weight',
            'description' => 'Weight measurement',
            'created_at' => '2026-10-07 09:17:33',
            'asset_number' => 'ASSET-9',
            'serial_number' => 'WEIGHT-001',
            'max_capacity' => 1000,
            'max_capacity_unit' => 'g',
            'resolution' => 0.1,
            'resolution_unit' => 'g',
            'brand' => 'Prototype Brand',
            'model' => 'Prototype Model',
        ],
        [
            'instrument_type_id' => 32,
            'instrument_id' => 3,
            'type_name' => 'Diameter',
            'description' => null,
            'created_at' => '2026-10-07 09:17:33',
            'asset_number' => null,
            'serial_number' => 'DIA-002',
            'max_capacity' => null,
            'max_capacity_unit' => null,
            'resolution' => null,
            'resolution_unit' => null,
            'brand' => null,
            'model' => null,
        ],
    ]);

    $response = $this->get(route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 31]));

    $response
        ->assertSee('Instrument Identity')
        ->assertSee('Sodiline')
        ->assertDontSee('Prototype instrument')
        ->assertSee('Plant A')
        ->assertSee('Line 2')
        ->assertSee('Weight')
        ->assertSee('WEIGHT-001')
        ->assertSee('ASSET-9')
        ->assertSee('Prototype Brand')
        ->assertSee('Prototype Model')
        ->assertSee('1000 g')
        ->assertSee('0.1 g')
        ->assertDontSee('Weight measurement')
        ->assertDontSee('Description')
        ->assertDontSee('DIA-002')
        ->assertSee('No calibration history found.');
});

it('opens the first instrument type from the instrument address', function () {
    createInstrumentDetailTestTables();

    DB::table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => 2,
        'description' => 'Prototype instrument',
        'created_at' => '2026-10-07 09:17:33',
    ]);

    DB::table('instrument_type')->insert([
        [
            'instrument_type_id' => 32,
            'instrument_id' => 3,
            'type_name' => 'Diameter',
            'description' => null,
            'created_at' => '2026-10-07 09:17:33',
            'asset_number' => null,
            'serial_number' => null,
            'max_capacity' => null,
            'max_capacity_unit' => null,
            'resolution' => null,
            'resolution_unit' => null,
            'brand' => null,
            'model' => null,
        ],
        [
            'instrument_type_id' => 31,
            'instrument_id' => 3,
            'type_name' => 'Weight',
            'description' => null,
            'created_at' => '2026-10-07 09:17:33',
            'asset_number' => null,
            'serial_number' => null,
            'max_capacity' => null,
            'max_capacity_unit' => null,
            'resolution' => null,
            'resolution_unit' => null,
            'brand' => null,
            'model' => null,
        ],
    ]);

    $this->get(route('instruments.show', 3))
        ->assertRedirect(route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 31]));
});

it('shows a dash when a calibration has no due date', function () {
    createInstrumentDetailTestTables();

    DB::table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => 2,
        'description' => 'Prototype instrument',
        'created_at' => '2026-10-07 09:17:33',
    ]);

    DB::table('instrument_type')->insert([
        'instrument_type_id' => 31,
        'instrument_id' => 3,
        'type_name' => 'Weight',
        'description' => null,
        'created_at' => '2026-10-07 09:17:33',
        'asset_number' => null,
        'serial_number' => null,
        'max_capacity' => null,
        'max_capacity_unit' => null,
        'resolution' => null,
        'resolution_unit' => null,
        'brand' => null,
        'model' => null,
    ]);
    DB::table('calibration')->insert([
        'calibration_id' => 103,
        'instrument_id' => 3,
        'calibration_provider_id' => 1,
        'calibration_number' => 'CAL-NO-DUE',
        'calibration_date' => '2026-10-07',
        'calibration_due' => null,
        'calibration_method' => 'EXTERNAL',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-NO-DUE',
        'remarks' => 'Due date unavailable',
        'created_at' => '2026-10-07 09:00:00',
        'updated_at' => '2026-10-07 09:00:00',
        'action_date' => null,
        'resume' => null,
        'customer_id' => 1,
    ]);

    $response = $this->get(route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 31]));

    $response
        ->assertSeeInOrder(['Calibration Date', 'Calibration Due', 'Status', 'Remarks'])
        ->assertSee('2026-10-07')
        ->assertSee('Due date unavailable')
        ->assertDontSee('CAL-NO-DUE')
        ->assertDontSee('On Track')
        ->assertDontSee('Overdue');
});

it('lists instrument calibrations from the newest date on every type', function () {
    createInstrumentDetailTestTables();

    DB::table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => null,
        'description' => null,
        'created_at' => '2026-10-07 09:17:33',
    ]);
    DB::table('instrument_type')->insert([
        [
            'instrument_type_id' => 31,
            'instrument_id' => 3,
            'type_name' => 'Weight',
            'description' => null,
            'created_at' => '2026-10-07 09:17:33',
            'asset_number' => null,
            'serial_number' => null,
            'max_capacity' => null,
            'max_capacity_unit' => null,
            'resolution' => null,
            'resolution_unit' => null,
            'brand' => null,
            'model' => null,
        ],
        [
            'instrument_type_id' => 32,
            'instrument_id' => 3,
            'type_name' => 'Diameter',
            'description' => null,
            'created_at' => '2026-10-07 09:17:33',
            'asset_number' => null,
            'serial_number' => null,
            'max_capacity' => null,
            'max_capacity_unit' => null,
            'resolution' => null,
            'resolution_unit' => null,
            'brand' => null,
            'model' => null,
        ],
    ]);
    DB::table('calibration')->insert([
        [
            'calibration_id' => 101,
            'instrument_id' => 3,
            'calibration_provider_id' => 1,
            'calibration_number' => 'CAL-OLD-001',
            'calibration_date' => '2025-10-21',
            'calibration_due' => '2026-10-21',
            'calibration_method' => 'EXTERNAL',
            'calibration_result' => 'PASS',
            'certificate_number' => null,
            'remarks' => 'Older calibration',
            'created_at' => '2025-10-21 09:00:00',
            'updated_at' => '2025-10-21 09:00:00',
            'action_date' => null,
            'resume' => null,
            'customer_id' => null,
        ],
        [
            'calibration_id' => 102,
            'instrument_id' => 3,
            'calibration_provider_id' => 1,
            'calibration_number' => 'CAL-NEW-001',
            'calibration_date' => '2026-10-07',
            'calibration_due' => '2027-10-07',
            'calibration_method' => 'EXTERNAL',
            'calibration_result' => 'PASS',
            'certificate_number' => null,
            'remarks' => 'Newer calibration',
            'created_at' => '2026-10-07 09:00:00',
            'updated_at' => '2026-10-07 09:00:00',
            'action_date' => null,
            'resume' => null,
            'customer_id' => null,
        ],
    ]);

    $this->travelTo('2027-01-01');

    $weight = $this->get(route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 31]));
    $diameter = $this->get(route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 32]));

    $weight
        ->assertSeeInOrder(['2026-10-07', '2027-10-07', 'On Track', 'Newer calibration', '2025-10-21', '2026-10-21', 'Overdue', 'Older calibration'])
        ->assertDontSee('CAL-NEW-001');
    $diameter->assertSeeInOrder(['Newer calibration', 'Older calibration']);
});

it('shows five calibration history rows and continues on the next page', function () {
    createInstrumentDetailTestTables();

    DB::table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => null,
        'description' => null,
        'created_at' => '2026-10-07 09:17:33',
    ]);
    DB::table('instrument_type')->insert([
        'instrument_type_id' => 31,
        'instrument_id' => 3,
        'type_name' => 'Weight',
        'description' => null,
        'created_at' => '2026-10-07 09:17:33',
        'asset_number' => null,
        'serial_number' => null,
        'max_capacity' => null,
        'max_capacity_unit' => null,
        'resolution' => null,
        'resolution_unit' => null,
        'brand' => null,
        'model' => null,
    ]);

    $rows = [];

    foreach (range(1, 6) as $number) {
        $rows[] = [
            'calibration_id' => 200 + $number,
            'instrument_id' => 3,
            'calibration_provider_id' => 1,
            'calibration_number' => 'CAL-PAGE-'.$number,
            'calibration_date' => sprintf('2026-06-%02d', $number),
            'calibration_due' => sprintf('2027-06-%02d', $number),
            'calibration_method' => 'EXTERNAL',
            'calibration_result' => 'PASS',
            'certificate_number' => null,
            'remarks' => 'History '.$number,
            'created_at' => '2026-06-01 09:00:00',
            'updated_at' => '2026-06-01 09:00:00',
            'action_date' => null,
            'resume' => null,
            'customer_id' => null,
        ];
    }

    DB::table('calibration')->insert($rows);

    $firstPage = $this->get(route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 31]));
    $secondPage = $this->get(route('instruments.types.show', [
        'instrument' => 3,
        'instrumentType' => 31,
        'page' => 2,
    ]));

    $firstPage
        ->assertSeeInOrder(['History 6', 'History 5', 'History 4', 'History 3', 'History 2'])
        ->assertDontSee('History 1');
    $secondPage
        ->assertSee('History 1')
        ->assertDontSee('History 6');
});

it('returns not found when the type belongs to another instrument', function () {
    createInstrumentDetailTestTables();

    DB::table('instrument')->insert([
        [
            'instrument_id' => 3,
            'instrument_name' => 'Sodiline',
            'detail_location_id' => null,
            'description' => null,
            'created_at' => '2026-10-07 09:17:33',
        ],
        [
            'instrument_id' => 4,
            'instrument_name' => 'Gauge',
            'detail_location_id' => null,
            'description' => null,
            'created_at' => '2026-10-07 09:17:33',
        ],
    ]);
    DB::table('instrument_type')->insert([
        'instrument_type_id' => 40,
        'instrument_id' => 4,
        'type_name' => 'Weight',
        'description' => null,
        'created_at' => '2026-10-07 09:17:33',
        'asset_number' => null,
        'serial_number' => null,
        'max_capacity' => null,
        'max_capacity_unit' => null,
        'resolution' => null,
        'resolution_unit' => null,
        'brand' => null,
        'model' => null,
    ]);

    $this->get(route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 40]))
        ->assertNotFound();
});

it('links each instrument name to its detail page', function () {
    createInstrumentDetailTestTables();

    DB::table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => 2,
        'description' => null,
        'created_at' => '2026-10-07 09:17:33',
    ]);

    $response = $this->get(route('instruments.index'));

    $response->assertSee('href="'.route('instruments.show', 3).'"', false);
});

it('returns not found for an unknown instrument', function () {
    createInstrumentDetailTestTables();

    $response = $this->get(route('instruments.show', 999));

    $response->assertNotFound();
});
