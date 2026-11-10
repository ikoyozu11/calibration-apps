<?php

namespace App\Console\Commands;

use App\CalibrationDueNotifications\CalibrationDueNotificationService;
use App\CalibrationSessions\CalibrationHttpConnection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('calibration:send-due-reminders')]
#[Description('Prepare and send the consolidated daily Calibration Due reminder email.')]
final class SendCalibrationDueRemindersCommand extends Command
{
    public function handle(
        CalibrationHttpConnection $httpConnection,
        CalibrationDueNotificationService $service,
    ): int {
        if (! (bool) config('calibration_due_notifications.enabled', false)) {
            $this->comment('Calibration Due notifications are disabled.');

            return self::SUCCESS;
        }

        try {
            $result = $service->run($httpConnection->resolve());
        } catch (Throwable $exception) {
            $this->error('Calibration Due reminders could not be processed safely.');

            report($exception);

            return self::FAILURE;
        }

        return match ($result['status']) {
            'no_reminders' => $this->success('No Calibration Due reminders qualify today.'),
            'recipient_missing' => $this->failure('Calibration Due reminder recipient is not configured.'),
            CalibrationDueNotificationService::STATUS_SENT => $this->success(sprintf(
                'Calibration Due reminder sent for %d item(s).',
                $result['sent_count'],
            )),
            'already_sent', 'already_processed' => $this->success('Calibration Due reminder was already processed today.'),
            CalibrationDueNotificationService::STATUS_FAILED,
            CalibrationDueNotificationService::STATUS_DELIVERY_UNKNOWN,
            CalibrationDueNotificationService::STATUS_PARTIAL_FAILURE => $this->failure(
                'Calibration Due reminder delivery requires review.',
            ),
            default => self::SUCCESS,
        };
    }

    private function success(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    private function failure(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
