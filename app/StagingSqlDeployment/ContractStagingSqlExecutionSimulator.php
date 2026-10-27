<?php

namespace App\StagingSqlDeployment;

/**
 * Offline-only simulator for rollback / verification / ambiguous-COMMIT contracts.
 * Never opens a database connector.
 */
final class ContractStagingSqlExecutionSimulator
{
    public function __construct(
        private StagingSqlDeploymentGateValidator $gates = new StagingSqlDeploymentGateValidator,
    ) {}

    public function simulateSqlError(
        AtomicStagingSqlPackage $package,
        StagingSqlDeploymentTarget $target,
        StagingSqlDeploymentAuthorization $authorization,
    ): StagingSqlExecutionOutcome {
        $this->gates->assertPackageReady($package);
        $this->gates->assertTargetAllowed($target);
        $this->gates->assertAuthorization($authorization);

        return StagingSqlExecutionOutcome::rolledBack(
            'Contract: SQL error aborts the single transaction; no COMMIT; connection must close.'
        );
    }

    public function simulateVerificationFailure(
        AtomicStagingSqlPackage $package,
        StagingSqlDeploymentTarget $target,
        StagingSqlDeploymentAuthorization $authorization,
    ): StagingSqlExecutionOutcome {
        $this->gates->assertPackageReady($package);
        $this->gates->assertTargetAllowed($target);
        $this->gates->assertAuthorization($authorization);

        return StagingSqlExecutionOutcome::verificationFailed(
            'Contract: pre-COMMIT verification failure prevents COMMIT and requires ROLLBACK.'
        );
    }

    public function simulateAmbiguousCommit(
        AtomicStagingSqlPackage $package,
        StagingSqlDeploymentTarget $target,
        StagingSqlDeploymentAuthorization $authorization,
    ): StagingSqlExecutionOutcome {
        $this->gates->assertPackageReady($package);
        $this->gates->assertTargetAllowed($target);
        $this->gates->assertAuthorization($authorization);

        return StagingSqlExecutionOutcome::ambiguousCommit(
            'Contract: connection loss during COMMIT is UNVERIFIABLE; no blind retry; require read-only re-inventory.'
        );
    }
}
