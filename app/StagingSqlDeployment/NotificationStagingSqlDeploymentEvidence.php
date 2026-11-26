<?php

namespace App\StagingSqlDeployment;

/**
 * Operator evidence required before a future deployment entry point may run.
 *
 * Values are supplied by the separately authorized release process; this
 * class never discovers, creates, or verifies them through a database call.
 */
final class NotificationStagingSqlDeploymentEvidence
{
    public function __construct(
        public readonly string $releaseIdentity,
        public readonly string $artifactSha256,
        public readonly string $databaseIdentity,
        public readonly string $host,
        public readonly int $port,
        public readonly string $backupSha256,
        public readonly string $backupCreatedAt,
        public readonly string $recoveryDestination,
        public readonly bool $backupVerified,
        public readonly bool $operatorAuthorized,
    ) {}

    public function assertComplete(NotificationStagingSqlPackage $package): void
    {
        $this->assertBinding($package);

        if (! $this->backupVerified) {
            throw new StagingSqlDeploymentException('Fresh staging backup verification is required.');
        }

        if (! $this->operatorAuthorized) {
            throw new StagingSqlDeploymentException('Explicit notification schema deployment authorization is required.');
        }
    }

    public function assertBinding(NotificationStagingSqlPackage $package): void
    {
        if ($this->releaseIdentity !== $package->releaseIdentity) {
            throw new StagingSqlDeploymentException('Notification release identity does not match the package.');
        }

        if (! hash_equals($package->artifactSha256, strtolower($this->artifactSha256))) {
            throw new StagingSqlDeploymentException('Notification SQL artifact checksum does not match the package.');
        }

        if ($this->databaseIdentity !== ApprovedStagingSqlArtifacts::APPROVED_DATABASE) {
            throw new StagingSqlDeploymentException('Notification deployment database identity is not approved staging.');
        }

        if (! in_array(strtolower($this->host), ApprovedStagingSqlArtifacts::APPROVED_HOSTS, true)
            || $this->port !== ApprovedStagingSqlArtifacts::APPROVED_PORT) {
            throw new StagingSqlDeploymentException('Notification deployment endpoint is not approved staging.');
        }

        if (! preg_match('/^[a-f0-9]{64}$/i', $this->backupSha256)) {
            throw new StagingSqlDeploymentException('Fresh staging backup checksum evidence is missing.');
        }

        if (trim($this->backupCreatedAt) === '' || trim($this->recoveryDestination) === '') {
            throw new StagingSqlDeploymentException('Backup creation and recovery evidence is incomplete.');
        }
    }
}
