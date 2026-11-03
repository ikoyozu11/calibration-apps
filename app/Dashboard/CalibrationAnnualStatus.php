<?php

namespace App\Dashboard;

enum CalibrationAnnualStatus: string
{
    case NotCalibrated = 'Not Calibrated';
    case InProgress = 'In Progress';
    case Completed = 'Completed';
}
