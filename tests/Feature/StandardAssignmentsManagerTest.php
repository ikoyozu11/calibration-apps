<?php

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
        'instrument' => 3,
        'instrumentType' => 31,
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
        'instrument' => 3,
        'instrumentType' => 31,
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
        'instrument' => 3,
        'instrumentType' => 31,
    ])
        ->call('selectCandidate', 10)
        ->assertDispatched('instruments-desk-confirm-standard-assignment')
        ->call('assignStandard')
        ->assertDontSee('Move up')
        ->assertDontSee('Move down')
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
        'instrument' => 3,
        'instrumentType' => 31,
    ])
        ->call('startAddingStandard')
        ->set('newInstrumentName', 'Weight Standard E2 Class')
        ->set('newTypeSpecification', '0.0001 - 10.0000 gr')
        ->set('newSerialNumber', '40488')
        ->call('createStandard')
        ->assertDontSee('Standard saved. Assign it to this instrument type when ready.')
        ->assertDontSee('Selected Standard')
        ->assertSee('Weight Standard E2 Class')
        ->assertSee('40488')
        ->call('assignStandard')
        ->assertDontSee('Standard assigned.')
        ->assertSee('STD-0001');

    $this->assertDatabaseHas('standard_master', [
        'standard_code' => 'STD-0001',
        'instrument_name' => 'Weight Standard E2 Class',
        'type_specification' => '0.0001 - 10.0000 gr',
        'serial_number' => '40488',
    ]);
});

it('opens assignment confirmation without changing assignments', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', '99.0 mmWG', 'ET4276');

    Livewire::test('pages::instruments.standards', [
        'instrument' => 3,
        'instrumentType' => 31,
    ])
        ->call('selectCandidate', 10)
        ->assertDispatched('instruments-desk-confirm-standard-assignment')
        ->assertDontSee('Selected Standard')
        ->call('clearSelection');

    $this->assertDatabaseCount('instrument_type_standard_assignment', 0);
});

it('opens the add standard custom modal without rendering an inline form', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    Livewire::test('pages::instruments.standards', [
        'instrument' => 3,
        'instrumentType' => 31,
    ])
        ->assertSee('Add New Standard')
        ->assertSee('Create a new standard in the Standard Master.')
        ->call('startAddingStandard')
        ->assertSet('addingStandard', true)
        ->assertSee('Add New Standard')
        ->assertSee('Instrument Name')
        ->assertSee('Type / Specification')
        ->assertSee('Serial Number');
});

it('validates the add standard form before asking for confirmation', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    Livewire::test('pages::instruments.standards', [
        'instrument' => 3,
        'instrumentType' => 31,
    ])
        ->call('startAddingStandard')
        ->call('prepareNewStandardCreation')
        ->assertHasErrors(['newInstrumentName'])
        ->assertNotDispatched('instruments-desk-confirm-new-standard-creation');
});

it('cancels a new standard without saving it', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    Livewire::test('pages::instruments.standards', [
        'instrument' => 3,
        'instrumentType' => 31,
    ])
        ->call('startAddingStandard')
        ->set('newInstrumentName', 'Temporary Standard')
        ->call('cancelAddingStandard')
        ->assertSet('addingStandard', false)
        ->assertSet('newInstrumentName', '')
        ->assertSet('newTypeSpecification', '')
        ->assertSet('newSerialNumber', '');

    $this->assertDatabaseCount('standard_master', 0);
});

it('dispatches standard creation confirmation after validation', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    Livewire::test('pages::instruments.standards', [
        'instrument' => 3,
        'instrumentType' => 31,
    ])
        ->call('startAddingStandard')
        ->set('newInstrumentName', 'Pressure Drop Standard')
        ->set('newTypeSpecification', 'Standard PD')
        ->set('newSerialNumber', 'ET4276')
        ->call('prepareNewStandardCreation')
        ->assertDispatched('instruments-desk-confirm-new-standard-creation');
});

it('creates a standard with optional fields left blank', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    Livewire::test('pages::instruments.standards', [
        'instrument' => 3,
        'instrumentType' => 31,
    ])
        ->call('startAddingStandard')
        ->set('newInstrumentName', 'Length Standard')
        ->call('createStandard')
        ->assertSet('addingStandard', false);

    $this->assertDatabaseHas('standard_master', [
        'instrument_name' => 'Length Standard',
        'type_specification' => null,
        'serial_number' => null,
    ]);
});

it('preserves the modal state and field errors when standard creation validation fails', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    Livewire::test('pages::instruments.standards', [
        'instrument' => 3,
        'instrumentType' => 31,
    ])
        ->call('startAddingStandard')
        ->set('newInstrumentName', 'Pressure Drop Standard')
        ->set('newTypeSpecification', str_repeat('x', 151))
        ->call('createStandard')
        ->assertSet('addingStandard', true)
        ->assertHasErrors(['newTypeSpecification']);
});

it('prevents duplicate assignment from the manager', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', null, 'ET4276');

    $writer = new InstrumentTypeStandardAssignmentWriter;
    $writer->assign(InstrumentType::query()->findOrFail(31), StandardMaster::query()->findOrFail(10));

    Livewire::test('pages::instruments.standards', [
        'instrument' => 3,
        'instrumentType' => 31,
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
        'instrument' => 3,
        'instrumentType' => 31,
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
        'instrument' => 3,
        'instrumentType' => 31,
    ])
        ->assertDontSee('Remove this standard assignment?')
        ->assertDontSee('Move up')
        ->assertDontSee('Move down')
        ->call('removeAssignment', $assignment->instrument_type_standard_assignment_id)
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
        'instrument' => 3,
        'instrumentType' => 31,
    ])
        ->call('removeAssignment', $assignment->instrument_type_standard_assignment_id);

    $this->assertDatabaseHas('calibration_standard_usage', [
        'calibration_standard_usage_id' => 1,
        'standard_range' => '99.0',
        'standard_unit' => 'mmWG',
    ]);
});
