<?php

use App\Authorization\ApplicationCapability;
use App\Authorization\ApplicationRoleName;
use App\Exports\CalibrationReportXlsxExport;
use App\Reports\CalibrationReport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

function createCalibrationReportTables(): void
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
        $table->string('serial_number')->nullable();
        $table->unsignedBigInteger('detail_location_id')->nullable();
        $table->timestamp('created_at')->nullable();
    });

    Schema::create('instrument_type', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name');
        $table->string('brand')->nullable();
        $table->string('model')->nullable();
        $table->string('asset_number')->nullable();
    });

    Schema::create('calibration', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->unsignedBigInteger('instrument_type_id');
        $table->string('session_status');
        $table->date('calibration_date')->nullable();
        $table->date('calibration_due')->nullable();
        $table->timestamp('created_at');
        $table->timestamp('completed_at')->nullable();
        $table->timestamp('cancelled_at')->nullable();
        $table->string('snapshot_instrument_name')->nullable();
        $table->string('snapshot_instrument_type_name')->nullable();
        $table->string('snapshot_brand')->nullable();
        $table->string('snapshot_model')->nullable();
        $table->string('snapshot_serial_number')->nullable();
        $table->string('snapshot_asset_number')->nullable();
    });
}

function insertCalibrationReportRecord(array $attributes): void
{
    DB::table('calibration')->insert($attributes + [
        'session_status' => 'completed',
        'created_at' => '2026-01-01 08:00:00',
        'snapshot_instrument_name' => 'Gauge',
        'snapshot_instrument_type_name' => $attributes['snapshot_instrument_type_name'] ?? 'Pressure',
        'snapshot_brand' => 'Brand',
        'snapshot_model' => 'Model',
        'snapshot_serial_number' => 'SN',
        'snapshot_asset_number' => 'ASSET',
    ]);
}

function seedCalibrationReportIdentity(): void
{
    DB::table('location')->insert([
        ['location_id' => 1, 'location_name' => 'FMD'],
        ['location_id' => 2, 'location_name' => 'Warehouse'],
    ]);
    DB::table('detail_location')->insert([
        ['detail_location_id' => 1, 'location_id' => 1, 'detail_location_name' => 'FM 01/07'],
        ['detail_location_id' => 2, 'location_id' => 2, 'detail_location_name' => 'WH 01'],
    ]);
    DB::table('instrument')->insert([
        ['instrument_id' => 1, 'instrument_name' => 'Gauge', 'serial_number' => 'SN-1', 'detail_location_id' => 1, 'created_at' => '2026-01-01 08:00:00'],
        ['instrument_id' => 2, 'instrument_name' => 'Scale', 'serial_number' => 'SN-2', 'detail_location_id' => 2, 'created_at' => '2026-01-01 08:00:00'],
    ]);
    DB::table('instrument_type')->insert([
        ['instrument_type_id' => 11, 'instrument_id' => 1, 'type_name' => 'Pressure', 'brand' => 'Brand', 'model' => 'P1', 'asset_number' => 'A-11'],
        ['instrument_type_id' => 12, 'instrument_id' => 1, 'type_name' => 'Temperature', 'brand' => 'Brand', 'model' => 'T1', 'asset_number' => 'A-12'],
        ['instrument_type_id' => 21, 'instrument_id' => 2, 'type_name' => 'Weight', 'brand' => 'Brand', 'model' => 'W1', 'asset_number' => 'A-21'],
        ['instrument_type_id' => 22, 'instrument_id' => 2, 'type_name' => 'Volume', 'brand' => 'Brand', 'model' => 'V1', 'asset_number' => 'A-22'],
    ]);
}

it('includes every registered type and selects the latest session by start timestamp', function () {
    createCalibrationReportTables();
    seedCalibrationReportIdentity();
    $this->travelTo('2026-11-10 00:15:00');

    insertCalibrationReportRecord([
        'calibration_id' => 1, 'instrument_id' => 1, 'instrument_type_id' => 11,
        'created_at' => '2025-12-20 08:00:00', 'calibration_date' => '2025-12-20', 'calibration_due' => '2026-12-20',
    ]);
    insertCalibrationReportRecord([
        'calibration_id' => 2, 'instrument_id' => 1, 'instrument_type_id' => 11,
        'created_at' => '2026-02-01 08:00:00', 'calibration_date' => '2026-02-01', 'calibration_due' => '2027-02-01',
    ]);
    DB::table('calibration')->insert([
        'calibration_id' => 3, 'instrument_id' => 1, 'instrument_type_id' => 11,
        'session_status' => 'in_progress', 'created_at' => '2026-09-01 08:00:00',
    ]);
    DB::table('calibration')->insert([
        'calibration_id' => 5, 'instrument_id' => 2, 'instrument_type_id' => 21,
        'session_status' => 'cancelled', 'created_at' => '2026-07-01 08:00:00',
        'cancelled_at' => '2026-07-02 08:00:00',
    ]);
    insertCalibrationReportRecord([
        'calibration_id' => 4, 'instrument_id' => 1, 'instrument_type_id' => 12,
        'created_at' => '2026-08-01 08:00:00', 'calibration_date' => '2026-08-01', 'calibration_due' => '2027-08-01',
        'snapshot_instrument_type_name' => 'Temperature',
    ]);

    $rows = CalibrationReport::all(DB::connection(), 2026, '', '');

    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('instrument_type')->all())->toContain('Pressure', 'Temperature', 'Weight', 'Volume')
        ->and($rows->firstWhere('instrument_type', 'Pressure')['annual_status'])->toBe('In Progress')
        ->and($rows->firstWhere('instrument_type', 'Weight')['annual_status'])->toBe('Cancelled')
        ->and($rows->firstWhere('instrument_type', 'Volume')['annual_status'])->toBe('Not Calibrated')
        ->and($rows->firstWhere('instrument_type', 'Pressure')['calibration_id'])->toBe(3);
});

it('uses master locations for filtering and exports all filtered instrument types', function () {
    createCalibrationReportTables();
    seedCalibrationReportIdentity();
    $this->travelTo('2026-11-10 00:15:00');

    insertCalibrationReportRecord([
        'calibration_id' => 1, 'instrument_id' => 1, 'instrument_type_id' => 11,
        'created_at' => '2026-02-01 08:00:00', 'calibration_date' => '2026-02-01', 'calibration_due' => '2027-02-01',
    ]);

    $locations = CalibrationReport::locations(DB::connection(), 2026);
    $rows = CalibrationReport::all(DB::connection(), 2026, 'Warehouse', '');
    $spreadsheet = (new CalibrationReportXlsxExport(DB::connection(), 2026, '', ''))->spreadsheet();

    expect($locations)->toBe(['FMD', 'Warehouse'])
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('instrument_type')->all())->toContain('Weight', 'Volume')
        ->and($spreadsheet->getSheetCount())->toBe(1)
        ->and($spreadsheet->getActiveSheet()->getCell('A1')->getValue())->toBe('CALIBRATION REPORT 2026')
        ->and($spreadsheet->getActiveSheet()->getCell('A4')->getValue())->toBe('Instrument Name')
        ->and($spreadsheet->getActiveSheet()->getCell('C4')->getValue())->toBe('Type')
        ->and($spreadsheet->getActiveSheet()->getCell('I4')->getValue())->toBe('Calibration Status')
        ->and($spreadsheet->getActiveSheet()->getShowGridlines())->toBeFalse()
        ->and($spreadsheet->getActiveSheet()->getColumnDimension('A')->getWidth())->toBe(55.5)
        ->and($spreadsheet->getActiveSheet()->getColumnDimension('B')->getWidth())->toBe(27.5)
        ->and($spreadsheet->getActiveSheet()->getColumnDimension('C')->getWidth())->toBe(41.0)
        ->and($spreadsheet->getActiveSheet()->getColumnDimension('G')->getWidth())->toBe(22.0)
        ->and($spreadsheet->getActiveSheet()->getColumnDimension('H')->getWidth())->toBe(22.0)
        ->and($spreadsheet->getActiveSheet()->getColumnDimension('I')->getWidth())->toBe(25.0)
        ->and($spreadsheet->getActiveSheet()->getCell('A2')->getValue())->toBe('Calibration Year:')
        ->and($spreadsheet->getActiveSheet()->getCell('E2')->getValue())->toBe('Location:')
        ->and($spreadsheet->getActiveSheet()->getCell('I2')->getValue())->toBe('Generated Date:')
        ->and($spreadsheet->getActiveSheet()->getCell('J2')->getValue())->toBe('10-Nov-2026 07:15')
        ->and($spreadsheet->getActiveSheet()->getCell('F2')->getValue())->toBe('All Locations')
        ->and($spreadsheet->getActiveSheet()->getRowDimension(1)->getRowHeight())->toBe(40.5)
        ->and($spreadsheet->getActiveSheet()->getRowDimension(2)->getRowHeight())->toBe(28.5)
        ->and($spreadsheet->getActiveSheet()->getRowDimension(3)->getRowHeight())->toBe(18.0)
        ->and($spreadsheet->getActiveSheet()->getCell('A8')->getValue())->toBe('Scale')
        ->and($spreadsheet->getActiveSheet()->getCell('I5')->getValue())->toBe('On Track')
        ->and($spreadsheet->getActiveSheet()->getStyle('A4')->getFill()->getStartColor()->getRGB())->toBe('B4C6E7')
        ->and($spreadsheet->getActiveSheet()->getStyle('I5')->getFill()->getStartColor()->getRGB())->toBe('00B050')
        ->and($spreadsheet->getActiveSheet()->getCell('G5')->getDataType())->toBe('n')
        ->and($spreadsheet->getActiveSheet()->getCell('G7')->getValue())->toBeNull()
        ->and($spreadsheet->getActiveSheet()->getAutoFilter()->getRange())->toBe('A4:K8')
        ->and($spreadsheet->getActiveSheet()->getFreezePane())->toBe('A5')
        ->and($spreadsheet->getActiveSheet()->getPageSetup()->getOrientation())->toBe('landscape')
        ->and($spreadsheet->getActiveSheet()->getPageSetup()->getPrintArea())->toBe('A1:K8');
});

it('allows the existing history capability for viewers and rejects guests', function () {
    actingAsRole(ApplicationRoleName::Viewer);

    expect(Gate::allows(ApplicationCapability::HistoryView->value))->toBeTrue()
        ->and(route('calibration-reports.index', absolute: false))->toBe('/calibration-reports')
        ->and(route('calibration-reports.export', absolute: false))->toBe('/calibration-reports/export.xlsx');

    auth()->logout();

    $this->get(route('calibration-reports.index'))->assertRedirect(route('login'));
    $this->get(route('calibration-reports.export'))->assertRedirect(route('login'));
});
