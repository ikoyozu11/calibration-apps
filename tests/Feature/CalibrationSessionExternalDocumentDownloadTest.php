<?php

use App\Authorization\ApplicationCapability;
use App\Authorization\ApplicationRoleName;
use App\CalibrationSessions\CalibrationSessionConnection;
use App\CalibrationSessions\ReadCalibrationSessionHttpConnection;
use App\Models\Calibration;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestRefusingPostgresConnector;

afterEach(function (): void {
    Model::preventLazyLoading(false);
});

function downloadDocumentArrange(array $overrides = []): void
{
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject(array_merge([
        'calibration_method' => Calibration::METHOD_EXTERNAL,
        'calibration_provider_id' => null,
    ], $overrides));
    Storage::fake('calibration_documents');
}

function downloadDocumentPut(
    string $contents = "%PDF-1.4\nactive\n",
    string $filename = 'vendor.pdf',
    bool $active = true,
    int $documentId = 1,
    int $calibrationId = 5,
    string $uuid = '11111111-1111-1111-1111-000000000001',
): object {
    $path = $uuid.'.pdf';
    Storage::disk('calibration_documents')->put($path, $contents);
    DB::table('calibration_external_document')->insert([
        'document_id' => $documentId,
        'calibration_id' => $calibrationId,
        'storage_uuid' => $uuid,
        'storage_path' => $path,
        'original_filename' => $filename,
        'mime_type' => 'application/pdf',
        'byte_size' => strlen($contents),
        'sha256' => hash('sha256', $contents),
        'is_active' => $active ? 1 : 0,
        'uploaded_at' => '2026-10-22 08:00:00',
        'superseded_at' => $active ? null : '2026-10-22 09:00:00',
        'uploaded_by_user_id' => 90,
    ]);

    return DB::table('calibration_external_document')->where('document_id', $documentId)->first();
}

function downloadDocumentUrl(int $calibrationId = 5, int $documentId = 1): string
{
    return route('calibrations.external-documents.download', [
        'calibration' => $calibrationId,
        'document' => $documentId,
    ]);
}

function downloadDocumentFingerprint(): array
{
    return calibrationSessionPinningUiFingerprint();
}

it('lets an administrator, engineer, and viewer download an active external pdf', function (ApplicationRoleName $role) {
    downloadDocumentArrange();
    $contents = "%PDF-1.4\nactive-bytes\n";
    downloadDocumentPut($contents);
    actingAsRole($role);

    expect(Gate::allows(ApplicationCapability::HistoryView->value))->toBeTrue()
        ->and(Gate::allows(ApplicationCapability::DocumentsDownload->value))->toBe($role !== ApplicationRoleName::Viewer)
        ->and(Gate::allows(ApplicationCapability::SessionRecord->value))->toBe($role !== ApplicationRoleName::Viewer)
        ->and(Gate::allows(ApplicationCapability::SessionComplete->value))->toBe($role !== ApplicationRoleName::Viewer);

    $response = $this->get(downloadDocumentUrl());

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('x-content-type-options', 'nosniff');

    $disposition = (string) $response->headers->get('content-disposition');

    expect($response->streamedContent())->toBe($contents)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
    expect(strtolower($disposition))->toContain('attachment')
        ->and($disposition)->toContain('vendor.pdf')
        ->and($disposition)->not->toContain('11111111-1111-1111-1111-000000000001');
    expect((string) $response->headers->get('content-type'))->toContain('application/pdf');
})->with([
    'administrator' => ApplicationRoleName::Administrator,
    'engineer' => ApplicationRoleName::Engineer,
    'viewer' => ApplicationRoleName::Viewer,
]);

it('lets permitted readers download a superseded historical pdf', function () {
    downloadDocumentArrange();
    $old = "%PDF-1.4\nhistorical\n";
    $new = "%PDF-1.4\ncurrent\n";
    downloadDocumentPut($old, 'first.pdf', false, 1, 5, '11111111-1111-1111-1111-000000000001');
    downloadDocumentPut($new, 'second.pdf', true, 2, 5, '11111111-1111-1111-1111-000000000002');
    actingAsRole(ApplicationRoleName::Viewer);

    $response = $this->get(downloadDocumentUrl(5, 1));

    $response->assertOk();
    expect($response->streamedContent())->toBe($old)
        ->and((string) $response->headers->get('content-disposition'))->toContain('first.pdf');
});

it('shows download on session detail for a viewer without upload controls', function () {
    downloadDocumentArrange(['session_status' => Calibration::STATUS_COMPLETED]);
    downloadDocumentPut();
    actingAsRole(ApplicationRoleName::Viewer);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Download')
        ->assertSee(downloadDocumentUrl(), false)
        ->assertDontSee('>Upload PDF</button>', false)
        ->assertDontSee('Save Vendor')
        ->assertDontSee('11111111-1111-1111-1111-000000000001.pdf');
});

it('redirects anonymous users and does not leak document existence', function () {
    downloadDocumentArrange();
    downloadDocumentPut();
    Auth::logout();
    $before = downloadDocumentFingerprint();

    $this->get(downloadDocumentUrl())->assertRedirect(route('login'));

    expect(downloadDocumentFingerprint())->toBe($before)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('denies a roleless user without revealing the file', function () {
    downloadDocumentArrange();
    downloadDocumentPut();
    actingAsRolelessUser();
    $before = downloadDocumentFingerprint();

    $this->get(downloadDocumentUrl())->assertForbidden();

    expect(downloadDocumentFingerprint())->toBe($before);
});

it('returns not found for the wrong session, missing records, and internal calibrations', function (callable $arrange, int $calibrationId, int $documentId) {
    $arrange();
    actingAsRole(ApplicationRoleName::Administrator);

    $this->get(downloadDocumentUrl($calibrationId, $documentId))
        ->assertNotFound()
        ->assertDontSee('storage_path')
        ->assertDontSee('calibration-documents');
})->with([
    'wrong calibration' => [function (): void {
        downloadDocumentArrange();
        downloadDocumentPut();
        DB::table('calibration')->insert([
            'calibration_id' => 6,
            'instrument_id' => 1,
            'instrument_type_id' => 11,
            'calibration_number' => 'CAL.SESS.6',
            'calibration_method' => Calibration::METHOD_EXTERNAL,
            'session_status' => Calibration::STATUS_IN_PROGRESS,
            'created_at' => '2026-10-20 09:00:00',
            'updated_at' => '2026-10-20 09:00:00',
        ]);
    }, 6, 1],
    'other session document' => [function (): void {
        downloadDocumentArrange();
        downloadDocumentPut("%PDF-1.4\none\n", 'one.pdf', true, 1, 5, '11111111-1111-1111-1111-000000000001');
        DB::table('calibration')->insert([
            'calibration_id' => 6,
            'instrument_id' => 1,
            'instrument_type_id' => 11,
            'calibration_number' => 'CAL.SESS.6',
            'calibration_method' => Calibration::METHOD_EXTERNAL,
            'session_status' => Calibration::STATUS_IN_PROGRESS,
            'created_at' => '2026-10-20 09:00:00',
            'updated_at' => '2026-10-20 09:00:00',
        ]);
        downloadDocumentPut("%PDF-1.4\ntwo\n", 'two.pdf', true, 2, 6, '11111111-1111-1111-1111-000000000002');
    }, 5, 2],
    'missing calibration' => [function (): void {
        downloadDocumentArrange();
        downloadDocumentPut();
        DB::table('calibration')->delete();
    }, 5, 1],
    'missing document' => [function (): void {
        downloadDocumentArrange();
    }, 5, 1],
    'internal' => [function (): void {
        downloadDocumentArrange(['calibration_method' => Calibration::METHOD_INTERNAL]);
        downloadDocumentPut();
    }, 5, 1],
]);

it('returns not found for a missing or invalid private file without exposing paths', function (callable $mutate) {
    downloadDocumentArrange();
    downloadDocumentPut();
    $mutate();
    actingAsRole(ApplicationRoleName::Administrator);

    $this->get(downloadDocumentUrl())
        ->assertNotFound()
        ->assertDontSee('11111111-1111-1111-1111-000000000001')
        ->assertDontSee('calibration-documents');
})->with([
    'missing file' => [function (): void {
        Storage::disk('calibration_documents')->delete('11111111-1111-1111-1111-000000000001.pdf');
    }],
    'invalid stored path' => [function (): void {
        DB::table('calibration_external_document')->where('document_id', 1)->update([
            'storage_path' => '../secret.pdf',
        ]);
    }],
]);

it('sanitizes unicode and control characters in the download filename', function (string $original, string $expectedNeedle, string $forbidden) {
    downloadDocumentArrange();
    downloadDocumentPut("%PDF-1.4\nname\n", $original);
    actingAsRole(ApplicationRoleName::Engineer);

    $response = $this->get(downloadDocumentUrl());
    $disposition = (string) $response->headers->get('content-disposition');

    $response->assertOk();
    expect($disposition)->toContain($expectedNeedle)
        ->and($disposition)->not->toContain("\r")
        ->and($disposition)->not->toContain("\n")
        ->and($disposition)->not->toContain($forbidden)
        ->and($response->streamedContent())->toBe("%PDF-1.4\nname\n");
})->with([
    'unicode' => ['Kalibrasi Café.pdf', 'Kalibrasi', 'storage_path'],
    'crlf' => ["evil\r\nSet-Cookie: x.pdf", 'evil', '11111111-1111-1111-1111-000000000001'],
]);

it('does not mutate calibration or document rows while downloading', function () {
    downloadDocumentArrange([
        'external_vendor_name' => 'Acme Labs',
        'session_status' => Calibration::STATUS_COMPLETED,
        'calibration_date' => '2026-10-22',
        'calibration_due' => '2027-10-22',
        'completed_at' => '2026-10-22 10:00:00',
        'certificate_number' => null,
    ]);
    downloadDocumentPut();
    actingAsRole(ApplicationRoleName::Administrator);
    $before = downloadDocumentFingerprint();
    $default = DB::connection();
    $default->flushQueryLog();
    $default->enableQueryLog();

    $this->get(downloadDocumentUrl())->assertOk();

    $sql = strtolower(implode("\n", array_column($default->getQueryLog(), 'query')));

    expect(downloadDocumentFingerprint())->toBe($before)
        ->and($sql)->not->toContain('update "calibration"')
        ->and($sql)->not->toContain('insert into "calibration"')
        ->and($sql)->not->toContain('update "calibration_external_document"')
        ->and($sql)->not->toContain('insert into "calibration_external_document"')
        ->and(DB::table('calibration')->where('calibration_id', 5)->value('external_vendor_name'))->toBe('Acme Labs')
        ->and(DB::table('calibration')->where('calibration_id', 5)->value('certificate_number'))->toBeNull()
        ->and((int) DB::table('calibration_external_document')->where('document_id', 1)->value('is_active'))->toBe(1);
});

it('allows download after cancel without restoring write controls', function () {
    downloadDocumentArrange(['session_status' => Calibration::STATUS_CANCELLED]);
    downloadDocumentPut();
    actingAsRole(ApplicationRoleName::Viewer);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Download')
        ->assertDontSee('>Upload PDF</button>', false);

    $this->get(downloadDocumentUrl())->assertOk();
});

it('fails closed when http reads cannot use postgresql', function () {
    downloadDocumentArrange();
    downloadDocumentPut();
    actingAsRole(ApplicationRoleName::Administrator);
    app()->instance(ReadCalibrationSessionHttpConnection::class, new ReadCalibrationSessionHttpConnection(
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

    $this->get(downloadDocumentUrl())
        ->assertStatus(503)
        ->assertDontSee('calibration_db')
        ->assertDontSee('pgsql');

    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0)
        ->and(downloadDocumentFingerprint()['documents'])->toHaveCount(1);
});
