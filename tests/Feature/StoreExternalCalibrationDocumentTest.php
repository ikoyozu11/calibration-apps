<?php

use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\ResolveDomainActorException;
use App\CalibrationSessions\StoreExternalCalibrationDocument;
use App\CalibrationSessions\StoreExternalCalibrationDocumentException;
use App\Models\Calibration;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestRefusingPostgresConnector;

const EXTERNAL_DOCUMENT_CONNECTION = 'external_document_sqlite';

function externalDocumentConnection(): Connection
{
    config([
        'database.connections.'.EXTERNAL_DOCUMENT_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(EXTERNAL_DOCUMENT_CONNECTION);

    $connection = DB::connection(EXTERNAL_DOCUMENT_CONNECTION);

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
    $connection->unprepared("
        CREATE TABLE user_app_user_map (
            user_app_user_map_id INTEGER PRIMARY KEY,
            laravel_user_id INTEGER NOT NULL,
            app_user_id INTEGER NOT NULL,
            status VARCHAR(20) NOT NULL,
            created_at TEXT NOT NULL,
            inactivated_at TEXT NULL,
            CONSTRAINT uq_user_app_user_map_laravel_user UNIQUE (laravel_user_id),
            CONSTRAINT uq_user_app_user_map_app_user UNIQUE (app_user_id),
            CONSTRAINT fk_user_app_user_map_user
                FOREIGN KEY (laravel_user_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_user_app_user_map_app_user
                FOREIGN KEY (app_user_id) REFERENCES app_user (app_user_id) ON DELETE RESTRICT,
            CONSTRAINT chk_user_app_user_map_status
                CHECK (status IN ('active', 'inactive')),
            CONSTRAINT chk_user_app_user_map_inactivated_at
                CHECK (
                    (status = 'active' AND inactivated_at IS NULL)
                    OR (status = 'inactive' AND inactivated_at IS NOT NULL)
                )
        )
    ");
    $connection->unprepared('
        CREATE TABLE calibration (
            calibration_id INTEGER PRIMARY KEY,
            session_status VARCHAR(20) NOT NULL,
            calibration_method VARCHAR(20) NOT NULL,
            calibration_provider_id INTEGER NULL
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
        CREATE UNIQUE INDEX uq_calibration_external_document_active
        ON calibration_external_document (calibration_id)
        WHERE is_active = 1
    ');

    Storage::fake('calibration_documents');
    Storage::fake('public');
    Storage::fake('local');

    return $connection;
}

function externalDocumentStore(): StoreExternalCalibrationDocument
{
    return app(StoreExternalCalibrationDocument::class);
}

/**
 * @param  array{laravel_user_id?: int, app_user_id?: int, status?: string, inactivated_at?: string|null, is_active?: int, map_id?: int}  $actor
 */
function seedExternalDocumentActor(Connection $connection, array $actor = []): void
{
    $connection->table('users')->insert(['id' => $actor['laravel_user_id'] ?? 5]);
    $connection->table('app_user')->insert([
        'app_user_id' => $actor['app_user_id'] ?? 90,
        'is_active' => $actor['is_active'] ?? 1,
    ]);
    $connection->table('user_app_user_map')->insert([
        'user_app_user_map_id' => $actor['map_id'] ?? 1,
        'laravel_user_id' => $actor['laravel_user_id'] ?? 5,
        'app_user_id' => $actor['app_user_id'] ?? 90,
        'status' => $actor['status'] ?? 'active',
        'created_at' => '2026-10-22 07:00:00',
        'inactivated_at' => $actor['inactivated_at'] ?? null,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedExternalSession(Connection $connection, array $overrides = []): void
{
    seedExternalDocumentActor($connection);
    $connection->table('calibration')->insert(array_merge([
        'calibration_id' => 40,
        'session_status' => Calibration::STATUS_IN_PROGRESS,
        'calibration_method' => Calibration::METHOD_EXTERNAL,
        'calibration_provider_id' => null,
    ], $overrides));
}

function externalPdf(string $body = 'vendor'): string
{
    return "%PDF-1.4\n".$body."\n";
}

function storeVendorPdf(Connection $connection, string $filename = 'vendor.pdf', ?string $contents = null, int $laravelUserId = 5): void
{
    externalDocumentStore()->store(40, $filename, $contents ?? externalPdf(), $laravelUserId, $connection);
}

function externalDocumentSql(Connection $connection): string
{
    return strtolower(implode("\n", array_column($connection->getQueryLog(), 'query')));
}

function externalDocuments(Connection $connection): Collection
{
    return $connection->table('calibration_external_document')->orderBy('document_id')->get();
}

it('stores the first external pdf on the private disk and records the domain actor', function () {
    $connection = externalDocumentConnection();
    seedExternalSession($connection);
    $contents = externalPdf('first');
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    storeVendorPdf($connection, '  Vendor Certificate.pdf  ', $contents);

    $row = externalDocuments($connection)->sole();
    $sql = externalDocumentSql($connection);
    $calibrationPosition = strpos($sql, 'from "calibration"');
    $documentPosition = strpos($sql, 'from "calibration_external_document"');
    $mapPosition = strpos($sql, 'from "user_app_user_map"');
    $appUserPosition = strpos($sql, 'from "app_user"');
    $insertPosition = strpos($sql, 'insert into "calibration_external_document"');

    expect($row->calibration_id)->toBe(40)
        ->and($row->original_filename)->toBe('Vendor Certificate.pdf')
        ->and($row->mime_type)->toBe('application/pdf')
        ->and($row->byte_size)->toBe(strlen($contents))
        ->and($row->sha256)->toBe(hash('sha256', $contents))
        ->and($row->is_active)->toBe(1)
        ->and($row->superseded_at)->toBeNull()
        ->and($row->uploaded_at)->not->toBeNull()
        ->and($row->uploaded_by_user_id)->toBe(90)
        ->and($row->storage_path)->toBe($row->storage_uuid.'.pdf')
        ->and($row->storage_path)->toMatch('/^[0-9a-f-]{36}\.pdf$/')
        ->and($row->storage_path)->not->toContain('Vendor')
        ->and(Storage::disk('calibration_documents')->get($row->storage_path))->toBe($contents)
        ->and(Storage::disk('calibration_documents')->allFiles())->toHaveCount(1)
        ->and(Storage::disk('public')->allFiles())->toBeEmpty()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty()
        ->and($connection->table('calibration')->value('session_status'))->toBe('in_progress')
        ->and($connection->table('calibration')->value('calibration_method'))->toBe('EXTERNAL')
        ->and($connection->table('calibration')->value('calibration_provider_id'))->toBeNull()
        ->and($calibrationPosition)->toBeInt()
        ->and($documentPosition)->toBeInt()
        ->and($mapPosition)->toBeInt()
        ->and($appUserPosition)->toBeInt()
        ->and($insertPosition)->toBeInt()
        ->and($calibrationPosition)->toBeLessThan($documentPosition)
        ->and($documentPosition)->toBeLessThan($mapPosition)
        ->and($mapPosition)->toBeLessThan($appUserPosition)
        ->and($appUserPosition)->toBeLessThan($insertPosition)
        ->and($sql)->not->toContain('update "calibration"')
        ->and($sql)->not->toContain('certificate')
        ->and($sql)->not->toContain('instrument_type')
        ->and($sql)->not->toContain('calibration_session_setting')
        ->and(externalDocumentSql($default))->not->toContain('calibration_external_document')
        ->and(Schema::connection('sqlite')->hasTable('calibration_external_document'))->toBeFalse()
        ->and(config('database.default'))->toBe('sqlite')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('replaces the active pdf and keeps the superseded row and file', function () {
    $connection = externalDocumentConnection();
    seedExternalSession($connection);
    $first = externalPdf('first');
    $second = externalPdf('second');
    storeVendorPdf($connection, 'first.pdf', $first);
    $original = externalDocuments($connection)->sole();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    storeVendorPdf($connection, 'second.pdf', $second);

    $rows = externalDocuments($connection);
    $sql = externalDocumentSql($connection);
    $updatePosition = strpos($sql, 'update "calibration_external_document"');
    $insertPosition = strpos($sql, 'insert into "calibration_external_document"');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->document_id)->toBe($original->document_id)
        ->and($rows[0]->is_active)->toBe(0)
        ->and($rows[0]->superseded_at)->not->toBeNull()
        ->and($rows[0]->storage_path)->toBe($original->storage_path)
        ->and($rows[0]->sha256)->toBe($original->sha256)
        ->and($rows[1]->is_active)->toBe(1)
        ->and($rows[1]->superseded_at)->toBeNull()
        ->and($rows[1]->original_filename)->toBe('second.pdf')
        ->and($rows[1]->uploaded_by_user_id)->toBe(90)
        ->and(Storage::disk('calibration_documents')->get($original->storage_path))->toBe($first)
        ->and(Storage::disk('calibration_documents')->get($rows[1]->storage_path))->toBe($second)
        ->and(Storage::disk('calibration_documents')->allFiles())->toHaveCount(2)
        ->and($updatePosition)->toBeInt()
        ->and($insertPosition)->toBeInt()
        ->and($updatePosition)->toBeLessThan($insertPosition)
        ->and($connection->table('calibration')->value('session_status'))->toBe('in_progress');
});

it('accepts a pdf at the configured size and rejects one byte more', function () {
    $connection = externalDocumentConnection();
    seedExternalSession($connection);
    $contents = externalPdf('sized');
    config(['calibration.documents.max_bytes' => strlen($contents)]);

    storeVendorPdf($connection, 'sized.pdf', $contents);

    expect(externalDocuments($connection)->sole()->byte_size)->toBe(strlen($contents));

    config(['calibration.documents.max_bytes' => strlen($contents) - 1]);

    expect(fn () => storeVendorPdf($connection, 'larger.pdf', $contents))
        ->toThrow(StoreExternalCalibrationDocumentException::class, 'The PDF exceeds the configured size limit.');

    expect(externalDocuments($connection))->toHaveCount(1)
        ->and(Storage::disk('calibration_documents')->allFiles())->toHaveCount(1);
});

it('rejects a filename that is not a single pdf name before reading the session', function (string $filename) {
    $connection = externalDocumentConnection();
    seedExternalSession($connection);
    $connection->enableQueryLog();

    expect(fn () => storeVendorPdf($connection, $filename))
        ->toThrow(StoreExternalCalibrationDocumentException::class, 'The original filename must be a single PDF file name.');

    expect(externalDocumentSql($connection))->not->toContain('from "calibration"')
        ->and(externalDocuments($connection))->toBeEmpty()
        ->and(Storage::disk('calibration_documents')->allFiles())->toBeEmpty();
})->with([
    'blank' => '',
    'whitespace' => '   ',
    'traversal' => '../vendor.pdf',
    'nested slash' => 'vendor/secret.pdf',
    'nested backslash' => 'vendor\\secret.pdf',
    'parent segment' => 'vendor/../secret.pdf',
    'dot dot name' => 'vendor..pdf',
    'wrong extension' => 'vendor.txt',
    'double extension' => 'vendor.pdf.exe',
    'uppercase extension' => 'vendor.PDF',
    'empty stem' => '.pdf',
    'null byte' => "vendor\0.pdf",
]);

it('rejects empty or non-pdf contents before reading the session', function (string $contents, string $message) {
    $connection = externalDocumentConnection();
    seedExternalSession($connection);
    $connection->enableQueryLog();

    expect(fn () => storeVendorPdf($connection, 'vendor.pdf', $contents))
        ->toThrow(StoreExternalCalibrationDocumentException::class, $message);

    expect(externalDocumentSql($connection))->not->toContain('from "calibration"')
        ->and(Storage::disk('calibration_documents')->allFiles())->toBeEmpty();
})->with([
    'empty' => ['', 'The PDF contents are required.'],
    'missing signature' => ['not a pdf', 'The PDF contents must begin with %PDF-.'],
    'truncated signature' => ['%PDF', 'The PDF contents must begin with %PDF-.'],
]);

it('rejects a calibration id below 1 before reading the session', function (int $calibrationId) {
    $connection = externalDocumentConnection();
    seedExternalSession($connection);
    $connection->enableQueryLog();

    expect(fn () => externalDocumentStore()->store($calibrationId, 'vendor.pdf', externalPdf(), 5, $connection))
        ->toThrow(StoreExternalCalibrationDocumentException::class, 'The calibration id must be at least 1.');

    expect(externalDocumentSql($connection))->not->toContain('from "calibration"')
        ->and(Storage::disk('calibration_documents')->allFiles())->toBeEmpty()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'zero' => 0,
    'negative' => -1,
]);

it('rejects a missing calibration', function () {
    $connection = externalDocumentConnection();
    seedExternalDocumentActor($connection);

    expect(fn () => storeVendorPdf($connection))
        ->toThrow(StoreExternalCalibrationDocumentException::class, 'The calibration does not exist.');

    expect(externalDocuments($connection))->toBeEmpty()
        ->and(Storage::disk('calibration_documents')->allFiles())->toBeEmpty();
});

it('rejects an internal session', function () {
    $connection = externalDocumentConnection();
    seedExternalSession($connection, [
        'calibration_method' => Calibration::METHOD_INTERNAL,
    ]);

    expect(fn () => storeVendorPdf($connection))
        ->toThrow(StoreExternalCalibrationDocumentException::class, 'Vendor PDFs can be stored only for an external calibration session.');

    expect(externalDocuments($connection))->toBeEmpty()
        ->and(Storage::disk('calibration_documents')->allFiles())->toBeEmpty()
        ->and($connection->table('calibration')->value('calibration_method'))->toBe('INTERNAL');
});

it('rejects a session that is not in progress', function (string $sessionStatus) {
    $connection = externalDocumentConnection();
    seedExternalSession($connection, [
        'session_status' => $sessionStatus,
    ]);

    expect(fn () => storeVendorPdf($connection))
        ->toThrow(StoreExternalCalibrationDocumentException::class, 'Only an in-progress calibration session can store a vendor PDF.');

    expect(externalDocuments($connection))->toBeEmpty()
        ->and(Storage::disk('calibration_documents')->allFiles())->toBeEmpty()
        ->and($connection->table('calibration')->value('session_status'))->toBe($sessionStatus);
})->with([
    'completed' => Calibration::STATUS_COMPLETED,
    'cancelled' => Calibration::STATUS_CANCELLED,
]);

it('rejects an active document that already has a superseded timestamp', function () {
    $connection = externalDocumentConnection();
    seedExternalSession($connection);
    $connection->table('calibration_external_document')->insert([
        'calibration_id' => 40,
        'storage_uuid' => '11111111-1111-1111-1111-111111111111',
        'storage_path' => '11111111-1111-1111-1111-111111111111.pdf',
        'original_filename' => 'broken.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => 8,
        'sha256' => str_repeat('a', 64),
        'is_active' => 1,
        'uploaded_at' => '2026-10-22 08:00:00',
        'superseded_at' => '2026-10-22 08:30:00',
        'uploaded_by_user_id' => 90,
    ]);
    Storage::disk('calibration_documents')->put(
        '11111111-1111-1111-1111-111111111111.pdf',
        externalPdf('kept'),
    );

    expect(fn () => storeVendorPdf($connection, 'replacement.pdf'))
        ->toThrow(StoreExternalCalibrationDocumentException::class, 'The active vendor PDF record is inconsistent.');

    expect(externalDocuments($connection))->toHaveCount(1)
        ->and(externalDocuments($connection)->first()->superseded_at)->toBe('2026-10-22 08:30:00')
        ->and(externalDocuments($connection)->first()->is_active)->toBe(1)
        ->and(Storage::disk('calibration_documents')->allFiles())->toBe([
            '11111111-1111-1111-1111-111111111111.pdf',
        ]);
});

it('rolls back when the actor mapping is inactive and stores nothing', function () {
    $connection = externalDocumentConnection();
    seedExternalSession($connection);
    $connection->table('user_app_user_map')->update([
        'status' => 'inactive',
        'inactivated_at' => '2026-10-22 08:00:00',
    ]);

    expect(fn () => storeVendorPdf($connection))
        ->toThrow(ResolveDomainActorException::class, 'The domain actor mapping is inactive.');

    expect(externalDocuments($connection))->toBeEmpty()
        ->and(Storage::disk('calibration_documents')->allFiles())->toBeEmpty()
        ->and($connection->table('calibration')->value('session_status'))->toBe('in_progress');
});

it('rolls back when the mapped app user is inactive and stores nothing', function () {
    $connection = externalDocumentConnection();
    seedExternalSession($connection);
    $connection->table('app_user')->update(['is_active' => 0]);

    expect(fn () => storeVendorPdf($connection))
        ->toThrow(ResolveDomainActorException::class, 'The mapped app user is inactive.');

    expect(externalDocuments($connection))->toBeEmpty()
        ->and(Storage::disk('calibration_documents')->allFiles())->toBeEmpty();
});

it('deletes only the new file when the document insert fails', function () {
    $connection = externalDocumentConnection();
    seedExternalSession($connection);
    $previous = externalPdf('previous');
    storeVendorPdf($connection, 'previous.pdf', $previous);
    $original = externalDocuments($connection)->sole();
    $connection->unprepared("
        CREATE TRIGGER abort_external_document_insert
        BEFORE INSERT ON calibration_external_document
        BEGIN
            SELECT RAISE(ABORT, 'simulated insert failure');
        END
    ");

    expect(fn () => storeVendorPdf($connection, 'next.pdf', externalPdf('next')))
        ->toThrow(QueryException::class);

    $row = externalDocuments($connection)->sole();

    expect($row->document_id)->toBe($original->document_id)
        ->and($row->is_active)->toBe(1)
        ->and($row->superseded_at)->toBeNull()
        ->and($row->storage_path)->toBe($original->storage_path)
        ->and(Storage::disk('calibration_documents')->allFiles())->toBe([$original->storage_path])
        ->and(Storage::disk('calibration_documents')->get($original->storage_path))->toBe($previous);
});

it('creates no document row when the storage write fails', function () {
    $connection = externalDocumentConnection();
    seedExternalSession($connection);
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->andReturn(false);
    $filesystem->shouldReceive('put')->once()->andReturn(false);
    $filesystem->shouldReceive('delete')->never();
    Storage::shouldReceive('disk')->with('calibration_documents')->andReturn($filesystem);

    expect(fn () => storeVendorPdf($connection))
        ->toThrow(StoreExternalCalibrationDocumentException::class, 'The vendor PDF could not be stored.');

    expect(externalDocuments($connection))->toBeEmpty()
        ->and($connection->table('calibration')->value('session_status'))->toBe('in_progress');
});

it('rejects a disk other than the private calibration documents disk', function () {
    $connection = externalDocumentConnection();
    seedExternalSession($connection);
    config(['calibration.documents.disk' => 'public']);

    expect(fn () => storeVendorPdf($connection))
        ->toThrow(StoreExternalCalibrationDocumentException::class, 'Vendor PDFs require the private calibration documents disk.');

    expect(externalDocuments($connection))->toBeEmpty()
        ->and(Storage::disk('public')->allFiles())->toBeEmpty()
        ->and(Storage::disk('calibration_documents')->allFiles())->toBeEmpty()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('propagates an unsafe connection exception before querying or storing', function () {
    $connection = externalDocumentConnection();
    seedExternalSession($connection);
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('table');
    $unsafe->shouldNotReceive('transaction');

    expect(fn () => externalDocumentStore()->store(40, 'vendor.pdf', externalPdf(), 5, $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class, 'Calibration session workflow requires an explicit safe database connection.');

    expect(externalDocuments($connection))->toBeEmpty()
        ->and(Storage::disk('calibration_documents')->allFiles())->toBeEmpty()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('stores through the supplied connection when its name is empty or unregistered', function (string $connectionName) {
    $connection = externalDocumentConnection();
    seedExternalSession($connection);
    $config = new ReflectionProperty($connection, 'config');
    $settings = $config->getValue($connection);
    $settings['name'] = $connectionName;
    $config->setValue($connection, $settings);
    $default = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $default->flushQueryLog();
    $default->enableQueryLog();

    storeVendorPdf($connection);

    expect($connection->getName())->toBe($connectionName)
        ->and(externalDocuments($connection)->sole()->uploaded_by_user_id)->toBe(90)
        ->and(externalDocumentSql($connection))->toContain('insert into "calibration_external_document"')
        ->and(externalDocumentSql($default))->not->toContain('calibration_external_document')
        ->and(externalDocumentSql($default))->not->toContain('user_app_user_map')
        ->and(Schema::connection('sqlite')->hasTable('user_app_user_map'))->toBeFalse()
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty name' => '',
    'unregistered name' => 'unowned-external-document',
]);
