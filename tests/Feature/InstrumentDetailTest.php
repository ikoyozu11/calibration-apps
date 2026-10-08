<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createInstrumentDetailTestTables(): void
{
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

it('renders an instrument identity and its types', function () {
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
            'instrument_type_id' => 31,
            'instrument_id' => 3,
            'type_name' => 'Weight',
            'description' => 'Weight measurement',
            'created_at' => '2026-10-07 09:17:33',
            'asset_number' => null,
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
            'serial_number' => null,
            'max_capacity' => null,
            'max_capacity_unit' => null,
            'resolution' => null,
            'resolution_unit' => null,
            'brand' => null,
            'model' => null,
        ],
        [
            'instrument_type_id' => 33,
            'instrument_id' => 3,
            'type_name' => 'PD',
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

    $response = $this->get(route('instruments.show', 3));

    $response
        ->assertSee('Instrument Identity')
        ->assertSee('Sodiline')
        ->assertSee('Instrument Types')
        ->assertSeeInOrder(['Weight', 'Diameter', 'PD'])
        ->assertSee('WEIGHT-001')
        ->assertSee('Prototype Brand')
        ->assertSee('Calibration History')
        ->assertSee('No calibration history found.');
});

it('renders calibration history in descending calibration date order', function () {
    createInstrumentDetailTestTables();

    DB::table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => 2,
        'description' => 'Prototype instrument',
        'created_at' => '2026-10-07 09:17:33',
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
            'certificate_number' => 'CERT-OLD-001',
            'remarks' => 'Older calibration',
            'created_at' => '2025-10-21 09:00:00',
            'updated_at' => '2025-10-21 09:00:00',
            'action_date' => null,
            'resume' => null,
            'customer_id' => 1,
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
            'certificate_number' => 'CERT-NEW-001',
            'remarks' => 'Newer calibration',
            'created_at' => '2026-10-07 09:00:00',
            'updated_at' => '2026-10-07 09:00:00',
            'action_date' => null,
            'resume' => 'Instrument working properly',
            'customer_id' => 1,
        ],
    ]);

    $this->travelTo(Carbon::parse('2027-01-01'));

    $response = $this->get(route('instruments.show', 3));

    $response
        ->assertSee('Calibration History')
        ->assertSeeInOrder(['CAL-NEW-001', 'CAL-OLD-001'])
        ->assertSee('CERT-NEW-001')
        ->assertSee('CERT-OLD-001')
        ->assertSee('On Track')
        ->assertSee('Overdue');
});

it('shows no calibration status when the due date is missing', function () {
    createInstrumentDetailTestTables();

    DB::table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => 2,
        'description' => 'Prototype instrument',
        'created_at' => '2026-10-07 09:17:33',
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

    $response = $this->get(route('instruments.show', 3));

    $response
        ->assertSee('CAL-NO-DUE')
        ->assertDontSee('On Track')
        ->assertDontSee('Overdue');
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
