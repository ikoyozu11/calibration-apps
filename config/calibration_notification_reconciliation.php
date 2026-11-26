<?php

return [
    /*
    | The reconciliation command is intentionally disabled until the guarded
    | staging schema has been installed and separately approved for activation.
    */
    'enabled' => false,
    'staging_opt_in' => filter_var(
        env('CALIBRATION_NOTIFICATION_RECONCILIATION_STAGING', false),
        FILTER_VALIDATE_BOOL,
    ),
    'timezone' => 'Asia/Jakarta',
    'chunk_size' => 250,
];
