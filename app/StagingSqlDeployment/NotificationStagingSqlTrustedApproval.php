<?php

namespace App\StagingSqlDeployment;

/**
 * Trusted release-process boundary for notification schema authorization.
 *
 * The production implementation must be supplied by the separately approved
 * deployment process. Browser input, environment variables, and historical
 * approval objects must not implement this boundary.
 */
interface NotificationStagingSqlTrustedApproval
{
    public function assertApproved(
        NotificationStagingSqlPackage $package,
        NotificationStagingSqlDeploymentEvidence $evidence,
    ): void;
}
