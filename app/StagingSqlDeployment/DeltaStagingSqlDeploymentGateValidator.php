<?php

namespace App\StagingSqlDeployment;

final class DeltaStagingSqlDeploymentGateValidator
{
    public function __construct(
        private StagingSqlDeploymentGateValidator $commonGates = new StagingSqlDeploymentGateValidator,
        private StagingSqlScriptParser $parser = new StagingSqlScriptParser,
    ) {}

    public function assertPackageReady(
        DeltaAtomicStagingSqlPackage $package,
        DeltaStagingSqlReleaseApproval $approval,
    ): void {
        $approval->assertComplete();
        if ($package->releaseId !== $approval->releaseId
            || $package->resolvedHead !== $package->approvedHead
            || $package->approvedHead !== $approval->approvedHead) {
            throw new StagingSqlDeploymentException('Delta package release approval mismatch.');
        }

        $artifact = $package->artifacts[0] ?? null;
        if ($artifact === null || $artifact['relative_path'] !== DeltaStagingSqlReleaseManifest::ARTIFACT_PATH) {
            throw new StagingSqlDeploymentException('Delta package contains an unexpected artifact.');
        }
        if ($artifact['source_sha256'] !== strtolower($approval->artifactSha256)
            || $artifact['body_sha256'] !== strtolower($approval->artifactBodySha256)) {
            throw new StagingSqlDeploymentException('Delta package artifact integrity gate failed.');
        }

        $this->commonGates->assertBodiesContainNoTopLevelCommit($package->orderedBodySql(), $this->parser);
    }

    public function assertTargetAllowed(StagingSqlDeploymentTarget $target): void
    {
        $this->commonGates->assertTargetAllowed($target);
    }

    public function assertAuthorization(
        StagingSqlDeploymentAuthorization $authorization,
        DeltaStagingSqlReleaseApproval $approval,
    ): void {
        if (! $authorization->operatorWriteApproved) {
            throw new StagingSqlDeploymentException('Missing explicit delta write authorization.');
        }
        if (! $authorization->backupBaselineVerified
            || $authorization->approvedBackupSha256 !== strtolower($approval->freshBackupSha256)) {
            throw new StagingSqlDeploymentException('Delta release backup verification mismatch.');
        }
        if (! $authorization->freshSchemaInventoryVerified || ! $authorization->freshTriggerFingerprintVerified) {
            throw new StagingSqlDeploymentException('Missing fresh delta preflight verification.');
        }
        if (! $authorization->operatorConfirmedExecution) {
            throw new StagingSqlDeploymentException('Missing explicit delta execution confirmation.');
        }
        if ($authorization->approvedCheckpoint !== $approval->checkpoint
            || $authorization->approvedDatabase !== ApprovedStagingSqlArtifacts::APPROVED_DATABASE
            || ! in_array(strtolower($authorization->approvedHost), ApprovedStagingSqlArtifacts::APPROVED_HOSTS, true)
            || $authorization->approvedPort !== ApprovedStagingSqlArtifacts::APPROVED_PORT
            || $authorization->approvedReleaseHead !== $approval->approvedHead
            || $authorization->approvedArtifactSha256 !== [strtolower($approval->artifactSha256)]) {
            throw new StagingSqlDeploymentException('Delta release authorization scope mismatch.');
        }
    }

    public function assertAuthorizationMatchesTarget(
        StagingSqlDeploymentAuthorization $authorization,
        StagingSqlDeploymentTarget $target,
    ): void {
        $this->commonGates->assertAuthorizationMatchesTarget($authorization, $target);
    }

    public function assertNoDefaultConnectionFallback(bool $usingDefaultConnection): void
    {
        $this->commonGates->assertNoDefaultConnectionFallback($usingDefaultConnection);
    }
}
