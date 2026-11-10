<?php

namespace App\CalibrationDueNotifications;

use App\CalibrationSessions\CalibrationSessionConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;

final class CalibrationDueReminderPreview
{
    public function __construct(
        private CalibrationDueReminderSelector $selector,
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    /**
     * @return array{
     *     evaluation_date: string,
     *     recipient_count: int,
     *     recipients: list<array{recipient: string, eligible_count: int, skipped_count: int, status: string}>,
     *     eligible_count: int,
     *     expected_email_count: int,
     *     milestone_breakdown: array<string, int>,
     *     due_dates: array<string, int>,
     *     skipped_count: int,
     *     retry_eligible_count: int,
     * }
     */
    public function preview(Connection $connection, ?CarbonImmutable $now = null): array
    {
        $connection = $this->sessionConnection->resolve($connection);
        $timezone = (string) config('calibration_due_notifications.timezone', CalibrationDueMilestoneCalculator::TIMEZONE);
        $now ??= CarbonImmutable::now($timezone);
        $date = $now->setTimezone($timezone)->toDateString();
        $reminders = $this->selector->select(
            $connection,
            $now,
            max(0, (int) config('calibration_due_notifications.catch_up_days', 3)),
        );
        $recipients = $this->configuredRecipients();
        $recipientResults = [];
        $eligibleCount = 0;
        $skippedCount = 0;
        $retryEligibleCount = 0;

        foreach ($recipients as $recipient) {
            $result = $this->previewRecipient($connection, $reminders->all(), $recipient, $date);
            $recipientResults[] = [
                'recipient' => $recipient,
                ...$result,
            ];
            $eligibleCount += $result['eligible_count'];
            $skippedCount += $result['skipped_count'];
            $retryEligibleCount += $result['retry_eligible_count'];
        }

        return [
            'evaluation_date' => $date,
            'recipient_count' => count($recipients),
            'recipients' => $recipientResults,
            'eligible_count' => $eligibleCount,
            'expected_email_count' => count(array_filter(
                $recipientResults,
                static fn (array $result): bool => $result['eligible_count'] > 0,
            )),
            'milestone_breakdown' => $this->milestoneBreakdown($reminders->all()),
            'due_dates' => $this->dueDateBreakdown($reminders->all()),
            'skipped_count' => $skippedCount,
            'retry_eligible_count' => $retryEligibleCount,
        ];
    }

    /**
     * @param  list<CalibrationDueReminder>  $reminders
     * @return array{eligible_count: int, skipped_count: int, status: string, retry_eligible_count: int}
     */
    private function previewRecipient(Connection $connection, array $reminders, string $recipient, string $date): array
    {
        $batch = $connection->table('calibration_due_notification_batches')
            ->whereDate('notification_date', $date)
            ->where('recipient', $recipient)
            ->first();

        if ($batch === null) {
            return [
                'eligible_count' => count($reminders),
                'skipped_count' => 0,
                'status' => 'new',
                'retry_eligible_count' => 0,
            ];
        }

        $blockedStatuses = [
            CalibrationDueNotificationService::STATUS_SENT => 'already_sent',
            CalibrationDueNotificationService::STATUS_DELIVERY_UNKNOWN => 'delivery_unknown',
            CalibrationDueNotificationService::STATUS_PROCESSING => 'processing',
        ];

        if (isset($blockedStatuses[$batch->status])) {
            return [
                'eligible_count' => 0,
                'skipped_count' => count($reminders),
                'status' => $blockedStatuses[$batch->status],
                'retry_eligible_count' => 0,
            ];
        }

        $maxAttempts = max(1, (int) config('calibration_due_notifications.max_retry_attempts', 3));
        $historyItems = $connection->table('calibration_due_notification_items')
            ->where('batch_id', $batch->id)
            ->get();
        $historyByKey = $historyItems->keyBy(fn (object $item): string => $this->historyKey($item, $recipient));
        $eligibleCount = 0;
        $retryEligibleCount = 0;

        foreach ($reminders as $reminder) {
            $item = $historyByKey->get($this->dedupeKey($reminder, $recipient));

            if ($item !== null
                && (! in_array($item->status, [
                    CalibrationDueNotificationService::STATUS_PENDING,
                    CalibrationDueNotificationService::STATUS_FAILED,
                ], true) || (int) $item->attempts >= $maxAttempts)) {
                continue;
            }

            $eligibleCount++;

            if ($item?->status === CalibrationDueNotificationService::STATUS_FAILED) {
                $retryEligibleCount++;
            }
        }

        return [
            'eligible_count' => $eligibleCount,
            'skipped_count' => count($reminders) - $eligibleCount,
            'status' => $eligibleCount > 0 ? 'retry_or_pending' : 'already_processed',
            'retry_eligible_count' => $retryEligibleCount,
        ];
    }

    /** @return list<string> */
    private function configuredRecipients(): array
    {
        $configured = config('calibration_due_notifications.recipients');

        if (is_array($configured) && $configured !== []) {
            return array_values(array_filter($configured, static fn (mixed $recipient): bool => is_string($recipient)));
        }

        return CalibrationDueRecipients::fromEnvironment(
            null,
            config('calibration_due_notifications.recipient'),
        );
    }

    /** @param list<CalibrationDueReminder> $reminders */
    private function milestoneBreakdown(array $reminders): array
    {
        return collect($reminders)->countBy('milestoneCode')->sortKeys()->all();
    }

    /** @param list<CalibrationDueReminder> $reminders */
    private function dueDateBreakdown(array $reminders): array
    {
        return collect($reminders)->countBy(
            fn (CalibrationDueReminder $reminder): string => $reminder->calibrationDue->toDateString(),
        )->sortKeys()->all();
    }

    private function dedupeKey(CalibrationDueReminder $reminder, string $recipient): string
    {
        return implode('|', [
            $reminder->instrumentTypeId,
            $reminder->calibrationDue->toDateString(),
            $reminder->milestoneCode,
            $recipient,
        ]);
    }

    private function historyKey(object $item, string $recipient): string
    {
        return implode('|', [
            (int) $item->instrument_type_id,
            (string) $item->due_date_snapshot,
            (string) $item->milestone_code,
            $recipient,
        ]);
    }
}
