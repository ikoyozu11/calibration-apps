<?php

namespace App\Console\Commands;

use App\CalibrationDueNotifications\CalibrationDueReminderPreview;
use App\CalibrationSessions\CalibrationHttpConnection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('calibration:preview-due-reminders')]
#[Description('Preview eligible Calibration Due reminders without sending or persisting anything.')]
final class PreviewCalibrationDueRemindersCommand extends Command
{
    public function handle(
        CalibrationHttpConnection $httpConnection,
        CalibrationDueReminderPreview $preview,
    ): int {
        try {
            $result = $preview->preview($httpConnection->resolve());
        } catch (Throwable $exception) {
            $this->error('Calibration Due reminder preview could not be processed safely.');

            report($exception);

            return self::FAILURE;
        }

        $this->info('Calibration Due reminder preview');
        $this->line('Evaluation date (Asia/Jakarta): '.$result['evaluation_date']);
        $this->line('Configured recipients: '.$result['recipient_count']);
        $this->line('Eligible reminder items: '.$result['eligible_count']);
        $this->line('Expected outgoing emails: '.$result['expected_email_count']);
        $this->line('Skipped by existing history: '.$result['skipped_count']);
        $this->line('Retry-eligible failed items: '.$result['retry_eligible_count']);

        $this->newLine();
        $this->line('Per recipient:');
        foreach ($result['recipients'] as $recipient) {
            $this->line(sprintf(
                '  %s: %d eligible, %d skipped, status=%s',
                $recipient['recipient'],
                $recipient['eligible_count'],
                $recipient['skipped_count'],
                $recipient['status'],
            ));
        }

        $this->newLine();
        $this->line('Milestone breakdown:');
        foreach ($result['milestone_breakdown'] as $milestone => $count) {
            $this->line("  {$milestone}: {$count}");
        }

        $this->line('Due-date breakdown:');
        foreach ($result['due_dates'] as $dueDate => $count) {
            $this->line("  {$dueDate}: {$count}");
        }

        return self::SUCCESS;
    }
}
