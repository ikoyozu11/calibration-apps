<?php

namespace App\StagingSqlDeployment;

/**
 * Future execution boundary. Implementations must not open PostgreSQL unless
 * a later checkpoint explicitly enables a live adapter.
 */
interface StagingSqlExecutionAdapter
{
    public function execute(
        AtomicStagingSqlPackage $package,
        StagingSqlDeploymentTarget $target,
        StagingSqlDeploymentAuthorization $authorization,
    ): StagingSqlExecutionOutcome;
}
