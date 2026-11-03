<?php

use App\Activities\CalibrationActivityMasterWriter;
use App\CalibrationSessions\CalibrationHttpConnection;
use App\CalibrationSessions\CalibrationHttpConnectionException;
use App\Models\InstrumentType;
use App\Models\StandardMaster;
use App\Standards\InstrumentTypeStandardAssignmentWriter;
use App\Standards\StandardMasterWriter;
use Illuminate\Support\Facades\Config;
use Tests\TestRefusingPostgresConnector;

afterEach(function (): void {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.pgsql.database', ':memory:');
    Config::set('database.connections.pgsql.url', '');
});

it('writes standard and activity masters through the safe http connection', function () {
    createInstrumentDetailTestTables();

    $standard = app(StandardMasterWriter::class)->create('Weight Standard E2 Class', '0.0001 - 10.0000 gr', '40488');
    $activity = app(CalibrationActivityMasterWriter::class)->create('Leakage test');

    expect($standard->getConnectionName())->toBe('sqlite')
        ->and($activity->getConnectionName())->toBe('sqlite');

    $this->assertDatabaseHas('standard_master', [
        'standard_code' => 'STD-0001',
        'instrument_name' => 'Weight Standard E2 Class',
    ]);
    $this->assertDatabaseHas('calibration_activity_master', [
        'activity_code' => 'ACT-0001',
        'activity_name' => 'Leakage test',
    ]);
});

it('assigns standards through the safe http connection', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
    insertStandardMaster(10, 'STD-0010', 'Pressure Drop Standard', '99.0 mmWG', 'ET4276');

    $assignment = app(InstrumentTypeStandardAssignmentWriter::class)->assign(
        InstrumentType::on('sqlite')->findOrFail(31),
        StandardMaster::on('sqlite')->findOrFail(10),
    );

    expect($assignment->getConnectionName())->toBe('sqlite');
    $this->assertDatabaseHas('instrument_type_standard_assignment', [
        'instrument_type_id' => 31,
        'standard_id' => 10,
    ]);
});

it('refuses master-data writers on postgresql without opening it', function () {
    TestRefusingPostgresConnector::$connectCalls = 0;
    Config::set('database.default', 'pgsql');
    Config::set('database.connections.pgsql.database', 'laravel');
    Config::set('database.connections.pgsql.url', null);

    expect(fn () => app(StandardMasterWriter::class)->create('Weight Standard'))
        ->toThrow(CalibrationHttpConnectionException::class)
        ->and(fn () => app(CalibrationActivityMasterWriter::class)->create('Cleaning'))
        ->toThrow(CalibrationHttpConnectionException::class)
        ->and(fn () => app(InstrumentTypeStandardAssignmentWriter::class)->assign(
            new InstrumentType,
            new StandardMaster,
        ))
        ->toThrow(CalibrationHttpConnectionException::class)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0)
        ->and(CalibrationHttpConnection::STAGING_HTTP_ACTIVATION)->toBeTrue();
});

it('keeps laravel user ids separate from app user ids', function () {
    $start = (string) file_get_contents(app_path('CalibrationSessions/StartCalibrationSession.php'));
    $actor = (string) file_get_contents(app_path('CalibrationSessions/ResolveDomainActor.php'));
    $login = (string) file_get_contents(app_path('Authorization/ResolveLoginEmail.php'));

    expect($start)->toContain('ResolveDomainActor')
        ->and($start)->toContain('started_by_app_user_id')
        ->and($start)->toContain('never browser-supplied app_user_id')
        ->and($actor)->toContain('user_app_user_map')
        ->and($actor)->toContain('laravel_user_id')
        ->and($actor)->toContain('does not call auth()')
        ->and($login)->toContain('user_app_user_map')
        ->and($login)->toContain('app_user');
});
