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
            with: ['reminders' => $this->reminders],
        );
    }
}
