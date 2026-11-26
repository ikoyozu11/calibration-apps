<?php

return [
    // This remains disabled until the guarded notification schema is deployed
    // and a separate activation checkpoint is approved.
    'enabled' => false,
    'staging_opt_in' => filter_var(
        env('CALIBRATION_NOTIFICATION_CENTER_STAGING', false),
        FILTER_VALIDATE_BOOL,
    ),
];
