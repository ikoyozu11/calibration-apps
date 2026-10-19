<?php

use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\StandardMaster;
use App\Standards\InstrumentTypeStandardAssignmentWriter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

it('opens the manage standards page from instrument detail', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    $this->get(route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 31]))
        ->assertSee('Standard(s) Used')
        ->assertSee('No standards assigned.')
        ->assertSee('Manage Standards')
        ->assertSee('href="'.route('instruments.types.standards', ['instrument' => 3, 'instrumentType' => 31]).'"', false)
        ->assertDontSee('Assign Standard');

    Livewire::test('pages::instruments.standards', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->assertSee('Manage Standards')
        ->assertSee('Back to instrument detail')
        ->assertSee('Assigned Standards')
        ->assertSee('Assign Standard')
        ->assertSee('Add New Standard')
        ->assertSee('No standards assigned.');
});

it('rejects a standards page for a type that belongs to another instrument', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    DB::table('instrument')->insert([
        'instrument_id' => 4,
        'instrument_name' => 'Other',
        'serial_number' => 'OTHER-1',
        'detail_location_id' => null,
        'description' => null,
        'created_at' => '2026-10-07 09:17:33',
    ]);
    DB::table('instrument_type')->insert([
        'instrument_type_id' => 40,
        'instrument_id' => 4,
        'type_name' => 'Foreign',
        'description' => null,
        'created_at' => '2026-10-07 09:17:33',
    ]);

    $this->get(route('instruments.types.standards', ['instrument' => 3, 'instrumentType' => 40]))
        ->assertNotFound();
});

it('searches candidate standards by name type and serial', function (string $search, string $expected, string $unexpected) {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', 'Standard PD', 'ET4276');
    insertStandardMaster(11, 'STD-0011', 'Weight Standard', 'Mass', 'W-9');

    Livewire::test('pages::instruments.standards', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->set('search', $search)
        ->assertSee($expected)
        ->assertDontSee($unexpected);
})->with([
    'name' => ['Pressure Drop', 'ET4276', 'Weight Standard'],
    'serial' => ['ET4276', 'Pressure Drop Standard', 'Weight Standard'],
    'type' => ['Standard PD', 'ET4276', 'Weight Standard'],
]);

it('assigns a selected master standard and shows it on instrument detail', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', '99.0 mmWG', 'ET4276');

    Livewire::test('pages::instruments.standards', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('selectCandidate', 10)
        ->assertSee('Selected Standard')
        ->assertSee('STD-0010')
        ->call('assignStandard')
        ->assertSee('Standard assigned.')
        ->assertSee('Pressure Drop Standard')
        ->assertSee('ET4276')
        ->assertSee('99.0 mmWG');

    $this->get(route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 31]))
        ->assertSee('Pressure Drop Standard')
        ->assertSee('ET4276')
        ->assertSee('99.0 mmWG')
        ->assertDontSee('No standards assigned.');
});

it('creates a new master standard from manage standards and selects it', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    Livewire::test('pages::instruments.standards', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startAddingStandard')
        ->set('newInstrumentName', 'Weight Standard E2 Class')
        ->set('newTypeSpecification', '0.0001 - 10.0000 gr')
        ->set('newSerialNumber', '40488')
        ->call('createStandard')
        ->assertSee('Standard saved. Assign it to this instrument type when ready.')
        ->assertSee('Selected Standard')
        ->assertSee('Weight Standard E2 Class')
        ->assertSee('40488')
        ->call('assignStandard')
        ->assertSee('Standard assigned.')
        ->assertSee('STD-0001');

    $this->assertDatabaseHas('standard_master', [
        'standard_code' => 'STD-0001',
        'instrument_name' => 'Weight Standard E2 Class',
        'type_specification' => '0.0001 - 10.0000 gr',
        'serial_number' => '40488',
    ]);
});

it('prevents duplicate assignment from the manager', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', null, 'ET4276');

    $writer = new InstrumentTypeStandardAssignmentWriter;
    $writer->assign(InstrumentType::query()->findOrFail(31), StandardMaster::query()->findOrFail(10));

    Livewire::test('pages::instruments.standards', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('selectCandidate', 10)
        ->call('assignStandard')
        ->assertHasErrors(['standard']);
});

it('assigns multiple standards and reorders them', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertStandardMaster(10, 'STD-0010', 'Standard A', null, 'A-1');
    insertStandardMaster(11, 'STD-0011', 'Standard B', null, 'B-1');

    $component = Livewire::test('pages::instruments.standards', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ]);

    $component->call('selectCandidate', 10)->call('assignStandard');
    $component->call('selectCandidate', 11)->call('assignStandard');

    $secondId = (int) DB::table('instrument_type_standard_assignment')
        ->where('standard_id', 11)
        ->value('instrument_type_standard_assignment_id');

    $component
        ->call('moveAssignment', $secondId, -1)
        ->assertSee('Standard order updated.');

    expect(DB::table('instrument_type_standard_assignment')->orderBy('assignment_order')->pluck('standard_id')->all())
        ->toBe([11, 10]);
});

it('removes an assignment only after confirmation and leaves the master standard', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', null, 'ET4276');

    $writer = new InstrumentTypeStandardAssignmentWriter;
    $assignment = $writer->assign(InstrumentType::query()->findOrFail(31), StandardMaster::query()->findOrFail(10));

    Livewire::test('pages::instruments.standards', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('askToRemove', $assignment->instrument_type_standard_assignment_id)
        ->assertSee('Remove this standard assignment?')
        ->call('removeAssignment')
        ->assertSee('Standard assignment removed.')
        ->assertSee('No standards assigned.');

    $this->assertDatabaseCount('instrument_type_standard_assignment', 0);
    $this->assertDatabaseHas('standard_master', [
        'standard_id' => 10,
        'instrument_name' => 'Pressure Drop Standard',
    ]);
});

it('does not mutate calibration standard usage when an assignment is removed', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', null, 'ET4276');

    Schema::create('calibration_standard_usage', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_standard_usage_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('standard_instrument_id');
        $table->integer('usage_order');
        $table->string('standard_range')->nullable();
        $table->string('standard_unit')->nullable();
        $table->timestamp('created_at');
    });
    DB::table('calibration_standard_usage')->insert([
        'calibration_standard_usage_id' => 1,
        'calibration_id' => 9,
        'standard_instrument_id' => 10,
        'usage_order' => 1,
        'standard_range' => '99.0',
        'standard_unit' => 'mmWG',
        'created_at' => '2026-06-12 09:00:00',
    ]);

    $writer = new InstrumentTypeStandardAssignmentWriter;
    $assignment = $writer->assign(InstrumentType::query()->findOrFail(31), StandardMaster::query()->findOrFail(10), '198.1', 'mmWG');

    Livewire::test('pages::instruments.standards', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('askToRemove', $assignment->instrument_type_standard_assignment_id)
        ->call('removeAssignment');

    $this->assertDatabaseHas('calibration_standard_usage', [
        'calibration_standard_usage_id' => 1,
        'standard_range' => '99.0',
        'standard_unit' => 'mmWG',
    ]);
});
