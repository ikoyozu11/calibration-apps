<?php

use App\Models\InstrumentType;
use App\Models\StandardMaster;
use App\Standards\InstrumentTypeStandardAssignmentException;
use App\Standards\InstrumentTypeStandardAssignmentWriter;
use App\Standards\StandardMasterException;
use App\Standards\StandardMasterWriter;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('assigns optional default range and unit without coercing textual values', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', '6.00 / 8.00 mmWG', 'ET4276');

    $writer = new InstrumentTypeStandardAssignmentWriter;
    $assignment = $writer->assign(
        InstrumentType::query()->findOrFail(31),
        StandardMaster::query()->findOrFail(10),
        '6.00 / 8.00',
        'mmWG',
    );

    expect($assignment->assignment_order)->toBe(1)
        ->and($assignment->standard_id)->toBe(10)
        ->and($assignment->instrumentName())->toBe('Pressure Drop Standard')
        ->and($assignment->default_range)->toBe('6.00 / 8.00')
        ->and($assignment->default_unit)->toBe('mmWG');

    $this->assertDatabaseHas('instrument_type_standard_assignment', [
        'instrument_type_id' => 31,
        'standard_id' => 10,
        'assignment_order' => 1,
        'default_range' => '6.00 / 8.00',
        'default_unit' => 'mmWG',
    ]);
});

it('rejects a duplicate assignment for the same instrument type', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', null, 'ET4276');

    $writer = new InstrumentTypeStandardAssignmentWriter;
    $type = InstrumentType::query()->findOrFail(31);
    $standard = StandardMaster::query()->findOrFail(10);
    $writer->assign($type, $standard);

    expect(fn () => $writer->assign($type, $standard))
        ->toThrow(InstrumentTypeStandardAssignmentException::class, 'This standard is already assigned.');

    $this->assertDatabaseCount('instrument_type_standard_assignment', 1);
});

it('allows one master standard to be assigned to multiple instrument types', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', '99.0 mmWG', 'ET4276');

    $writer = new InstrumentTypeStandardAssignmentWriter;
    $standard = StandardMaster::query()->findOrFail(10);
    $writer->assign(InstrumentType::query()->findOrFail(31), $standard, '99.0', 'mmWG');
    $writer->assign(InstrumentType::query()->findOrFail(32), $standard, '100-200', 'mmWG');

    $this->assertDatabaseCount('instrument_type_standard_assignment', 2);
});

it('allows the same serial number on different master entries', function () {
    createInstrumentDetailTestTables();
    insertStandardMaster(10, 'STD-0010', 'Diameter Standard', '16.00 mm', '28104');
    insertStandardMaster(11, 'STD-0011', 'Diameter Standard', '30.00 mm', '28104');

    $this->assertDatabaseCount('standard_master', 2);
    expect(StandardMaster::query()->where('serial_number', '28104')->count())->toBe(2);
});

it('stores two serial numbers in one field', function () {
    createInstrumentDetailTestTables();
    insertStandardMaster(10, 'STD-0010', 'Sodiline', null, '9710375_10/97 & 9710376_10/97');

    $this->assertDatabaseHas('standard_master', [
        'standard_code' => 'STD-0010',
        'serial_number' => '9710375_10/97 & 9710376_10/97',
    ]);
});

it('updates defaults without changing historical calibration usage', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', '99.0 mmWG', 'ET4276');

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
    $type = InstrumentType::query()->findOrFail(31);
    $assignment = $writer->assign($type, StandardMaster::query()->findOrFail(10), '99.0', 'mmWG');
    $writer->updateDefaults($type, $assignment->instrument_type_standard_assignment_id, '198.1', 'mmWG');
    $writer->remove($type, $assignment->instrument_type_standard_assignment_id);

    $this->assertDatabaseHas('calibration_standard_usage', [
        'calibration_standard_usage_id' => 1,
        'standard_instrument_id' => 10,
        'standard_range' => '99.0',
        'standard_unit' => 'mmWG',
    ]);
    $this->assertDatabaseCount('instrument_type_standard_assignment', 0);
});

it('reorders assignments and compact remaining order after remove', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertStandardMaster(10, 'STD-0010', 'Standard A', null, 'A-1');
    insertStandardMaster(11, 'STD-0011', 'Standard B', null, 'B-1');
    insertStandardMaster(12, 'STD-0012', 'Standard C', null, 'C-1');

    $writer = new InstrumentTypeStandardAssignmentWriter;
    $type = InstrumentType::query()->findOrFail(31);
    $first = $writer->assign($type, StandardMaster::query()->findOrFail(10));
    $second = $writer->assign($type, StandardMaster::query()->findOrFail(11));
    $third = $writer->assign($type, StandardMaster::query()->findOrFail(12));

    $writer->move($type, $third->instrument_type_standard_assignment_id, -1);

    expect(DB::table('instrument_type_standard_assignment')->orderBy('assignment_order')->pluck('standard_id')->all())
        ->toBe([10, 12, 11]);

    $writer->remove($type, $second->instrument_type_standard_assignment_id);

    expect(DB::table('instrument_type_standard_assignment')->orderBy('assignment_order')->get(['assignment_order', 'standard_id'])->map(fn ($row): array => [
        (int) $row->assignment_order,
        (int) $row->standard_id,
    ])->all())->toBe([
        [1, 10],
        [2, 12],
    ]);

    expect($first->instrument_type_standard_assignment_id)->not->toBe($second->instrument_type_standard_assignment_id);
});

it('blocks deleting a master standard while it is assigned', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', null, 'ET4276');

    $writer = new InstrumentTypeStandardAssignmentWriter;
    $writer->assign(InstrumentType::query()->findOrFail(31), StandardMaster::query()->findOrFail(10));

    expect(fn () => DB::table('standard_master')->where('standard_id', 10)->delete())
        ->toThrow(QueryException::class);
    expect(fn () => (new StandardMasterWriter)->delete(10))
        ->toThrow(StandardMasterException::class, 'This standard is assigned to an instrument type and cannot be deleted.');

    $this->assertDatabaseHas('standard_master', ['standard_id' => 10]);
    $this->assertDatabaseHas('instrument_type_standard_assignment', [
        'instrument_type_id' => 31,
        'standard_id' => 10,
    ]);
});
