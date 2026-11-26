<?php

namespace App\StagingSqlDeployment;

/**
 * Lifecycle handle for a durable approval reservation.
 */
interface NotificationStagingSqlApprovalReservation
{
    public function markCommitted(): void;

    public function releaseAfterRollback(): void;

    public function markAmbiguous(): void;
}
