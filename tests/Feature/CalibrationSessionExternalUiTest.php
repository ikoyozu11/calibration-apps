<?php

use App\Authorization\ApplicationCapability;
use App\Authorization\ApplicationRoleName;
use App\CalibrationSessions\CalibrationSessionConnection;
use App\CalibrationSessions\CompleteCalibrationSessionException;
use App\CalibrationSessions\RecordCalibrationSessionHttpConnection;
use App\CalibrationSessions\RecordCalibrationSessionHttpException;
use App\CalibrationSessions\RecordExternalCalibrationVendorException;
use App\CalibrationSessions\StoreExternalCalibrationDocument;
use App\CalibrationSessions\StoreExternalCalibrationDocumentException;
use App\Models\Calibration;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestRefusingPostgresConnector;

afterEach(function (): void {
    Model::preventLazyLoading(false);
});

function externalUiArrange(array $overrides = []): void
{
    createCalibrationSessionPinningUiTables();
    insertCalibrationSessionPinningUiSubject(array_merge([
        'calibration_method' => Calibration::METHOD_EXTERNAL,
        'calibration_provider_id' => null,
    ], $overrides));
    Storage::fake('calibration_documents');
}

function externalUiFreezeLocation(): void
{
    DB::table('calibration')->where('calibration_id', 5)->update([
        'snapshot_location_id' => 4,
        'snapshot_detail_location_id' => 8,
        'snapshot_location_code' => 'LAB',
        'snapshot_location_name' => 'Laboratory',
        'snapshot_detail_location_code' => 'B1',
        'snapshot_detail_location_name' => 'Bench 1',
        'calibration_provider_id' => null,
    ]);
}

function externalUiPdf(string $name = 'vendor.pdf', string $body = "%PDF-1.4\nvendor\n"): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $body);
}

function externalUiMapActor(): void
{
    mapStartCalibrationHttpActor();
}

function externalUiRow(): object
{
    return DB::table('calibration')->where('calibration_id', 5)->first();
}

function externalUiDocuments(): Collection
{
    return DB::table('calibration_external_document')->orderBy('document_id')->get();
}

function externalUiUnsafeWrites(): RecordCalibrationSessionHttpConnection
{
    return new RecordCalibrationSessionHttpConnection(
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
}

it('lets an administrator and an engineer save a vendor snapshot', function (ApplicationRoleName $role) {
    externalUiArrange();
    actingAsRole($role);

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('External Calibration Vendor (Optional)')
        ->assertSee('Save Vendor');

    calibrationSessionPinningUiPage()
        ->set('externalVendorName', 'Acme Labs')
        ->call('saveExternalVendor')
        ->assertHasNoErrors()
        ->assertSee('The vendor name was saved.');

    expect(externalUiRow()->external_vendor_name)->toBe('Acme Labs')
        ->and(externalUiRow()->calibration_provider_id)->toBeNull()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'administrator' => ApplicationRoleName::Administrator,
    'engineer' => ApplicationRoleName::Engineer,
]);

it('normalizes null, blank, whitespace, unicode, and overlength vendor values', function (?string $input, ?string $expected, ?string $error) {
    externalUiArrange(['external_vendor_name' => 'Prior']);
    actingAsRole(ApplicationRoleName::Administrator);

    $page = calibrationSessionPinningUiPage()
        ->set('externalVendorName', $input ?? '')
        ->call('saveExternalVendor');

    if ($error !== null) {
        $page->assertHasErrors(['externalVendorName' => $error]);
        expect(externalUiRow()->external_vendor_name)->toBe('Prior')
            ->and($page->get('externalVendorName'))->toBe($input);
    } else {
        $page->assertHasNoErrors()->assertSee('The vendor name was saved.');
        expect(externalUiRow()->external_vendor_name)->toBe($expected);
    }
})->with([
    'empty' => ['', null, null],
    'whitespace' => ['   ', null, null],
    'unicode' => ['PT Kalibrasi Café', 'PT Kalibrasi Café', null],
    'overlength' => [str_repeat('a', 256), null, RecordExternalCalibrationVendorException::vendorNameTooLong()->getMessage()],
]);

it('keeps vendor read-only for a viewer and anonymous user', function () {
    externalUiArrange(['external_vendor_name' => 'Acme Labs']);
    actingAsRole(ApplicationRoleName::Viewer);
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Acme Labs')
        ->assertDontSee('Save Vendor')
        ->assertDontSee('>Upload PDF</button>', false);

    calibrationSessionPinningUiPage()
        ->set('externalVendorName', 'Changed')
        ->call('saveExternalVendor')
        ->assertForbidden();

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);

    Auth::logout();
    $this->get(route('calibrations.show', 5))->assertRedirect(route('login'));
    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('rejects vendor writes for internal, completed, and cancelled sessions', function (?array $overrides, string $message) {
    externalUiArrange($overrides ?? []);
    $before = calibrationSessionPinningUiFingerprint();

    calibrationSessionPinningUiPage()
        ->set('externalVendorName', 'Acme Labs')
        ->call('saveExternalVendor')
        ->assertHasErrors(['saveExternalVendor' => $message]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
})->with([
    'internal' => [[
        'calibration_method' => Calibration::METHOD_INTERNAL,
        'calibration_provider_id' => 77,
    ], RecordExternalCalibrationVendorException::unsupportedCalibrationMethod()->getMessage()],
    'completed' => [[
        'session_status' => Calibration::STATUS_COMPLETED,
        'external_vendor_name' => 'Frozen Vendor',
    ], RecordExternalCalibrationVendorException::invalidSessionStatus()->getMessage()],
    'cancelled' => [[
        'session_status' => Calibration::STATUS_CANCELLED,
        'external_vendor_name' => 'Frozen Vendor',
    ], RecordExternalCalibrationVendorException::invalidSessionStatus()->getMessage()],
]);

it('does not change provider_id when saving a vendor', function () {
    externalUiArrange(['calibration_provider_id' => 9]);

    calibrationSessionPinningUiPage()
        ->set('externalVendorName', 'Acme Labs')
        ->call('saveExternalVendor')
        ->assertHasNoErrors();

    expect((int) externalUiRow()->calibration_provider_id)->toBe(9)
        ->and(externalUiRow()->external_vendor_name)->toBe('Acme Labs');
});

it('lets an administrator and an engineer upload a private vendor pdf', function (ApplicationRoleName $role) {
    externalUiArrange();
    actingAsRole($role);
    externalUiMapActor();
    $contents = "%PDF-1.4\nadmin-or-engineer\n";

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('External Certificate PDF')
        ->assertSee('Upload PDF');

    calibrationSessionPinningUiPage()
        ->set('externalCertificatePdf', externalUiPdf('certificate.pdf', $contents))
        ->call('uploadExternalDocument')
        ->assertHasNoErrors()
        ->assertSee('The vendor PDF was stored.')
        ->assertSee('certificate.pdf')
        ->assertSee('Active');

    $row = externalUiDocuments()->sole();

    expect($row->original_filename)->toBe('certificate.pdf')
        ->and($row->mime_type)->toBe('application/pdf')
        ->and((int) $row->is_active)->toBe(1)
        ->and($row->storage_path)->toBe($row->storage_uuid.'.pdf')
        ->and(Storage::disk('calibration_documents')->get($row->storage_path))->toBe($contents)
        ->and(Storage::disk('calibration_documents')->allFiles())->toHaveCount(1)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'administrator' => ApplicationRoleName::Administrator,
    'engineer' => ApplicationRoleName::Engineer,
]);

it('denies pdf upload for a viewer and anonymous user', function () {
    externalUiArrange();
    actingAsRole(ApplicationRoleName::Viewer);
    externalUiMapActor();
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertDontSee('>Upload PDF</button>', false)
        ->assertDontSee('>Replace PDF</button>', false);

    calibrationSessionPinningUiPage()
        ->set('externalCertificatePdf', externalUiPdf())
        ->call('uploadExternalDocument')
        ->assertForbidden();

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(Storage::disk('calibration_documents')->allFiles())->toBeEmpty();

    Auth::logout();
    $this->get(route('calibrations.show', 5))->assertRedirect(route('login'));
});

it('rejects pdf uploads for internal, completed, and cancelled sessions', function (?array $overrides, string $message) {
    externalUiArrange($overrides ?? []);
    externalUiMapActor();
    $before = calibrationSessionPinningUiFingerprint();

    calibrationSessionPinningUiPage()
        ->set('externalCertificatePdf', externalUiPdf())
        ->call('uploadExternalDocument')
        ->assertHasErrors(['uploadExternalDocument' => $message]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(Storage::disk('calibration_documents')->allFiles())->toBeEmpty();
})->with([
    'internal' => [[
        'calibration_method' => Calibration::METHOD_INTERNAL,
    ], StoreExternalCalibrationDocumentException::invalidMethod()->getMessage()],
    'completed' => [[
        'session_status' => Calibration::STATUS_COMPLETED,
    ], StoreExternalCalibrationDocumentException::invalidSessionStatus()->getMessage()],
    'cancelled' => [[
        'session_status' => Calibration::STATUS_CANCELLED,
    ], StoreExternalCalibrationDocumentException::invalidSessionStatus()->getMessage()],
]);

it('rejects missing, invalid, and oversized vendor pdfs', function (callable $file, string $errorKey, string $message) {
    externalUiArrange();
    externalUiMapActor();
    config(['calibration.documents.max_bytes' => 32]);

    $page = calibrationSessionPinningUiPage();

    if ($file() !== null) {
        $page->set('externalCertificatePdf', $file());
    }

    $page->call('uploadExternalDocument')
        ->assertHasErrors([$errorKey => $message]);

    expect(externalUiDocuments())->toHaveCount(0)
        ->and(Storage::disk('calibration_documents')->allFiles())->toBeEmpty();
})->with([
    'missing' => [fn () => null, 'externalCertificatePdf', 'A vendor PDF is required.'],
    'invalid mime' => [fn () => externalUiPdf('notes.txt', 'plain text'), 'uploadExternalDocument', 'The vendor file must be a PDF.'],
    'invalid header' => [fn () => externalUiPdf('vendor.pdf', 'not-a-pdf'), 'uploadExternalDocument', StoreExternalCalibrationDocumentException::invalidPdfSignature()->getMessage()],
    'oversized' => [fn () => externalUiPdf('vendor.pdf', "%PDF-1.4\n".str_repeat('a', 40)), 'uploadExternalDocument', StoreExternalCalibrationDocumentException::fileTooLarge()->getMessage()],
]);

it('replaces an active pdf, keeps history, and leaves the vendor snapshot unchanged', function () {
    externalUiArrange();
    externalUiMapActor();

    calibrationSessionPinningUiPage()
        ->set('externalVendorName', 'Acme Labs')
        ->call('saveExternalVendor')
        ->assertHasNoErrors();

    $page = calibrationSessionPinningUiPage();
    $page->set('externalCertificatePdf', externalUiPdf('first.pdf', "%PDF-1.4\nfirst\n"))
        ->call('uploadExternalDocument')
        ->assertHasNoErrors();
    $page->set('externalCertificatePdf', externalUiPdf('second.pdf', "%PDF-1.4\nsecond\n"))
        ->call('uploadExternalDocument')
        ->assertHasNoErrors()
        ->assertSee('first.pdf')
        ->assertSee('second.pdf')
        ->assertSee('Replaced')
        ->assertSee('Active');

    $documents = externalUiDocuments();
    $firstPath = (string) $documents[0]->storage_path;
    $secondPath = (string) $documents[1]->storage_path;

    expect(externalUiRow()->external_vendor_name)->toBe('Acme Labs')
        ->and($documents)->toHaveCount(2)
        ->and((int) $documents[0]->is_active)->toBe(0)
        ->and($documents[0]->original_filename)->toBe('first.pdf')
        ->and((int) $documents[1]->is_active)->toBe(1)
        ->and($documents[1]->original_filename)->toBe('second.pdf')
        ->and(Storage::disk('calibration_documents')->get($firstPath))->toBe("%PDF-1.4\nfirst\n")
        ->and(Storage::disk('calibration_documents')->get($secondPath))->toBe("%PDF-1.4\nsecond\n")
        ->and($page->html())->not->toContain($firstPath)
        ->and($page->html())->not->toContain('/storage/');
});

it('maps storage and metadata failures without claiming a committed document', function () {
    externalUiArrange();
    externalUiMapActor();

    $store = Mockery::mock(StoreExternalCalibrationDocument::class);
    $store->shouldReceive('store')->once()->andThrow(StoreExternalCalibrationDocumentException::storageWriteFailure());
    app()->instance(StoreExternalCalibrationDocument::class, $store);

    calibrationSessionPinningUiPage()
        ->set('externalCertificatePdf', externalUiPdf())
        ->call('uploadExternalDocument')
        ->assertHasErrors(['uploadExternalDocument' => StoreExternalCalibrationDocumentException::storageWriteFailure()->getMessage()])
        ->assertDontSee('The vendor PDF was stored.');

    expect(externalUiDocuments())->toHaveCount(0);

    $metadata = Mockery::mock(StoreExternalCalibrationDocument::class);
    $metadata->shouldReceive('store')->once()->andThrow(new QueryException(
        'sqlite',
        'insert into calibration_external_document',
        [],
        new PDOException('metadata failed'),
    ));
    app()->instance(StoreExternalCalibrationDocument::class, $metadata);

    calibrationSessionPinningUiPage()
        ->set('externalCertificatePdf', externalUiPdf())
        ->call('uploadExternalDocument')
        ->assertHasErrors(['uploadExternalDocument' => 'The vendor PDF metadata could not be saved.'])
        ->assertDontSee('insert into calibration_external_document');

    expect(externalUiDocuments())->toHaveCount(0);
});

it('refuses an unsafe connection before a permanent pdf write', function () {
    externalUiArrange();
    externalUiMapActor();
    app()->instance(RecordCalibrationSessionHttpConnection::class, externalUiUnsafeWrites());

    Livewire::test('pages::calibrations.show', [
        'calibration' => 5,
    ])
        ->set('externalCertificatePdf', externalUiPdf())
        ->call('uploadExternalDocument')
        ->assertHasErrors(['uploadExternalDocument' => RecordCalibrationSessionHttpException::writesUnavailable()->getMessage()]);

    expect(externalUiDocuments())->toHaveCount(0)
        ->and(Storage::disk('calibration_documents')->allFiles())->toBeEmpty()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('completes an external session with an active pdf and optional vendor', function (?string $vendor) {
    $this->freezeTime();
    externalUiArrange();
    actingAsRole(ApplicationRoleName::Administrator);
    externalUiMapActor();
    externalUiFreezeLocation();

    expect(Gate::allows(ApplicationCapability::SessionComplete->value))->toBeTrue();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Complete External Calibration');

    expect(calibrationSessionPinningUiPage()->instance()->canCompleteExternalSession())->toBeTrue()
        ->and(calibrationSessionPinningUiPage()->html())->toContain('wire:submit="requestCompleteExternalSession"');

    $page = calibrationSessionPinningUiPage();

    if ($vendor !== null) {
        $page->set('externalVendorName', $vendor)->call('saveExternalVendor')->assertHasNoErrors();
    }

    $page->set('externalCertificatePdf', externalUiPdf())
        ->call('uploadExternalDocument')
        ->assertHasNoErrors();

    $page->set('completionDate', '2026-10-22')
        ->set('completionDue', '2027-10-22')
        ->call('completeExternalSession')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-terminal-success')
        ->assertDontSee('The calibration session has been completed.')
        ->assertDontSee('Save Vendor')
        ->assertDontSee('>Upload PDF</button>', false)
        ->assertDontSee('>Complete External Calibration</button>', false);

    $row = externalUiRow();

    expect($row->session_status)->toBe(Calibration::STATUS_COMPLETED)
        ->and($row->calibration_date)->toBe('2026-10-22')
        ->and($row->calibration_due)->toBe('2027-10-22')
        ->and($row->completed_at)->not->toBeNull()
        ->and($row->certificate_number)->toBeNull()
        ->and($row->external_vendor_name)->toBe($vendor)
        ->and($row->calibration_provider_id)->toBeNull();
})->with([
    'null vendor' => [null],
    'recorded vendor' => ['Acme Labs'],
]);

it('lets an engineer complete an eligible external session', function () {
    $this->freezeTime();
    externalUiArrange();
    actingAsRole(ApplicationRoleName::Engineer);
    externalUiMapActor();
    externalUiFreezeLocation();

    expect(Gate::allows(ApplicationCapability::SessionComplete->value))->toBeTrue();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Complete External Calibration');

    expect(calibrationSessionPinningUiPage()->instance()->canCompleteExternalSession())->toBeTrue()
        ->and(calibrationSessionPinningUiPage()->html())->toContain('wire:submit="requestCompleteExternalSession"');

    calibrationSessionPinningUiPage()
        ->set('externalCertificatePdf', externalUiPdf())
        ->call('uploadExternalDocument')
        ->assertHasNoErrors()
        ->set('completionDate', '2026-10-22')
        ->set('completionDue', '2027-10-22')
        ->call('completeExternalSession')
        ->assertHasNoErrors();

    expect(externalUiRow()->session_status)->toBe(Calibration::STATUS_COMPLETED)
        ->and(externalUiRow()->certificate_number)->toBeNull()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('keeps external complete read-only for a viewer', function () {
    externalUiArrange();
    externalUiFreezeLocation();
    actingAsRole(ApplicationRoleName::Viewer);
    $before = calibrationSessionPinningUiFingerprint();

    expect(Gate::allows(ApplicationCapability::SessionComplete->value))->toBeFalse()
        ->and(Gate::allows(ApplicationCapability::HistoryView->value))->toBeTrue();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertDontSee('Complete External Calibration');

    expect(calibrationSessionPinningUiPage()->instance()->canCompleteExternalSession())->toBeFalse()
        ->and(calibrationSessionPinningUiPage()->html())->not->toContain('wire:submit="completeExternalSession"');

    calibrationSessionPinningUiPage()
        ->set('completionDate', '2026-10-22')
        ->set('completionDue', '2027-10-22')
        ->call('completeExternalSession')
        ->assertForbidden();

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(externalUiRow()->session_status)->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('redirects anonymous users away from external complete without writes', function () {
    externalUiArrange();
    Auth::logout();
    $before = calibrationSessionPinningUiFingerprint();

    $this->get(route('calibrations.show', 5))->assertRedirect(route('login'));

    expect(calibrationSessionPinningUiFingerprint())->toBe($before)
        ->and(externalUiRow()->session_status)->toBe(Calibration::STATUS_IN_PROGRESS);
});

it('denies external complete when session.complete is denied even if session.record is allowed', function () {
    externalUiArrange();
    actingAsRole(ApplicationRoleName::Administrator);
    denySessionCompleteWhileAllowingSessionRecord();

    try {
        expect(Gate::allows(ApplicationCapability::SessionRecord->value))->toBeTrue()
            ->and(Gate::allows(ApplicationCapability::SessionComplete->value))->toBeFalse();

        $before = calibrationSessionPinningUiFingerprint();

        $this->get(route('calibrations.show', 5))
            ->assertOk()
            ->assertDontSee('Complete External Calibration');

        expect(calibrationSessionPinningUiPage()->instance()->canCompleteExternalSession())->toBeFalse()
            ->and(calibrationSessionPinningUiPage()->html())->not->toContain('wire:submit="completeExternalSession"');

        calibrationSessionPinningUiPage()
            ->set('completionDate', '2026-10-22')
            ->set('completionDue', '2027-10-22')
            ->call('completeExternalSession')
            ->assertForbidden();

        expect(calibrationSessionPinningUiFingerprint())->toBe($before)
            ->and(externalUiRow()->session_status)->toBe(Calibration::STATUS_IN_PROGRESS);

        calibrationSessionPinningUiPage()
            ->set('externalVendorName', 'Acme Labs')
            ->call('saveExternalVendor')
            ->assertHasNoErrors();

        expect(externalUiRow()->external_vendor_name)->toBe('Acme Labs')
            ->and(externalUiRow()->session_status)->toBe(Calibration::STATUS_IN_PROGRESS)
            ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
    } finally {
        restoreApplicationCapabilityGates();
    }
});

it('cannot complete an external session without an active pdf or valid dates', function (string $date, string $due, ?string $errorKey, ?string $message, bool $withPdf) {
    externalUiArrange();
    externalUiMapActor();
    externalUiFreezeLocation();
    $page = calibrationSessionPinningUiPage();

    if ($withPdf) {
        $page->set('externalCertificatePdf', externalUiPdf())->call('uploadExternalDocument')->assertHasNoErrors();
    }

    $page->set('completionDate', $date)
        ->set('completionDue', $due)
        ->call('completeExternalSession');

    if ($errorKey !== null && $message !== null) {
        $page->assertHasErrors([$errorKey => $message]);
    }

    expect(externalUiRow()->session_status)->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and(externalUiRow()->certificate_number)->toBeNull();
})->with([
    'missing pdf' => ['2026-10-22', '2027-10-22', 'completeExternalSession', CompleteCalibrationSessionException::invalidActivePdfCount()->getMessage(), false],
    'missing date' => ['', '2027-10-22', 'completionDate', 'Calibration Date is required.', true],
    'missing due' => ['2026-10-22', '', 'completionDue', 'Calibration Due is required.', true],
    'invalid date' => ['22-10-2026', '2027-10-22', 'completeExternalSession', CompleteCalibrationSessionException::invalidDates()->getMessage(), true],
    'due before date' => ['2026-10-22', '2026-10-21', 'completeExternalSession', CompleteCalibrationSessionException::dueBeforeCalibrationDate()->getMessage(), true],
]);

it('asks for confirmation before completing an external session', function () {
    externalUiArrange();
    actingAsRole(ApplicationRoleName::Administrator);

    calibrationSessionPinningUiPage()
        ->call('requestCompleteExternalSession')
        ->assertHasNoErrors()
        ->assertDispatched('instruments-desk-confirm-completion');
});

it('rejects a repeated external complete and keeps the session read-only', function () {
    $this->freezeTime();
    externalUiArrange();
    externalUiMapActor();
    externalUiFreezeLocation();

    $page = calibrationSessionPinningUiPage();
    $page->set('externalCertificatePdf', externalUiPdf())->call('uploadExternalDocument')->assertHasNoErrors();
    $page->set('completionDate', '2026-10-22')
        ->set('completionDue', '2027-10-22')
        ->call('completeExternalSession')
        ->assertHasNoErrors();

    $afterFirst = calibrationSessionPinningUiFingerprint();

    $page->set('completionDate', '2026-11-01')
        ->set('completionDue', '2027-11-01')
        ->call('completeExternalSession')
        ->assertHasErrors(['completeExternalSession' => CompleteCalibrationSessionException::invalidSessionStatus()->getMessage()]);

    expect(calibrationSessionPinningUiFingerprint())->toBe($afterFirst)
        ->and(externalUiRow()->certificate_number)->toBeNull();
});

it('does not impose internal requirements on external complete', function () {
    externalUiArrange();
    externalUiMapActor();
    externalUiFreezeLocation();

    $this->get(route('calibrations.show', 5))
        ->assertOk()
        ->assertSee('Complete External Calibration')
        ->assertDontSee('Complete requires a frozen instrument identity and location, Internal Calibration Team')
        ->assertDontSee('Test Components have not been pinned to this session yet.')
        ->assertDontSee('Requirement Modes')
        ->assertDontSee('Record Activity')
        ->assertDontSee('Save Activities')
        ->assertDontSee('Resolve Requirement');

    calibrationSessionPinningUiPage()
        ->set('externalCertificatePdf', externalUiPdf())
        ->call('uploadExternalDocument')
        ->assertHasNoErrors()
        ->set('completionDate', '2026-10-22')
        ->set('completionDue', '2027-10-22')
        ->call('completeExternalSession')
        ->assertHasNoErrors();

    expect(externalUiRow()->session_status)->toBe(Calibration::STATUS_COMPLETED)
        ->and(DB::table('calibration_standard_usage')->count())->toBe(0)
        ->and(DB::table('calibration_activity')->count())->toBe(0)
        ->and(DB::table('calibration_environment_value')->count())->toBe(0)
        ->and(DB::table('calibration_scope')->whereNotNull('test_table_snapshot')->count())->toBe(0);
});

it('rejects the internal complete action on an external session', function () {
    externalUiArrange();
    $before = calibrationSessionPinningUiFingerprint();

    calibrationSessionPinningUiPage()
        ->set('completionDate', '2026-10-22')
        ->set('completionDue', '2027-10-22')
        ->call('completeSession')
        ->assertHasErrors(['completeSession' => 'This Complete action is available only for an internal calibration session.']);

    expect(calibrationSessionPinningUiFingerprint())->toBe($before);
});

it('fails closed when http external writes cannot use postgresql', function () {
    externalUiArrange();
    app()->instance(RecordCalibrationSessionHttpConnection::class, externalUiUnsafeWrites());

    Livewire::test('pages::calibrations.show', [
        'calibration' => 5,
    ])
        ->set('externalVendorName', 'Acme Labs')
        ->call('saveExternalVendor')
        ->assertHasErrors(['saveExternalVendor' => RecordCalibrationSessionHttpException::writesUnavailable()->getMessage()]);

    expect(externalUiRow()->external_vendor_name)->toBeNull()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('keeps start calibration free of requirement modes after the external session ui', function () {
    createStartCalibrationHttpTables();
    seedStartCalibrationHttpSubject();

    Livewire::test('pages::instruments.start-calibration', [
        'instrument' => 1,
        'instrumentType' => 11,
    ])
        ->assertSee('Start Calibration')
        ->assertDontSee('Requirement Modes')
        ->assertDontSee('>test_profile</dt>', false);
});
