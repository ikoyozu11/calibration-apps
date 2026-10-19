<?php

use App\Standards\StandardMasterException;
use App\Standards\StandardMasterImporter;
use App\Standards\StandardMasterWorkbook;
use App\Standards\StandardMasterWriter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('imports exactly 70 unique standard codes from the approved workbook', function () {
    createInstrumentDetailTestTables();

    $path = database_path('imports/Standard_Used_Master_Final_70.xlsx');
    $rows = (new StandardMasterWorkbook)->rows($path);

    expect($rows)->toHaveCount(70)
        ->and(collect($rows)->pluck('standard_code')->unique()->count())->toBe(70)
        ->and(collect($rows)->firstWhere('standard_code', 'STD-0001'))->toMatchArray([
            'instrument_name' => 'Weight Standard E2 Class',
            'type_specification' => '0.0001 - 10.0000 gr',
            'serial_number' => '40488',
        ])
        ->and(collect($rows)->firstWhere('standard_code', 'STD-0070')['type_specification'])->toBeNull()
        ->and(collect($rows)->contains(fn (array $row): bool => $row['serial_number'] === '9710375_10/97 & 9710376_10/97'))->toBeTrue()
        ->and(collect($rows)->where('serial_number', '28104')->count())->toBe(2)
        ->and(collect($rows)->where('instrument_name', 'Diameter Standard')->count())->toBeGreaterThan(1);

    $importer = new StandardMasterImporter(new StandardMasterWorkbook);
    $first = $importer->import($path);
    $second = $importer->import($path);

    expect($first['imported'])->toBe(70)
        ->and($first['skipped'])->toBe(0)
        ->and($second['imported'])->toBe(0)
        ->and($second['skipped'])->toBe(70);

    $this->assertDatabaseCount('standard_master', 70);

    Artisan::call('standards:import-master', ['path' => $path]);

    $this->assertDatabaseCount('standard_master', 70);
});

it('does not overwrite a manually edited master row on subsequent import', function () {
    createInstrumentDetailTestTables();

    $path = database_path('imports/Standard_Used_Master_Final_70.xlsx');
    $importer = new StandardMasterImporter(new StandardMasterWorkbook);
    $importer->import($path);

    $id = (int) DB::table('standard_master')->where('standard_code', 'STD-0001')->value('standard_id');
    (new StandardMasterWriter)->update($id, 'Edited Weight Standard', 'custom range', 'EDIT-1');

    $importer->import($path);

    $this->assertDatabaseHas('standard_master', [
        'standard_code' => 'STD-0001',
        'instrument_name' => 'Edited Weight Standard',
        'type_specification' => 'custom range',
        'serial_number' => 'EDIT-1',
    ]);
    $this->assertDatabaseCount('standard_master', 70);
});

it('creates edits and deletes an unassigned standard', function () {
    createInstrumentDetailTestTables();

    $writer = new StandardMasterWriter;
    $standard = $writer->create('Weight Standard', 'E2', '40488');

    expect($standard->standard_code)->toBe('STD-0001')
        ->and($standard->type_specification)->toBe('E2')
        ->and($standard->serial_number)->toBe('40488');

    $updated = $writer->update($standard->standard_id, 'Weight Standard E2 Class', null, '40488');

    expect($updated->instrument_name)->toBe('Weight Standard E2 Class')
        ->and($updated->type_specification)->toBeNull();

    $writer->delete($standard->standard_id);

    $this->assertDatabaseCount('standard_master', 0);
});

it('requires an instrument name', function () {
    createInstrumentDetailTestTables();

    expect(fn () => (new StandardMasterWriter)->create('   '))
        ->toThrow(StandardMasterException::class, 'Instrument Name is required.');
});
