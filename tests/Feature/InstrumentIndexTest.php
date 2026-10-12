<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

function createInstrumentIndexTables(): void
{
    Schema::create('location', function (Blueprint $table): void {
        $table->unsignedBigInteger('location_id')->primary();
        $table->string('location_name');
    });

    Schema::create('detail_location', function (Blueprint $table): void {
        $table->unsignedBigInteger('detail_location_id')->primary();
        $table->unsignedBigInteger('location_id');
        $table->string('detail_location_name');
    });

    Schema::create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name');
        $table->unsignedBigInteger('detail_location_id')->nullable();
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
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertInstrumentIndexRow(array $attributes): void
{
    DB::table('instrument')->insert([
        'instrument_id' => $attributes['instrument_id'],
        'instrument_name' => $attributes['instrument_name'],
        'detail_location_id' => $attributes['detail_location_id'] ?? null,
        'created_at' => '2026-02-01 00:00:00',
    ]);
}

beforeEach(function () {
    Model::preventLazyLoading();
});

it('shows one instrument row with its type serial asset and location', function () {
    createInstrumentIndexTables();

    DB::table('location')->insert([
        'location_id' => 1,
        'location_name' => 'Plant A',
    ]);
    DB::table('detail_location')->insert([
        'detail_location_id' => 2,
        'location_id' => 1,
        'detail_location_name' => 'Line 2',
    ]);
    insertInstrumentIndexRow([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => 2,
    ]);
    DB::table('instrument_type')->insert([
        'instrument_type_id' => 1,
        'instrument_id' => 3,
        'type_name' => 'Weight',
        'brand' => 'Sodim',
        'model' => 'PDV - PD',
        'serial_number' => 'WEIGHT-001',
        'asset_number' => 'ASSET-9',
    ]);

    $response = $this->get(route('instruments.index'));

    $response->assertSeeInOrder([
        'Instrument Name',
        'Brand',
        'Type',
        'Model',
        'Serial Number',
        'Asset Number',
        'Location',
        'Detail Location',
    ]);
    $response->assertSeeInOrder(['Sodiline', 'Sodim', 'Weight', 'PDV - PD', 'WEIGHT-001', 'ASSET-9', 'Plant A', 'Line 2']);
    $response->assertSee('href="'.route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 1]).'"', false);
    $response->assertDontSee('Calibration History');
});

it('shows a separate row for every type even when one serial is shared', function () {
    createInstrumentIndexTables();

    insertInstrumentIndexRow([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
    ]);
    DB::table('instrument_type')->insert([
        [
            'instrument_type_id' => 1,
            'instrument_id' => 3,
            'type_name' => 'Weight',
            'serial_number' => 'ONLY-SERIAL',
            'asset_number' => 'ASSET-1',
        ],
        [
            'instrument_type_id' => 2,
            'instrument_id' => 3,
            'type_name' => 'Diameter',
            'serial_number' => 'ONLY-SERIAL',
            'asset_number' => 'ASSET-2',
        ],
        [
            'instrument_type_id' => 3,
            'instrument_id' => 3,
            'type_name' => 'PD',
            'serial_number' => null,
            'asset_number' => 'ASSET-3',
        ],
    ]);

    $response = $this->get(route('instruments.index'));

    $response->assertSeeInOrder([
        'Sodiline', 'Weight', 'ONLY-SERIAL', 'ASSET-1',
        'Sodiline', 'Diameter', 'ONLY-SERIAL', 'ASSET-2',
        'Sodiline', 'PD', '—', 'ASSET-3',
    ]);
    $response->assertSee('href="'.route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 1]).'"', false);
    $response->assertSee('href="'.route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 2]).'"', false);
    $response->assertSee('href="'.route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 3]).'"', false);
});

it('pages each instrument type as its own row', function () {
    createInstrumentIndexTables();

    insertInstrumentIndexRow([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
    ]);

    foreach (range(1, 12) as $id) {
        DB::table('instrument_type')->insert([
            'instrument_type_id' => $id,
            'instrument_id' => 3,
            'type_name' => sprintf('Type %02d', $id),
            'serial_number' => null,
            'asset_number' => null,
        ]);
    }

    $component = Livewire::test('pages::instruments');

    $component->assertSee('Type 01');
    $component->assertSee('Type 10');
    $component->assertDontSee('Type 11');

    $component->call('gotoPage', 2);

    $component->assertSee('Type 11');
    $component->assertSee('Type 12');
    $component->assertSee('Sodiline');
    $component->assertDontSee('Type 01');
});

it('shows ten instruments per page and the rest on the next page', function () {
    createInstrumentIndexTables();

    foreach (range(1, 12) as $id) {
        insertInstrumentIndexRow([
            'instrument_id' => $id,
            'instrument_name' => sprintf('Gauge %02d', $id),
        ]);
    }

    $component = Livewire::test('pages::instruments');

    $component->assertSee('Gauge 01');
    $component->assertSee('Gauge 10');
    $component->assertDontSee('Gauge 11');

    $component->call('gotoPage', 2);

    $component->assertSee('Gauge 11');
    $component->assertSee('Gauge 12');
    $component->assertDontSee('Gauge 01');
});

it('filters the instrument list by name', function () {
    createInstrumentIndexTables();

    insertInstrumentIndexRow(['instrument_id' => 1, 'instrument_name' => 'Digital Caliper']);
    insertInstrumentIndexRow(['instrument_id' => 2, 'instrument_name' => 'Sodiline']);

    Livewire::test('pages::instruments')
        ->set('search', 'digital')
        ->assertSee('Digital Caliper')
        ->assertDontSee('Sodiline');
});

it('keeps the instrument type and location totals while a name search changes the list', function () {
    createInstrumentIndexTables();

    DB::table('location')->insert([
        ['location_id' => 1, 'location_name' => 'Plant A'],
        ['location_id' => 2, 'location_name' => 'Plant B'],
        ['location_id' => 3, 'location_name' => '   '],
    ]);
    DB::table('detail_location')->insert([
        ['detail_location_id' => 10, 'location_id' => 1, 'detail_location_name' => 'Line 1'],
        ['detail_location_id' => 11, 'location_id' => 1, 'detail_location_name' => 'Line 2'],
        ['detail_location_id' => 12, 'location_id' => 2, 'detail_location_name' => 'Line 3'],
        ['detail_location_id' => 13, 'location_id' => 3, 'detail_location_name' => 'Blank'],
    ]);
    insertInstrumentIndexRow(['instrument_id' => 1, 'instrument_name' => 'Sodiline', 'detail_location_id' => 10]);
    insertInstrumentIndexRow(['instrument_id' => 2, 'instrument_name' => 'Digital Caliper', 'detail_location_id' => 11]);
    insertInstrumentIndexRow(['instrument_id' => 3, 'instrument_name' => 'Gauge', 'detail_location_id' => 12]);
    insertInstrumentIndexRow(['instrument_id' => 4, 'instrument_name' => 'Spare', 'detail_location_id' => 13]);
    insertInstrumentIndexRow(['instrument_id' => 5, 'instrument_name' => 'Loose']);
    DB::table('instrument_type')->insert([
        ['instrument_type_id' => 1, 'instrument_id' => 1, 'type_name' => 'Weight', 'serial_number' => null, 'asset_number' => null],
        ['instrument_type_id' => 2, 'instrument_id' => 1, 'type_name' => 'Diameter', 'serial_number' => null, 'asset_number' => null],
        ['instrument_type_id' => 3, 'instrument_id' => 2, 'type_name' => 'Mitutoyo', 'serial_number' => null, 'asset_number' => null],
    ]);

    Livewire::test('pages::instruments')
        ->assertSeeInOrder(['Instruments', '5', 'Types', '3', 'Locations', '2'])
        ->set('search', 'digital')
        ->assertSee('Digital Caliper')
        ->assertDontSee('Sodiline')
        ->assertSeeInOrder(['Instruments', '5', 'Types', '3', 'Locations', '2']);
});

it('keeps the page size to ten twenty five or fifty rows', function () {
    createInstrumentIndexTables();

    foreach (range(1, 12) as $id) {
        insertInstrumentIndexRow([
            'instrument_id' => $id,
            'instrument_name' => sprintf('Gauge %02d', $id),
        ]);
    }

    Livewire::test('pages::instruments')
        ->set('perPage', 25)
        ->assertSee('Gauge 01')
        ->assertSee('Gauge 12')
        ->set('perPage', 100)
        ->assertSet('perPage', 10)
        ->assertDontSee('Gauge 11');
});
