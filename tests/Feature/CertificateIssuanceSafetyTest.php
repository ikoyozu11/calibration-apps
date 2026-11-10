<?php

use App\Certificates\CertificateNumberAllocator;
use App\Certificates\IssueInternalCertificate;
use Illuminate\Support\Facades\Storage;

it('cleans all pending pdf artifacts after a rolled back transaction without touching unrelated files', function () {
    Storage::fake('calibration_documents');
    $disk = Storage::disk('calibration_documents');
    $disk->put('internal-certificates/attempt-one.pdf', 'one');
    $disk->put('internal-certificates/attempt-two.pdf', 'two');
    $disk->put('internal-certificates/committed.pdf', 'committed');

    $issuer = new IssueInternalCertificate(new CertificateNumberAllocator);
    $pendingPaths = (new ReflectionProperty($issuer, 'pendingPaths'));
    $pendingPaths->setValue($issuer, [
        'internal-certificates/attempt-one.pdf',
        'internal-certificates/attempt-two.pdf',
    ]);

    $issuer->cleanupLastArtifact();

    expect($disk->exists('internal-certificates/attempt-one.pdf'))->toBeFalse()
        ->and($disk->exists('internal-certificates/attempt-two.pdf'))->toBeFalse()
        ->and($disk->exists('internal-certificates/committed.pdf'))->toBeTrue();
});

it('forgets committed artifacts without deleting the issued pdf', function () {
    Storage::fake('calibration_documents');
    $disk = Storage::disk('calibration_documents');
    $disk->put('internal-certificates/committed.pdf', 'committed');

    $issuer = new IssueInternalCertificate(new CertificateNumberAllocator);
    $pendingPaths = (new ReflectionProperty($issuer, 'pendingPaths'));
    $pendingPaths->setValue($issuer, ['internal-certificates/committed.pdf']);

    $issuer->forgetLastArtifact();
    $issuer->cleanupLastArtifact();

    expect($disk->exists('internal-certificates/committed.pdf'))->toBeTrue();
});
