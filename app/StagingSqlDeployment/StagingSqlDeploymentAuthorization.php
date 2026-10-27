<?php

namespace App\StagingSqlDeployment;

/**
 * Explicit multi-factor, scoped authorization. A CLI flag alone is never sufficient.
 * Scoped approval for checkpoint 2C.2B-21Z-D atomic staging SQL deployment.
 */
final class StagingSqlDeploymentAuthorization
{
    public const APPROVED_CHECKPOINT = '2C.2B-21Z-D';

    /**
     * @param  list<string>  $approvedArtifactSha256
     */
    public function __construct(
        public readonly bool $operatorWriteApproved,
        public readonly bool $backupBaselineVerified,
        public readonly bool $freshSchemaInventoryVerified,
        public readonly bool $freshTriggerFingerprintVerified,
        public readonly bool $operatorConfirmedExecution,
        public readonly string $approvedBackupSha256 = '',
        public readonly string $approvedCheckpoint = '',
        public readonly string $approvedDatabase = '',
        public readonly string $approvedHost = '',
        public readonly int $approvedPort = 0,
        public readonly string $approvedReleaseHead = '',
        public readonly array $approvedArtifactSha256 = [],
    ) {}

    public static function denied(): self
    {
        return new self(
            operatorWriteApproved: false,
            backupBaselineVerified: false,
            freshSchemaInventoryVerified: false,
            freshTriggerFingerprintVerified: false,
            operatorConfirmedExecution: false,
        );
    }

    /**
     * Offline/test helper for a fully scoped approval object. Does not authorize
     * live PostgreSQL execution by itself — the live adapter still requires an
     * injected connection factory and a later operational entry point.
     */
    public static function scopedForApprovedRelease(string $backupSha256 = ApprovedStagingSqlArtifacts::APPROVED_BACKUP_SHA256): self
    {
        return new self(
            operatorWriteApproved: true,
            backupBaselineVerified: true,
            freshSchemaInventoryVerified: true,
            freshTriggerFingerprintVerified: true,
            operatorConfirmedExecution: true,
            approvedBackupSha256: $backupSha256,
            approvedCheckpoint: self::APPROVED_CHECKPOINT,
            approvedDatabase: ApprovedStagingSqlArtifacts::APPROVED_DATABASE,
            approvedHost: '127.0.0.1',
            approvedPort: ApprovedStagingSqlArtifacts::APPROVED_PORT,
            approvedReleaseHead: ApprovedStagingSqlArtifacts::APPROVED_HEAD,
            approvedArtifactSha256: array_column(ApprovedStagingSqlArtifacts::ordered(), 'sha256'),
        );
    }
}
