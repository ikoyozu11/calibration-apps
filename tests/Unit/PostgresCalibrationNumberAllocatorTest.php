<?php

use App\CalibrationSessions\CalibrationNumberAllocator;
use App\CalibrationSessions\PostgresCalibrationNumberAllocator;
use Illuminate\Database\Connection;
use Tests\TestCase;

uses(TestCase::class);

it('binds the postgres allocator as the default implementation', function () {
    expect(app(CalibrationNumberAllocator::class))->toBeInstanceOf(PostgresCalibrationNumberAllocator::class);
});

it('refuses to allocate on a non-pgsql connection', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('sqlite');

    expect(fn () => (new PostgresCalibrationNumberAllocator)->allocate(
        $connection,
        new DateTimeImmutable('2026-10-20 10:00:00', new DateTimeZone('Asia/Jakarta')),
    ))->toThrow(RuntimeException::class, 'PostgreSQL Calibration Number allocation requires a pgsql connection.');
});

it('formats nextval using asia jakarta month and year', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('scalar')
        ->once()
        ->with("select nextval('calibration_number_seq')")
        ->andReturn(2);

    expect((new PostgresCalibrationNumberAllocator)->allocate(
        $connection,
        new DateTimeImmutable('2026-10-20 10:00:00', new DateTimeZone('Asia/Jakarta')),
    ))->toBe('CAL.VT0002/10/26');
});
