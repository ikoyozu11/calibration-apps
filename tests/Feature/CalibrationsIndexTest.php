<?php

use App\Authorization\ApplicationRoleName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Model::preventLazyLoading();
});

it('renders reports directly without calibration tabs for Viewer', function () {
    createCalibrationIndexTables();
    actingAsRole(ApplicationRoleName::Viewer);

    $this->get(route('calibrations.index'))
        ->assertSee('Calibration reports and instrument status overview.')
        ->assertSee('Calibration Reports')
        ->assertSee('Export XLSX')
        ->assertDontSee('role="tablist"', false)
        ->assertDontSee('role="tab"', false)
        ->assertDontSee('Session History')
        ->assertDontSee('Start Calibration');
});

it('keeps calibration tab navigation for Administrator and Engineer', function () {
    createCalibrationIndexTables();

    foreach ([ApplicationRoleName::Administrator, ApplicationRoleName::Engineer] as $role) {
        actingAsRole($role);

        $this->get(route('calibrations.index'))
            ->assertSee('role="tablist"', false)
            ->assertSee('Reports')
            ->assertSee('Session History');
    }
});

it('reactively filters reports and resets report pagination for every role', function (ApplicationRoleName $role) {
    createCalibrationIndexTables();
    insertCalibrationIndexInstrument([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline Gauge',
        'serial_number' => 'WEIGHT-001',
    ]);
    insertCalibrationIndexType([
        'instrument_type_id' => 1,
        'instrument_id' => 3,
        'type_name' => 'Weight',
        'brand' => 'Sodim',
        'model' => 'PDV - PD',
        'asset_number' => 'ASSET-9',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-10-01',
        'calibration_due' => '2026-12-12',
    ]);
    DB::statement('PRAGMA case_sensitive_like = ON');
    actingAsRole($role);

    try {
        Livewire::test('pages::calibrations')
            ->set('paginators.reportPage', 2)
            ->set('reportSearch', 'sOdIm')
            ->assertSet('reportSearch', 'sOdIm')
            ->assertSet('paginators.reportPage', 1)
            ->assertSee('Sodiline Gauge')
            ->set('reportSearch', 'does-not-exist')
            ->assertSee('No instrument types match the selected filters.')
            ->call('clearReportFilters')
            ->assertSet('reportSearch', '')
            ->assertSee('Sodiline Gauge');
    } finally {
        DB::statement('PRAGMA case_sensitive_like = OFF');
    }
})->with([
    'Administrator' => ApplicationRoleName::Administrator,
    'Engineer' => ApplicationRoleName::Engineer,
    'Viewer' => ApplicationRoleName::Viewer,
]);

it('filters the start worklist by location and keeps its filters separate from reports', function (ApplicationRoleName $role) {
    createCalibrationIndexTables();
    DB::table('location')->insert([
        ['location_id' => 1, 'location_code' => 'FMD', 'location_name' => 'Filling Hall'],
        ['location_id' => 2, 'location_code' => 'LAB', 'location_name' => 'Laboratory'],
    ]);
    DB::table('detail_location')->insert([
        ['detail_location_id' => 1, 'location_id' => 1, 'detail_location_code' => 'FM-01', 'detail_location_name' => 'Line 1'],
        ['detail_location_id' => 2, 'location_id' => 2, 'detail_location_code' => 'LAB-01', 'detail_location_name' => 'Bench 1'],
    ]);
    insertCalibrationIndexInstrument([
        'instrument_id' => 3,
        'instrument_name' => 'Filling Gauge',
        'detail_location_id' => 1,
    ]);
    insertCalibrationIndexInstrument([
        'instrument_id' => 4,
        'instrument_name' => 'Laboratory Gauge',
        'detail_location_id' => 2,
    ]);
    insertCalibrationIndexType([
        'instrument_type_id' => 1,
        'instrument_id' => 3,
        'type_name' => 'Pressure',
        'brand' => 'Sodim',
    ]);
    insertCalibrationIndexType([
        'instrument_type_id' => 2,
        'instrument_id' => 4,
        'type_name' => 'Temperature',
        'brand' => 'Acme',
    ]);
    actingAsRole($role);

    $component = Livewire::test('pages::calibrations')
        ->set('tab', 'start')
        ->assertSeeInOrder(['Calibration Year', 'Location', 'Search', 'Per page'])
        ->assertSee('All Locations')
        ->assertSeeInOrder(['Current Due Status', 'Location', 'Detail Location', 'Action'])
        ->assertSeeInOrder(['Filling Hall', 'Line 1'])
        ->assertSeeInOrder(['Laboratory', 'Bench 1'])
        ->set('reportLocation', 'Filling Hall')
        ->set('reportSearch', 'reports-only')
        ->set('paginators.worklistPage', 2)
        ->set('worklistLocation', 'Filling Hall')
        ->set('worklistSearch', 'pressure')
        ->assertSet('worklistLocation', 'Filling Hall')
        ->assertSet('worklistSearch', 'pressure')
        ->assertSet('reportLocation', 'Filling Hall')
        ->assertSet('reportSearch', 'reports-only')
        ->assertSet('paginators.worklistPage', 1)
        ->assertSee('Filling Gauge')
        ->assertDontSee('Laboratory Gauge')
        ->call('clearWorklistFilters')
        ->assertSet('worklistYear', 2026)
        ->assertSet('worklistLocation', '')
        ->assertSet('worklistSearch', '')
        ->assertSet('worklistPerPage', 10)
        ->assertSet('paginators.worklistPage', 1)
        ->assertSet('reportLocation', 'Filling Hall')
        ->assertSet('reportSearch', 'reports-only')
        ->assertSee('Laboratory Gauge');
})->with([
    'Administrator' => ApplicationRoleName::Administrator,
    'Engineer' => ApplicationRoleName::Engineer,
]);

it('shows each instrument type with the latest calibration standing', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline', 'serial_number' => 'WEIGHT-001']);
    insertCalibrationIndexInstrument(['instrument_id' => 4, 'instrument_name' => 'Due Today']);
    insertCalibrationIndexInstrument(['instrument_id' => 5, 'instrument_name' => 'Late Gauge']);
    insertCalibrationIndexInstrument(['instrument_id' => 6, 'instrument_name' => 'No History']);
    insertCalibrationIndexInstrument(['instrument_id' => 7, 'instrument_name' => 'Open Due']);
    insertCalibrationIndexType([
        'instrument_type_id' => 1,
        'instrument_id' => 3,
        'type_name' => 'Weight',
        'brand' => 'Sodim',
        'model' => 'PDV - PD',
        'asset_number' => 'ASSET-9',
    ]);
    insertCalibrationIndexType([
        'instrument_type_id' => 2,
        'instrument_id' => 3,
        'type_name' => 'Diameter',
    ]);
    insertCalibrationIndexType(['instrument_type_id' => 3, 'instrument_id' => 4, 'type_name' => 'Gauge']);
    insertCalibrationIndexType(['instrument_type_id' => 4, 'instrument_id' => 5, 'type_name' => 'Gauge']);
    insertCalibrationIndexType(['instrument_type_id' => 5, 'instrument_id' => 6, 'type_name' => 'Gauge']);
    insertCalibrationIndexType(['instrument_type_id' => 6, 'instrument_id' => 7, 'type_name' => 'Gauge']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-09-01',
        'calibration_due' => '2026-10-11',
        'calibration_result' => 'PASS',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-10-01',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'FAIL',
        'certificate_number' => 'CERT-1',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 6,
        'instrument_id' => 3,
        'instrument_type_id' => 2,
        'calibration_date' => '2026-10-01',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'FAIL',
        'certificate_number' => 'CERT-DIA',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 3,
        'instrument_id' => 4,
        'instrument_type_id' => 3,
        'calibration_date' => '2026-10-12',
        'calibration_due' => '2026-10-12',
        'calibration_result' => 'PASS',
        'certificate_number' => '   ',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 4,
        'instrument_id' => 5,
        'instrument_type_id' => 4,
        'calibration_date' => '2026-09-15',
        'calibration_due' => '2026-10-11',
        'certificate_number' => 'CERT-2',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 5,
        'instrument_id' => 7,
        'instrument_type_id' => 6,
        'calibration_date' => '2026-10-02',
        'calibration_result' => 'PASS',
    ]);
    insertCalibrationIndexScope(['calibration_scope_id' => 1, 'calibration_id' => 1, 'instrument_type_id' => 1]);
    insertCalibrationIndexScope(['calibration_scope_id' => 2, 'calibration_id' => 1, 'instrument_type_id' => 2]);
    insertCalibrationIndexScope(['calibration_scope_id' => 3, 'calibration_id' => 2, 'instrument_type_id' => 1]);
    insertCalibrationIndexScope(['calibration_scope_id' => 4, 'calibration_id' => 2, 'instrument_type_id' => 2]);
    insertCalibrationIndexScope(['calibration_scope_id' => 5, 'calibration_id' => 3, 'instrument_type_id' => 3]);
    insertCalibrationIndexScope(['calibration_scope_id' => 6, 'calibration_id' => 4, 'instrument_type_id' => 4]);
    insertCalibrationIndexScope(['calibration_scope_id' => 7, 'calibration_id' => 5, 'instrument_type_id' => 6]);

    $response = Livewire::test('pages::calibrations')->set('tab', 'start');

    $response
        ->assertSeeInOrder([
            'Instrument Name',
            'Brand',
            'Type',
            'Model',
            'Serial Number',
            'Asset Number',
            'Calibration Date',
            'Calibration Due',
            'Annual Progress',
            'Current Due Status',
        ])
        ->assertSee('Calibration Worklist')
        ->assertSeeInOrder(['Completed', '5', 'In Progress', '0', 'Not Calibrated', '1'])
        ->assertSeeInOrder(['Sodiline', 'Sodim', 'Weight', 'PDV - PD', 'WEIGHT-001', 'ASSET-9', '2026-10-01', '2026-12-12', 'Completed', 'On Track'])
        ->assertSeeInOrder(['Sodiline', 'Diameter', '2026-10-01', '2026-12-12', 'Completed', 'On Track'])
        ->assertSeeInOrder(['Due Today', '2026-10-12', '2026-10-12', 'Completed', 'Due Soon'])
        ->assertSeeInOrder(['Late Gauge', '2026-09-15', '2026-10-11', 'Completed', 'Overdue'])
        ->assertSeeInOrder(['No History', 'Not Calibrated', 'Not Calibrated'])
        ->assertSeeInOrder(['Open Due', '2026-10-02', 'Completed', 'Not Calibrated'])
        ->assertDontSee('2026-09-01')
        ->assertDontSee('This month')
        ->assertDontSee('Pass / Fail')
        ->assertDontSee('No certificate')
        ->assertDontSee('Calibration Number')
        ->assertDontSee('Remarks')
        ->assertSee('Start Calibration')
        ->assertSee('href="'.e(route('instruments.types.calibrations.start', [
            'instrument' => 6,
            'instrumentType' => 5,
        ])).'"', false)
        ->assertDontSee('Total Instruments')
        ->assertSee('href="'.route('calibrations.show', 2).'"', false)
        ->assertDontSee('href="'.route('calibrations.show', 1).'"', false);
});

it('opens the latest calibration that covers each instrument type', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertCalibrationIndexType(['instrument_type_id' => 2, 'instrument_id' => 3, 'type_name' => 'Diameter']);
    insertCalibrationIndexType(['instrument_type_id' => 3, 'instrument_id' => 3, 'type_name' => 'PD']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-08-01',
        'calibration_due' => '2026-09-01',
        'calibration_result' => 'PASS',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-10-01',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-WEIGHT',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 3,
        'instrument_id' => 3,
        'instrument_type_id' => 2,
        'calibration_date' => '2026-09-15',
        'calibration_due' => '2026-10-11',
        'calibration_result' => 'FAIL',
        'certificate_number' => 'CERT-DIAMETER',
    ]);
    insertCalibrationIndexScope(['calibration_scope_id' => 1, 'calibration_id' => 1, 'instrument_type_id' => 1]);
    insertCalibrationIndexScope(['calibration_scope_id' => 2, 'calibration_id' => 2, 'instrument_type_id' => 1]);
    insertCalibrationIndexScope(['calibration_scope_id' => 3, 'calibration_id' => 3, 'instrument_type_id' => 2]);

    $response = Livewire::test('pages::calibrations')->set('tab', 'start');

    $response
        ->assertSeeInOrder(['Sodiline', 'Weight', '2026-10-01', '2026-12-12', 'Completed', 'On Track'])
        ->assertSeeInOrder(['Sodiline', 'Diameter', '2026-09-15', '2026-10-11', 'Completed', 'Overdue'])
        ->assertSeeInOrder(['Sodiline', 'PD', 'Not Calibrated', 'Not Calibrated'])
        ->assertDontSee('2026-08-01')
        ->assertSee('href="'.route('calibrations.show', 2).'"', false)
        ->assertSee('href="'.route('calibrations.show', 3).'"', false)
        ->assertDontSee('href="'.route('calibrations.show', 1).'"', false);
});

it('keeps the calibration totals when the type list moves to the next page', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    foreach (range(1, 11) as $id) {
        insertCalibrationIndexInstrument([
            'instrument_id' => $id,
            'instrument_name' => sprintf('Gauge %02d', $id),
        ]);
        insertCalibrationIndexType([
            'instrument_type_id' => $id,
            'instrument_id' => $id,
            'type_name' => 'Weight',
        ]);
    }

    insertCalibrationIndexRecord([
        'calibration_id' => 11,
        'instrument_id' => 11,
        'instrument_type_id' => 11,
        'calibration_date' => '2026-10-03',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-11',
    ]);

    $firstPage = Livewire::test('pages::calibrations')->set('tab', 'start');
    $secondPage = Livewire::test('pages::calibrations')->set('tab', 'start')->set('paginators.worklistPage', 2);

    $firstPage
        ->assertSeeInOrder(['Completed', '1', 'In Progress', '0', 'Not Calibrated', '10'])
        ->assertSee('Gauge 01')
        ->assertDontSee('Gauge 11');
    $secondPage
        ->assertSeeInOrder(['Completed', '1', 'In Progress', '0', 'Not Calibrated', '10'])
        ->assertSee('Gauge 11')
        ->assertDontSee('Gauge 01');
});

it('filters calibration rows by name brand type or model without changing the totals', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 1, 'instrument_name' => 'Sodiline', 'serial_number' => 'WEIGHT-001']);
    insertCalibrationIndexInstrument(['instrument_id' => 2, 'instrument_name' => 'Digital Caliper']);
    insertCalibrationIndexType([
        'instrument_type_id' => 1,
        'instrument_id' => 1,
        'type_name' => 'Weight',
        'brand' => 'Sodim',
        'model' => 'PDV - PD',
    ]);
    insertCalibrationIndexType([
        'instrument_type_id' => 2,
        'instrument_id' => 1,
        'type_name' => 'Diameter',
    ]);
    insertCalibrationIndexType([
        'instrument_type_id' => 3,
        'instrument_id' => 2,
        'type_name' => 'Mitutoyo',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 2,
        'instrument_type_id' => 3,
        'calibration_date' => '2026-10-12',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-1',
    ]);

    Livewire::test('pages::calibrations')
        ->set('tab', 'start')
        ->set('worklistSearch', 'diameter')
        ->assertSee('Diameter')
        ->assertDontSee('Weight')
        ->assertDontSee('Digital Caliper')
        ->assertSeeInOrder(['Completed', '1', 'In Progress', '0', 'Not Calibrated', '2'])
        ->set('worklistSearch', 'sodim')
        ->assertSee('Weight')
        ->assertDontSee('Diameter')
        ->assertSeeInOrder(['Completed', '1', 'In Progress', '0', 'Not Calibrated', '2'])
        ->set('worklistSearch', 'pdv')
        ->assertSee('PDV - PD')
        ->assertDontSee('Diameter')
        ->set('worklistSearch', 'WEIGHT-001')
        ->assertDontSee('Sodiline')
        ->assertSeeInOrder(['Completed', '1', 'In Progress', '0', 'Not Calibrated', '2']);
});

it('rejects a page size outside the allowed choices', function () {
    createCalibrationIndexTables();

    foreach (range(1, 11) as $id) {
        insertCalibrationIndexInstrument([
            'instrument_id' => $id,
            'instrument_name' => sprintf('Gauge %02d', $id),
        ]);
        insertCalibrationIndexType([
            'instrument_type_id' => $id,
            'instrument_id' => $id,
            'type_name' => 'Weight',
        ]);
    }

    Livewire::test('pages::calibrations')
        ->set('tab', 'start')
        ->set('worklistPerPage', 100)
        ->assertSet('worklistPerPage', 10)
        ->assertDontSee('Gauge 11');
});

it('shows zero totals when no instruments exist', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    $response = Livewire::test('pages::calibrations')->set('tab', 'start');

    $response
        ->assertSeeInOrder(['Completed', '0', 'In Progress', '0', 'Not Calibrated', '0'])
        ->assertSee('No instruments found.');
});

it('prefers an in-progress session for the year and ignores cancelled sessions', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-09-01',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-DONE',
        'session_status' => 'completed',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'session_status' => 'in_progress',
        'created_at' => '2026-10-12 08:00:00',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 3,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-10-11',
        'calibration_due' => '2026-10-11',
        'session_status' => 'cancelled',
        'created_at' => '2026-10-11 08:00:00',
    ]);

    $response = Livewire::test('pages::calibrations')->set('tab', 'start');

    $response
        ->assertSeeInOrder(['Completed', '0', 'In Progress', '1', 'Not Calibrated', '0'])
        ->assertSeeInOrder(['Sodiline', 'Weight', '2026-09-01', '2026-12-12', 'In Progress', 'On Track'])
        ->assertDontSee('2026-10-11')
        ->assertDontSee('Active session')
        ->assertSee('href="'.route('calibrations.show', 2).'"', false)
        ->assertDontSee('href="'.route('calibrations.show', 1).'"', false)
        ->assertDontSee('href="'.route('calibrations.show', 3).'"', false);
});

it('does not treat a completed session from another year as the annual cell', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2025-06-01',
        'calibration_due' => '2025-12-01',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-2025',
    ]);

    $response = Livewire::test('pages::calibrations')->set('tab', 'start');

    $response
        ->assertSeeInOrder(['Completed', '0', 'In Progress', '0', 'Not Calibrated', '1'])
        ->assertSeeInOrder(['Sodiline', 'Weight', 'Not Calibrated', 'Overdue'])
        ->assertDontSee('2025-06-01')
        ->assertDontSee('href="'.route('calibrations.show', 1).'"', false);
});

it('switches the annual cell when the selected year changes', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-03-01',
        'calibration_due' => '2026-09-01',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-2026',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2027-04-01',
        'calibration_due' => '2027-10-01',
        'calibration_result' => 'FAIL',
        'certificate_number' => 'CERT-2027',
    ]);

    Livewire::test('pages::calibrations')
        ->set('tab', 'start')
        ->assertSee('option value="2027"', false)
        ->assertSeeInOrder(['Completed', '1', 'In Progress', '0', 'Not Calibrated', '0'])
        ->assertSeeInOrder(['Sodiline', 'Weight', '2026-03-01', '2026-09-01', 'Completed', 'On Track'])
        ->assertDontSee('2027-04-01')
        ->set('tab', 'start')
        ->set('worklistYear', 2027)
        ->assertSeeInOrder(['Completed', '1', 'In Progress', '0', 'Not Calibrated', '0'])
        ->assertSeeInOrder(['Sodiline', 'Weight', '2027-04-01', '2027-10-01', 'Completed', 'On Track'])
        ->assertDontSee('2026-03-01');
});

it('clamps an unsupported year back to the default', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    Livewire::test('pages::calibrations')
        ->set('tab', 'start')
        ->set('worklistYear', 2019)
        ->assertSet('worklistYear', 2026)
        ->assertSee('Calibration Worklist');
});

it('keeps Reports as the default tab', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);

    Livewire::test('pages::calibrations')
        ->assertSee('Calibration Reports')
        ->assertSee('Session History')
        ->assertSet('tab', 'reports')
        ->assertSee('Sodiline')
        ->set('tab', 'unknown')
        ->assertSet('tab', 'reports')
        ->assertSee('Calibration Reports');
});

it('escapes instrument identity values in the annual overview', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => '<script>alert(1)</script>']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);

    $response = Livewire::test('pages::calibrations')->set('tab', 'start');

    $response
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('treats a cancelled-only type as not calibrated for the selected year', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-10-11',
        'calibration_due' => '2026-12-12',
        'session_status' => 'cancelled',
        'created_at' => '2026-10-11 08:00:00',
    ]);

    $response = Livewire::test('pages::calibrations')->set('tab', 'start');

    $response
        ->assertSeeInOrder(['Completed', '0', 'In Progress', '0', 'Not Calibrated', '1'])
        ->assertSeeInOrder(['Sodiline', 'Weight', 'Not Calibrated', 'Not Calibrated'])
        ->assertDontSee('2026-10-11')
        ->assertDontSee('href="'.route('calibrations.show', 1).'"', false);
});

it('keeps a cross-year in-progress session off the selected year progress', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-03-01',
        'calibration_due' => '2026-12-12',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-2026',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'session_status' => 'in_progress',
        'created_at' => '2027-02-01 08:00:00',
    ]);

    Livewire::test('pages::calibrations')
        ->set('tab', 'start')
        ->assertSeeInOrder(['Completed', '1', 'In Progress', '0', 'Not Calibrated', '0'])
        ->assertSeeInOrder(['Sodiline', 'Weight', '2026-03-01', '2026-12-12', 'Completed', 'On Track'])
        ->assertSee('Active session (2027)')
        ->assertSee('href="'.route('calibrations.show', 2).'"', false)
        ->set('tab', 'start')
        ->set('worklistYear', 2027)
        ->assertSeeInOrder(['Completed', '0', 'In Progress', '1', 'Not Calibrated', '0'])
        ->assertSeeInOrder(['Sodiline', 'Weight', 'In Progress', 'On Track'])
        ->assertDontSee('Active session (2027)')
        ->assertDontSee('2026-03-01');
});

it('keeps current due status independent of the selected annual year', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-03-01',
        'calibration_due' => '2026-09-01',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-2026',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'calibration_date' => '2027-01-15',
        'calibration_due' => '2027-12-12',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-2027',
    ]);

    Livewire::test('pages::calibrations')
        ->set('tab', 'start')
        ->assertSeeInOrder(['Sodiline', 'Weight', '2026-03-01', '2026-09-01', 'Completed', 'On Track'])
        ->set('worklistYear', 2027)
        ->assertSeeInOrder(['Sodiline', 'Weight', '2027-01-15', '2027-12-12', 'Completed', 'On Track']);
});

it('reuses home due standing thresholds for current due status', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');

    insertCalibrationIndexInstrument(['instrument_id' => 1, 'instrument_name' => 'On Track Type']);
    insertCalibrationIndexInstrument(['instrument_id' => 2, 'instrument_name' => 'Due Soon Type']);
    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Overdue Type']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 1, 'type_name' => 'Gauge']);
    insertCalibrationIndexType(['instrument_type_id' => 2, 'instrument_id' => 2, 'type_name' => 'Gauge']);
    insertCalibrationIndexType(['instrument_type_id' => 3, 'instrument_id' => 3, 'type_name' => 'Gauge']);
    insertCalibrationIndexRecord([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => 1,
        'calibration_date' => '2025-10-01',
        'calibration_due' => '2026-12-12',
        'certificate_number' => 'CERT-TRACK',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 2,
        'instrument_id' => 2,
        'instrument_type_id' => 2,
        'calibration_date' => '2025-10-01',
        'calibration_due' => '2026-10-12',
        'certificate_number' => 'CERT-SOON',
    ]);
    insertCalibrationIndexRecord([
        'calibration_id' => 3,
        'instrument_id' => 3,
        'instrument_type_id' => 3,
        'calibration_date' => '2025-10-01',
        'calibration_due' => '2026-10-11',
        'certificate_number' => 'CERT-LATE',
    ]);

    $response = Livewire::test('pages::calibrations')->set('tab', 'start');

    $response
        ->assertSeeInOrder(['Completed', '0', 'In Progress', '0', 'Not Calibrated', '3'])
        ->assertSeeInOrder(['On Track Type', 'Not Calibrated', 'On Track'])
        ->assertSeeInOrder(['Due Soon Type', 'Not Calibrated', 'Due Soon'])
        ->assertSeeInOrder(['Overdue Type', 'Not Calibrated', 'Overdue'])
        ->assertDontSee('2025-10-01');
});
