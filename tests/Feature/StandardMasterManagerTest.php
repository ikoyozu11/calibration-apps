<?php

use App\Models\InstrumentType;
use App\Models\StandardMaster;
use App\Standards\InstrumentTypeStandardAssignmentWriter;
use Livewire\Livewire;

it('lists and searches the standard master', function () {
    createInstrumentDetailTestTables();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', 'Standard PD', 'ET4276');
    insertStandardMaster(11, 'STD-0011', 'Weight Standard', null, 'W-9');

    Livewire::test('pages::standards')
        ->assertSee('Standard Master')
        ->assertSee('STD-0010')
        ->assertSee('Pressure Drop Standard')
        ->assertSee('Add New Standard')
        ->set('search', 'ET4276')
        ->assertSee('Pressure Drop Standard')
        ->assertDontSee('Weight Standard');
});

it('creates and edits a standard from the master page', function () {
    createInstrumentDetailTestTables();

    $component = Livewire::test('pages::standards')
        ->call('startAdding')
        ->set('instrumentName', 'Weight Standard E2 Class')
        ->set('typeSpecification', '0.0001 - 10.0000 gr')
        ->set('serialNumber', '40488')
        ->call('saveStandard')
        ->assertSee('Standard saved.')
        ->assertSee('STD-0001')
        ->assertSee('Weight Standard E2 Class');

    $id = (int) StandardMaster::query()->value('standard_id');

    $component
        ->call('startEditing', $id)
        ->set('instrumentName', 'Weight Standard F1 Class')
        ->set('typeSpecification', '')
        ->call('saveStandard')
        ->assertSee('Standard updated.')
        ->assertSee('Weight Standard F1 Class')
        ->assertDontSee('0.0001 - 10.0000 gr');
});

it('shows where a standard is assigned and blocks deletion', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', '99.0 mmWG', 'ET4276');

    (new InstrumentTypeStandardAssignmentWriter)->assign(
        InstrumentType::query()->findOrFail(31),
        StandardMaster::query()->findOrFail(10),
    );

    Livewire::test('pages::standards')
        ->assertSee('Sodiline · Weight')
        ->call('askToRemove', 10)
        ->call('removeStandard')
        ->assertSee('This standard is assigned to an instrument type and cannot be deleted.');

    $this->assertDatabaseHas('standard_master', ['standard_id' => 10]);
});

it('deletes an unassigned standard after confirmation', function () {
    createInstrumentDetailTestTables();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', null, 'ET4276');

    Livewire::test('pages::standards')
        ->call('askToRemove', 10)
        ->call('removeStandard')
        ->assertSee('Standard deleted.');

    $this->assertDatabaseCount('standard_master', 0);
});
