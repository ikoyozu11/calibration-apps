<?php

return [

    /*
    |--------------------------------------------------------------------------
    | External Calibration Documents
    |--------------------------------------------------------------------------
    |
    | Vendor PDFs are stored on a private disk that must never be publicly
    | served. Original filenames are metadata only. Storage paths use a UUID.
    |
    | File/database consistency:
    | 1. Write the PDF to the disk first.
    | 2. Insert calibration_external_document in the same application transaction.
    | 3. If the insert fails, delete the newly written file.
    | 4. If the database rolls back after a successful insert, the UUID file is
    |    an orphan and must be removed by a later cleanup scan of disk files
    |    whose UUID is not present in calibration_external_document.
    | 5. Superseded files remain on disk for audit and are never deleted by
    |    replacement. Terminal sessions reject further uploads.
    |
    */

    'documents' => [
        'disk' => 'calibration_documents',
        'max_bytes' => (int) env('CALIBRATION_DOCUMENTS_MAX_BYTES', 20 * 1024 * 1024),
        'allowed_mime' => 'application/pdf',
    ],

];
