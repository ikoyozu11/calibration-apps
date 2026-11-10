<?php

use App\Certificates\CertificateIssuanceException;
use App\Certificates\CertificateNumberAllocator;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function certificateNumberConnection(): Connection
{
    config([
        'database.connections.certificate_number_sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
    ]);

    DB::purge('certificate_number_sqlite');
    $connection = DB::connection('certificate_number_sqlite');
    $connection->unprepared('
        CREATE TABLE certificate (
            certificate_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            certificate_number VARCHAR(50) NOT NULL,
            issued_date TEXT NOT NULL
        )
    ');

    return $connection;
}

it('continues the annual sequence across months and resets in a new year', function () {
    $connection = certificateNumberConnection();
    $connection->table('certificate')->insert([
        ['calibration_id' => 1, 'certificate_number' => 'SR.VT001/01/26', 'issued_date' => '2026-01-05'],
        ['calibration_id' => 2, 'certificate_number' => 'SR.VT002/10/26', 'issued_date' => '2026-10-05'],
        ['calibration_id' => 3, 'certificate_number' => 'SR.VT099/12/25', 'issued_date' => '2025-12-05'],
    ]);

    $allocator = new CertificateNumberAllocator;

    expect($allocator->allocate($connection, Carbon::create(2026, 11, 4, 0, 0, 0, 'Asia/Jakarta')))
        ->toBe('SR.VT003/11/26')
        ->and($allocator->allocate($connection, Carbon::create(2027, 1, 4, 0, 0, 0, 'Asia/Jakarta')))
        ->toBe('SR.VT001/01/27');
});

it('fails clearly when the annual sequence exceeds 999', function () {
    $connection = certificateNumberConnection();
    $connection->table('certificate')->insert([
        'calibration_id' => 1,
        'certificate_number' => 'SR.VT999/11/26',
        'issued_date' => '2026-11-04',
    ]);

    expect(fn () => (new CertificateNumberAllocator)->allocate(
        $connection,
        Carbon::create(2026, 11, 5, 0, 0, 0, 'Asia/Jakarta'),
    ))->toThrow(CertificateIssuanceException::class, 'exceeded 999');
});
