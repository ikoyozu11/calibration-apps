<?php

namespace App\StagingSqlDeployment;

/**
 * Runtime schema verification boundary. Implementations must use an explicitly
 * approved staging connection and must not accept a browser-selected target.
 */
interface NotificationStagingSqlSchemaPreflight
{
    public function assertReady(
        NotificationStagingSqlPackage $package,
        StagingSqlDeploymentTarget $target,
    ): void;
}
