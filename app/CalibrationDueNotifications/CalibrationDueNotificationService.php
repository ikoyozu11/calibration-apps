<?php

namespace App\CalibrationDueNotifications;

use App\CalibrationSessions\CalibrationSessionConnection;
use App\Mail\CalibrationDueReminderMail;
use App\Models\CalibrationDueNotificationBatch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

final class CalibrationDueNotificationService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELIVERY_UNKNOWN = 'delivery_unknown';

    public const STATUS_OBSOLETE = 'obsolete';

    public const STATUS_PARTIAL_FAILURE = 'partial_failure';

    public function __construct(
        private CalibrationDueReminderSelector $selector,
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    /**
     * @return array{status: string, reminder_count: int, sent_count: int, recipient_count: int, sent_recipient_count: int, failed_recipient_count: int, unknown_recipient_count: int}
     */
    public function run(Connection $connection, ?CarbonImmutable $now = null): array
    {
        if (! (bool) config('calibration_due_notifications.enabled', false)) {
            return $this->summary('disabled', 0, [], 0);
        }

        $recipients = $this->configuredRecipients();

        if ($recipients === []) {
            return $this->summary('recipient_missing', 0, [], 0);
        }

        $connection = $this->sessionConnection->resolve($connection);

        $timezone = (string) config('calibration_due_notifications.timezone', CalibrationDueMilestoneCalculator::TIMEZONE);
        $now ??= CarbonImmutable::now($timezone);
        $reminders = $this->selector->select(
            $connection,
            $now,
            max(0, (int) config('calibration_due_notifications.catch_up_days', 3)),
        );

        if ($reminders->isEmpty()) {
            return $this->summary('no_reminders', 0, [], count($recipients));
        }

        $results = [];
        foreach ($recipients as $recipient) {
            $results[] = $this->processRecipient($connection, $reminders->all(), $recipient, $now);
        }

        return $this->summarizeRecipientResults($results, $reminders->count(), count($recipients));
    }

    /**
     * @param  list<CalibrationDueReminder>  $reminders
     * @return array{status: string, sent_count: int}
     */
    private function processRecipient(Connection $connection, array $reminders, string $recipient, CarbonImmutable $now): array
    {
        $batch = $this->persistReminders($connection, $reminders, $recipient, $now);

        if ($batch['status'] !== self::STATUS_PROCESSING) {
            return ['status' => $batch['status'], 'sent_count' => 0];
        }

        $claimedItems = $batch['items'];
        $claimedReminders = $claimedItems
            ->map(fn (object $item): CalibrationDueReminder => CalibrationDueReminder::fromHistory($item))
            ->all();
        $timezone = (string) config('calibration_due_notifications.timezone', CalibrationDueMilestoneCalculator::TIMEZONE);

        try {
            Mail::to($recipient)->send(new CalibrationDueReminderMail($claimedReminders, $now->setTimezone($timezone)->startOfDay()));

            $this->markSent($connection, $batch['batch_id'], $claimedItems->pluck('id')->all(), $now);

            return [
                'status' => self::STATUS_SENT,
                'sent_count' => count($claimedReminders),
            ];
        } catch (Throwable $exception) {
            $status = $exception instanceof TransportExceptionInterface
                ? self::STATUS_DELIVERY_UNKNOWN
                : self::STATUS_FAILED;

            $this->markFailed($connection, $batch['batch_id'], $claimedItems->pluck('id')->all(), $status, $exception, $now);

            Log::error('Calibration due reminder delivery failed.', [
                'status' => $status,
                'batch_id' => $batch['batch_id'],
                'exception' => $exception::class,
            ]);

            return [
                'status' => $status,
                'sent_count' => 0,
            ];
        }
    }

    /**
     * @return list<string>
     */
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

    /**
     * @param  list<array{status: string, sent_count: int}>  $results
     * @return array{status: string, reminder_count: int, sent_count: int, recipient_count: int, sent_recipient_count: int, failed_recipient_count: int, unknown_recipient_count: int}
     */
    private function summarizeRecipientResults(array $results, int $reminderCount, int $recipientCount): array
    {
        $statuses = array_column($results, 'status');
        $failed = count(array_filter($statuses, fn (string $status): bool => $status === self::STATUS_FAILED));
        $unknown = count(array_filter($statuses, fn (string $status): bool => $status === self::STATUS_DELIVERY_UNKNOWN));
        $sent = count(array_filter($statuses, fn (string $status): bool => $status === self::STATUS_SENT));
        $sentCount = array_sum(array_column($results, 'sent_count'));

        if ($failed + $unknown > 0) {
            $status = $recipientCount === 1
                ? ($unknown > 0 ? self::STATUS_DELIVERY_UNKNOWN : self::STATUS_FAILED)
                : self::STATUS_PARTIAL_FAILURE;
        } elseif ($sent > 0) {
            $status = self::STATUS_SENT;
        } elseif (count(array_filter($statuses, fn (string $value): bool => $value === 'already_sent')) === $recipientCount) {
            $status = 'already_sent';
        } else {
            $status = 'already_processed';
        }

        return $this->summary($status, $reminderCount, $results, $recipientCount);
    }

    /**
     * @param  list<array{status: string, sent_count: int}>  $results
     * @return array{status: string, reminder_count: int, sent_count: int, recipient_count: int, sent_recipient_count: int, failed_recipient_count: int, unknown_recipient_count: int}
     */
    private function summary(string $status, int $reminderCount, array $results, int $recipientCount): array
    {
        $statuses = array_column($results, 'status');

        return [
            'status' => $status,
            'reminder_count' => $reminderCount,
            'sent_count' => array_sum(array_column($results, 'sent_count')),
            'recipient_count' => $recipientCount,
            'sent_recipient_count' => count(array_filter($statuses, fn (string $value): bool => $value === self::STATUS_SENT)),
            'failed_recipient_count' => count(array_filter($statuses, fn (string $value): bool => $value === self::STATUS_FAILED)),
            'unknown_recipient_count' => count(array_filter($statuses, fn (string $value): bool => $value === self::STATUS_DELIVERY_UNKNOWN)),
        ];
    }

    /**
     * @param  list<CalibrationDueReminder>  $reminders
     * @return array{batch_id: int, status: string, items: Collection<int, object>}
     */
    private function persistReminders(Connection $connection, array $reminders, string $recipient, CarbonImmutable $now): array
    {
        $name = $connection->getName();
        $date = $now->setTimezone((string) config('calibration_due_notifications.timezone'))->toDateString();
        $maxAttempts = max(1, (int) config('calibration_due_notifications.max_retry_attempts', 3));
        $staleBefore = $now->subMinutes(15);

        return DB::connection($name)->transaction(function () use ($name, $date, $recipient, $now, $reminders, $maxAttempts, $staleBefore): array {
            $batch = CalibrationDueNotificationBatch::on($name)
                ->newQuery()
                ->whereDate('notification_date', $date)
                ->where('recipient', $recipient)
                ->lockForUpdate()
                ->first();

            if ($batch === null) {
                $batch = CalibrationDueNotificationBatch::on($name)->create([
                    'notification_date' => $date,
                    'timezone' => (string) config('calibration_due_notifications.timezone'),
                    'recipient' => $recipient,
                    'status' => self::STATUS_PENDING,
                    'attempts' => 0,
                ]);
            }

            if ($batch->status === self::STATUS_SENT) {
                return ['batch_id' => (int) $batch->id, 'status' => 'already_sent', 'items' => collect()];
            }

            if ($batch->status === self::STATUS_DELIVERY_UNKNOWN) {
                return ['batch_id' => (int) $batch->id, 'status' => self::STATUS_DELIVERY_UNKNOWN, 'items' => collect()];
            }

            if ($batch->status === self::STATUS_PROCESSING
                && $batch->claimed_at !== null
                && $batch->claimed_at->greaterThan($staleBefore)) {
                return ['batch_id' => (int) $batch->id, 'status' => 'already_processing', 'items' => collect()];
            }

            if ($batch->status === self::STATUS_PROCESSING) {
                $unknownMessage = 'Stale processing state may have reached the mail transport.';
                DB::connection($name)->table('calibration_due_notification_items')
                    ->where('batch_id', $batch->id)
                    ->where('status', self::STATUS_PROCESSING)
                    ->update([
                        'status' => self::STATUS_DELIVERY_UNKNOWN,
                        'failure_message' => $unknownMessage,
                        'updated_at' => $now,
                    ]);
                $batch->update([
                    'status' => self::STATUS_DELIVERY_UNKNOWN,
                    'failed_at' => $now,
                    'failure_message' => $unknownMessage,
                    'updated_at' => $now,
                ]);

                return ['batch_id' => (int) $batch->id, 'status' => self::STATUS_DELIVERY_UNKNOWN, 'items' => collect()];
            }

            $rows = array_map(fn (CalibrationDueReminder $reminder): array => [
                'batch_id' => $batch->id,
                'instrument_id' => $reminder->instrumentId,
                'instrument_type_id' => $reminder->instrumentTypeId,
                'latest_calibration_id' => $reminder->latestCalibrationId,
                'calibration_method' => $reminder->calibrationMethod,
                'instrument_name_snapshot' => $reminder->instrumentName,
                'instrument_type_snapshot' => $reminder->instrumentType,
                'serial_number_snapshot' => $reminder->serialNumber,
                'location_snapshot' => $reminder->location,
                'due_date_snapshot' => $reminder->calibrationDue->toDateString(),
                'milestone_code' => $reminder->milestoneCode,
                'milestone_date' => $reminder->milestoneDate->toDateString(),
                'recipient' => $recipient,
                'status' => self::STATUS_PENDING,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ], $reminders);

            DB::connection($name)->table('calibration_due_notification_items')->insertOrIgnore($rows);

            $candidateItems = DB::connection($name)
                ->table('calibration_due_notification_items')
                ->where('batch_id', $batch->id)
                ->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED])
                ->where('attempts', '<', $maxAttempts)
                ->lockForUpdate()
                ->get();

            $eligibleKeys = collect($reminders)
                ->map(fn (CalibrationDueReminder $reminder): string => $this->dedupeKey(
                    $reminder->instrumentTypeId,
                    $reminder->calibrationDue->toDateString(),
                    $reminder->milestoneCode,
                    $recipient,
                ))
                ->flip();

            $items = $candidateItems->filter(function (object $item) use ($eligibleKeys, $recipient): bool {
                $key = $this->dedupeKey(
                    (int) $item->instrument_type_id,
                    (string) $item->due_date_snapshot,
                    (string) $item->milestone_code,
                    $recipient,
                );

                return $eligibleKeys->has($key);
            })->values();

            $obsoleteItems = $candidateItems->reject(fn (object $item): bool => $items->contains('id', $item->id));

            if ($obsoleteItems->isNotEmpty()) {
                DB::connection($name)->table('calibration_due_notification_items')
                    ->whereIn('id', $obsoleteItems->pluck('id')->all())
                    ->update([
                        'status' => self::STATUS_OBSOLETE,
                        'failure_message' => 'Current Calibration Due changed before delivery.',
                        'updated_at' => $now,
                    ]);
            }

            if ($items->isEmpty()) {
                return ['batch_id' => (int) $batch->id, 'status' => 'already_processed', 'items' => $items];
            }

            foreach ($items as $item) {
                DB::connection($name)->table('calibration_due_notification_items')
                    ->where('id', $item->id)
                    ->update([
                        'status' => self::STATUS_PROCESSING,
                        'attempts' => ((int) $item->attempts) + 1,
                        'last_attempt_at' => $now,
                        'updated_at' => $now,
                    ]);
                $item->attempts = ((int) $item->attempts) + 1;
            }

            $batch->update([
                'status' => self::STATUS_PROCESSING,
                'attempts' => ((int) $batch->attempts) + 1,
                'claimed_at' => $now,
                'updated_at' => $now,
            ]);

            return ['batch_id' => (int) $batch->id, 'status' => self::STATUS_PROCESSING, 'items' => $items];
        });
    }

    /** @param list<int> $itemIds */
    private function markSent(Connection $connection, int $batchId, array $itemIds, CarbonImmutable $now): void
    {
        DB::connection($connection->getName())->transaction(function () use ($connection, $batchId, $itemIds, $now): void {
            DB::connection($connection->getName())->table('calibration_due_notification_items')
                ->whereIn('id', $itemIds)
                ->update(['status' => self::STATUS_SENT, 'failure_message' => null, 'updated_at' => $now]);
            CalibrationDueNotificationBatch::on($connection->getName())->whereKey($batchId)->update([
                'status' => self::STATUS_SENT,
                'sent_at' => $now,
                'failure_message' => null,
                'updated_at' => $now,
            ]);
        });
    }

    /** @param list<int> $itemIds */
    private function markFailed(Connection $connection, int $batchId, array $itemIds, string $status, Throwable $exception, CarbonImmutable $now): void
    {
        $message = mb_substr($exception->getMessage(), 0, 1000);
        DB::connection($connection->getName())->transaction(function () use ($connection, $batchId, $itemIds, $status, $message, $now): void {
            DB::connection($connection->getName())->table('calibration_due_notification_items')
                ->whereIn('id', $itemIds)
                ->update(['status' => $status, 'failure_message' => $message, 'updated_at' => $now]);
            CalibrationDueNotificationBatch::on($connection->getName())->whereKey($batchId)->update([
                'status' => $status,
                'failed_at' => $now,
                'failure_message' => $message,
                'updated_at' => $now,
            ]);
        });
    }

    private function dedupeKey(int $instrumentTypeId, string $dueDate, string $milestoneCode, string $recipient): string
    {
        return implode('|', [$instrumentTypeId, $dueDate, $milestoneCode, $recipient]);
    }
}
