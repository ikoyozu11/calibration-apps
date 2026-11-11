<?php

use App\CalibrationDueNotifications\CalibrationDueReminder;
use App\Mail\CalibrationDueReminderMail;
use Carbon\CarbonImmutable;

it('renders the approved English HTML and text content for all milestones', function (): void {
    config(['calibration_due_notifications.public_url' => '']);

    $reminders = collect([
        'two_months',
        'one_month',
        'seven_days',
        'three_days',
        'due_today',
    ])->map(fn (string $milestone): CalibrationDueReminder => reminderForTemplate($milestone))->all();
    $mail = new CalibrationDueReminderMail($reminders, CarbonImmutable::parse('2026-11-11', 'Asia/Jakarta'));
    $views = $mail->content()->with;

    $html = view($mail->content()->view, $views)->render();
    $text = view($mail->content()->text, $views)->render();

    expect($html)
        ->toContain('INSTRUMENTS DESK')
        ->toContain('Calibration Management System')
        ->toContain('Calibration Due Reminder')
        ->toContain('2-Month Reminder')
        ->toContain('1-Month Reminder')
        ->toContain('7-Day Reminder')
        ->toContain('3-Day Reminder')
        ->toContain('Due Today')
        ->toContain('5 instruments included in this reminder.')
        ->not->toContain('Open Instruments Desk')
        ->not->toContain('TEST EMAIL - NO ACTION REQUIRED')
        ->and($text)
        ->toContain('INSTRUMENTS DESK')
        ->toContain('Calibration Due Reminder')
        ->toContain('Due Today')
        ->toContain('E2E TEST INSTRUMENT - DO NOT ACTION')
        ->not->toContain('Open Instruments Desk:')
        ->not->toContain('TEST EMAIL - NO ACTION REQUIRED');
});

it('renders multiple instruments and escapes long dynamic values', function (): void {
    config(['calibration_due_notifications.public_url' => '']);

    $reminders = [
        reminderForTemplate('due_today', 'Long <Instrument> & Synthetic Name '.str_repeat('X', 120)),
        reminderForTemplate('three_days', 'Second Synthetic Instrument'),
    ];
    $mail = new CalibrationDueReminderMail($reminders, CarbonImmutable::parse('2026-11-11', 'Asia/Jakarta'));
    $content = $mail->content();
    $html = view($content->view, $content->with)->render();
    $text = view($content->text, $content->with)->render();

    expect($html)
        ->toContain('2 instruments included in this reminder.')
        ->toContain('Long &lt;Instrument&gt; &amp; Synthetic Name')
        ->toContain('Second Synthetic Instrument')
        ->not->toContain('Long <Instrument> & Synthetic Name')
        ->and($text)
        ->toContain('Long &lt;Instrument&gt; &amp; Synthetic Name')
        ->toContain('Second Synthetic Instrument');
});

it('shows the public URL CTA only for a valid public HTTPS URL', function (): void {
    $mail = new CalibrationDueReminderMail([
        reminderForTemplate('due_today'),
    ], CarbonImmutable::parse('2026-11-11', 'Asia/Jakarta'));

    foreach ([null, '', 'http://instruments.example.com', 'https://localhost/app', 'https://127.0.0.1/app'] as $url) {
        config(['calibration_due_notifications.public_url' => $url]);
        expect($mail->publicUrl())->toBeNull();
    }

    config(['calibration_due_notifications.public_url' => 'https://instruments.example.com/app']);
    $content = $mail->content();
    $html = view($content->view, $content->with)->render();
    $text = view($content->text, $content->with)->render();

    expect($mail->publicUrl())->toBe('https://instruments.example.com/app')
        ->and($html)->toContain('Open Instruments Desk')
        ->toContain('href="https://instruments.example.com/app"')
        ->and($text)->toContain('Open Instruments Desk: https://instruments.example.com/app');
});

function reminderForTemplate(string $milestone, string $instrumentName = 'E2E TEST INSTRUMENT - DO NOT ACTION'): CalibrationDueReminder
{
    return new CalibrationDueReminder(
        instrumentId: 1,
        instrumentTypeId: 1,
        latestCalibrationId: 1,
        calibrationMethod: 'INTERNAL',
        instrumentName: $instrumentName,
        instrumentType: 'E2E TEST TYPE',
        serialNumber: 'E2E-NOTIF-02-TEST',
        location: 'E2E TEST LOCATION',
        calibrationDue: CarbonImmutable::parse('2026-11-11', 'Asia/Jakarta'),
        milestoneCode: $milestone,
        milestoneDate: CarbonImmutable::parse('2026-11-11', 'Asia/Jakarta'),
    );
}
