<?php

namespace App\StagingSqlDeployment;

/**
 * Compatibility wrapper kept for older offline tests.
 * Prefer {@see PdoStagingSqlConnectionFactory} for 21Z-D.
 */
final class SealedPdoStagingSqlConnectionFactory implements StagingSqlConnectionFactory
{
    public function __construct(private PdoStagingSqlConnectionFactory $inner = new PdoStagingSqlConnectionFactory) {}

    public function unsealForAuthorizedCheckpoint(string $checkpoint): void
    {
        // Offline tests historically called unseal without credentials; refuse.
        throw new StagingSqlDeploymentException(
            'PDO staging SQL factory remains sealed until the 21Z-D entry point unseals with credentials.'
        );
    }

    public function connect(StagingSqlDeploymentTarget $target): StagingSqlSession
    {
        return $this->inner->connect($target);
    }

    public function isUnsealed(): bool
    {
        return $this->inner->isUnsealed();
    }
}
