<?php

namespace App\StagingSqlDeployment;

/**
 * Offline factory. Never opens PostgreSQL. Records acquisition attempts.
 */
final class FakeStagingSqlConnectionFactory implements StagingSqlConnectionFactory
{
    public int $connectAttempts = 0;

    public ?StagingSqlDeploymentTarget $lastTarget = null;

    public function __construct(private FakeStagingSqlSession $session = new FakeStagingSqlSession) {}

    public function connect(StagingSqlDeploymentTarget $target): StagingSqlSession
    {
        $this->connectAttempts++;
        $this->lastTarget = $target;

        if (! $this->session->isOpen()) {
            // Recreate a fresh open session if previous was closed.
            $this->session = new FakeStagingSqlSession;
        }

        return $this->session;
    }

    public function session(): FakeStagingSqlSession
    {
        return $this->session;
    }
}
