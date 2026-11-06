<?php

it('keeps notification delivery disabled by default and uses the safe command path', function () {
    $root = dirname(__DIR__, 2);
    $config = file_get_contents($root.'/config/calibration_due_notifications.php');
    $command = file_get_contents($root.'/app/Console/Commands/SendCalibrationDueRemindersCommand.php');
    $bootstrap = file_get_contents($root.'/bootstrap/app.php');
    $resolver = file_get_contents($root.'/app/CalibrationDueNotifications/CurrentCalibrationDueResolver.php');
    $service = file_get_contents($root.'/app/CalibrationDueNotifications/CalibrationDueNotificationService.php');
    $preflight = file_get_contents($root.'/app/StagingRuntime/StagingActivationPreflight.php');

    expect($config)->not->toBeFalse()
        ->and($config)->toContain("'enabled' => (bool) env('CALIBRATION_DUE_NOTIFICATIONS_ENABLED', false)")
        ->and($command)->not->toBeFalse()
        ->and($command)->toContain('CalibrationHttpConnection')
        ->and($command)->toContain('calibration:send-due-reminders')
        ->and($bootstrap)->not->toBeFalse()
        ->and($bootstrap)->toContain("->dailyAt('08:00')")
        ->and($bootstrap)->toContain("->timezone((string) config('calibration_due_notifications.timezone'")
        ->and($resolver)->toContain('CalibrationSessionConnection')
        ->and($resolver)->toContain('$this->sessionConnection->resolve($connection)')
        ->and($service)->toContain("STATUS_DELIVERY_UNKNOWN = 'delivery_unknown'")
        ->and($service)->toContain("STATUS_OBSOLETE = 'obsolete'")
        ->and($service)->toContain('Current Calibration Due changed before delivery.')
        ->and($service)->toContain('Stale processing state may have reached the mail transport.')
        ->and($preflight)->toContain('database/sql/calibration_due_notification_history.sql')
        ->and($preflight)->toContain('requires migration approval and fresh backup/schema verification.');
});
