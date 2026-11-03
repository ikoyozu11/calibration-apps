<?php

use App\CalibrationSessions\CalibrationHttpConnection;
use App\StagingRuntime\StagingRuntimeIsolation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestRefusingPostgresConnector;

afterEach(function (): void {
    Config::set('database.staging_runtime_isolation', false);
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.pgsql.database', ':memory:');
    Config::set('database.connections.pgsql.url', '');
});

it('binds remaining core ui routes by numeric ids', function () {
    $routes = (string) file_get_contents(base_path('routes/web.php'));
    $show = (string) file_get_contents(resource_path('views/pages/instruments/⚡show.blade.php'));
    $components = (string) file_get_contents(resource_path('views/pages/instruments/⚡test-components.blade.php'));
    $standards = (string) file_get_contents(resource_path('views/pages/instruments/⚡standards.blade.php'));
    $certificate = (string) file_get_contents(resource_path('views/pages/certificates/⚡show.blade.php'));
    $writer = (string) file_get_contents(app_path('Livewire/Concerns/ManagesTestComponents.php'));

    expect($routes)->toContain("->whereNumber('instrument')")
        ->and($routes)->toContain("->whereNumber('instrumentType')")
        ->and($routes)->toContain("->whereNumber('certificate')")
        ->and($show)->toContain('public int $instrumentId')
        ->and($show)->not->toContain('public Instrument $instrument;')
        ->and($components)->toContain('public int $instrumentTypeId')
        ->and($components)->not->toContain('mount(Instrument $instrument')
        ->and($standards)->not->toContain('public InstrumentType $instrumentType;')
        ->and($certificate)->toContain('public int $certificateId')
        ->and($certificate)->not->toContain('public Certificate $certificate;')
        ->and($certificate)->toContain('TestPoint::on($this->readConnectionName())')
        ->and($certificate)->not->toContain('TestPoint::query()')
        ->and($writer)->toContain('CalibrationHttpConnection')
        ->and($writer)->not->toContain('DB::transaction')
        ->and($writer)->not->toContain('DB::update');
});

it('loads instrument detail and type pages through the safe read connection', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    $this->get(route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 31]))
        ->assertOk()
        ->assertSee('Sodiline');

    $this->get(route('instruments.types.test-components', ['instrument' => 3, 'instrumentType' => 31]))
        ->assertOk()
        ->assertSee('Manage Test Components');

    $this->get(route('instruments.types.standards', ['instrument' => 3, 'instrumentType' => 31]))
        ->assertOk()
        ->assertSee('Manage Standards');
});

it('returns 404 when a type does not belong to the requested instrument', function () {
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

    $this->get(route('instruments.types.show', ['instrument' => 3, 'instrumentType' => 40]))
        ->assertNotFound();
    $this->get(route('instruments.types.test-components', ['instrument' => 3, 'instrumentType' => 40]))
        ->assertNotFound();
    $this->get(route('instruments.types.standards', ['instrument' => 3, 'instrumentType' => 40]))
        ->assertNotFound();
});

it('rejects a non-numeric instrument or certificate route before model lookup', function () {
    $this->get('/instruments/abc')->assertNotFound();
    $this->get('/certificates/abc')->assertNotFound();
});

it('loads dashboard and instrument index through the safe read connection', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    $this->get(route('home'))->assertOk()->assertSee('Dashboard');
    $this->get(route('instruments.index'))->assertOk()->assertSee('Sodiline');
});

it('loads standards and activities through the safe read connection', function () {
    createInstrumentDetailTestTables();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', 'Standard PD', 'ET4276');
    insertCalibrationActivityMaster(1, 'ACT-0001', 'Verification');

    $this->get(route('standards.index'))
        ->assertOk()
        ->assertSee('Standard Master')
        ->assertSee('STD-0010');
    $this->get(route('activities.index'))
        ->assertOk()
        ->assertSee('Activity Master')
        ->assertSee('ACT-0001');
});

it('rejects production configuration on remaining core ui before queries', function () {
    TestRefusingPostgresConnector::$connectCalls = 0;
    Config::set('database.staging_runtime_isolation', false);
    Config::set('database.default', 'pgsql');
    Config::set('database.connections.pgsql.database', StagingRuntimeIsolation::FORBIDDEN_PRODUCTION_DATABASE);
    Config::set('database.connections.pgsql.url', null);

    $this->get(route('instruments.index'))->assertStatus(503);
    $this->get(route('home'))->assertStatus(503);
    $this->get(route('standards.index'))->assertStatus(503);
    $this->get(route('activities.index'))->assertStatus(503);
    $this->get(route('certificates.show', 1))->assertStatus(503);
    $this->get(route('instruments.types.test-components', ['instrument' => 3, 'instrumentType' => 31]))->assertStatus(503);
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0)
        ->and(CalibrationHttpConnection::STAGING_HTTP_ACTIVATION)->toBeTrue();
});
