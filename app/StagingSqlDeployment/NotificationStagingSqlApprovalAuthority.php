<?php

namespace App\StagingSqlDeployment;

/**
 * Trusted provenance boundary. Implementations must verify an authentic,
 * release-scoped approval source; claim shape validation is not sufficient.
 */
interface NotificationStagingSqlApprovalAuthority
{
    public function assertAuthentic(NotificationStagingSqlApprovalClaims $claims): void;
}
