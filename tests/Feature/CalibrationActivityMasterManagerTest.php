<?php

use App\Models\CalibrationActivityMaster;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('lists and searches the activity master without authentication', function () {
    createInstrumentDetailTestTables();
    insertCalibrationActivityMaster(1, 'ACT-0001', 'Verification');
    insertCalibrationActivityMaster(2, 'ACT-0005', 'Cleaning');

    $this->get(route('activities.index'))
        ->assertOk()
        ->assertSee('Activity Master')
        ->assertSee('Activities');

    Livewire::test('pages::activities')
        ->assertSee('Activity Master')
        ->assertSee('ACT-0001')
        ->assertSee('Verification')
        ->assertSee('Add New Activity')
        ->set('search', 'Cleaning')
        ->assertSee('Cleaning')
        ->assertDontSee('Verification');
});

it('creates and edits an activity from the master page', function () {
    createInstrumentDetailTestTables();

    $component = Livewire::test('pages::activities')
        ->call('startAdding')
        ->set('activityName', 'Leakage test')
        ->call('saveActivity')
        ->assertSee('Activity saved.')
        ->assertSee('ACT-0001')
        ->assertSee('Leakage test');

    $id = (int) CalibrationActivityMaster::query()->value('activity_id');

    $component
        ->call('startEditing', $id)
        ->set('activityName', 'Leakage Test')
        ->call('saveActivity')
        ->assertSee('Activity updated.')
        ->assertSee('Leakage Test');
});

it('requires an activity name on the master page', function () {
    createInstrumentDetailTestTables();

    Livewire::test('pages::activities')
        ->call('startAdding')
        ->set('activityName', '   ')
        ->call('saveActivity')
        ->assertHasErrors(['activityName']);
});

it('deactivates and reactivates an activity', function () {
    createInstrumentDetailTestTables();
    insertCalibrationActivityMaster(1, 'ACT-0001', 'Verification');

    Livewire::test('pages::activities')
        ->assertSee('Active')
        ->call('deactivateActivity', 1)
        ->assertSee('Activity deactivated.')
        ->assertSee('Inactive')
        ->call('activateActivity', 1)
        ->assertSee('Activity reactivated.')
        ->assertSee('Active');

    $this->assertDatabaseHas('calibration_activity_master', [
        'activity_id' => 1,
        'is_active' => true,
    ]);
});

it('deletes an unused activity after confirmation', function () {
    createInstrumentDetailTestTables();
    insertCalibrationActivityMaster(1, 'ACT-0001', 'Verification');

    Livewire::test('pages::activities')
        ->call('askToRemove', 1)
        ->call('removeActivity')
        ->assertSee('Activity deleted.');

    $this->assertDatabaseCount('calibration_activity_master', 0);
});

it('blocks deletion when the activity is used in a calibration session', function () {
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

    Livewire::test('pages::activities')
        ->call('askToRemove', 1)
        ->call('removeActivity')
        ->assertSee('This activity is used in a calibration session and cannot be deleted.');

    $this->assertDatabaseHas('calibration_activity_master', ['activity_id' => 1]);
});
