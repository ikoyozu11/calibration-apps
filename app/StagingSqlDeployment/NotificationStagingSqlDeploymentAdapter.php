<?php

namespace App\StagingSqlDeployment;

/**
 * Controlled, non-executing notification deployment preflight adapter.
 *
 * This adapter deliberately has no execute method. It validates the package,
 * trusted approval boundary, backup bytes, and runtime schema preflight. A
 * later release may compose these checks with an explicitly authorized live
 * executor without accepting arbitrary SQL or database targets.
 */
final class NotificationStagingSqlDeploymentAdapter
{
    public function __construct(
        private StagingSqlDeploymentGateValidator $gates = new StagingSqlDeploymentGateValidator,
        private NotificationStagingSqlBackupVerifier $backupVerifier = new NotificationStagingSqlBackupVerifier,
    ) {}

    public function preflight(
        NotificationStagingSqlPackage $package,
        NotificationStagingSqlDeploymentEvidence $evidence,
        string $backupPath,
        ?NotificationStagingSqlTrustedApproval $trustedApproval,
        ?NotificationStagingSqlSchemaPreflight $schemaPreflight,
    ): NotificationStagingSqlPreflightResult {
        if ($trustedApproval === null) {
            throw new StagingSqlDeploymentException('Trusted notification deployment authorization is unavailable.');
        }

        if ($schemaPreflight === null) {
            throw new StagingSqlDeploymentException('Notification staging schema preflight is unavailable.');
        }

        $target = new StagingSqlDeploymentTarget(
            $package->targetHost,
            $package->targetPort,
            $package->targetDatabase,
        );

        $this->gates->assertTargetAllowed($target);
        $evidence->assertComplete($package);
        $trustedApproval->assertApproved($package, $evidence);
        $this->backupVerifier->assertFileMatches($backupPath, $evidence->backupSha256);
        $schemaPreflight->assertReady($package, $target);

        return new NotificationStagingSqlPreflightResult(
            releaseIdentity: $package->releaseIdentity,
            artifactSha256: $package->artifactSha256,
            targetDatabase: $target->database,
        );
    }
}
