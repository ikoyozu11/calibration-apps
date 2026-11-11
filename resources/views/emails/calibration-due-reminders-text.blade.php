@php
    $milestoneLabels = [
        'two_months' => '2-Month Reminder',
        'one_month' => '1-Month Reminder',
        'seven_days' => '7-Day Reminder',
        'three_days' => '3-Day Reminder',
        'due_today' => 'Due Today',
    ];
    $instrumentCount = count($reminders);
@endphp
INSTRUMENTS DESK
Calibration Management System

Calibration Due Reminder

Dear Team,

This is an automated notification from Instruments Desk to inform you that the following {{ $instrumentCount === 1 ? 'instrument is approaching or has reached' : 'instruments are approaching or have reached' }} their scheduled calibration due dates.

Please review the details below and arrange the necessary calibration activities as appropriate.

CALIBRATION REMINDER SUMMARY
{{ $instrumentCount }} {{ $instrumentCount === 1 ? 'instrument' : 'instruments' }} included in this reminder.

@foreach ($reminders as $reminder)
Instrument Name: {{ $reminder->instrumentName }}
Instrument Type: {{ $reminder->instrumentType }}
Serial Number: {{ $reminder->serialNumber ?: '—' }}
Location: {{ $reminder->location ?: '—' }}
Calibration Due Date: {{ $reminder->calibrationDue->format('d M Y') }}
Reminder Status: {{ $milestoneLabels[$reminder->milestoneCode] ?? 'Calibration Reminder' }}

@endforeach
@if ($publicUrl !== null)
For more information, please access Instruments Desk to review the latest calibration records and instrument details.
Open Instruments Desk: {{ $publicUrl }}

@endif
Best regards,

Instruments Desk
Calibration Management System

Automated Calibration Notification
This is a system-generated email. Please do not reply.
