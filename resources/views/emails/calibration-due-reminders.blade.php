<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Calibration Due Reminder</title>
</head>
<body style="margin: 0; background: #f4f7fb; color: #172554; font-family: Arial, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding: 24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="680" cellspacing="0" cellpadding="0" style="max-width: 680px; width: 100%; background: #ffffff;">
                    <tr>
                        <td style="padding: 22px 24px; background: #193b7a; color: #ffffff;">
                            <div style="font-size: 20px; font-weight: 700;">Instruments Desk</div>
                            <div style="margin-top: 4px; font-size: 13px;">Calibration Due Reminder</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 16px;">The following calibration reminders require attention:</p>
                            <table role="table" width="100%" cellspacing="0" cellpadding="8" style="border-collapse: collapse; font-size: 13px;">
                                <thead>
                                    <tr style="background: #e7eef8; text-align: left;">
                                        <th>Instrument</th>
                                        <th>Type</th>
                                        <th>Serial Number</th>
                                        <th>Location</th>
                                        <th>Calibration Due</th>
                                        <th>Milestone</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($reminders as $reminder)
                                        <tr>
                                            <td style="border-bottom: 1px solid #dbe4f0;">{{ $reminder->instrumentName }}</td>
                                            <td style="border-bottom: 1px solid #dbe4f0;">{{ $reminder->instrumentType }}</td>
                                            <td style="border-bottom: 1px solid #dbe4f0;">{{ $reminder->serialNumber ?? '—' }}</td>
                                            <td style="border-bottom: 1px solid #dbe4f0;">{{ $reminder->location ?? '—' }}</td>
                                            <td style="border-bottom: 1px solid #dbe4f0;">{{ $reminder->calibrationDue->format('d M Y') }}</td>
                                            <td style="border-bottom: 1px solid #dbe4f0;">{{ str_replace('_', ' ', $reminder->milestoneCode) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
