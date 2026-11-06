<?php

namespace App\StagingSqlDeployment;

final class DeltaStagingSqlReleaseApproval
{
    public function __construct(
        public readonly string $releaseId,
        public readonly string $approvedHead,
        public readonly string $checkpoint,
        public readonly string $freshBackupSha256,
        public readonly string $artifactSha256,
        public readonly string $artifactBodySha256,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        return new self(
            releaseId: (string) ($values['release_id'] ?? ''),
            approvedHead: (string) ($values['approved_head'] ?? ''),
            checkpoint: (string) ($values['checkpoint'] ?? ''),
            freshBackupSha256: strtolower((string) ($values['fresh_backup_sha256'] ?? '')),
            artifactSha256: strtolower((string) ($values['artifact_sha256'] ?? '')),
            artifactBodySha256: strtolower((string) ($values['artifact_body_sha256'] ?? '')),
        );
    }

    public function assertComplete(): void
    {
        if ($this->releaseId !== DeltaStagingSqlReleaseManifest::RELEASE_ID) {
            throw new StagingSqlDeploymentException('Delta release identifier is not approved.');
        }

        foreach ([
            'release HEAD' => $this->approvedHead,
            'deployment checkpoint' => $this->checkpoint,
            'fresh backup SHA-256' => $this->freshBackupSha256,
            'artifact SHA-256' => $this->artifactSha256,
            'artifact body SHA-256' => $this->artifactBodySha256,
        ] as $label => $value) {
            if ($value === '') {
                throw new StagingSqlDeploymentException('Missing approved '.$label.'.');
            }
        }

        foreach ([
            'approved release HEAD' => $this->approvedHead,
            'fresh backup SHA-256' => $this->freshBackupSha256,
            'artifact SHA-256' => $this->artifactSha256,
            'artifact body SHA-256' => $this->artifactBodySha256,
        ] as $label => $value) {
            if (preg_match('/^[a-f0-9]{40}$|^[a-f0-9]{64}$/i', $value) !== 1) {
                throw new StagingSqlDeploymentException('Invalid '.$label.' format.');
            }
        }
    }
}
