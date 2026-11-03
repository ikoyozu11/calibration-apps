<?php

use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\CompleteCalibrationSession;
use App\CalibrationSessions\RecordExternalCalibrationVendor;
use App\CalibrationSessions\RecordExternalCalibrationVendorException;
use App\CalibrationSessions\StoreExternalCalibrationDocument;
use App\Models\Calibration;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestRefusingPostgresConnector;

const EXTERNAL_VENDOR_CONNECTION = 'external_vendor_sqlite';

function externalVendorConnection(): Connection
{
    config([
        'database.connections.'.EXTERNAL_VENDOR_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
    ]);

    DB::purge(EXTERNAL_VENDOR_CONNECTION);

    $connection = DB::connection(EXTERNAL_VENDOR_CONNECTION);
    $connection->unprepared('
        CREATE TABLE users (
            id INTEGER PRIMARY KEY
        )
    ');
    $connection->unprepared('
        CREATE TABLE app_user (
            app_user_id INTEGER PRIMARY KEY,
            is_active INTEGER NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE user_app_user_map (
            user_app_user_map_id INTEGER PRIMARY KEY,
            laravel_user_id INTEGER NOT NULL,
            app_user_id INTEGER NOT NULL,
            status VARCHAR(20) NOT NULL,
            created_at TEXT NOT NULL,
            inactivated_at TEXT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration (
            calibration_id INTEGER PRIMARY KEY,
            instrument_id INTEGER NULL,
            instrument_type_id INTEGER NULL,
            calibration_provider_id INTEGER NULL,
            calibration_number VARCHAR(50) NULL,
            calibration_date TEXT NULL,
            calibration_due TEXT NULL,
            calibration_method VARCHAR(20) NULL,
            session_status VARCHAR(20) NOT NULL,
            certificate_number TEXT NULL,
            remarks TEXT NULL,
            completed_at TEXT NULL,
            cancelled_at TEXT NULL,
            cancelled_reason TEXT NULL,
            cancelled_by_user_id INTEGER NULL,
            started_by_app_user_id INTEGER NULL,
            snapshot_instrument_name TEXT NULL,
            snapshot_instrument_type_name TEXT NULL,
            snapshot_location_id INTEGER NULL,
            snapshot_detail_location_id INTEGER NULL,
            snapshot_location_code TEXT NULL,
            snapshot_location_name TEXT NULL,
            snapshot_detail_location_code TEXT NULL,
            snapshot_detail_location_name TEXT NULL,
            external_vendor_name VARCHAR(255) NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_external_document (
            document_id INTEGER PRIMARY KEY AUTOINCREMENT,
            calibration_id INTEGER NOT NULL,
            storage_uuid TEXT NOT NULL UNIQUE,
            storage_path TEXT NOT NULL,
            original_filename TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            byte_size INTEGER NOT NULL,
            sha256 TEXT NOT NULL,
            is_active INTEGER NOT NULL,
            uploaded_at TEXT NOT NULL,
            superseded_at TEXT NULL,
            uploaded_by_user_id INTEGER NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE certificate (
            certificate_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            certificate_number VARCHAR(50) NOT NULL
        )
    ');

    Storage::fake('calibration_documents');

    return $connection;
}

function externalVendorService(): RecordExternalCalibrationVendor
{
    return app(RecordExternalCalibrationVendor::class);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedExternalVendorCalibration(Connection $connection, array $overrides = []): void
{
    $connection->table('calibration')->insert(array_merge([
        'calibration_id' => 40,
        'instrument_id' => 3,
        'instrument_type_id' => 12,
        'calibration_provider_id' => null,
        'calibration_number' => 'CAL.VT0040/10/26',
        'calibration_date' => null,
        'calibration_due' => null,
        'calibration_method' => Calibration::METHOD_EXTERNAL,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'certificate_number' => null,
        'remarks' => 'leave me',
        'completed_at' => null,
        'cancelled_at' => null,
        'cancelled_reason' => null,
        'cancelled_by_user_id' => null,
        'started_by_app_user_id' => 90,
        'snapshot_instrument_name' => 'Gauge',
        'snapshot_instrument_type_name' => 'Pressure',
        'snapshot_location_id' => 4,
        'snapshot_detail_location_id' => 8,
        'snapshot_location_code' => 'LAB',
        'snapshot_location_name' => 'Laboratory',
        'snapshot_detail_location_code' => 'B1',
        'snapshot_detail_location_name' => 'Bench 1',
        'external_vendor_name' => null,
        'created_at' => '2026-10-22 08:00:00',
        'updated_at' => '2026-10-22 08:00:00',
    ], $overrides));
}

function seedExternalVendorActor(Connection $connection): void
{
    $connection->table('users')->insert(['id' => 5]);
    $connection->table('app_user')->insert([
        'app_user_id' => 90,
        'is_active' => 1,
    ]);
    $connection->table('user_app_user_map')->insert([
        'user_app_user_map_id' => 1,
        'laravel_user_id' => 5,
        'app_user_id' => 90,
        'status' => 'active',
        'created_at' => '2026-10-22 08:00:00',
        'inactivated_at' => null,
    ]);
}

function seedExternalVendorDocument(Connection $connection, string $filename = 'vendor.pdf'): void
{
    $connection->table('calibration_external_document')->insert([
        'calibration_id' => 40,
        'storage_uuid' => '11111111-1111-1111-1111-000000000001',
        'storage_path' => '11111111-1111-1111-1111-000000000001.pdf',
        'original_filename' => $filename,
        'mime_type' => 'application/pdf',
        'byte_size' => 10,
        'sha256' => str_repeat('ab', 32),
        'is_active' => 1,
        'uploaded_at' => '2026-10-22 08:00:00',
        'superseded_at' => null,
        'uploaded_by_user_id' => 90,
    ]);
}

function externalVendorRow(Connection $connection): object
{
    return $connection->table('calibration')->where('calibration_id', 40)->first();
}

function externalVendorSql(Connection $connection): string
{
    return implode("\n", array_column($connection->getQueryLog(), 'query'));
}

it('exposes a nullable vendor column and leaves existing rows null', function () {
    config([
        'database.connections.'.EXTERNAL_VENDOR_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
    ]);
    DB::purge(EXTERNAL_VENDOR_CONNECTION);
    $connection = DB::connection(EXTERNAL_VENDOR_CONNECTION);
    $connection->unprepared('
        CREATE TABLE calibration (
            calibration_id INTEGER PRIMARY KEY,
            calibration_method VARCHAR(20) NOT NULL,
            session_status VARCHAR(20) NOT NULL
        )
    ');
    $connection->table('calibration')->insert([
        'calibration_id' => 1,
        'calibration_method' => Calibration::METHOD_EXTERNAL,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
    ]);

    $connection->unprepared('ALTER TABLE calibration ADD COLUMN external_vendor_name VARCHAR(255) NULL');

    expect(Schema::connection(EXTERNAL_VENDOR_CONNECTION)->hasColumn('calibration', 'external_vendor_name'))->toBeTrue()
        ->and($connection->table('calibration')->where('calibration_id', 1)->value('external_vendor_name'))->toBeNull()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('declares the staging guard, rerun refusal, and nullable varchar in the vendor script', function () {
    $sql = file_get_contents(base_path('database/sql/calibration_external_vendor_name.sql'));

    expect($sql)->toContain('BEGIN;')
        ->and($sql)->toContain('COMMIT;')
        ->and($sql)->toContain("current_database() IS DISTINCT FROM 'calibration_staging_db'")
        ->and($sql)->toContain("to_regclass('public.calibration')")
        ->and($sql)->toContain('ADD COLUMN external_vendor_name character varying(255)')
        ->and($sql)->not->toContain('ADD COLUMN IF NOT EXISTS')
        ->and($sql)->toContain('Do not use IF NOT EXISTS')
        ->and($sql)->toContain('is not idempotent and external_vendor_name already exists')
        ->and($sql)->toContain("column_name = 'external_vendor_name'")
        ->and($sql)->not->toContain('DEFAULT')
        ->and($sql)->not->toContain('UPDATE public.calibration')
        ->and($sql)->not->toContain('FOREIGN KEY')
        ->and($sql)->toContain('Do not run via php artisan migrate')
        ->and($sql)->toContain('Do not execute on calibration_db')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('records and updates an external vendor snapshot', function () {
    $connection = externalVendorConnection();
    seedExternalVendorCalibration($connection);

    externalVendorService()->record(40, 'Acme Labs', $connection);
    expect(externalVendorRow($connection)->external_vendor_name)->toBe('Acme Labs');

    externalVendorService()->record(40, 'Beta Metrology', $connection);
    expect(externalVendorRow($connection)->external_vendor_name)->toBe('Beta Metrology')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('normalizes null, empty, whitespace, trim, and internal spaces', function (?string $input, ?string $expected) {
    $connection = externalVendorConnection();
    seedExternalVendorCalibration($connection, ['external_vendor_name' => 'keep']);

    externalVendorService()->record(40, $input, $connection);

    expect(externalVendorRow($connection)->external_vendor_name)->toBe($expected);
})->with([
    'null' => [null, null],
    'empty string' => ['', null],
    'whitespace only' => ['   ', null],
    'trimmed edges' => ['  Acme Labs  ', 'Acme Labs'],
    'internal spaces' => ['Acme  Labs', 'Acme  Labs'],
]);

it('accepts a unicode vendor name and a 255-character value', function () {
    $connection = externalVendorConnection();
    seedExternalVendorCalibration($connection);
    $max = str_repeat('あ', 255);

    externalVendorService()->record(40, 'PT Kalibrasi Café', $connection);
    expect(externalVendorRow($connection)->external_vendor_name)->toBe('PT Kalibrasi Café');

    externalVendorService()->record(40, $max, $connection);
    expect(externalVendorRow($connection)->external_vendor_name)->toBe($max);
});

it('rejects an overlength vendor name without writing', function () {
    $connection = externalVendorConnection();
    seedExternalVendorCalibration($connection, ['external_vendor_name' => 'Prior']);
    $before = (array) externalVendorRow($connection);

    expect(fn () => externalVendorService()->record(40, str_repeat('a', 256), $connection))
        ->toThrow(RecordExternalCalibrationVendorException::class, RecordExternalCalibrationVendorException::vendorNameTooLong()->getMessage());

    expect((array) externalVendorRow($connection))->toBe($before);
});

it('rejects internal, completed, cancelled, and missing sessions', function (?array $overrides, string $message) {
    $connection = externalVendorConnection();
    if ($overrides !== null) {
        seedExternalVendorCalibration($connection, $overrides);
    }
    $before = $overrides === null ? [] : (array) externalVendorRow($connection);

    expect(fn () => externalVendorService()->record(40, 'Acme Labs', $connection))
        ->toThrow(RecordExternalCalibrationVendorException::class, $message);

    if ($overrides !== null) {
        expect((array) externalVendorRow($connection))->toBe($before);
    } else {
        expect($connection->table('calibration')->count())->toBe(0);
    }
})->with([
    'internal' => [[
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'calibration_provider_id' => 77,
    ], RecordExternalCalibrationVendorException::unsupportedCalibrationMethod()->getMessage()],
    'completed' => [[
        'session_status' => Calibration::STATUS_COMPLETED,
        'external_vendor_name' => 'Frozen Vendor',
        'completed_at' => '2026-10-22 10:00:00',
    ], RecordExternalCalibrationVendorException::invalidSessionStatus()->getMessage()],
    'cancelled' => [[
        'session_status' => Calibration::STATUS_CANCELLED,
        'cancelled_at' => '2026-10-22 10:00:00',
        'external_vendor_name' => 'Frozen Vendor',
    ], RecordExternalCalibrationVendorException::invalidSessionStatus()->getMessage()],
    'missing' => [null, RecordExternalCalibrationVendorException::missingCalibration()->getMessage()],
]);

it('changes only the vendor snapshot column', function () {
    $connection = externalVendorConnection();
    seedExternalVendorCalibration($connection, [
        'calibration_provider_id' => 9,
        'calibration_date' => '2026-01-01',
        'calibration_due' => '2027-01-01',
        'certificate_number' => 'CERT-KEEP',
        'session_status' => Calibration::STATUS_IN_PROGRESS,
    ]);
    seedExternalVendorDocument($connection);
    $before = (array) externalVendorRow($connection);
    $documents = $connection->table('calibration_external_document')->orderBy('document_id')->get()->map(fn (object $row): array => (array) $row)->all();

    externalVendorService()->record(40, 'Acme Labs', $connection);

    $after = (array) externalVendorRow($connection);
    expect($after['external_vendor_name'])->toBe('Acme Labs')
        ->and($after['calibration_provider_id'])->toBe($before['calibration_provider_id'])
        ->and($after['calibration_date'])->toBe($before['calibration_date'])
        ->and($after['calibration_due'])->toBe($before['calibration_due'])
        ->and($after['certificate_number'])->toBe('CERT-KEEP')
        ->and($after['session_status'])->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($connection->table('calibration_external_document')->orderBy('document_id')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($documents);

    unset($before['external_vendor_name'], $after['external_vendor_name']);
    expect($after)->toBe($before);
});

it('does not infer a vendor name from a pdf filename or contents', function () {
    $connection = externalVendorConnection();
    seedExternalVendorCalibration($connection);
    seedExternalVendorActor($connection);

    app(StoreExternalCalibrationDocument::class)->store(40, 'acme-vendor.pdf', "%PDF-1.4\nvendor-body\n", 5, $connection);

    expect(externalVendorRow($connection)->external_vendor_name)->toBeNull()
        ->and($connection->table('calibration_external_document')->value('original_filename'))->toBe('acme-vendor.pdf');
});

it('keeps the vendor snapshot when a pdf is replaced', function () {
    $connection = externalVendorConnection();
    seedExternalVendorCalibration($connection);
    seedExternalVendorActor($connection);
    externalVendorService()->record(40, 'Acme Labs', $connection);

    $store = app(StoreExternalCalibrationDocument::class);
    $store->store(40, 'first.pdf', "%PDF-1.4\nfirst\n", 5, $connection);
    $store->store(40, 'second.pdf', "%PDF-1.4\nsecond\n", 5, $connection);

    $documents = $connection->table('calibration_external_document')->orderBy('document_id')->get();

    expect(externalVendorRow($connection)->external_vendor_name)->toBe('Acme Labs')
        ->and($documents)->toHaveCount(2)
        ->and((int) $documents[0]->is_active)->toBe(0)
        ->and($documents[0]->original_filename)->toBe('first.pdf')
        ->and((int) $documents[1]->is_active)->toBe(1)
        ->and($documents[1]->original_filename)->toBe('second.pdf');
});

it('lets complete succeed with a null vendor and preserves a recorded vendor', function () {
    $connection = externalVendorConnection();
    seedExternalVendorCalibration($connection);
    seedExternalVendorDocument($connection);

    app(CompleteCalibrationSession::class)->complete(40, '2026-10-22', '2027-10-22', $connection);
    expect(externalVendorRow($connection)->external_vendor_name)->toBeNull()
        ->and(externalVendorRow($connection)->session_status)->toBe(Calibration::STATUS_COMPLETED)
        ->and(externalVendorRow($connection)->certificate_number)->toBeNull();

    $second = externalVendorConnection();
    seedExternalVendorCalibration($second, ['external_vendor_name' => 'Acme Labs']);
    seedExternalVendorDocument($second);
    app(CompleteCalibrationSession::class)->complete(40, '2026-10-22', '2027-10-22', $second);

    expect($second->table('calibration')->where('calibration_id', 40)->value('external_vendor_name'))->toBe('Acme Labs')
        ->and($second->table('calibration')->where('calibration_id', 40)->value('session_status'))->toBe(Calibration::STATUS_COMPLETED);
});

it('rolls a vendor write back with the caller transaction', function () {
    $connection = externalVendorConnection();
    seedExternalVendorCalibration($connection);
    externalVendorService()->record(40, 'Prior Vendor', $connection);

    $connection->beginTransaction();
    externalVendorService()->record(40, 'New Vendor', $connection);
    expect(externalVendorRow($connection)->external_vendor_name)->toBe('New Vendor');
    $connection->rollBack();

    expect(externalVendorRow($connection)->external_vendor_name)->toBe('Prior Vendor');
});

it('uses the supplied connection and rejects an unsafe connection before querying', function () {
    $connection = externalVendorConnection();
    seedExternalVendorCalibration($connection);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    externalVendorService()->record(40, 'Acme Labs', $connection);

    expect(externalVendorSql($connection))->toContain('update "calibration"')
        ->and(externalVendorSql($default))->not->toContain('calibration')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);

    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('table');
    $unsafe->shouldNotReceive('transaction');

    expect(fn () => externalVendorService()->record(40, 'Unsafe', $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});
