<?php

namespace App\StagingSqlDeployment;

/**
 * Durable replay-protection boundary. Implementations must atomically reserve
 * an approval identifier and release identity across concurrent workers.
 */
interface NotificationStagingSqlApprovalReplayProtection
{
    public function reserve(NotificationStagingSqlApprovalClaims $claims): NotificationStagingSqlApprovalReservation;
}
