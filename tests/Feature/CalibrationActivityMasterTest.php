<?php

use App\Activities\CalibrationActivityMasterException;
use App\Activities\CalibrationActivityMasterImporter;
use App\Activities\CalibrationActivityMasterWorkbook;
use App\Activities\CalibrationActivityMasterWriter;
use App\Models\CalibrationActivityMaster;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('imports exactly 16 unique activity codes from the approved workbook', function () {
    createInstrumentDetailTestTables();

    $path = database_path('imports/Calibration_Activity_Master_Extracted.xlsx');
    $rows = (new CalibrationActivityMasterWorkbook)->rows($path);

    expect($rows)->toHaveCount(16)
        ->and(collect($rows)->pluck('activity_code')->unique()->count())->toBe(16)
        ->and(collect($rows)->firstWhere('activity_code', 'ACT-0001'))->toMatchArray([
            'activity_name' => 'Verification',
        ])
        ->and(collect($rows)->firstWhere('activity_code', 'ACT-0003')['activity_name'])->toBe('Cleaning/replace vacuum filter')
        ->and(collect($rows)->firstWhere('activity_code', 'ACT-0016')['activity_name'])->toBe('Verification with weight standard')
        ->and(collect($rows)->where('activity_name', 'Cleaning')->count())->toBe(1)
        ->and(collect($rows)->where('activity_name', 'Cleaning All Module')->count())->toBe(1);

    $importer = new CalibrationActivityMasterImporter(new CalibrationActivityMasterWorkbook);
    $first = $importer->import($path);
    $second = $importer->import($path);

    expect($first['imported'])->toBe(16)
        ->and($first['skipped'])->toBe(0)
        ->and($first['total'])->toBe(16)
        ->and($second['imported'])->toBe(0)
        ->and($second['skipped'])->toBe(16);

    $this->assertDatabaseCount('calibration_activity_master', 16);
    $this->assertDatabaseCount('calibration_activity', 0);

    Artisan::call('activities:import-master', ['path' => $path]);

    $this->assertDatabaseCount('calibration_activity_master', 16);
});

it('does not overwrite a manually edited activity name on subsequent import', function () {
    createInstrumentDetailTestTables();

    $path = database_path('imports/Calibration_Activity_Master_Extracted.xlsx');
    $importer = new CalibrationActivityMasterImporter(new CalibrationActivityMasterWorkbook);
    $importer->import($path);

    $id = (int) DB::table('calibration_activity_master')->where('activity_code', 'ACT-0001')->value('activity_id');
    (new CalibrationActivityMasterWriter)->update($id, 'Edited Verification');

    $importer->import($path);

    $this->assertDatabaseHas('calibration_activity_master', [
        'activity_code' => 'ACT-0001',
        'activity_name' => 'Edited Verification',
    ]);
    $this->assertDatabaseCount('calibration_activity_master', 16);
});

it('creates edits deactivates and deletes an unused activity', function () {
    createInstrumentDetailTestTables();

    $writer = new CalibrationActivityMasterWriter;
    $activity = $writer->create('Leakage test');

    expect($activity->activity_code)->toBe('ACT-0001')
        ->and($activity->activity_name)->toBe('Leakage test')
        ->and($activity->is_active)->toBeTrue();

    $updated = $writer->update($activity->activity_id, 'Leakage Test');

    expect($updated->activity_name)->toBe('Leakage Test');

    $inactive = $writer->deactivate($activity->activity_id);

    expect($inactive->is_active)->toBeFalse()
        ->and(CalibrationActivityMaster::query()->active()->count())->toBe(0);

    $active = $writer->activate($activity->activity_id);

    expect($active->is_active)->toBeTrue()
        ->and(CalibrationActivityMaster::query()->active()->count())->toBe(1);

    $writer->delete($activity->activity_id);

    $this->assertDatabaseCount('calibration_activity_master', 0);
});

it('assigns the next ACT code without colliding with imported codes', function () {
    createInstrumentDetailTestTables();
    insertCalibrationActivityMaster(1, 'ACT-0016', 'Verification with weight standard');

    $activity = (new CalibrationActivityMasterWriter)->create('Custom activity');

    expect($activity->activity_code)->toBe('ACT-0017');
});

it('rejects a duplicate activity code', function () {
    createInstrumentDetailTestTables();
    insertCalibrationActivityMaster(1, 'ACT-0001', 'Verification');

    expect(fn () => (new CalibrationActivityMasterWriter)->create('Other verification', 'ACT-0001'))
        ->toThrow(CalibrationActivityMasterException::class, 'This activity code is already in use.');
});

it('requires an activity name', function () {
    createInstrumentDetailTestTables();

    expect(fn () => (new CalibrationActivityMasterWriter)->create('   '))
        ->toThrow(CalibrationActivityMasterException::class, 'Activity Name is required.');
});

it('excludes inactive activities from future selection queries', function () {
    createInstrumentDetailTestTables();
    insertCalibrationActivityMaster(1, 'ACT-0001', 'Verification', true);
    insertCalibrationActivityMaster(2, 'ACT-0002', 'Cleaning All Module', false);

    $codes = CalibrationActivityMaster::query()->active()->orderBy('activity_code')->pluck('activity_code')->all();

    expect($codes)->toBe(['ACT-0001']);
});

it('does not delete an activity used by a calibration session', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertCalibrationActivityMaster(1, 'ACT-0001', 'Verification');

    DB::table('calibration')->insert([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'calibration_provider_id' => 1,
        'calibration_number' => 'CAL-1',
        'calibration_date' => '2026-01-01',
        'calibration_due' => '2027-01-01',
        'calibration_method' => 'INTERNAL',
        'session_status' => 'completed',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
    DB::table('calibration_activity')->insert([
        'calibration_activity_id' => 1,
        'calibration_id' => 1,
        'activity_type' => 'Verification',
        'activity_description' => 'Verification',
        'activity_result' => null,
        'activity_order' => 1,
        'created_at' => '2026-01-01 00:00:00',
    ]);

    expect(fn () => (new CalibrationActivityMasterWriter)->delete(1))
        ->toThrow(CalibrationActivityMasterException::class, 'This activity is used in a calibration session and cannot be deleted.');

    $this->assertDatabaseHas('calibration_activity_master', ['activity_id' => 1]);
});
