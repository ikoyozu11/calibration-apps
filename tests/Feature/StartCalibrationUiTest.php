<?php

use App\Authorization\ApplicationRoleName;
use App\CalibrationSessions\CalibrationSessionConnection;
use App\CalibrationSessions\StartCalibrationException;
use App\CalibrationSessions\StartCalibrationHttpConnection;
use App\CalibrationSessions\StartCalibrationHttpException;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestRefusingPostgresConnector;

beforeEach(function (): void {
    bindStartCalibrationHttpAllocator();
});

it('lets an administrator see start on annual overview rows without an active session', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');
    actingAsRole(ApplicationRoleName::Administrator);
    insertCalibrationIndexInstrument(['instrument_id' => 6, 'instrument_name' => 'No History']);
    insertCalibrationIndexType(['instrument_type_id' => 5, 'instrument_id' => 6, 'type_name' => 'Gauge']);

    $this->get(route('calibrations.index'))
        ->assertSee('Start Calibration')
        ->assertSee('href="'.e(route('instruments.types.calibrations.start', [
            'instrument' => 6,
            'instrumentType' => 5,
        ])).'"', false)
        ->assertDontSee('Open Session');
});

it('lets an engineer see start on annual overview', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');
    actingAsRole(ApplicationRoleName::Engineer);
    insertCalibrationIndexInstrument(['instrument_id' => 6, 'instrument_name' => 'No History']);
    insertCalibrationIndexType(['instrument_type_id' => 5, 'instrument_id' => 6, 'type_name' => 'Gauge']);

    $this->get(route('calibrations.index'))
        ->assertSee('Start Calibration')
        ->assertDontSee('Complete Calibration')
        ->assertDontSee('Cancel Calibration');
});

it('hides start from a viewer on annual overview', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');
    actingAsRole(ApplicationRoleName::Viewer);
    insertCalibrationIndexInstrument(['instrument_id' => 6, 'instrument_name' => 'No History']);
    insertCalibrationIndexType(['instrument_type_id' => 5, 'instrument_id' => 6, 'type_name' => 'Gauge']);

    $this->get(route('calibrations.index'))
        ->assertDontSee('Start Calibration')
        ->assertSee('No History');
});

it('forbids a viewer from opening the start page', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();
    actingAsRole(ApplicationRoleName::Viewer);

    $this->get(route('instruments.types.calibrations.start', [
        'instrument' => 1,
        'instrumentType' => 11,
    ]))->assertForbidden();
});

it('forbids a viewer from submitting start', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();
    actingAsRole(ApplicationRoleName::Viewer);

    expect(fn () => startCalibrationHttpPage()->call('startCalibration'))
        ->toThrow(InvalidArgumentException::class);

    expect(Calibration::query()->count())->toBe(0);
});

it('redirects an anonymous user to login', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();
    Auth::logout();

    $this->get(route('instruments.types.calibrations.start', [
        'instrument' => 1,
        'instrumentType' => 11,
    ]))->assertRedirect(route('login'));

    expect(Calibration::query()->count())->toBe(0);
});

it('rejects an instrument type that belongs to another instrument', function () {
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

it('keeps the start action available without rendering requirement modes', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();

    startCalibrationHttpPage()
        ->assertSee('Instrument Type')
        ->assertSee('Gauge')
        ->assertSee('Weight')
        ->assertSee('Calibration Method')
        ->assertSee('Start Calibration')
        ->assertDontSee('Requirement Modes')
        ->assertDontSee('>standards</dt>', false)
        ->assertDontSee('>activity</dt>', false)
        ->assertDontSee('>environment</dt>', false)
        ->assertDontSee('>test_profile</dt>', false);
});

it('starts an internal session and redirects to the created session', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();
    $appUserId = mapStartCalibrationHttpActor();

    startCalibrationHttpPage()
        ->set('calibrationMethod', Calibration::METHOD_INTERNAL)
        ->call('startCalibration')
        ->assertHasNoErrors()
        ->assertRedirect(route('calibrations.show', 1));

    $calibration = Calibration::query()->findOrFail(1);

    expect($calibration->session_status)->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($calibration->calibration_method)->toBe(Calibration::METHOD_INTERNAL)
        ->and($calibration->calibration_date)->toBeNull()
        ->and($calibration->calibration_due)->toBeNull()
        ->and((int) $calibration->started_by_app_user_id)->toBe($appUserId)
        ->and((int) $calibration->instrument_id)->toBe(1)
        ->and((int) $calibration->instrument_type_id)->toBe(11)
        ->and($calibration->snapshot_instrument_name)->toBe('Gauge')
        ->and($calibration->snapshot_serial_number)->toBe('SN-1001')
        ->and(DB::table('calibration_requirement_snapshot')->where('calibration_id', 1)->count())->toBe(count(InstrumentTypeRequirement::CATEGORIES))
        ->and(DB::table('calibration_environment_parameter_snapshot')->where('calibration_id', 1)->count())->toBe(2)
        ->and(DB::table('calibration_environment_value')->count())->toBe(0)
        ->and(DB::table('calibration_scope')->count())->toBe(0)
        ->and(DB::table('calibration_standard_usage')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('starts an internal session without type temperature or humidity assignments', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();
    mapStartCalibrationHttpActor();
    DB::table('instrument_type_environment_parameter')->delete();

    startCalibrationHttpPage()
        ->set('calibrationMethod', Calibration::METHOD_INTERNAL)
        ->call('startCalibration')
        ->assertHasNoErrors()
        ->assertRedirect(route('calibrations.show', 1));

    expect(DB::table('calibration_environment_parameter_snapshot')->where('calibration_id', 1)->orderBy('display_order')->pluck('parameter_code')->all())->toBe([
        'temperature',
        'relative_humidity',
    ])
        ->and(DB::table('calibration_environment_parameter_snapshot')->where('calibration_id', 1)->pluck('requirement_mode')->unique()->all())->toBe([
            InstrumentTypeRequirement::MODE_REQUIRED,
        ])
        ->and(DB::table('calibration_environment_value')->count())->toBe(0);
});

it('starts an external session without a provider', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();
    mapStartCalibrationHttpActor();

    startCalibrationHttpPage()
        ->set('calibrationMethod', Calibration::METHOD_EXTERNAL)
        ->call('startCalibration')
        ->assertRedirect(route('calibrations.show', 1));

    $calibration = Calibration::query()->findOrFail(1);

    expect($calibration->calibration_method)->toBe(Calibration::METHOD_EXTERNAL)
        ->and($calibration->calibration_provider_id)->toBeNull()
        ->and($calibration->session_status)->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('rejects start when the domain actor mapping is missing', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();

    startCalibrationHttpPage()
        ->set('calibrationMethod', Calibration::METHOD_INTERNAL)
        ->call('startCalibration')
        ->assertHasErrors(['calibrationMethod' => 'The Laravel user has no domain actor mapping.']);

    expect(Calibration::query()->count())->toBe(0);
});

it('rejects start when the domain actor mapping is inactive', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();
    mapStartCalibrationHttpActor(mappingActive: false);

    startCalibrationHttpPage()
        ->set('calibrationMethod', Calibration::METHOD_INTERNAL)
        ->call('startCalibration')
        ->assertHasErrors(['calibrationMethod' => 'The domain actor mapping is inactive.']);

    expect(Calibration::query()->count())->toBe(0);
});

it('rejects start when the mapped app user is inactive', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();
    mapStartCalibrationHttpActor(appUserActive: false);

    startCalibrationHttpPage()
        ->set('calibrationMethod', Calibration::METHOD_INTERNAL)
        ->call('startCalibration')
        ->assertHasErrors(['calibrationMethod' => 'The mapped app user is inactive.']);

    expect(Calibration::query()->count())->toBe(0);
});

it('rejects a duplicate active session and links to it', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();
    mapStartCalibrationHttpActor();
    insertStartCalibrationHttpSession([
        'calibration_id' => 7,
        'calibration_number' => 'CAL.OPEN',
        'created_at' => '2025-03-01 09:00:00',
    ]);

    startCalibrationHttpPage()
        ->assertSee('Open Session')
        ->assertSee('href="'.route('calibrations.show', 7).'"', false)
        ->assertDontSee('>Start Calibration</button>', false)
        ->set('calibrationMethod', Calibration::METHOD_INTERNAL)
        ->call('startCalibration')
        ->assertHasErrors(['calibrationMethod' => StartCalibrationException::inProgressExists()->getMessage()]);

    expect(Calibration::query()->count())->toBe(1);
});

it('blocks a new start when a cross-year session is already in progress', function () {
    createCalibrationIndexTables();
    $this->travelTo('2026-10-12');
    actingAsRole(ApplicationRoleName::Engineer);
    insertCalibrationIndexInstrument(['instrument_id' => 3, 'instrument_name' => 'Sodiline']);
    insertCalibrationIndexType(['instrument_type_id' => 1, 'instrument_id' => 3, 'type_name' => 'Weight']);
    insertCalibrationIndexRecord([
        'calibration_id' => 9,
        'instrument_id' => 3,
        'instrument_type_id' => 1,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'created_at' => '2025-04-01 08:00:00',
    ]);

    $this->get(route('calibrations.index'))
        ->assertSee('Open Session')
        ->assertSee('href="'.e(route('calibrations.show', 9)).'"', false)
        ->assertDontSee('Start Calibration');
});

it('rejects an invalid calibration method', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();
    mapStartCalibrationHttpActor();

    startCalibrationHttpPage()
        ->set('calibrationMethod', 'FIELD')
        ->call('startCalibration')
        ->assertHasErrors('calibrationMethod');

    expect(Calibration::query()->count())->toBe(0);
});

it('does not create a session on get', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();
    mapStartCalibrationHttpActor();

    $this->get(route('instruments.types.calibrations.start', [
        'instrument' => 1,
        'instrumentType' => 11,
    ]))
        ->assertOk()
        ->assertSee('Start Calibration')
        ->assertSee('Instrument Type')
        ->assertSee('Gauge')
        ->assertSee('Weight')
        ->assertSee('Calibration Method')
        ->assertDontSee('Requirement Modes')
        ->assertDontSee('Complete Calibration')
        ->assertDontSee('Cancel Calibration');

    expect(Calibration::query()->count())->toBe(0)
        ->and(DB::table('instrument_type_requirement')->orderBy('requirement_category')->get(['requirement_category', 'requirement_mode'])->map(fn (object $row): array => (array) $row)->all())->toBe([
            ['requirement_category' => 'activity', 'requirement_mode' => 'optional'],
            ['requirement_category' => 'environment', 'requirement_mode' => 'required'],
            ['requirement_category' => 'standards', 'requirement_mode' => 'required'],
        ]);
});

it('rolls back when start cannot allocate an internal provider', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();
    mapStartCalibrationHttpActor();
    DB::table('calibration_provider')->delete();

    startCalibrationHttpPage()
        ->set('calibrationMethod', Calibration::METHOD_INTERNAL)
        ->call('startCalibration')
        ->assertHasErrors(['calibrationMethod' => 'Internal Calibration Team provider is not available.']);

    expect(Calibration::query()->count())->toBe(0)
        ->and(DB::table('calibration_requirement_snapshot')->count())->toBe(0);
});

it('fails closed when http start cannot use a postgresql write connection', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();
    mapStartCalibrationHttpActor();

    app()->instance(StartCalibrationHttpConnection::class, new StartCalibrationHttpConnection(
        app(CalibrationSessionConnection::class),
        new Repository([
            'database' => [
                'default' => 'pgsql',
                'connections' => [
                    'pgsql' => [
                        'driver' => 'pgsql',
                        'database' => 'calibration_db',
                        'url' => null,
                    ],
                ],
            ],
        ]),
    ));

    Livewire::test('pages::instruments.start-calibration', [
        'instrument' => 1,
        'instrumentType' => 11,
    ])
        ->set('calibrationMethod', Calibration::METHOD_INTERNAL)
        ->call('startCalibration')
        ->assertHasErrors(['calibrationMethod' => 'Starting a calibration session is not available in this environment.']);

    expect(Calibration::query()->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('resolves sqlite memory for http start writes', function () {
    $connection = app(StartCalibrationHttpConnection::class)->resolve();

    expect($connection->getDriverName())->toBe('sqlite')
        ->and($connection->getDatabaseName())->toBe(':memory:')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('refuses a postgresql default connection without opening postgres', function () {
    $guard = new StartCalibrationHttpConnection(
        app(CalibrationSessionConnection::class),
        new Repository([
            'database' => [
                'default' => 'pgsql',
                'connections' => [
                    'pgsql' => [
                        'driver' => 'pgsql',
                        'database' => 'calibration_db',
                        'url' => null,
                    ],
                ],
            ],
        ]),
    );

    expect(fn () => $guard->resolve())
        ->toThrow(StartCalibrationHttpException::class, 'Starting a calibration session is not available in this environment.')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('does not add start actions to session history', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();

    Livewire::test('pages::calibrations')
        ->set('tab', 'history')
        ->assertSee('Session History')
        ->assertDontSee('Start Calibration')
        ->assertDontSee('Complete Calibration')
        ->assertDontSee('Cancel Calibration');
});
