<?php

use App\CalibrationSessions\CalibrationHttpConnection;
use App\CalibrationSessions\CalibrationHttpConnectionException;
use App\CalibrationSessions\CalibrationSessionConnection;
use App\CalibrationSessions\ReadCalibrationSessionHttpConnection;
use App\CalibrationSessions\ReadCalibrationSessionHttpException;
use App\CalibrationSessions\RecordCalibrationSessionHttpConnection;
use App\CalibrationSessions\RecordCalibrationSessionHttpException;
use App\CalibrationSessions\StartCalibrationHttpConnection;
use App\CalibrationSessions\StartCalibrationHttpException;
use Illuminate\Config\Repository;
use Tests\TestCase;
use Tests\TestRefusingPostgresConnector;
use Tests\UnsafeTestDatabaseException;

uses(TestCase::class);

it('keeps layer 2 staging http activation enabled', function () {
    expect(CalibrationHttpConnection::STAGING_HTTP_ACTIVATION)->toBeTrue()
        ->and(app(CalibrationHttpConnection::class)->stagingHttpEnabled())->toBeTrue();
});

it('resolves sqlite memory for http pages', function () {
    $connection = app(CalibrationHttpConnection::class)->resolve();

    expect($connection->getDriverName())->toBe('sqlite')
        ->and($connection->getDatabaseName())->toBe(':memory:')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('refuses default postgresql and named staging when isolation is off', function () {
    TestRefusingPostgresConnector::$connectCalls = 0;
    $guard = app(CalibrationSessionConnection::class);

    $postgres = new CalibrationHttpConnection($guard, new Repository([
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

    $staging = new CalibrationHttpConnection($guard, new Repository([
        'database' => [
            'default' => 'pgsql_staging',
            'connections' => [
                'pgsql_staging' => [
                    'driver' => 'pgsql',
                    'database' => 'calibration_staging_db',
                    'url' => null,
                ],
            ],
        ],
    ]));

    expect(fn () => $postgres->resolve())
        ->toThrow(CalibrationHttpConnectionException::class)
        ->and(fn () => $staging->resolve())
        ->toThrow(CalibrationHttpConnectionException::class)
        ->and(fn () => (new ReadCalibrationSessionHttpConnection($guard, new Repository([
            'database' => ['default' => 'pgsql', 'connections' => ['pgsql' => ['driver' => 'pgsql', 'database' => 'laravel']]],
        ])))->resolve())
        ->toThrow(ReadCalibrationSessionHttpException::class)
        ->and(fn () => (new StartCalibrationHttpConnection($guard, new Repository([
            'database' => ['default' => 'pgsql', 'connections' => ['pgsql' => ['driver' => 'pgsql', 'database' => 'laravel']]],
        ])))->resolve())
        ->toThrow(StartCalibrationHttpException::class)
        ->and(fn () => (new RecordCalibrationSessionHttpConnection($guard, new Repository([
            'database' => ['default' => 'pgsql', 'connections' => ['pgsql' => ['driver' => 'pgsql', 'database' => 'laravel']]],
        ])))->resolve())
        ->toThrow(RecordCalibrationSessionHttpException::class)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('resolves named pgsql_staging after isolation checks', function () {
    $source = (string) file_get_contents(app_path('CalibrationSessions/CalibrationHttpConnection.php'));
    $resolveStart = strpos($source, 'public function resolve(): Connection');

    expect($source)->toContain('resolveNamed(CalibrationSessionConnection::STAGING_CONNECTION)')
        ->and($source)->toContain('STAGING_HTTP_ACTIVATION = true')
        ->and($source)->not->toContain("env('")
        ->and($source)->not->toContain('DB::connection()')
        ->and($resolveStart)->toBeGreaterThan(0)
        ->and(strpos($source, 'sqliteMemoryAvailable()', $resolveStart))
        ->toBeLessThan((int) strpos($source, 'stagingHttpEnabled()', $resolveStart));
});

it('refuses isolated staging postgres when isolation is off', function () {
    TestRefusingPostgresConnector::$connectCalls = 0;
    $guard = app(CalibrationSessionConnection::class);

    $selector = new CalibrationHttpConnection($guard, new Repository([
        'database' => [
            'staging_runtime_isolation' => false,
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => [
                    'driver' => 'pgsql',
                    'host' => '127.0.0.1',
                    'port' => 5432,
                    'database' => 'calibration_staging_db',
                    'url' => null,
                ],
                'pgsql_staging' => [
                    'driver' => 'pgsql',
                    'host' => '127.0.0.1',
                    'port' => 5432,
                    'database' => 'calibration_staging_db',
                    'url' => null,
                ],
            ],
        ],
        'session' => ['driver' => 'file'],
        'cache' => ['default' => 'array'],
        'queue' => ['default' => 'sync'],
    ]));

    expect(fn () => $selector->resolve())
        ->toThrow(CalibrationHttpConnectionException::class)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0)
        ->and(CalibrationHttpConnection::STAGING_HTTP_ACTIVATION)->toBeTrue();
});

it('opens named pgsql_staging when activation and isolation are on', function () {
    TestRefusingPostgresConnector::$connectCalls = 0;
    $guard = app(CalibrationSessionConnection::class);

    $selector = new CalibrationHttpConnection($guard, new Repository([
        'database' => [
            'staging_runtime_isolation' => true,
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => [
                    'driver' => 'pgsql',
                    'host' => '127.0.0.1',
                    'port' => 5432,
                    'database' => 'calibration_staging_db',
                    'url' => null,
                ],
                'pgsql_staging' => [
                    'driver' => 'pgsql',
                    'host' => '127.0.0.1',
                    'port' => 5432,
                    'database' => 'calibration_staging_db',
                    'url' => null,
                ],
            ],
        ],
        'session' => ['driver' => 'file'],
        'cache' => ['default' => 'array'],
        'queue' => ['default' => 'sync'],
    ]));

    expect(fn () => $selector->resolve())
        ->toThrow(function (UnsafeTestDatabaseException $exception): void {
            expect($exception->getMessage())->toContain('pgsql_staging');
        })
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});
