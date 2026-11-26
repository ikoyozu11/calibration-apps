<?php

namespace App\StagingSqlDeployment;

/**
 * Runtime verification boundary for the future notification executor.
 */
interface NotificationStagingSqlExecutionVerifier
{
    public function assertPreDeploymentReady(
        NotificationStagingSqlPackage $package,
        StagingSqlDeploymentTarget $target,
        StagingSqlSession $session,
    ): void;

    public function assertPostDeploymentReady(
        NotificationStagingSqlPackage $package,
        StagingSqlDeploymentTarget $target,
        StagingSqlSession $session,
    ): void;
}
