<?php

namespace App\Dashboard;

use App\Models\Calibration;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum CalibrationStanding: string
{
    case OnTrack = 'On Track';
    case DueSoon = 'Due Soon';
    case Overdue = 'Overdue';
    case NotCalibrated = 'Not Calibrated';

    public static function fromLatest(?Calibration $calibration): self
    {
        return self::fromDueDate($calibration?->calibration_due);
    }

    public static function fromDueDate(?CarbonInterface $due, ?CarbonInterface $evaluationDate = null): self
    {
        if ($due === null) {
            return self::NotCalibrated;
        }

        $due = CarbonImmutable::instance($due)->startOfDay();
        $today = $evaluationDate === null
            ? today()
            : CarbonImmutable::instance($evaluationDate)->startOfDay();

        if ($today->greaterThan($due)) {
            return self::Overdue;
        }

        if ($due->lessThanOrEqualTo($today->copy()->addDays(60))) {
            return self::DueSoon;
        }

        return self::OnTrack;
    }
}
