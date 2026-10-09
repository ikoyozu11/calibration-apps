<?php

namespace App\Dashboard;

use App\Models\Calibration;

enum CalibrationStanding: string
{
    case OnTrack = 'On Track';
    case DueSoon = 'Due Soon';
    case Overdue = 'Overdue';
    case NotCalibrated = 'Not Calibrated';

    public static function fromLatest(?Calibration $calibration): self
    {
        if ($calibration === null || $calibration->calibration_due === null) {
            return self::NotCalibrated;
        }

        $due = $calibration->calibration_due->startOfDay();
        $today = today();

        if ($today->greaterThan($due)) {
            return self::Overdue;
        }

        if ($due->lessThanOrEqualTo($today->copy()->addDays(60))) {
            return self::DueSoon;
        }

        return self::OnTrack;
    }
}
