Instruments Desk - Calibration Due Reminder

The following calibration reminders require attention:

@foreach ($reminders as $reminder)
Instrument: {{ $reminder->instrumentName }}
Type: {{ $reminder->instrumentType }}
Serial Number: {{ $reminder->serialNumber ?? '—' }}
Location: {{ $reminder->location ?? '—' }}
Calibration Due: {{ $reminder->calibrationDue->format('d M Y') }}
Milestone: {{ str_replace('_', ' ', $reminder->milestoneCode) }}

@endforeach
