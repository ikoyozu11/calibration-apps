<?php

namespace App\StagingSqlDeployment;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Immutable claims that a trusted release authority must authenticate.
 *
 * Shape validation is deliberately separate from provenance verification.
 */
final class NotificationStagingSqlApprovalClaims
{
    public const OPERATION_INSTALL_SCHEMA = 'install_notification_schema';

    public function __construct(
        public readonly string $approvalId,
        public readonly string $operatorIdentity,
        public readonly string $releaseIdentity,
        public readonly string $artifactSha256,
        public readonly string $databaseIdentity,
        public readonly string $host,
        public readonly int $port,
        public readonly string $backupSha256,
        public readonly DateTimeImmutable $issuedAt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly string $operation,
    ) {}

    public function assertMatches(
        NotificationStagingSqlPackage $package,
        NotificationStagingSqlDeploymentEvidence $evidence,
        DateTimeInterface $now,
    ): void {
        $this->assertShape($now);

        if ($this->releaseIdentity !== $package->releaseIdentity
            || $this->artifactSha256 !== $package->artifactSha256) {
            throw new StagingSqlDeploymentException('Trusted notification approval release binding mismatch.');
        }

        if ($this->databaseIdentity !== $package->targetDatabase
            || $this->databaseIdentity !== ApprovedStagingSqlArtifacts::APPROVED_DATABASE
            || $this->host !== $package->targetHost
            || $this->port !== $package->targetPort) {
            throw new StagingSqlDeploymentException('Trusted notification approval target binding mismatch.');
        }

        if ($this->backupSha256 !== strtolower($evidence->backupSha256)) {
            throw new StagingSqlDeploymentException('Trusted notification approval backup binding mismatch.');
        }
    }

    public function assertShape(DateTimeInterface $now): void
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $this->approvalId)
            || trim($this->operatorIdentity) === '') {
            throw new StagingSqlDeploymentException('Trusted notification approval identity claims are malformed.');
        }

        foreach ([$this->artifactSha256, $this->backupSha256] as $checksum) {
            if (! preg_match('/^[a-f0-9]{64}$/', $checksum)) {
                throw new StagingSqlDeploymentException('Trusted notification approval checksum claim is malformed.');
            }
        }

        if ($this->operation !== self::OPERATION_INSTALL_SCHEMA
            || $this->databaseIdentity !== ApprovedStagingSqlArtifacts::APPROVED_DATABASE
            || $this->host !== '127.0.0.1'
            || $this->port !== ApprovedStagingSqlArtifacts::APPROVED_PORT) {
            throw new StagingSqlDeploymentException('Trusted notification approval operation or target is invalid.');
        }

        if ($this->expiresAt <= $this->issuedAt || $now < $this->issuedAt || $now >= $this->expiresAt) {
            throw new StagingSqlDeploymentException('Trusted notification approval is expired or not yet valid.');
        }
    }
}
