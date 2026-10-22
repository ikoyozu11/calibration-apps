<?php

it('configures a private calibration documents disk that is not publicly served', function () {
    expect(config('filesystems.disks.calibration_documents.serve'))->toBeFalse()
        ->and(config('filesystems.disks.calibration_documents.visibility'))->toBe('private')
        ->and(basename((string) config('filesystems.disks.calibration_documents.root')))->toBe('calibration-documents')
        ->and(config('filesystems.disks.local.serve'))->toBeTrue()
        ->and(config('calibration.documents.disk'))->toBe('calibration_documents')
        ->and(config('calibration.documents.allowed_mime'))->toBe('application/pdf')
        ->and(config('calibration.documents.max_bytes'))->toBe(20 * 1024 * 1024);
});
