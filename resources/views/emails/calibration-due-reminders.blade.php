<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Calibration Due Reminder</title>
</head>
<body style="margin: 0; background: #f3f6fa; color: #263449; font-family: Arial, Helvetica, sans-serif;">
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
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding: 24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="680" cellspacing="0" cellpadding="0" style="width: 100%; max-width: 680px; background: #ffffff; border: 1px solid #d9e2ee;">
                    <tr>
                        <td style="padding: 24px 28px; background: #193b7a; color: #ffffff;">
                            <div style="font-size: 22px; font-weight: 700; letter-spacing: .2px;">INSTRUMENTS DESK</div>
                            <div style="margin-top: 5px; font-size: 13px; color: #dbeafe;">Calibration Management System</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 28px;">
                            <h1 style="margin: 0 0 18px; color: #193b7a; font-size: 21px; line-height: 1.3;">Calibration Due Reminder</h1>
                            <p style="margin: 0 0 14px; line-height: 1.55;">Dear Team,</p>
                            <p style="margin: 0 0 14px; line-height: 1.55;">This is an automated notification from Instruments Desk to inform you that the following {{ $instrumentCount === 1 ? 'instrument is approaching or has reached' : 'instruments are approaching or have reached' }} their scheduled calibration due dates.</p>
                            <p style="margin: 0 0 22px; line-height: 1.55;">Please review the details below and arrange the necessary calibration activities as appropriate.</p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 22px; background: #f1f5f9; border-left: 4px solid #3b82f6;">
                                <tr>
                                    <td style="padding: 14px 16px;">
                                        <div style="color: #193b7a; font-size: 13px; font-weight: 700;">Calibration reminder summary</div>
                                        <div style="margin-top: 5px; color: #526176; font-size: 13px;">{{ $instrumentCount }} {{ $instrumentCount === 1 ? 'instrument' : 'instruments' }} included in this reminder.</div>
                                    </td>
                                </tr>
                            </table>

                            <table role="table" width="100%" cellspacing="0" cellpadding="0" style="width: 100%; border-collapse: collapse; font-size: 12px; line-height: 1.4;">
                                <thead>
                                    <tr style="background: #e7eef8; color: #193b7a; text-align: left;">
                                        <th style="padding: 10px 8px; border-bottom: 2px solid #cbd8e8;">Instrument Name</th>
                                        <th style="padding: 10px 8px; border-bottom: 2px solid #cbd8e8;">Instrument Type</th>
                                        <th style="padding: 10px 8px; border-bottom: 2px solid #cbd8e8;">Serial Number</th>
                                        <th style="padding: 10px 8px; border-bottom: 2px solid #cbd8e8;">Location</th>
                                        <th style="padding: 10px 8px; border-bottom: 2px solid #cbd8e8;">Calibration Due Date</th>
                                        <th style="padding: 10px 8px; border-bottom: 2px solid #cbd8e8;">Reminder Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($reminders as $reminder)
                                        <tr>
                                            <td style="padding: 10px 8px; border-bottom: 1px solid #dbe4f0; vertical-align: top; overflow-wrap: anywhere;">{{ $reminder->instrumentName }}</td>
                                            <td style="padding: 10px 8px; border-bottom: 1px solid #dbe4f0; vertical-align: top; overflow-wrap: anywhere;">{{ $reminder->instrumentType }}</td>
                                            <td style="padding: 10px 8px; border-bottom: 1px solid #dbe4f0; vertical-align: top; overflow-wrap: anywhere;">{{ $reminder->serialNumber ?: '—' }}</td>
                                            <td style="padding: 10px 8px; border-bottom: 1px solid #dbe4f0; vertical-align: top; overflow-wrap: anywhere;">{{ $reminder->location ?: '—' }}</td>
                                            <td style="padding: 10px 8px; border-bottom: 1px solid #dbe4f0; vertical-align: top; white-space: nowrap;">{{ $reminder->calibrationDue->format('d M Y') }}</td>
                                            <td style="padding: 10px 8px; border-bottom: 1px solid #dbe4f0; vertical-align: top;">{{ $milestoneLabels[$reminder->milestoneCode] ?? 'Calibration Reminder' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>

                            @if ($publicUrl !== null)
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top: 24px; background: #f1f5f9;">
                                    <tr>
                                        <td style="padding: 18px; text-align: center;">
                                            <p style="margin: 0 0 12px; color: #526176; font-size: 13px; line-height: 1.5;">For more information, please access Instruments Desk to review the latest calibration records and instrument details.</p>
                                            <a href="{{ $publicUrl }}" style="display: inline-block; padding: 11px 18px; background: #3b82f6; color: #ffffff; font-size: 13px; font-weight: 700; text-decoration: none;">Open Instruments Desk</a>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            <p style="margin: 26px 0 0; line-height: 1.55;">Best regards,<br>Instruments Desk<br>Calibration Management System</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 28px; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 11px; line-height: 1.5;">Automated Calibration Notification<br>This is a system-generated email. Please do not reply.</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
