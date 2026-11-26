<?php

use App\Dashboard\HomeOperationalViewModel;
use App\Models\Calibration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createHomeOperationalTables(): void
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
        $table->timestamp('created_at');
    });

    Schema::create('instrument_type', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name');
        $table->timestamp('created_at');
    });

    Schema::create('calibration', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->unsignedBigInteger('instrument_type_id')->nullable();
        $table->date('calibration_date')->nullable();
        $table->date('calibration_due')->nullable();
        $table->string('session_status');
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamp('cancelled_at')->nullable();
        $table->string('snapshot_instrument_name')->nullable();
        $table->string('snapshot_serial_number')->nullable();
    });
}

function insertHomeInstrument(int $id, string $name, ?int $detailLocationId = null): void
{
    DB::table('instrument')->insert([
        'instrument_id' => $id,
        'instrument_name' => $name,
        'serial_number' => 'SN-'.$id,
        'detail_location_id' => $detailLocationId,
        'created_at' => now(),
    ]);
}

function insertHomeCalibration(int $id, int $instrumentId, array $values = []): void
{
    DB::table('calibration')->insert(array_merge([
        'calibration_id' => $id,
        'instrument_id' => $instrumentId,
        'instrument_type_id' => null,
        'calibration_date' => today()->subYear()->toDateString(),
        'calibration_due' => today()->addDays(61)->toDateString(),
        'session_status' => Calibration::STATUS_COMPLETED,
        'created_at' => now()->subDay(),
        'updated_at' => now(),
        'completed_at' => now()->subHours(12),
        'cancelled_at' => null,
        'snapshot_instrument_name' => null,
        'snapshot_serial_number' => null,
    ], $values));
}

beforeEach(function (): void {
    createHomeOperationalTables();
});

it('returns a safe empty operational view model', function (): void {
    $viewModel = HomeOperationalViewModel::current(DB::connection('sqlite'));

    expect($viewModel->totalInstruments)->toBe(0)
        ->and($viewModel->onTrack)->toBe(0)
        ->and($viewModel->dueSoon)->toBe(0)
        ->and($viewModel->overdue)->toBe(0)
        ->and($viewModel->notCalibrated)->toBe(0)
        ->and($viewModel->calibrationHealth['segments'][0]['percent'])->toBe('0%')
        ->and($viewModel->needsAttention)->toBe([])
        ->and($viewModel->locationOverview)->toBe([])
        ->and($viewModel->recentActivity)->toBe([]);
});

it('uses current standings, 60 day boundary, deterministic attention ordering, and unassigned locations', function (): void {
    DB::table('location')->insert([
        ['location_id' => 1, 'location_name' => 'Lab'],
    ]);
    DB::table('detail_location')->insert([
        ['detail_location_id' => 1, 'location_id' => 1, 'detail_location_name' => 'Room'],
    ]);
    insertHomeInstrument(1, 'Overdue Z', 1);
    insertHomeInstrument(2, 'Overdue A');
    insertHomeInstrument(3, 'Due Soon B');
    insertHomeInstrument(4, 'Due Soon A');
    insertHomeInstrument(5, 'Boundary');
    insertHomeInstrument(6, 'On Track');
    insertHomeInstrument(7, 'Not Calibrated');

    insertHomeCalibration(1, 1, ['calibration_due' => today()->subDay()->toDateString()]);
    insertHomeCalibration(2, 2, ['calibration_due' => today()->subDay()->toDateString()]);
    insertHomeCalibration(3, 3, ['calibration_due' => today()->addDays(10)->toDateString()]);
    insertHomeCalibration(4, 4, ['calibration_due' => today()->addDays(10)->toDateString()]);
    insertHomeCalibration(5, 5, ['calibration_due' => today()->addDays(60)->toDateString()]);
    insertHomeCalibration(6, 6, ['calibration_due' => today()->addDays(61)->toDateString()]);

    $viewModel = HomeOperationalViewModel::current(DB::connection('sqlite'));

    expect($viewModel->totalInstruments)->toBe(7)
        ->and($viewModel->overdue)->toBe(2)
        ->and($viewModel->dueSoon)->toBe(3)
        ->and($viewModel->onTrack)->toBe(1)
        ->and($viewModel->notCalibrated)->toBe(1)
        ->and(array_column($viewModel->needsAttention, 'instrument_name'))
        ->toBe(['Overdue A', 'Overdue Z', 'Due Soon A', 'Due Soon B', 'Boundary'])
        ->and($viewModel->locationOverview)
        ->toBe([
            ['location' => 'Unassigned', 'count' => 6],
            ['location' => 'Lab', 'count' => 1],
        ]);
});

it('selects the latest completed calibration once per instrument', function (): void {
    insertHomeInstrument(1, 'Repeated Gauge');
    insertHomeCalibration(1, 1, [
        'calibration_date' => today()->subYear()->toDateString(),
        'calibration_due' => today()->addDays(61)->toDateString(),
    ]);
    insertHomeCalibration(2, 1, [
        'calibration_date' => today()->toDateString(),
        'calibration_due' => today()->subDay()->toDateString(),
    ]);

    $viewModel = HomeOperationalViewModel::current(DB::connection('sqlite'));

    expect($viewModel->totalInstruments)->toBe(1)
        ->and($viewModel->onTrack)->toBe(0)
        ->and($viewModel->overdue)->toBe(1)
        ->and($viewModel->needsAttention)->toHaveCount(1)
        ->and($viewModel->needsAttention[0]['instrument_name'])->toBe('Repeated Gauge');
});

it('maps dated calibration activity events and omits missing timestamps', function (): void {
    insertHomeInstrument(1, 'Gauge');
    insertHomeCalibration(1, 1, [
        'created_at' => now()->subDays(3),
        'completed_at' => now()->subDays(2),
        'cancelled_at' => null,
    ]);
    insertHomeCalibration(2, 1, [
        'session_status' => Calibration::STATUS_CANCELLED,
        'created_at' => now()->subDay(),
        'completed_at' => null,
        'cancelled_at' => now()->subHours(2),
    ]);

    $events = HomeOperationalViewModel::current(DB::connection('sqlite'))->recentActivity;

    expect(array_column($events, 'event'))->toBe(['Cancelled', 'Started', 'Completed', 'Started'])
        ->and($events[0]['status'])->toBe('Cancelled')
        ->and($events[0]['instrument_name'])->toBe('Gauge')
        ->and($events[0]['detail_url'])->toContain('/calibrations/2')
        ->and($events[2]['status'])->toBe('Completed')
        ->and(array_column($events, 'key'))->not->toContain('1:cancelled');
});
