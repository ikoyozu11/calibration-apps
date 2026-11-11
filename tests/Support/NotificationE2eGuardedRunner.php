<?php

namespace Tests\Support;

use App\CalibrationDueNotifications\CalibrationDueReminderSelector;
use App\Mail\CalibrationDueReminderMail;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

final class NotificationE2eGuardedRunner
{
    /**
     * @param  callable(): void  $seed
     * @return array{exit_code: int, recipient: string, reminder_count: int, mail_count: int, batch_count: int, item_count: int, status: string, due_date: string, milestone: string}
     */
    public function runDryRun(callable $seed, CarbonImmutable $now): array
    {
        $this->assertSqliteMemory();
        $recipients = NotificationE2eRecipientAllowlist::assertEffectiveRecipients();
        $connection = DB::connection();

        if ($connection->table('calibration_due_notification_batches')->count() !== 0
            || $connection->table('calibration_due_notification_items')->count() !== 0) {
            throw new RuntimeException('The dry-run database already contains notification history.');
        }

        $seed();

        config([
            'calibration_due_notifications.enabled' => true,
            'calibration_due_notifications.catch_up_days' => 0,
            'calibration_due_notifications.max_retry_attempts' => 1,
        ]);

        Carbon::setTestNow($now);
        Mail::fake();

        try {
            $reminders = app(CalibrationDueReminderSelector::class)->select($connection, $now, 0);

            if ($reminders->count() !== 1 || $reminders->first()->milestoneCode !== 'due_today') {
                throw new RuntimeException('The dry-run preflight did not produce exactly one due_today reminder.');
            }

            $exitCode = Artisan::call('calibration:send-due-reminders');
        } finally {
            Carbon::setTestNow();
        }

        $captured = [];
        Mail::assertSent(CalibrationDueReminderMail::class, function (CalibrationDueReminderMail $mail) use (&$captured): bool {
            $captured[] = $mail;

            return true;
        });
        Mail::assertSent(CalibrationDueReminderMail::class, $recipients);

        $batch = $connection->table('calibration_due_notification_batches')->first();
        $item = $connection->table('calibration_due_notification_items')->first();

        if ($exitCode !== 0 || count($captured) !== 1 || $batch === null || $item === null) {
            throw new RuntimeException('The dry-run did not produce exactly one successful notification history event.');
        }

        return [
            'exit_code' => $exitCode,
            'recipient' => $recipients[0],
            'reminder_count' => count($captured[0]->reminders),
            'mail_count' => count($captured),
            'batch_count' => $connection->table('calibration_due_notification_batches')->count(),
            'item_count' => $connection->table('calibration_due_notification_items')->count(),
            'status' => (string) $item->status,
            'due_date' => (string) $item->due_date_snapshot,
            'milestone' => (string) $item->milestone_code,
        ];
    }

    private function assertSqliteMemory(): void
    {
        $default = config('database.default');
        $connection = is_string($default) ? config('database.connections.'.$default) : null;

        if ($default !== 'sqlite'
            || ! is_array($connection)
            || ($connection['driver'] ?? null) !== 'sqlite'
            || ($connection['database'] ?? null) !== ':memory:'
            || ! in_array($connection['url'] ?? null, [null, ''], true)) {
            throw new RuntimeException('Dry-run requires the default SQLite :memory: connection.');
        }
    }
}
