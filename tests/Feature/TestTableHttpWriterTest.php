<?php

use App\CalibrationSessions\CalibrationHttpConnection;
use App\CalibrationSessions\CalibrationHttpConnectionException;
use App\TestColumns\ColumnDefinition;
use App\TestColumns\PostgresTestColumnWriter;
use App\TestColumns\PostgresTestPointValueWriter;
use App\TestColumns\PostgresTestProfileLifecycle;
use App\TestColumns\PostgresTestTableVersionWriter;
use App\TestColumns\SqliteTestColumnWriter;
use App\TestColumns\SqliteTestPointValueWriter;
use App\TestColumns\SqliteTestProfileLifecycle;
use App\TestColumns\TestColumnWriter;
use App\TestColumns\TestPointValueWriter;
use App\TestColumns\TestProfileLifecycle;
use App\TestColumns\TestTableVersionWriter;
use Illuminate\Support\Facades\Config;
use Tests\TestRefusingPostgresConnector;

afterEach(function (): void {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.pgsql.database', ':memory:');
    Config::set('database.connections.pgsql.url', '');
});

it('selects sqlite test table writers from config without opening postgresql', function () {
    TestRefusingPostgresConnector::$connectCalls = 0;

    expect(app(TestColumnWriter::class))->toBeInstanceOf(SqliteTestColumnWriter::class)
        ->and(app(TestPointValueWriter::class))->toBeInstanceOf(SqliteTestPointValueWriter::class)
        ->and(CalibrationHttpConnection::STAGING_HTTP_ACTIVATION)->toBeTrue()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('writes columns and values through the safe http connection', function () {
    createInstrumentDetailTestTables();
    insertColumnManagerFixture();

    $column = new ColumnDefinition(
        profileId: 1,
        columnKey: 'set_point',
        columnLabel: 'Set Point',
        columnOrder: 2,
        dataType: 'numeric',
        valueSource: 'template',
        unit: 'g',
        displayScale: 3,
        formulaCode: null,
        formulaInputs: [],
    );

    $columnId = app(TestColumnWriter::class)->insert($column);
    app(TestPointValueWriter::class)->upsert(1, 10, $columnId, '100.000', null, 'g');

    $this->assertDatabaseHas('test_column', [
        'test_column_id' => $columnId,
        'column_key' => 'set_point',
        'column_label' => 'Set Point',
    ]);
    $this->assertDatabaseHas('test_point_value', [
        'test_profile_id' => 1,
        'test_point_id' => 10,
        'test_column_id' => $columnId,
        'numeric_value' => '100.000',
    ]);
});

it('selects postgres test table writers without opening postgresql when isolation is off', function () {
    TestRefusingPostgresConnector::$connectCalls = 0;
    Config::set('database.default', 'pgsql');
    Config::set('database.connections.pgsql.database', 'laravel');
    Config::set('database.connections.pgsql.url', null);

    expect(app(TestColumnWriter::class))->toBeInstanceOf(PostgresTestColumnWriter::class)
        ->and(app(TestPointValueWriter::class))->toBeInstanceOf(PostgresTestPointValueWriter::class)
        ->and(app(TestTableVersionWriter::class))->toBeInstanceOf(PostgresTestTableVersionWriter::class)
        ->and(app(TestProfileLifecycle::class))->toBeInstanceOf(PostgresTestProfileLifecycle::class)
        ->and(fn () => app(TestProfileLifecycle::class)->isUsedInCalibration(1))
        ->toThrow(CalibrationHttpConnectionException::class)
        ->and(fn () => app(SqliteTestProfileLifecycle::class)->deleteUnused(1))
        ->toThrow(CalibrationHttpConnectionException::class)
        ->and(fn () => app(SqliteTestColumnWriter::class)->insert(new ColumnDefinition(
            profileId: 1,
            columnKey: 'set_point',
            columnLabel: 'Set Point',
            columnOrder: 1,
            dataType: 'numeric',
            valueSource: 'template',
            unit: null,
            displayScale: null,
            formulaCode: null,
            formulaInputs: [],
        )))
        ->toThrow(CalibrationHttpConnectionException::class)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('does not choose test table writers through the default connection', function () {
    $provider = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));
    $column = (string) file_get_contents(app_path('TestColumns/SqliteTestColumnWriter.php'));
    $value = (string) file_get_contents(app_path('TestColumns/SqliteTestPointValueWriter.php'));
    $version = (string) file_get_contents(app_path('TestColumns/SqliteTestTableVersionWriter.php'));
    $lifecycle = (string) file_get_contents(app_path('TestColumns/SqliteTestProfileLifecycle.php'));
    $guard = (string) file_get_contents(app_path('TestColumns/TestTableVersionGuard.php'));

    expect($provider)->toContain('testTableWriterDriver()')
        ->and($provider)->not->toContain('DB::connection()')
        ->and($column)->toContain('WritesThroughHttpConnection')
        ->and($column)->not->toContain('DB::table')
        ->and($value)->toContain('WritesThroughHttpConnection')
        ->and($value)->not->toContain('DB::table')
        ->and($version)->toContain('WritesThroughHttpConnection')
        ->and($version)->not->toContain('DB::table')
        ->and($lifecycle)->toContain('WritesThroughHttpConnection')
        ->and($lifecycle)->not->toContain('DB::table')
        ->and($guard)->toContain('WritesThroughHttpConnection')
        ->and($guard)->not->toContain('DB::table');
});
