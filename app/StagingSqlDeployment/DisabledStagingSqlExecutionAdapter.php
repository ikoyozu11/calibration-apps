<?php

namespace App\StagingSqlDeployment;

/**
 * Fail-closed execution adapter for 21Z-A/21Z-B.
 *
 * Validates gates and ordered bodies, then refuses to open PostgreSQL or
 * emit COMMIT. Loading this class never connects. No live adapter in 21Z-B.
 */
final class DisabledStagingSqlExecutionAdapter implements StagingSqlExecutionAdapter
{
    public function __construct(
        private StagingSqlDeploymentGateValidator $gates = new StagingSqlDeploymentGateValidator,
    ) {}

    public function execute(
        AtomicStagingSqlPackage $package,
        StagingSqlDeploymentTarget $target,
        StagingSqlDeploymentAuthorization $authorization,
    ): StagingSqlExecutionOutcome {
        $this->gates->assertPackageReady($package);
        $this->gates->assertTargetAllowed($target);
        $this->gates->assertAuthorization($authorization);
        $this->gates->assertNoDefaultConnectionFallback(usingDefaultConnection: false);
        $this->gates->assertBodiesContainNoTopLevelCommit($package->orderedBodySql());

        // Future live adapter must follow StagingSqlAtomicExecutionSteps and
        // must NOT run a preassembled string that includes COMMIT before verify.
        if (! StagingSqlAtomicExecutionSteps::commitIsLastMutableStep()) {
            throw new StagingSqlDeploymentException('Atomic execution step contract is invalid.');
        }

        return StagingSqlExecutionOutcome::disabled(
            'Live staging SQL execution is disabled. Offline runner only; no PostgreSQL connection opened.'
        );
    }
}
