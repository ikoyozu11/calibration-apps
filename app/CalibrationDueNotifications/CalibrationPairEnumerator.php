<?php

namespace App\CalibrationDueNotifications;

use Illuminate\Database\Connection;

interface CalibrationPairEnumerator
{
    public function enumerate(Connection $connection): AuthoritativeCalibrationPairEnumeration;
}
