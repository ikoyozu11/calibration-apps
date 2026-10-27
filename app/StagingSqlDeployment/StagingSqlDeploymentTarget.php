<?php

namespace App\StagingSqlDeployment;

final class StagingSqlDeploymentTarget
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
    ) {}

    public static function approvedLocalStaging(): self
    {
        return new self('127.0.0.1', ApprovedStagingSqlArtifacts::APPROVED_PORT, ApprovedStagingSqlArtifacts::APPROVED_DATABASE);
    }
}
