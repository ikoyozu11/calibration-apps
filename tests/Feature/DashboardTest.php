<?php

use App\Authorization\ApplicationRoleName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

function createDashboardTestTables(): void
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

    Schema::create('standard_master', function (Blueprint $table): void {
        $table->unsignedBigInteger('standard_id')->primary();
    });

    Schema::create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name');
        $table->string('serial_number')->nullable();
        $table->unsignedBigInteger('detail_location_id')->nullable();
        $table->text('description')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('instrument_type', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name');
        $table->string('serial_number')->nullable();
        $table->string('asset_number')->nullable();
        $table->string('brand')->nullable();
        $table->string('model')->nullable();
        $table->timestamp('created_at');
        addInstrumentTypeIntervalColumn($table);
    });

    Schema::create('calibration', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->date('calibration_date')->nullable();
        $table->date('calibration_due')->nullable();
        $table->string('calibration_result', 20)->nullable();
        $table->string('certificate_number', 100)->nullable();
        addCalibrationSessionColumns($table);
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamp('cancelled_at')->nullable();
    });

    Schema::create('calibration_scope', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_scope_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('instrument_type_id');
        $table->integer('scope_order');
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertDashboardInstrument(array $attributes): void
{
    DB::table('instrument')->insert(array_merge([
        'detail_location_id' => null,
        'description' => null,
        'created_at' => '2026-01-01 00:00:00',
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertDashboardCalibration(array $attributes): void
{
    DB::table('calibration')->insert(array_merge([
        'calibration_date' => '2026-08-01',
        'calibration_due' => today()->toDateString(),
    ], $attributes));
}

beforeEach(function (): void {
    Model::preventLazyLoading();
});

it('renders the operational command center at the root', function (): void {
    createDashboardTestTables();
    insertDashboardInstrument(['instrument_id' => 1, 'instrument_name' => 'On Track Gauge']);
    insertDashboardInstrument(['instrument_id' => 2, 'instrument_name' => 'Due Gauge']);
    insertDashboardInstrument(['instrument_id' => 3, 'instrument_name' => 'Overdue Gauge']);
    insertDashboardCalibration(['calibration_id' => 1, 'instrument_id' => 1, 'calibration_due' => today()->addDays(61)->toDateString()]);
    insertDashboardCalibration(['calibration_id' => 2, 'instrument_id' => 2, 'calibration_due' => today()->addDays(60)->toDateString()]);
    insertDashboardCalibration(['calibration_id' => 3, 'instrument_id' => 3, 'calibration_due' => today()->subDay()->toDateString()]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Instrument calibration and operational overview.')
        ->assertSeeInOrder(['Total Instruments', '3', 'On Track', '1', 'Due Soon', '1', 'Overdue', '1'])
        ->assertSee('Calibration Health')
        ->assertSee('Needs Attention')
        ->assertSee('Location Overview')
        ->assertSee('Recent Activity');
});

it('renders safe empty states and zero health percentages', function (): void {
    createDashboardTestTables();

    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('All Clear')
        ->assertSee('No overdue or due-soon instruments at the moment.')
        ->assertSee('No instrument locations found.')
        ->assertSee('No calibration activity found.')
        ->assertSee('data-dashboard-percentage="0"', false)
        ->assertSee('0%', false);
});

it('renders needs attention in operational priority order with authorized links', function (): void {
    createDashboardTestTables();
    insertDashboardInstrument(['instrument_id' => 1, 'instrument_name' => 'Due Later', 'serial_number' => 'DUE-2']);
    insertDashboardInstrument(['instrument_id' => 2, 'instrument_name' => 'Overdue Alpha', 'serial_number' => 'OD-1']);
    insertDashboardInstrument(['instrument_id' => 3, 'instrument_name' => 'Due Soon', 'serial_number' => 'DUE-1']);
    insertDashboardInstrument(['instrument_id' => 4, 'instrument_name' => 'No History']);
    insertDashboardCalibration(['calibration_id' => 1, 'instrument_id' => 1, 'calibration_due' => today()->addDays(30)->toDateString()]);
    insertDashboardCalibration(['calibration_id' => 2, 'instrument_id' => 2, 'calibration_due' => today()->subDay()->toDateString()]);
    insertDashboardCalibration(['calibration_id' => 3, 'instrument_id' => 3, 'calibration_due' => today()->addDays(7)->toDateString()]);

    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSeeInOrder(['Overdue Alpha', 'Overdue', 'Due Soon', 'Due Soon', 'Due Later'])
        ->assertSee('href="'.route('instruments.show', 2).'"', false)
        ->assertSee('href="'.route('instruments.show', 3).'"', false)
        ->assertDontSee('No History');
});

it('renders location overview including Unassigned and recent activity timestamps', function (): void {
    createDashboardTestTables();
    DB::table('location')->insert([
        ['location_id' => 1, 'location_name' => 'Lab A'],
    ]);
    DB::table('detail_location')->insert([
        ['detail_location_id' => 1, 'location_id' => 1, 'detail_location_name' => 'Room 1'],
    ]);
    insertDashboardInstrument(['instrument_id' => 1, 'instrument_name' => 'Located Gauge', 'detail_location_id' => 1]);
    insertDashboardInstrument(['instrument_id' => 2, 'instrument_name' => 'Located Gauge 2', 'detail_location_id' => 1]);
    insertDashboardInstrument(['instrument_id' => 3, 'instrument_name' => 'Unassigned Gauge']);
    insertDashboardCalibration([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'session_status' => 'completed',
        'created_at' => '2026-11-18 08:00:00',
        'completed_at' => '2026-11-19 09:30:00',
    ]);

    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSeeInOrder(['Lab A', '2', 'Unassigned', '1'])
        ->assertSee('style="width: 100%"', false)
        ->assertSee('style="width: 50%"', false)
        ->assertSee('Started')
        ->assertSee('Completed')
        ->assertSee('Status:')
        ->assertSee('19 Nov 2026, 09:30')
        ->assertSee('href="'.route('calibrations.show', 1).'"', false);
});

it('paginates dashboard attention and activity lists independently', function (): void {
    createDashboardTestTables();

    foreach (range(1, 7) as $index) {
        insertDashboardInstrument([
            'instrument_id' => $index,
            'instrument_name' => 'Attention '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'serial_number' => 'ATT-'.$index,
        ]);
        insertDashboardCalibration([
            'calibration_id' => $index,
            'instrument_id' => $index,
            'calibration_due' => today()->addDays(30)->toDateString(),
            'created_at' => today()->subDays($index)->startOfDay()->toDateTimeString(),
        ]);
    }

    $this->get(route('home'))
        ->assertSee('Page 1 of 3')
        ->assertSee('Page 1 of 2')
        ->assertSee('Attention 01')
        ->assertSee('Attention 03')
        ->assertDontSee('href="'.route('instruments.show', 4).'"', false)
        ->assertSee('aria-label="Next Needs Attention page"', false)
        ->assertSee('aria-label="Next Recent Activity page"', false);

    Livewire::test('pages::dashboard')
        ->assertSet('needsAttentionPage', 1)
        ->assertSet('recentActivityPage', 1)
        ->call('nextNeedsAttentionPage')
        ->assertSet('needsAttentionPage', 2)
        ->assertSet('recentActivityPage', 1)
        ->assertSee('href="'.route('instruments.show', 4).'"', false)
        ->assertDontSee('href="'.route('instruments.show', 1).'"', false)
        ->call('nextRecentActivityPage')
        ->assertSet('needsAttentionPage', 2)
        ->assertSet('recentActivityPage', 2)
        ->assertSee('href="'.route('calibrations.show', 6).'"', false)
        ->call('nextRecentActivityPage')
        ->assertSet('recentActivityPage', 2);
});

it('keeps the authenticated dashboard navigation contract available to Viewer', function (): void {
    createDashboardTestTables();
    actingAsRole(ApplicationRoleName::Viewer);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('href="'.route('calibrations.index').'"', false)
        ->assertDontSee('href="'.route('standards.index').'"', false)
        ->assertDontSee('href="'.route('activities.index').'"', false);
});

it('keeps the instrument and calibration routes available', function (): void {
    createDashboardTestTables();

    $this->get(route('instruments.index'))->assertOk();
    $this->get(route('instruments.show', 999))->assertNotFound();
    $this->get(route('calibrations.index'))->assertOk();
    $this->get(route('calibrations.show', 999))->assertNotFound();
});
