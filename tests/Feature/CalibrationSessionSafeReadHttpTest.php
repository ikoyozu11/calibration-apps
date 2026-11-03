<?php

use App\Authorization\ApplicationCapability;
use App\Authorization\ApplicationRoleName;
use App\CalibrationSessions\CalibrationSessionConnection;
use App\CalibrationSessions\ReadCalibrationSessionHttpConnection;
use App\CalibrationSessions\ReadCalibrationSessionHttpException;
use App\StagingRuntime\StagingRuntimeIsolation;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestRefusingPostgresConnector;

afterEach(function (): void {
    Config::set('database.staging_runtime_isolation', false);
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.pgsql.database', ':memory:');
    Config::set('database.connections.pgsql.url', '');
    Config::set('session.driver', 'array');
});

it('rejects production database configuration on login before authentication queries', function () {
    auth()->logout();
    TestRefusingPostgresConnector::$connectCalls = 0;
    Config::set('database.staging_runtime_isolation', false);
    Config::set('database.default', 'pgsql');
    Config::set('database.connections.pgsql.database', StagingRuntimeIsolation::FORBIDDEN_PRODUCTION_DATABASE);
    Config::set('database.connections.pgsql.url', null);

    $this->get('/login')->assertStatus(503);
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects production configuration on calibration index before domain queries', function () {
    TestRefusingPostgresConnector::$connectCalls = 0;
    Config::set('database.staging_runtime_isolation', false);
    Config::set('database.default', 'pgsql');
    Config::set('database.connections.pgsql.database', StagingRuntimeIsolation::FORBIDDEN_PRODUCTION_DATABASE);
    Config::set('database.connections.pgsql.url', null);

    $this->get(route('calibrations.index'))->assertStatus(503);
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('loads session detail through a numeric route and sqlite read connection', function () {
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('CAL.SESS.5');
});

it('returns 404 for an unknown numeric session id after safe resolution', function () {
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();

    $this->get(route('calibrations.show', 99999))->assertNotFound();
});

it('rejects a non-numeric session detail route before model lookup', function () {
    $this->get('/calibrations/abc')->assertNotFound();
});

it('lets a viewer open session detail through HistoryView', function () {
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();
    actingAsRole(ApplicationRoleName::Viewer);

    $this->get(route('calibrations.show', 5))->assertOk();
});

it('denies a viewer a direct complete action', function () {
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();
    actingAsRole(ApplicationRoleName::Viewer);

    expect(Gate::forUser(auth()->user())->denies(ApplicationCapability::SessionComplete->value))->toBeTrue();

    Livewire::test('pages::calibrations.show', ['calibration' => 5])
        ->call('completeSession')
        ->assertForbidden();
});

it('does not keep implicit eloquent calibration binding on the session detail route', function () {
    $routes = (string) file_get_contents(base_path('routes/web.php'));
    $show = (string) file_get_contents(resource_path('views/pages/calibrations/⚡show.blade.php'));
    $start = (string) file_get_contents(resource_path('views/pages/instruments/⚡start-calibration.blade.php'));

    expect($routes)->toContain("->whereNumber('calibration')")
        ->and($routes)->toContain("->whereNumber('instrument')")
        ->and($routes)->toContain("->whereNumber('instrumentType')")
        ->and($show)->toContain('public int $calibrationId')
        ->and($show)->not->toContain('public Calibration $calibration')
        ->and($show)->not->toContain('mount(Calibration $calibration)')
        ->and($start)->toContain('public int $instrumentId')
        ->and($start)->toContain('public int $instrumentTypeId')
        ->and($start)->not->toContain('public Instrument $instrument;')
        ->and($start)->not->toContain('public InstrumentType $instrumentType;');
});

it('loads the calibration index through the safe sqlite read connection', function () {
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject();

    $this->get(route('calibrations.index'))
        ->assertOk()
        ->assertSee('Live Gauge')
        ->assertSee('Live Type');
});

it('loads start calibration through numeric instrument and type ids', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();

    $this->get(route('instruments.types.calibrations.start', [
        'instrument' => 1,
        'instrumentType' => 11,
    ]))->assertOk()->assertSee('Start Calibration');
});

it('returns 404 when the start type does not belong to the instrument', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();
    DB::table('instrument')->insert([
        'instrument_id' => 2,
        'instrument_name' => 'Other',
        'created_at' => '2026-10-20 09:00:00',
    ]);
    DB::table('instrument_type')->insert([
        'instrument_type_id' => 21,
        'instrument_id' => 2,
        'type_name' => 'Foreign',
        'created_at' => '2026-10-20 09:00:00',
    ]);

    $this->get(route('instruments.types.calibrations.start', [
        'instrument' => 1,
        'instrumentType' => 21,
    ]))->assertNotFound();
});

it('returns 404 for unknown start instrument or type ids', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();

    $this->get(route('instruments.types.calibrations.start', [
        'instrument' => 99,
        'instrumentType' => 11,
    ]))->assertNotFound();
});

it('refuses default postgresql and pgsql_staging http reads before pdo', function () {
    TestRefusingPostgresConnector::$connectCalls = 0;
    $guard = app(CalibrationSessionConnection::class);

    $defaultPostgres = new ReadCalibrationSessionHttpConnection($guard, new Repository([
        'database' => [
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => [
                    'driver' => 'pgsql',
                    'database' => 'laravel',
                    'url' => null,
                ],
            ],
        ],
    ]));

    $staging = new ReadCalibrationSessionHttpConnection($guard, new Repository([
        'database' => [
            'default' => 'pgsql_staging',
            'connections' => [
                'pgsql_staging' => [
                    'driver' => 'pgsql',
                    'database' => StagingRuntimeIsolation::REQUIRED_DATABASE,
                    'url' => null,
                ],
            ],
        ],
    ]));

    expect(fn () => $defaultPostgres->resolve())
        ->toThrow(ReadCalibrationSessionHttpException::class, ReadCalibrationSessionHttpException::readsUnavailable()->getMessage())
        ->and(fn () => $staging->resolve())
        ->toThrow(ReadCalibrationSessionHttpException::class, ReadCalibrationSessionHttpException::readsUnavailable()->getMessage())
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('resolves sqlite memory for http session reads', function () {
    $connection = app(ReadCalibrationSessionHttpConnection::class)->resolve();

    expect($connection->getDriverName())->toBe('sqlite')
        ->and($connection->getDatabaseName())->toBe(':memory:')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});
