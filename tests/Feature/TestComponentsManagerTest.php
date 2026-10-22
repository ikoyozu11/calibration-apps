<?php

use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\TestProfile;
use App\TestColumns\ColumnDefinition;
use App\TestColumns\SqliteTestColumnWriter;
use App\TestColumns\SqliteTestPointValueWriter;
use App\TestColumns\TestColumnWriteException;
use App\TestColumns\TestColumnWriter;
use App\TestColumns\TestPointValueWriter;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('opens the manage test components page from instrument detail', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    $this->get(route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 31]))
        ->assertSee('Manage Test Components')
        ->assertSee('href="'.route('instruments.types.test-components', ['instrument' => 3, 'instrumentType' => 31]).'"', false)
        ->assertDontSee('Add Test Table')
        ->assertDontSee('Manage Columns')
        ->assertDontSee('Add Row');

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->assertSee('Manage Test Components')
        ->assertSee('Back to instrument detail')
        ->assertSee('Add Test Table')
        ->assertSee('Test Tables');
});

it('rejects a test components page for a type that belongs to another instrument', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    DB::table('instrument')->insert([
        'instrument_id' => 4,
        'instrument_name' => 'Other',
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

    $this->get(route('instruments.types.test-components', ['instrument' => 3, 'instrumentType' => 40]))
        ->assertNotFound();
});

it('clears editor form state when switching test tables in the sidebar', function () {
    createInstrumentDetailTestTables();
    insertDynamicRowFixture();

    DB::table('test_profile')->insert([
        'test_profile_id' => 2,
        'instrument_type_id' => 31,
        'profile_name' => 'Second Table',
        'display_title' => 'Second title',
        'description' => null,
        'created_at' => '2026-10-14 09:00:00',
    ]);

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('selectProfile', 1)
        ->call('startManagingColumns', 1)
        ->set('columnLabel', 'Draft Label')
        ->assertSet('columnLabel', 'Draft Label')
        ->assertSet('managingColumnsProfileId', 1)
        ->call('selectProfile', 2)
        ->assertSet('selectedProfileId', 2)
        ->assertSet('columnLabel', '')
        ->assertSet('managingColumnsProfileId', null)
        ->assertSet('editingPointId', null)
        ->assertSee('Second Table');
});

it('creates the first test table without a display title', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->assertSee('Add Test Table')
        ->call('startAddingTestTable')
        ->assertSee('Profile name')
        ->assertSee('(optional)')
        ->set('profileName', ' Weight Calibration ')
        ->set('displayTitle', '   ')
        ->call('saveTestTable')
        ->assertHasNoErrors()
        ->assertSee('Test table added.')
        ->assertSee('Add Test Table')
        ->assertSee('Weight Calibration')
        ->assertSee('No columns yet.')
        ->assertDontSee('No test components found.')
        ->assertDontSee('Title not set')
        ->assertSet('addingTestTable', false);

    $this->assertDatabaseHas('test_profile', [
        'instrument_type_id' => 31,
        'profile_name' => 'Weight Calibration',
        'display_title' => null,
    ]);
    $this->assertDatabaseMissing('test_profile', [
        'instrument_type_id' => 32,
    ]);
});

it('creates a second test table and titles the existing one together', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    DB::table('test_profile')->insert([
        'test_profile_id' => 1,
        'instrument_type_id' => 31,
        'profile_name' => 'INTERNAL',
        'display_title' => null,
        'description' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);

    TestProfile::creating(function (TestProfile $profile): void {
        if ($profile->profile_name === 'BOOM') {
            throw new RuntimeException('stop the second table');
        }
    });

    try {
        expect(fn () => Livewire::test('pages::instruments.test-components', [
            'instrument' => Instrument::query()->findOrFail(3),
            'instrumentType' => InstrumentType::query()->findOrFail(31),
        ])
            ->call('startAddingTestTable')
            ->set('profileName', 'BOOM')
            ->set('displayTitle', 'New table')
            ->set('existingDisplayTitle', 'Changed title')
            ->call('saveTestTable'))->toThrow(RuntimeException::class);
    } finally {
        TestProfile::flushEventListeners();
    }

    expect(DB::table('test_profile')->where('profile_name', 'BOOM')->exists())->toBeFalse()
        ->and(DB::table('test_profile')->where('test_profile_id', 1)->value('display_title'))->toBeNull();

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startAddingTestTable')
        ->set('profileName', 'Diameter Check')
        ->set('displayTitle', '')
        ->set('existingDisplayTitle', '')
        ->call('saveTestTable')
        ->assertHasErrors(['displayTitle', 'existingDisplayTitle'])
        ->assertSee('Enter a table title.')
        ->assertSee('Enter a title for the existing test table.')
        ->set('displayTitle', ' diameter table ')
        ->set('existingDisplayTitle', ' Weight table ')
        ->call('saveTestTable')
        ->assertHasNoErrors()
        ->assertSee('Test table added.')
        ->assertSeeInOrder(['Weight table', 'diameter table'])
        ->assertSet('selectedProfileId', 2)
        ->assertSee('Diameter Check');

    $this->assertDatabaseHas('test_profile', [
        'test_profile_id' => 1,
        'instrument_type_id' => 31,
        'display_title' => 'Weight table',
    ]);
    $this->assertDatabaseHas('test_profile', [
        'profile_name' => 'Diameter Check',
        'instrument_type_id' => 31,
        'display_title' => 'diameter table',
    ]);
    $this->assertDatabaseCount('test_profile', 2);
});

it('rejects a duplicate table title', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    DB::table('test_profile')->insert([
        'test_profile_id' => 1,
        'instrument_type_id' => 31,
        'profile_name' => 'INTERNAL',
        'display_title' => null,
        'description' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startAddingTestTable')
        ->set('profileName', 'Second')
        ->set('displayTitle', ' scale ')
        ->set('existingDisplayTitle', 'Scale')
        ->call('saveTestTable')
        ->assertHasErrors(['displayTitle'])
        ->assertSee('Table titles must be different.')
        ->assertDontSee('Test table added.');

    expect(DB::table('test_profile')->count())->toBe(1)
        ->and(DB::table('test_profile')->where('test_profile_id', 1)->value('display_title'))->toBeNull();

    DB::table('test_profile')->where('test_profile_id', 1)->update(['display_title' => 'Weight table']);
    DB::table('test_profile')->insert([
        'test_profile_id' => 2,
        'instrument_type_id' => 31,
        'profile_name' => 'Diameter Check',
        'display_title' => 'Diameter table',
        'description' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startAddingTestTable')
        ->set('profileName', 'Third')
        ->set('displayTitle', 'WEIGHT TABLE')
        ->call('saveTestTable')
        ->assertHasErrors(['displayTitle'])
        ->assertSee('Table titles must be different.');

    $this->assertDatabaseCount('test_profile', 2);
    $this->assertDatabaseMissing('test_profile', [
        'profile_name' => 'Third',
    ]);
});

it('rejects a duplicate profile name for the instrument type', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    DB::table('test_profile')->insert([
        'test_profile_id' => 1,
        'instrument_type_id' => 31,
        'profile_name' => 'Weight Calibration',
        'display_title' => 'Keep me',
        'description' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startAddingTestTable')
        ->set('profileName', ' Weight Calibration ')
        ->set('displayTitle', 'New table')
        ->set('existingDisplayTitle', 'Changed title')
        ->call('saveTestTable')
        ->assertHasErrors(['profileName'])
        ->assertSee('A test table with this profile name already exists for this type.')
        ->assertDontSee('Test table added.');

    $this->assertDatabaseCount('test_profile', 1);
    $this->assertDatabaseHas('test_profile', [
        'test_profile_id' => 1,
        'display_title' => 'Keep me',
    ]);
});

it('rejects an invalid test table', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startAddingTestTable')
        ->set('profileName', '   ')
        ->call('saveTestTable')
        ->assertHasErrors(['profileName'])
        ->assertSee('Enter a profile name.')
        ->set('profileName', str_repeat('a', 151))
        ->call('saveTestTable')
        ->assertHasErrors(['profileName'])
        ->assertSee('The profile name must be 150 characters or fewer.')
        ->set('profileName', 'Valid profile')
        ->set('displayTitle', str_repeat('b', 256))
        ->call('saveTestTable')
        ->assertHasErrors(['displayTitle'])
        ->assertSee('The table title must be 255 characters or fewer.');

    $this->assertDatabaseCount('test_profile', 0);
});

it('uses the sqlite column writer while the test database is sqlite', function () {
    expect(app(TestColumnWriter::class))->toBeInstanceOf(SqliteTestColumnWriter::class);
});

it('adds the first column and keeps the legacy table until that save succeeds', function () {
    createInstrumentDetailTestTables();
    insertColumnManagerFixture();

    $component = Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ]);

    $component
        ->assertSee('Manage Columns')
        ->assertSee('+Tol')
        ->assertSee('Legacy point')
        ->call('startManagingColumns', 1)
        ->set('columnLabel', '   ')
        ->call('saveTestColumn')
        ->assertHasErrors(['columnLabel'])
        ->assertSee('Enter a column label.')
        ->assertSee('+Tol')
        ->set('columnLabel', 'Set Point')
        ->set('dataType', 'numeric')
        ->set('valueSource', 'template')
        ->set('unit', 'g')
        ->set('displayScale', '3')
        ->call('saveTestColumn')
        ->assertHasNoErrors()
        ->assertSee('Column added.')
        ->assertSee('Set Point')
        ->assertSee('set_point')
        ->assertDontSee('+Tol')
        ->assertDontSee('Legacy point');

    $this->assertDatabaseHas('test_column', [
        'test_profile_id' => 1,
        'column_key' => 'set_point',
        'column_label' => 'Set Point',
        'column_order' => 1,
        'data_type' => 'numeric',
        'value_source' => 'template',
        'unit' => 'g',
        'display_scale' => 3,
        'formula_code' => null,
    ]);
    $this->assertDatabaseHas('test_point', [
        'test_point_id' => 10,
        'nominal_value' => 100,
        'description' => 'Legacy point',
    ]);
    $this->assertDatabaseCount('test_point_value', 0);
});

it('keeps the column key when the label is edited', function () {
    createInstrumentDetailTestTables();
    insertColumnManagerFixture();

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startManagingColumns', 1)
        ->set('columnLabel', 'Set Point')
        ->call('saveTestColumn')
        ->call('editTestColumn', 1, 1)
        ->set('columnLabel', 'Nominal value')
        ->set('unit', 'kg')
        ->call('saveTestColumn')
        ->assertSee('Column updated.')
        ->assertSee('Nominal value')
        ->assertSee('set_point');

    $this->assertDatabaseHas('test_column', [
        'test_column_id' => 1,
        'column_key' => 'set_point',
        'column_label' => 'Nominal value',
        'unit' => 'kg',
    ]);
});

it('deletes a column only after confirmation and restores the legacy table', function () {
    createInstrumentDetailTestTables();
    insertColumnManagerFixture();

    $component = Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startManagingColumns', 1)
        ->set('columnLabel', 'Set Point')
        ->call('saveTestColumn')
        ->call('deleteTestColumn');

    $this->assertDatabaseCount('test_column', 1);

    $component
        ->call('askToDeleteTestColumn', 1, 1)
        ->assertSee('Delete this column?')
        ->call('cancelDeletingTestColumn')
        ->assertDontSee('Delete this column?')
        ->call('askToDeleteTestColumn', 1, 1)
        ->call('deleteTestColumn')
        ->assertSee('Column deleted.')
        ->assertSee('+Tol')
        ->assertSee('Legacy point');

    $this->assertDatabaseCount('test_column', 0);
});

it('reorders columns through the column writer', function () {
    createInstrumentDetailTestTables();
    insertColumnManagerFixture();

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startManagingColumns', 1)
        ->set('columnLabel', 'Note')
        ->set('dataType', 'text')
        ->set('valueSource', 'template')
        ->call('saveTestColumn')
        ->set('columnLabel', 'Setpoint')
        ->set('dataType', 'numeric')
        ->set('valueSource', 'template')
        ->call('saveTestColumn')
        ->call('moveTestColumn', 1, 2, 'up')
        ->assertSee('Column order updated.')
        ->assertSeeInOrder(['Setpoint', 'Note']);

    expect(DB::table('test_column')->orderBy('column_order')->pluck('column_key')->all())
        ->toBe(['setpoint', 'note']);
});

it('stores average and deviation formulas from numeric template or engineer columns', function () {
    createInstrumentDetailTestTables();
    insertColumnManagerFixture();

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startManagingColumns', 1)
        ->set('columnLabel', 'Reading')
        ->set('dataType', 'numeric')
        ->set('valueSource', 'engineer')
        ->call('saveTestColumn')
        ->set('columnLabel', 'Nominal')
        ->set('dataType', 'numeric')
        ->set('valueSource', 'template')
        ->call('saveTestColumn')
        ->set('columnLabel', 'Mean')
        ->set('dataType', 'text')
        ->set('valueSource', 'calculated')
        ->set('formulaCode', 'average')
        ->set('formulaInputs', ['reading'])
        ->call('saveTestColumn')
        ->assertHasErrors(['valueSource'])
        ->assertSee('A calculated column must be numeric or duration.')
        ->set('dataType', 'numeric')
        ->set('valueSource', 'calculated')
        ->set('formulaCode', 'average')
        ->set('formulaInputs', ['reading'])
        ->call('saveTestColumn')
        ->assertSee('Column added.')
        ->set('columnLabel', 'Error')
        ->set('dataType', 'numeric')
        ->set('valueSource', 'calculated')
        ->set('formulaCode', 'deviation')
        ->set('deviationMinuend', 'reading')
        ->set('deviationSubtrahend', 'nominal')
        ->call('saveTestColumn')
        ->assertSee('deviation of reading, nominal')
        ->set('columnLabel', 'Bad chain')
        ->set('dataType', 'numeric')
        ->set('valueSource', 'calculated')
        ->set('formulaCode', 'deviation')
        ->set('deviationMinuend', 'mean')
        ->set('deviationSubtrahend', 'nominal')
        ->call('saveTestColumn')
        ->assertHasErrors(['deviationMinuend'])
        ->call('askToDeleteTestColumn', 1, 1)
        ->call('deleteTestColumn')
        ->assertHasErrors(['columns'])
        ->assertSee('This column is used by a calculated column. Change that formula before deleting it.');

    $this->assertDatabaseHas('test_column', [
        'column_key' => 'mean',
        'formula_code' => 'average',
    ]);
    expect(DB::table('test_column')->where('column_key', 'mean')->value('formula_inputs'))
        ->toBe('["reading"]');
    $this->assertDatabaseHas('test_column', [
        'column_key' => 'error',
        'formula_code' => 'deviation',
    ]);
    expect(DB::table('test_column')->where('column_key', 'error')->value('formula_inputs'))
        ->toBe('["reading","nominal"]');
    $this->assertDatabaseMissing('test_column', [
        'column_key' => 'bad_chain',
    ]);
    $this->assertDatabaseHas('test_column', [
        'column_key' => 'reading',
    ]);
});

it('shows a database constraint message and does not save the column', function () {
    createInstrumentDetailTestTables();
    insertColumnManagerFixture();

    app()->instance(TestColumnWriter::class, new class implements TestColumnWriter
    {
        public function insert(ColumnDefinition $column): int
        {
            throw new TestColumnWriteException('A column with this key already exists on this test table.', 'columnLabel');
        }

        public function update(int $columnId, ColumnDefinition $column): void {}

        public function delete(int $columnId): void {}

        public function reorder(int $profileId, array $columnIds): void {}
    });

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startManagingColumns', 1)
        ->set('columnLabel', 'Set Point')
        ->call('saveTestColumn')
        ->assertHasErrors(['columnLabel'])
        ->assertSee('A column with this key already exists on this test table.')
        ->assertSee('+Tol');

    $this->assertDatabaseCount('test_column', 0);
});

it('rejects column changes for a test table on another type', function () {
    createInstrumentDetailTestTables();
    insertColumnManagerFixture();

    DB::table('test_profile')->insert([
        'test_profile_id' => 2,
        'instrument_type_id' => 32,
        'profile_name' => 'Diameter Test',
        'display_title' => null,
        'description' => null,
        'created_at' => '2026-10-14 09:00:00',
    ]);

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startManagingColumns', 2)
        ->assertNotFound();
});

it('uses the sqlite template value writer while the test database is sqlite', function () {
    expect(app(TestPointValueWriter::class))->toBeInstanceOf(SqliteTestPointValueWriter::class);
});

it('adds a row and saves template values without writing engineer cells', function () {
    createInstrumentDetailTestTables();
    insertDynamicRowFixture();

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->assertSee('Add Row')
        ->assertSee('Standard Value')
        ->call('startAddingRow', 1)
        ->assertSee('Row added.')
        ->assertSee('Edit row')
        ->set('templateValues.1', '10.5')
        ->set('templateValues.2', 'stable-note')
        ->set('templateValues.3', '99.25')
        ->call('saveTestRow')
        ->assertHasNoErrors()
        ->assertSee('Row saved.')
        ->assertSee('10.5')
        ->assertSee('stable-note')
        ->assertDontSee('99.25');

    $this->assertDatabaseCount('test_point', 1);
    $this->assertDatabaseHas('test_point', [
        'test_profile_id' => 1,
        'point_order' => 1,
    ]);
    $this->assertDatabaseHas('test_point_value', [
        'test_profile_id' => 1,
        'test_column_id' => 1,
        'numeric_value' => 10.5,
        'text_value' => null,
    ]);
    $this->assertDatabaseHas('test_point_value', [
        'test_profile_id' => 1,
        'test_column_id' => 2,
        'numeric_value' => null,
        'text_value' => 'stable-note',
    ]);
    $this->assertDatabaseMissing('test_point_value', [
        'test_column_id' => 3,
    ]);
    $this->assertDatabaseHas('test_column', [
        'test_column_id' => 1,
        'column_key' => 'standard_value',
    ]);
});

it('clears a blank template value and rejects an invalid number', function () {
    createInstrumentDetailTestTables();
    insertDynamicRowFixture();

    $component = Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startAddingRow', 1)
        ->set('templateValues.1', '10.5')
        ->set('templateValues.2', 'keep-me')
        ->call('saveTestRow')
        ->call('editTestRow', 1, 1)
        ->set('templateValues.1', 'not-a-number')
        ->call('saveTestRow')
        ->assertHasErrors(['templateValues.1'])
        ->assertSee('Enter a number for Standard Value.');

    $this->assertDatabaseHas('test_point_value', [
        'test_column_id' => 1,
        'numeric_value' => 10.5,
    ]);

    $component
        ->set('templateValues.1', '')
        ->set('templateValues.2', 'keep-me')
        ->call('saveTestRow')
        ->assertHasNoErrors()
        ->assertSee('Row saved.')
        ->assertDontSee('10.5')
        ->assertSee('keep-me');

    $this->assertDatabaseMissing('test_point_value', [
        'test_column_id' => 1,
    ]);
    $this->assertDatabaseHas('test_point_value', [
        'test_column_id' => 2,
        'text_value' => 'keep-me',
    ]);
});

it('deletes a row only after confirmation and restores the empty table', function () {
    createInstrumentDetailTestTables();
    insertDynamicRowFixture();

    $component = Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startAddingRow', 1)
        ->set('templateValues.1', '10.5')
        ->call('saveTestRow')
        ->call('deleteTestRow');

    $this->assertDatabaseCount('test_point', 1);

    $component
        ->call('askToDeleteTestRow', 1, 1)
        ->assertSee('Delete this row?')
        ->call('cancelDeletingTestRow')
        ->assertDontSee('Delete this row?')
        ->call('askToDeleteTestRow', 1, 1)
        ->call('deleteTestRow')
        ->assertSee('Row deleted.')
        ->assertSee('No test points yet.');

    $this->assertDatabaseCount('test_point', 0);
    $this->assertDatabaseCount('test_point_value', 0);
});

it('reorders rows by point_order', function () {
    createInstrumentDetailTestTables();
    insertDynamicRowFixture();

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startAddingRow', 1)
        ->set('templateValues.1', '100')
        ->call('saveTestRow')
        ->call('startAddingRow', 1)
        ->set('templateValues.1', '200')
        ->call('saveTestRow')
        ->call('moveTestRow', 1, 2, 'up')
        ->assertSee('Row order updated.')
        ->assertSeeInOrder(['200', '100']);

    expect(DB::table('test_point')->orderBy('point_order')->pluck('test_point_id')->all())
        ->toBe([2, 1]);
});

it('shows numeric template values with trailing zeros and leaves the stored value unchanged', function () {
    createInstrumentDetailTestTables();
    insertDynamicRowFixture();

    DB::table('test_column')->where('test_column_id', 1)->update(['display_scale' => 3]);
    DB::table('test_point')->insert([
        'test_point_id' => 1,
        'test_profile_id' => 1,
        'test_profile_version_id' => 1,
        'point_order' => 1,
        'created_at' => '2026-10-14 09:00:00',
    ]);
    DB::table('test_point_value')->insert([
        'test_point_value_id' => 1,
            'test_profile_id' => 1,
        'test_profile_version_id' => 1,
            'test_point_id' => 1,
        'test_column_id' => 1,
        'numeric_value' => 8,
        'text_value' => null,
        'unit' => null,
        'created_at' => '2026-10-14 09:00:00',
    ]);

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->assertSee('8.000')
        ->call('editTestRow', 1, 1)
        ->set('templateValues.2', 'keep-me')
        ->call('saveTestRow')
        ->assertSee('Row saved.')
        ->assertSee('8.000');

    $this->assertDatabaseHas('test_point_value', [
        'test_column_id' => 1,
        'numeric_value' => 8,
        'text_value' => null,
    ]);
});

it('saves duration template values as whole seconds and displays hh:mm:ss', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    DB::table('test_profile')->insert([
        'test_profile_id' => 1,
        'instrument_type_id' => 31,
        'profile_name' => 'Main Test',
        'display_title' => null,
        'description' => null,
        'created_at' => '2026-10-14 09:00:00',
    ]);

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startManagingColumns', 1)
        ->set('columnLabel', 'Standard Time')
        ->set('dataType', 'duration')
        ->set('valueSource', 'template')
        ->call('saveTestColumn')
        ->assertSee('Column added.')
        ->assertSee('hh:mm:ss')
        ->call('startAddingRow', 1)
        ->set('templateValues.1', '00:60:00')
        ->call('saveTestRow')
        ->assertHasErrors(['templateValues.1'])
        ->assertSee('Enter a duration as hh:mm:ss for Standard Time.')
        ->set('templateValues.1', '0.03.00')
        ->call('saveTestRow')
        ->assertHasNoErrors()
        ->assertSee('Row saved.')
        ->assertSee('00:03:00');

    $this->assertDatabaseHas('test_point_value', [
        'test_profile_id' => 1,
        'test_column_id' => 1,
        'numeric_value' => 180,
        'text_value' => null,
    ]);
});

it('stores correction formulas against column keys for numeric and duration columns', function () {
    createInstrumentDetailTestTables();
    insertColumnManagerFixture();

    Livewire::test('pages::instruments.test-components', [
            'instrument' => Instrument::query()->findOrFail(3),
            'instrumentType' => InstrumentType::query()->findOrFail(31),
        ])
        ->call('startManagingColumns', 1)
        ->set('columnLabel', 'Standard Value')
        ->set('dataType', 'numeric')
        ->set('valueSource', 'template')
        ->call('saveTestColumn')
        ->set('columnLabel', 'Reading Average')
        ->set('dataType', 'numeric')
        ->set('valueSource', 'engineer')
        ->call('saveTestColumn')
        ->set('columnLabel', 'Correction')
        ->set('dataType', 'numeric')
        ->set('valueSource', 'calculated')
        ->set('formulaCode', 'standard_minus_reading')
        ->set('deviationMinuend', 'standard_value')
        ->set('deviationSubtrahend', 'reading_average')
        ->call('saveTestColumn')
        ->assertSee('Column added.')
        ->assertSee('standard_minus_reading of standard_value, reading_average')
        ->set('columnLabel', 'Standard Time')
        ->set('dataType', 'duration')
        ->set('valueSource', 'template')
        ->call('saveTestColumn')
        ->set('columnLabel', 'Reading Time')
        ->set('dataType', 'duration')
        ->set('valueSource', 'engineer')
        ->call('saveTestColumn')
        ->set('columnLabel', 'Time Correction')
        ->set('dataType', 'duration')
        ->set('valueSource', 'calculated')
        ->set('formulaCode', 'reading_minus_standard')
        ->set('deviationMinuend', 'reading_time')
        ->set('deviationSubtrahend', 'standard_time')
        ->call('saveTestColumn')
        ->assertSee('reading_minus_standard of reading_time, standard_time')
        ->set('columnLabel', 'Mixed Correction')
        ->set('dataType', 'duration')
        ->set('valueSource', 'calculated')
        ->set('formulaCode', 'standard_minus_reading')
        ->set('deviationMinuend', 'standard_value')
        ->set('deviationSubtrahend', 'reading_average')
        ->call('saveTestColumn')
        ->assertHasErrors(['deviationMinuend']);

    $this->assertDatabaseHas('test_column', [
        'column_key' => 'correction',
        'data_type' => 'numeric',
        'formula_code' => 'standard_minus_reading',
    ]);
    expect(DB::table('test_column')->where('column_key', 'correction')->value('formula_inputs'))
        ->toBe('["standard_value","reading_average"]');
    $this->assertDatabaseHas('test_column', [
        'column_key' => 'time_correction',
        'data_type' => 'duration',
        'formula_code' => 'reading_minus_standard',
    ]);
    $this->assertDatabaseMissing('test_column', [
        'column_key' => 'mixed_correction',
    ]);
});

it('shows only the draft version on the manage page when an active version also exists', function () {
    createInstrumentDetailTestTables();
    insertTwoVersionTestTable();

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->assertSee('Draft Current')
        ->assertSee('draft-cell-200')
        ->assertSee('Draft · v2')
        ->assertSee('Manage Columns')
        ->assertDontSee('Active Voltage')
        ->assertDontSee('active-cell-100');
});

it('rejects column and row changes when the test table has no draft version', function () {
    createInstrumentDetailTestTables();
    insertDynamicRowFixture();

    DB::table('test_profile_version')->where('test_profile_version_id', 1)->update([
        'status' => 'active',
        'published_at' => '2026-10-14 09:00:00',
    ]);

    $component = Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->assertSee('This published test table is read-only.')
        ->assertSee('Active · v1 · read-only')
        ->assertDontSee('Manage Columns')
        ->assertDontSee('Add Row')
        ->call('startManagingColumns', 1)
        ->assertHasErrors(['testTable'])
        ->assertSee('This published test table is read-only.');

    $this->assertDatabaseCount('test_column', 3);

    $component
        ->call('startAddingRow', 1)
        ->assertHasErrors(['testTable']);

    $this->assertDatabaseCount('test_point', 0);
});

it('creates a draft version with a new test table', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startAddingTestTable')
        ->set('profileName', 'Weight Calibration')
        ->call('saveTestTable')
        ->assertHasNoErrors()
        ->assertSee('Draft · v1');

    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_id' => 1,
        'version_number' => 1,
        'status' => 'draft',
        'profile_name' => 'Weight Calibration',
        'published_at' => null,
    ]);
});

it('still edits a test table when the versioning catalog is not installed', function () {
    createInstrumentDetailTestTables(withVersioning: false);
    insertInstrumentDetailSubject();

    DB::table('test_profile')->insert([
            'test_profile_id' => 1,
            'instrument_type_id' => 31,
        'profile_name' => 'Main Test',
        'display_title' => null,
            'description' => null,
            'created_at' => '2026-10-14 09:00:00',
    ]);

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->assertDontSee('Draft · v1')
        ->call('startManagingColumns', 1)
        ->set('columnLabel', 'Legacy Note')
        ->call('saveTestColumn')
        ->assertHasNoErrors()
        ->assertSee('Column added.')
        ->assertSee('Legacy Note');

    $this->assertDatabaseHas('test_column', [
        'test_profile_id' => 1,
        'column_label' => 'Legacy Note',
    ]);
});

it('publishes a complete draft and then shows the active version as read-only', function () {
    createInstrumentDetailTestTables();
    insertTwoVersionTestTable();

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->assertSee('Publish Version')
        ->assertDontSee('Fork Draft')
        ->call('askToPublishVersion', 1)
        ->assertSee('Publish this draft as the active version?')
        ->call('publishVersion', 1)
        ->assertHasNoErrors()
        ->assertSee('Version published.')
        ->assertSee('Active · v2 · read-only')
        ->assertSee('This published test table is read-only.')
        ->assertSee('Draft Current')
        ->assertSee('Fork Draft')
        ->assertDontSee('Manage Columns')
        ->assertDontSee('Publish Version');

    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 12,
        'status' => 'active',
        'version_number' => 2,
    ]);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 11,
        'status' => 'retired',
        'version_number' => 1,
    ]);
});

it('rejects publishing a draft that has no columns or rows', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('startAddingTestTable')
        ->set('profileName', 'Weight Calibration')
        ->call('saveTestTable')
        ->assertSee('Publish Version')
        ->call('askToPublishVersion', 1)
        ->call('publishVersion', 1)
        ->assertHasErrors(['testTable'])
        ->assertSee('Publish a draft that has at least one column and one row.')
        ->assertSee('Draft · v1')
        ->assertSee('Manage Columns');

    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_id' => 1,
        'status' => 'draft',
        'version_number' => 1,
        'published_at' => null,
    ]);
});

it('forks the active version into a draft that can be edited', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->assertSee('Fork Draft')
        ->assertDontSee('Publish Version')
        ->assertSee('This published test table is read-only.')
        ->call('forkDraft', 1)
        ->assertHasNoErrors()
        ->assertSee('Draft created.')
        ->assertSee('Draft · v2')
        ->assertSee('Manage Columns')
        ->assertSee('Publish Version')
        ->assertDontSee('Fork Draft')
        ->assertDontSee('This published test table is read-only.')
        ->call('startManagingColumns', 1)
        ->assertHasNoErrors()
        ->assertSee('Add column');

    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 11,
        'status' => 'active',
        'version_number' => 1,
    ]);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_id' => 1,
        'status' => 'draft',
        'version_number' => 2,
        'published_at' => null,
    ]);
});

it('rejects forking when a draft already exists', function () {
    createInstrumentDetailTestTables();
    insertTwoVersionTestTable();

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->assertDontSee('Fork Draft')
        ->call('forkDraft', 1)
        ->assertHasErrors(['testTable'])
        ->assertSee('A draft version already exists for this test table.')
        ->assertSee('Draft · v2')
        ->assertSee('Manage Columns');

    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 11,
        'status' => 'active',
    ]);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 12,
        'status' => 'draft',
    ]);
    $this->assertDatabaseCount('test_profile_version', 2);
});

it('rejects fork and publish for a test table that belongs to another instrument type', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();

    DB::table('test_profile')->insert([
        'test_profile_id' => 2,
        'instrument_type_id' => 32,
        'profile_name' => 'Foreign Table',
        'display_title' => null,
        'description' => null,
        'created_at' => '2026-10-14 09:00:00',
    ]);
    insertTestProfileVersion([
        'test_profile_version_id' => 21,
        'test_profile_id' => 2,
        'profile_name' => 'Foreign Table',
        'status' => 'active',
    ]);

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('forkDraft', 2)
        ->assertNotFound();

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('publishVersion', 2)
        ->assertNotFound();

    $this->assertDatabaseCount('test_profile_version', 2);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 11,
        'status' => 'active',
    ]);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 21,
        'status' => 'active',
    ]);
});

it('does not show fork or publish actions when the versioning catalog is not installed', function () {
    createInstrumentDetailTestTables(withVersioning: false);
    insertInstrumentDetailSubject();

    DB::table('test_profile')->insert([
        'test_profile_id' => 1,
        'instrument_type_id' => 31,
        'profile_name' => 'Main Test',
        'display_title' => null,
        'description' => null,
        'created_at' => '2026-10-14 09:00:00',
    ]);

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->assertDontSee('Publish Version')
        ->assertDontSee('Fork Draft')
        ->assertDontSee('Draft · v1')
        ->assertSee('Manage Columns')
        ->call('forkDraft', 1)
        ->assertNotFound();

    Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ])
        ->call('askToPublishVersion', 1)
        ->assertNotFound();
});
