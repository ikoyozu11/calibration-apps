<?php

namespace App\Mail;

use App\CalibrationDueNotifications\CalibrationDueReminder;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class CalibrationDueReminderMail extends Mailable
{
    use Queueable;

    /** @param list<CalibrationDueReminder> $reminders */
    public function __construct(public array $reminders, public CarbonImmutable $notificationDate) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[Instruments Desk] Calibration Due Reminder - %s (%d Instruments)',
                $this->notificationDate->format('d M Y'),
                count($this->reminders),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.calibration-due-reminders',
            text: 'emails.calibration-due-reminders-text',
            with: [
                'reminders' => $this->reminders,
                'publicUrl' => $this->publicUrl(),
            ],
        );
    }

    public function publicUrl(): ?string
    {
        $configuredUrl = config('calibration_due_notifications.public_url', '');

        if (! is_string($configuredUrl) || trim($configuredUrl) === '') {
            return null;
        }

        $url = trim($configuredUrl);
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;

        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) $scheme) !== 'https'
            || ! is_string($host)
            || $host === ''
            || in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        return $url;
    }
}
