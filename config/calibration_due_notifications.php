<?php

use App\CalibrationDueNotifications\CalibrationDueRecipients;

return [
    'enabled' => (bool) env('CALIBRATION_DUE_NOTIFICATIONS_ENABLED', false),
    'recipient' => env('CALIBRATION_DUE_NOTIFICATIONS_RECIPIENT'),
    'recipients' => CalibrationDueRecipients::fromEnvironment(
        env('CALIBRATION_DUE_NOTIFICATIONS_RECIPIENTS'),
        env('CALIBRATION_DUE_NOTIFICATIONS_RECIPIENT'),
    ),
    'timezone' => env('CALIBRATION_DUE_NOTIFICATIONS_TIMEZONE', 'Asia/Jakarta'),
    'catch_up_days' => (int) env('CALIBRATION_DUE_NOTIFICATIONS_CATCH_UP_DAYS', 3),
    'max_retry_attempts' => (int) env('CALIBRATION_DUE_NOTIFICATIONS_MAX_RETRY_ATTEMPTS', 3),
];
