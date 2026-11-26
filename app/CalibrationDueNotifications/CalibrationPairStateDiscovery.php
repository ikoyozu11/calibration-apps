<?php

namespace App\CalibrationDueNotifications;

use Carbon\CarbonInterface;
use Illuminate\Database\Connection;

interface CalibrationPairStateDiscovery
{
    public function discoverPair(
        Connection $connection,
        int $instrumentId,
        int $instrumentTypeId,
        CarbonInterface $evaluationDate,
    ): CalibrationNotificationPairState;
}
