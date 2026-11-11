<?php

namespace Tests\Support;

use App\CalibrationDueNotifications\CalibrationDueReminderSelector;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Symfony\Component\Mime\Email;

final class NotificationE2e03bLiveRunner
{
    public const AUTHORIZATION_TOKEN = 'NOTIF-E2E-03B';

    /**
     * @param  callable(): void  $seed
     * @return array{recipient: string, reminder_count: int, expected_email_count: int, due_date: string, milestone: string}
     */
    public function preflight(callable $seed, CarbonImmutable $now): array
    {
        $this->assertSqliteMemory();
        $recipients = NotificationE2eRecipientAllowlist::assertEffectiveRecipients();
        $this->assertSmtpConfiguration();

        $connection = DB::connection();

        if ($connection->table('calibration_due_notification_batches')->count() !== 0
            || $connection->table('calibration_due_notification_items')->count() !== 0) {
            throw new RuntimeException('The live-send database must not contain notification history.');
        }

        $seed();
        $reminders = app(CalibrationDueReminderSelector::class)->select($connection, $now, 0);

        if ($reminders->count() !== 1 || $reminders->first()->milestoneCode !== 'due_today') {
            throw new RuntimeException('Live-send preflight requires exactly one due_today reminder.');
        }

        return [
            'recipient' => $recipients[0],
            'reminder_count' => $reminders->count(),
            'expected_email_count' => 1,
            'due_date' => $reminders->first()->calibrationDue->toDateString(),
            'milestone' => $reminders->first()->milestoneCode,
        ];
    }

    /**
     * @param  callable(): void  $seed
     * @return array{preflight: array{recipient: string, reminder_count: int, expected_email_count: int, due_date: string, milestone: string}, command_exit_code: int, application_send_attempts: int}
     */
    public function runAuthorized(
        string $authorizationToken,
        string $processAuthorization,
        string $markerPath,
        callable $seed,
        CarbonImmutable $now,
    ): array {
        if ($authorizationToken !== self::AUTHORIZATION_TOKEN
            || $processAuthorization !== self::AUTHORIZATION_TOKEN) {
            throw new RuntimeException('NOTIF-E2E-03B live-send authorization was not confirmed.');
        }

        config([
            'calibration_due_notifications.enabled' => true,
            'calibration_due_notifications.catch_up_days' => 0,
            'calibration_due_notifications.max_retry_attempts' => 1,
        ]);

        $preflight = $this->preflight($seed, $now);
        (new NotificationE2e03bExecutionMarker)->claim($markerPath);

        Event::listen(MessageSending::class, function (MessageSending $event): void {
            if (! $event->message instanceof Email) {
                throw new RuntimeException('The outgoing Laravel message is not a Symfony Email envelope.');
            }

            $this->prepareOutgoingMessage($event->message);
        });

        Carbon::setTestNow($now);

        try {
            $exitCode = Artisan::call('calibration:send-due-reminders');
        } finally {
            Carbon::setTestNow();
        }

        return [
            'preflight' => $preflight,
            'command_exit_code' => $exitCode,
            'application_send_attempts' => 1,
        ];
    }

    public function prepareOutgoingMessage(Email $message): void
    {
        $recipients = array_map(
            static fn (object $recipient): string => mb_strtolower($recipient->getAddress()),
            $message->getTo(),
        );

        if ($recipients !== [NotificationE2eRecipientAllowlist::ALLOWED_RECIPIENT]
            || $message->getCc() !== []
            || $message->getBcc() !== []) {
            throw new RuntimeException('The effective SMTP envelope is outside the approved allowlist.');
        }

        $subject = $message->getSubject();
        if (! str_starts_with($subject, '[TEST - DO NOT ACTION]')) {
            $message->subject('[TEST - DO NOT ACTION] '.$subject);
        }

        $markerHtml = '<p><strong>NOTIF-E2E-03B TEST ONLY:</strong> No calibration action is required.</p>';
        $markerText = "NOTIF-E2E-03B TEST ONLY: No calibration action is required.\n\n";
        $html = $message->getHtmlBody();
        $text = $message->getTextBody();

        if ($html === null || $text === null
            || ! str_contains($html.$text, 'E2E TEST INSTRUMENT - DO NOT ACTION')) {
            throw new RuntimeException('The synthetic test identity is missing from the outgoing message.');
        }

        $message->html($markerHtml.$html);
        $message->text($markerText.$text);
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
            throw new RuntimeException('Live-send requires the default SQLite :memory: connection.');
        }
    }

    private function assertSmtpConfiguration(): void
    {
        $mailer = config('mail.default');
        $smtp = config('mail.mailers.smtp');
        $required = [
            'host' => $smtp['host'] ?? null,
            'port' => $smtp['port'] ?? null,
            'scheme' => $smtp['scheme'] ?? null,
            'username' => $smtp['username'] ?? null,
            'password' => $smtp['password'] ?? null,
            'from.address' => config('mail.from.address'),
            'from.name' => config('mail.from.name'),
        ];

        if ($mailer !== 'smtp' || ($smtp['transport'] ?? null) !== 'smtp') {
            throw new RuntimeException('Live-send requires the Laravel SMTP mailer.');
        }

        foreach ($required as $name => $value) {
            if ($value === null || $value === '') {
                throw new RuntimeException('Live-send SMTP configuration is incomplete: '.$name.'.');
            }
        }
    }
}
