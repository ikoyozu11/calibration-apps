<?php

namespace App\StagingSqlDeployment;

/**
 * Validates the target before acquiring a session. Must not be invoked by
 * offline unit tests that exercise package parsing alone.
 */
interface StagingSqlConnectionFactory
{
    public function connect(StagingSqlDeploymentTarget $target): StagingSqlSession;
}
